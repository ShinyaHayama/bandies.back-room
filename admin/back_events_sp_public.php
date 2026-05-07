<?php

declare(strict_types=1);

/**
 * ✅ /admin/back_event_save_public.php（新規）
 * スタッフ用：トークンで back_events を保存
 */

date_default_timezone_set('Asia/Tokyo');

$paths = [
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) if (is_file($p)) {
    $dbFile = $p;
    break;
}
if (!$dbFile) {
    http_response_code(500);
    echo 'db.php not found';
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

require_once __DIR__ . '/_share_token.php';

function fail(string $m, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $m;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('method not allowed', 405);

$tokenRow = share_token_require($pdo);
$perms = share_token_permissions($tokenRow);
if (!share_token_can($perms, 'save_back')) fail('権限がありません', 403);

$tenantId = (int)$tokenRow['tenant_id'];
$tokenStoreId = (int)($tokenRow['store_id'] ?? 0);

$storeId = (int)($_POST['store_id'] ?? 0);
$businessDate = (string)($_POST['business_date'] ?? '');
$eventType = (string)($_POST['event_type'] ?? '');
$employeeId = (int)($_POST['employee_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 0);
$amountYen = (int)($_POST['amount_yen'] ?? 0);
$memo = trim((string)($_POST['memo'] ?? ''));
$drinkKind = trim((string)($_POST['drink_kind'] ?? ''));

$doConfirm = ((string)($_POST['do_confirm'] ?? '') === '1');

if ($tokenStoreId > 0 && $storeId !== $tokenStoreId) fail('このリンクは別店舗に使えません', 403);
if ($storeId <= 0) fail('store_id invalid');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) fail('business_date invalid');
if (!in_array($eventType, ['nomination', 'drink_back', 'escort'], true)) fail('event_type invalid');
if ($employeeId <= 0) fail('employee_id invalid');
if ($quantity <= 0) fail('quantity invalid');

$meta = [];
if ($memo !== '') $meta['memo'] = $memo;
if ($eventType === 'drink_back' && $drinkKind !== '') $meta['drink_kind'] = $drinkKind;
$metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

$status = $doConfirm ? 'confirmed' : 'draft';

// ✅ 出勤者チェック（time_punchesが取れる時だけ“出勤者のみ”）
try {
    $tpCols = [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `time_punches`")->fetchAll();
        foreach ($rows as $r) if (isset($r['Field'])) $tpCols[(string)$r['Field']] = true;
    } catch (Throwable $e) {
    }

    $strict = false;
    if (!empty($tpCols) && isset($tpCols['tenant_id'], $tpCols['employee_id'], $tpCols['business_date'])) {
        $strict = true;
        if (isset($tpCols['store_id'])) {
            $st = $pdo->prepare("SELECT 1 FROM time_punches WHERE tenant_id=? AND store_id=? AND business_date=? AND employee_id=? LIMIT 1");
            $st->execute([$tenantId, $storeId, $businessDate, $employeeId]);
        } else {
            $st = $pdo->prepare("SELECT 1 FROM time_punches WHERE tenant_id=? AND business_date=? AND employee_id=? LIMIT 1");
            $st->execute([$tenantId, $businessDate, $employeeId]);
        }
        if (!$st->fetch()) fail("この日は出勤があるキャストのみ登録できます。", 400);
    } elseif (!empty($tpCols) && isset($tpCols['tenant_id'], $tpCols['employee_id'], $tpCols['punched_at'])) {
        $strict = true;
        if (isset($tpCols['store_id'])) {
            $st = $pdo->prepare("SELECT 1 FROM time_punches WHERE tenant_id=? AND store_id=? AND DATE(punched_at)=? AND employee_id=? LIMIT 1");
            $st->execute([$tenantId, $storeId, $businessDate, $employeeId]);
        } else {
            $st = $pdo->prepare("SELECT 1 FROM time_punches WHERE tenant_id=? AND DATE(punched_at)=? AND employee_id=? LIMIT 1");
            $st->execute([$tenantId, $businessDate, $employeeId]);
        }
        if (!$st->fetch()) fail("この日は出勤があるキャストのみ登録できます。", 400);
    }
} catch (Throwable $e) {
    // strict判定失敗は“登録を止めない”（現場止めないため）
}

try {
    $ins = $pdo->prepare("
        INSERT INTO back_events
          (tenant_id, store_id, business_date, employee_id, event_type, quantity, amount_yen, status, meta_json, created_at)
        VALUES
          (:tenant_id, :store_id, :business_date, :employee_id, :event_type, :quantity, :amount_yen, :status, :meta_json, CURRENT_TIMESTAMP)
    ");
    $ins->execute([
        ':tenant_id' => $tenantId,
        ':store_id' => $storeId,
        ':business_date' => $businessDate,
        ':employee_id' => $employeeId,
        ':event_type' => $eventType,
        ':quantity' => $quantity,
        ':amount_yen' => $amountYen,
        ':status' => $status,
        ':meta_json' => $metaJson,
    ]);
} catch (Throwable $e) {
    fail("save failed\n\n" . $e->getMessage(), 500);
}

$t = urlencode(share_token_read_from_request());
$to = "/admin/back_events_sp_public.php?store_id={$storeId}&date=" . urlencode($businessDate) . "&t={$t}";
header('Location: ' . $to);
exit;