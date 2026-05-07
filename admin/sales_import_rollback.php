<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

date_default_timezone_set('Asia/Tokyo');

// ===== DB =====
$paths = [
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if ($dbFile === null) {
    http_response_code(500);
    exit('db.php not found');
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function is_valid_csrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}

$errors = [];
$batchId = (int)($_POST['import_batch_id'] ?? 0);
$storeId = (int)($_POST['store_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('method not allowed');
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!is_valid_csrf($csrf)) {
    http_response_code(400);
    exit('CSRF invalid');
}

if ($batchId <= 0) $errors[] = 'バッチIDが不正です。';

if ($errors) {
    http_response_code(400);
    exit('invalid request');
}

$pdo->beginTransaction();
try {
    // read batch
    $st = $pdo->prepare("SELECT store_id FROM sales_import_batches WHERE id=:id AND tenant_id=:t LIMIT 1");
    $st->execute([':id' => $batchId, ':t' => $tenantId]);
    $batchStoreId = (int)($st->fetchColumn() ?: 0);
    if ($batchStoreId <= 0) {
        throw new RuntimeException('batch not found');
    }

    // backups
    $bk = $pdo->prepare("SELECT business_date, sales_yen, visitors, sales_confirmed, updated_by_employee_id, updated_at
        FROM sales_import_backups WHERE import_batch_id=:b AND tenant_id=:t AND store_id=:s");
    $bk->execute([':b' => $batchId, ':t' => $tenantId, ':s' => $batchStoreId]);
    $rows = $bk->fetchAll();

    // delete affected rows
    $del = $pdo->prepare("DELETE FROM daily_store_reports WHERE tenant_id=:t AND store_id=:s AND business_date IN (
        SELECT business_date FROM sales_import_backups WHERE import_batch_id=:b AND tenant_id=:t2 AND store_id=:s2
    )");
    $del->execute([':b' => $batchId, ':t' => $tenantId, ':s' => $batchStoreId, ':t2' => $tenantId, ':s2' => $batchStoreId]);

    // restore backups
    if ($rows) {
        $ins = $pdo->prepare("INSERT INTO daily_store_reports
            (tenant_id, store_id, business_date, sales_yen, visitors, sales_confirmed, updated_by_employee_id, updated_at)
            VALUES
            (:t, :s, :d, :sales, :visitors, :confirmed, :eid, :updated_at)
            ON DUPLICATE KEY UPDATE
                sales_yen=VALUES(sales_yen),
                sales_confirmed=VALUES(sales_confirmed),
                visitors=VALUES(visitors),
                updated_by_employee_id=VALUES(updated_by_employee_id),
                updated_at=VALUES(updated_at)");
        foreach ($rows as $r) {
            $ins->execute([
                ':t' => $tenantId,
                ':s' => $batchStoreId,
                ':d' => $r['business_date'],
                ':sales' => (int)$r['sales_yen'],
                ':visitors' => (int)$r['visitors'],
                ':confirmed' => (int)$r['sales_confirmed'],
                ':eid' => $r['updated_by_employee_id'],
                ':updated_at' => $r['updated_at'],
            ]);
        }
    }

    $pdo->commit();
    header('Location: /admin/index.php?store_id=' . (int)$batchStoreId);
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n\n";
    echo "Message: " . $e->getMessage() . "\n";
    exit;
}
