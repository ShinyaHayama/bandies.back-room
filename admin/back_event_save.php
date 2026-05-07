<?php
// ✅ ファイル名: /admin/back_event_save.php
// ✅ 書き込み場所: このファイルを「丸ごと置き換え」

declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n\n";
    echo "Exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    echo $e->getTraceAsString();
    exit;
});

function startSession(string $name): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
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

// ===== モード判定（tokenがあるなら staff 扱いに寄せる）=====
$staffMode = ((string)($_POST['staff'] ?? '') === '1') || ((string)($_POST['token'] ?? '') !== '');
$postToken = (string)($_POST['token'] ?? '');

// ===== 認証 & セッション =====
$tenantId = 0;
$adminUserId = null;

$tokenStoreId = 0;
$tokenDate = '';

if ($staffMode) {
    startSession('STAFFSESSID');
    mustCsrf();

    if ($postToken === '') {
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

    $st = $pdo->prepare("SELECT " . implode(',', $selectCols) . " FROM staff_share_tokens WHERE token=? LIMIT 1");
    $st->execute([$postToken]);
    $row = $st->fetch();

    if (!$row) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token invalid';
        exit;
    }

    if (isset($row['expires_at']) && !empty($row['expires_at'])) {
        $exp = strtotime((string)$row['expires_at']);
        if ($exp !== false && $exp < time()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'token expired';
            exit;
        }
    }

    $tenantId = (int)$row['tenant_id'];
    $tokenStoreId = (int)$row['store_id'];
    $tokenDate = (string)$row['date_ymd'];

    if ($tenantId <= 0 || $tokenStoreId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tokenDate)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token data invalid';
        exit;
    }
} else {
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

    if (isset($_SESSION['tenant_admin_user_id'])) $adminUserId = (int)$_SESSION['tenant_admin_user_id'];
    if (isset($_SESSION['admin_user_id'])) $adminUserId = (int)$_SESSION['admin_user_id'];
}

// ===== 入力 =====
$storeId = (int)($_POST['store_id'] ?? 0);
$businessDate = (string)($_POST['business_date'] ?? '');
$eventType = (string)($_POST['event_type'] ?? '');
$employeeId = (int)($_POST['employee_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 1);
$amountYen = (int)($_POST['amount_yen'] ?? 0);
$typeItemId = (int)($_POST['type_item_id'] ?? 0);
$drinkKind = (string)($_POST['drink_kind'] ?? '');
$memo = (string)($_POST['memo'] ?? '');
$doConfirm = (int)($_POST['do_confirm'] ?? 0);

if ($storeId <= 0 || $employeeId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'invalid store/employee';
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'invalid business_date';
    exit;
}
if (!tableExists($pdo, 'back_event_types')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'back_event_types not found';
    exit;
}
$btc = tableColumns($pdo, 'back_event_types');
$colKey = isset($btc['event_type']) ? 'event_type'
    : (isset($btc['type_key']) ? 'type_key'
        : (isset($btc['code']) ? 'code'
            : (isset($btc['key']) ? 'key' : 'event_type')));
$colActive = isset($btc['is_active']) ? 'is_active'
    : (isset($btc['enabled']) ? 'enabled' : '');
$hasStoreCol = isset($btc['store_id']);

$w = ["tenant_id = :tid", "{$colKey} = :key"];
$params = [':tid' => $tenantId, ':key' => $eventType];
if ($hasStoreCol) {
    $w[] = "(store_id = :sid OR store_id IS NULL OR store_id = 0)";
    $params[':sid'] = $storeId;
}
if ($colActive !== '') {
    $w[] = "({$colActive} = 1 OR {$colActive} = '1')";
}
$sql = "SELECT 1 FROM back_event_types WHERE " . implode(' AND ', $w) . " LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute($params);
if (!$st->fetchColumn()) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'invalid event_type';
    exit;
}

$quantity = max(1, $quantity);
$amountYen = max(0, $amountYen);

$meta = ['memo' => $memo];
if ($eventType === 'drink_back' && $drinkKind !== '') $meta['drink_kind'] = $drinkKind;
if ($typeItemId > 0) {
    try {
        if (tableExists($pdo, 'back_event_type_items')) {
            $stItem = $pdo->prepare("
                SELECT label, amount_yen
                FROM back_event_type_items
                WHERE id=? AND tenant_id=? AND store_id=? AND is_active=1
                LIMIT 1
            ");
            $stItem->execute([$typeItemId, $tenantId, $storeId]);
            $rowItem = $stItem->fetch();
            if ($rowItem) {
                $meta['type_item_id'] = $typeItemId;
                $meta['type_item_label'] = (string)$rowItem['label'];
                $amountYen = max(0, (int)$rowItem['amount_yen']);
            }
        }
    } catch (Throwable $e) {
        // 握りつぶし
    }
}

$status = ($doConfirm === 1) ? 'confirmed' : 'draft';

// staffは「tokenの store/date と一致するPOSTだけ許可」
if ($staffMode) {
    if ($storeId !== $tokenStoreId || $businessDate !== $tokenDate) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "store/date mismatch (token vs post)";
        exit;
    }
}

// ===== 保存 =====
$st = $pdo->prepare("
    INSERT INTO back_events
      (tenant_id, store_id, business_date, event_type, employee_id, quantity, amount_yen, meta_json, status, created_by_admin_user_id)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$st->execute([
    $tenantId,
    $storeId,
    $businessDate,
    $eventType,
    $employeeId,
    $quantity,
    $amountYen,
    json_encode($meta, JSON_UNESCAPED_UNICODE),
    $status,
    $adminUserId
]);

// ===== 戻り先 =====
if ($staffMode) {
    header('Location: /s/back_events_sp.php?token=' . rawurlencode($postToken));
    exit;
}

$return = (string)($_POST['return'] ?? '/admin/back_events_sp.php');
if ($return === '' || $return[0] !== '/' || strpos($return, '/admin/') !== 0) $return = '/admin/back_events_sp.php';
header('Location: ' . $return);
exit;
