<?php
// ✅ ファイル名: /admin/back_event_delete.php
// ✅ 書き込み場所: このファイルを「丸ごと置き換え」
//
// 対応内容:
// - staff=1 の場合: STAFFSESSID + token認証 + tokenのstore/date一致の行だけ削除OK
// - admin の場合: 従来通り ADMINSESSID + 管理者ログイン + tenant文脈
// - CSRF は「そのモードのセッション」の csrf_token で検証

declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function startSession(string $name): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/', // /admin と /s の両方で使う
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

function mustCsrf(): void
{
    $csrf = (string)($_POST['csrf'] ?? '');
    if ($csrf === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'CSRF invalid';
        exit;
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function tableColumns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (isset($r['Field'])) $cols[(string)$r['Field']] = true;
        }
    } catch (Throwable $e) {
        return [];
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
if (!$dbFile) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'db.php not found';
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ===== mode =====
$staffMode = ((string)($_POST['staff'] ?? '') === '1');
$token = (string)($_POST['token'] ?? '');

$tenantId = 0;
$tokenStoreId = 0;
$tokenDate = '';

if ($staffMode) {
    // staff: STAFFセッション + token認証
    startSession('STAFFSESSID');
    mustCsrf();

    if ($token === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token missing';
        exit;
    }
    if (!tableExists($pdo, 'staff_share_tokens')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'staff_share_tokens not found';
        exit;
    }

    $cols = tableColumns($pdo, 'staff_share_tokens');
    foreach (['token', 'tenant_id', 'store_id', 'date_ymd'] as $need) {
        if (!isset($cols[$need])) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "staff_share_tokens missing column: {$need}";
            exit;
        }
    }

    $selectCols = ['tenant_id', 'store_id', 'date_ymd'];
    if (isset($cols['expires_at'])) $selectCols[] = 'expires_at';

    $stTok = $pdo->prepare("SELECT " . implode(',', $selectCols) . " FROM staff_share_tokens WHERE token=? LIMIT 1");
    $stTok->execute([$token]);
    $tokRow = $stTok->fetch();

    if (!$tokRow) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token invalid';
        exit;
    }

    if (isset($tokRow['expires_at']) && !empty($tokRow['expires_at'])) {
        $exp = strtotime((string)$tokRow['expires_at']);
        if ($exp !== false && $exp < time()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'token expired';
            exit;
        }
    }

    $tenantId = (int)$tokRow['tenant_id'];
    $tokenStoreId = (int)$tokRow['store_id'];
    $tokenDate = (string)$tokRow['date_ymd'];

    if ($tenantId <= 0 || $tokenStoreId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tokenDate)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token data invalid';
        exit;
    }
} else {
    // admin: 管理者ログイン + ADMINセッション
    startSession('ADMINSESSID');

    require_once __DIR__ . '/_auth.php';
    require_admin_login();

    require_once __DIR__ . '/_tenant_context.php';
    if (!isset($tenantId) || (int)$tenantId <= 0) {
        header('Location: /admin/login.php');
        exit;
    }
    $tenantId = (int)$tenantId;

    mustCsrf();
}

// ===== input =====
$id = (int)($_POST['id'] ?? 0);
$return = (string)($_POST['return'] ?? '/admin/back_events_sp.php');

if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'id invalid';
    exit;
}

// return整形
if ($staffMode) {
    $return = '/s/back_events_sp.php?token=' . rawurlencode($token);
} else {
    if ($return === '' || $return[0] !== '/' || strpos($return, '/admin/') !== 0) {
        $return = '/admin/back_events_sp.php';
    }
}

// ===== 権限チェック（staffは tokenのstore/dateの行だけ削除OK）=====
if ($staffMode) {
    $st = $pdo->prepare("SELECT store_id, business_date FROM back_events WHERE id=? AND tenant_id=? LIMIT 1");
    $st->execute([$id, $tenantId]);
    $r = $st->fetch();

    if (!$r) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'not found';
        exit;
    }

    if ((int)$r['store_id'] !== $tokenStoreId || (string)$r['business_date'] !== $tokenDate) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'forbidden (store/date mismatch)';
        exit;
    }
}

// ===== delete =====
$del = $pdo->prepare("DELETE FROM back_events WHERE id=? AND tenant_id=? LIMIT 1");
$del->execute([$id, $tenantId]);

header('Location: ' . $return);
exit;