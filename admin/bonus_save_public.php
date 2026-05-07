<?php

declare(strict_types=1);

/**
 * ✅ /admin/bonus_save_public.php（新規）
 * スタッフ用：トークンで daily_wage_adjustments を upsert
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
if (!share_token_can($perms, 'save_bonus')) fail('権限がありません', 403);

$tenantId = (int)$tokenRow['tenant_id'];
$tokenStoreId = (int)($tokenRow['store_id'] ?? 0);

$storeId = (int)($_POST['store_id'] ?? 0);
$date = (string)($_POST['business_date'] ?? '');
$empId = (int)($_POST['employee_id'] ?? 0);
$bonus = (int)($_POST['bonus_yen'] ?? 0);

if ($tokenStoreId > 0 && $storeId !== $tokenStoreId) fail('このリンクは別店舗に使えません', 403);
if ($storeId <= 0) fail('store_id invalid');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('business_date invalid');
if ($empId <= 0) fail('employee_id invalid');

// 列チェック
function tableColumns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        foreach ($rows as $r) if (isset($r['Field'])) $cols[(string)$r['Field']] = true;
    } catch (Throwable $e) {
        return [];
    }
    return $cols;
}

$cols = tableColumns($pdo, 'daily_wage_adjustments');
if (empty($cols)) fail('daily_wage_adjustments not found', 500);

$hasStoreId = isset($cols['store_id']);
$hasUpdatedAt = isset($cols['updated_at']);
$hasCreatedAt = isset($cols['created_at']);

// 既存探索
if ($hasStoreId) {
    $st = $pdo->prepare("SELECT id FROM daily_wage_adjustments WHERE tenant_id=? AND store_id=? AND employee_id=? AND business_date=? LIMIT 1");
    $st->execute([$tenantId, $storeId, $empId, $date]);
} else {
    $st = $pdo->prepare("SELECT id FROM daily_wage_adjustments WHERE tenant_id=? AND employee_id=? AND business_date=? LIMIT 1");
    $st->execute([$tenantId, $empId, $date]);
}
$found = $st->fetch(PDO::FETCH_ASSOC);

try {
    if ($found && isset($found['id'])) {
        $set = "bonus_yen=:b";
        if ($hasUpdatedAt) $set .= ", updated_at=CURRENT_TIMESTAMP";
        $upd = $pdo->prepare("UPDATE daily_wage_adjustments SET {$set} WHERE id=:id AND tenant_id=:t LIMIT 1");
        $upd->execute([':b' => $bonus, ':id' => (int)$found['id'], ':t' => $tenantId]);
    } else {
        $fields = ['tenant_id', 'employee_id', 'business_date', 'bonus_yen'];
        $vals = [':tenant_id', ':employee_id', ':business_date', ':bonus_yen'];
        $params = [
            ':tenant_id' => $tenantId,
            ':employee_id' => $empId,
            ':business_date' => $date,
            ':bonus_yen' => $bonus
        ];
        if ($hasStoreId) {
            $fields[] = 'store_id';
            $vals[] = ':store_id';
            $params[':store_id'] = $storeId;
        }
        if ($hasCreatedAt) {
            $fields[] = 'created_at';
            $vals[] = 'CURRENT_TIMESTAMP';
        }
        if ($hasUpdatedAt) {
            $fields[] = 'updated_at';
            $vals[] = 'CURRENT_TIMESTAMP';
        }

        $sql = "INSERT INTO daily_wage_adjustments (" . implode(',', $fields) . ") VALUES (" . implode(',', $vals) . ")";
        $ins = $pdo->prepare($sql);
        $ins->execute($params);
    }
} catch (Throwable $e) {
    fail("bonus save failed\n\n" . $e->getMessage(), 500);
}

$t = urlencode(share_token_read_from_request());
$to = "/admin/back_events_sp_public.php?store_id={$storeId}&date=" . urlencode($date) . "&t={$t}";
header('Location: ' . $to);
exit;