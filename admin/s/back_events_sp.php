<?php
// ✅ ファイル名: /s/back_events_sp.php
// ✅ 書き込み場所: このファイルを「丸ごと置き換え」
//
// 役割（安定版・出力ゼロでheaderを確実に通す）
// - token row をDBから読み tenant/store/date を確定
// - ユーザーが date/store_id を付けて来たら「必ず」新token発行して /admin へ直行
// - token-only ならそのまま /admin へ
//
// 重要：
// - display_errors は通常OFF（header(Location)を壊さない）
// - debug=1 の時だけ text/plain を返して停止（その時はリダイレクトしない）

declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

// ===== 本番は絶対に出力しない（headerを壊さない）=====
$debug = ((string)($_GET['debug'] ?? '') === '1');
if (!$debug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}
error_reporting(E_ALL);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

set_exception_handler(function (Throwable $e) use ($debug) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n\n";
    echo "Exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    if ($debug) {
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
        echo $e->getTraceAsString();
    }
    exit;
});

function base64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function newToken(int $bytes = 24): string
{
    return base64url(random_bytes($bytes));
}
function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}
function tableColumns(PDO $pdo, string $table): array
{
    $cols = [];
    $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (isset($r['Field'])) $cols[(string)$r['Field']] = true;
    }
    return $cols;
}

// ===== DB =====
$paths = [__DIR__ . '/../api/lib/db.php', __DIR__ . '/../lib/db.php'];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if (!$dbFile) throw new RuntimeException('db.php not found');
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ===== token =====
$token = (string)($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "token missing";
    exit;
}

if (!tableExists($pdo, 'staff_share_tokens')) {
    throw new RuntimeException('staff_share_tokens table not found');
}

$cols = tableColumns($pdo, 'staff_share_tokens');
foreach (['token', 'tenant_id', 'store_id', 'date_ymd'] as $need) {
    if (!isset($cols[$need])) throw new RuntimeException("staff_share_tokens missing column: {$need}");
}

$selectCols = ['id', 'token', 'tenant_id', 'store_id', 'date_ymd'];
if (isset($cols['expires_at'])) $selectCols[] = 'expires_at';

$st = $pdo->prepare("SELECT " . implode(',', $selectCols) . " FROM staff_share_tokens WHERE token=? LIMIT 1");
$st->execute([$token]);
$row = $st->fetch();

if (!$row) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "token invalid";
    exit;
}

// expires（列がある場合だけ）
if (isset($row['expires_at']) && !empty($row['expires_at'])) {
    $exp = strtotime((string)$row['expires_at']);
    if ($exp !== false && $exp < time()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "token expired";
        exit;
    }
}

$tenantId = (int)$row['tenant_id'];
$storeIdFromToken = (int)$row['store_id'];
$dateFromToken = (string)$row['date_ymd'];

if ($tenantId <= 0 || $storeIdFromToken <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromToken)) {
    throw new RuntimeException('token data invalid (tenant/store/date_ymd)');
}

// ===== リクエストの store/date（入力があれば採用）=====
$hasReqDate  = array_key_exists('date', $_GET);
$hasReqStore = array_key_exists('store_id', $_GET);

$reqStoreId = (int)($_GET['store_id'] ?? 0);
$reqDate    = (string)($_GET['date'] ?? '');

if ($reqDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) $reqDate = '';
if ($reqStoreId <= 0) $reqStoreId = 0;

$wantStoreId = ($reqStoreId > 0) ? $reqStoreId : $storeIdFromToken;
$wantDate    = ($reqDate !== '') ? $reqDate : $dateFromToken;

// ✅ 仕様：date/store_id を送ってきたら“必ず”新token発行
$mustReissue = ($hasReqDate || $hasReqStore);

// debug=1 のときだけ停止して状況表示（ここではリダイレクトしない）
if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== /s/back_events_sp.php DEBUG ===\n";
    echo "script: " . __FILE__ . "\n\n";
    echo "token(in): " . $token . "\n";
    echo "tokenDate(from DB): " . $dateFromToken . "\n";
    echo "store(from DB): " . $storeIdFromToken . "\n\n";
    echo "GET.date exists: " . ($hasReqDate ? 'YES' : 'NO') . "\n";
    echo "GET.date raw: " . (string)($_GET['date'] ?? '') . "\n";
    echo "GET.store_id raw: " . (string)($_GET['store_id'] ?? '') . "\n\n";
    echo "wantDate: " . $wantDate . "\n";
    echo "wantStoreId: " . $wantStoreId . "\n";
    echo "mustReissue: " . ($mustReissue ? 'YES' : 'NO') . "\n";
    exit;
}

// expires_at があるなら 24h
$expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * 24);

if ($mustReissue) {
    // token重複が怖いので最大5回トライ
    $new = '';
    for ($i = 0; $i < 5; $i++) {
        $candidate = newToken(24);
        $chk = $pdo->prepare("SELECT 1 FROM staff_share_tokens WHERE token=? LIMIT 1");
        $chk->execute([$candidate]);
        if (!$chk->fetchColumn()) {
            $new = $candidate;
            break;
        }
    }
    if ($new === '') throw new RuntimeException('failed to generate unique token');

    $insCols = ['token', 'tenant_id', 'store_id', 'date_ymd'];
    $insVals = [':token', ':tenant_id', ':store_id', ':date_ymd'];
    $params = [
        ':token' => $new,
        ':tenant_id' => $tenantId,
        ':store_id' => $wantStoreId,
        ':date_ymd' => $wantDate,
    ];

    if (isset($cols['expires_at'])) {
        $insCols[] = 'expires_at';
        $insVals[] = ':expires_at';
        $params[':expires_at'] = $expiresAt;
    }
    if (isset($cols['created_at'])) {
        $insCols[] = 'created_at';
        $insVals[] = 'CURRENT_TIMESTAMP';
    }

    $sql = "INSERT INTO staff_share_tokens (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insVals) . ")";
    $pdo->prepare($sql)->execute($params);

    // ✅ 新token作ったら adminへ直行（token-onlyで入る）
    $to = '/admin/back_events_sp.php?' . http_build_query([
        'staff' => '1',
        'token' => $new,
    ]);
    header('Location: ' . $to, true, 302);
    exit;
}

// token-only で来た場合はそのまま admin
$to = '/admin/back_events_sp.php?' . http_build_query([
    'staff' => '1',
    'token' => $token,
]);
header('Location: ' . $to, true, 302);
exit;