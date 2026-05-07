<?php
// /worker/qr_sales_save.php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_worker_login();

header('Content-Type: application/json; charset=utf-8');

function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

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
    json_error('db.php not found', 500);
}
require_once $dbFile;
require_once __DIR__ . '/../admin/_business_day.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $st->execute([':col' => $column]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

$tenantId = (int)($_SESSION['worker_tenant_id'] ?? 0);
$storeId = (int)($_SESSION['worker_store_id'] ?? 0);
$employeeId = (int)($_SESSION['worker_employee_id'] ?? 0);
if ($tenantId <= 0 || $storeId <= 0 || $employeeId <= 0) {
    json_error('認証情報が無効です。', 401);
}

$payload = [];
$rawBody = file_get_contents('php://input');
if ($rawBody !== '') {
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) $payload = [];
}

$sales = (int)($payload['sales_yen'] ?? 0);
$visitors = (int)($payload['visitors'] ?? 0);
if ($sales < 0) $sales = 0;
if ($visitors < 0) $visitors = 0;
$requestedBusinessDate = trim((string)($payload['business_date'] ?? ''));
if ($requestedBusinessDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedBusinessDate)) {
    json_error('営業日が不正です。');
}
if ($requestedBusinessDate !== '') {
    $dateCheck = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedBusinessDate);
    if (!$dateCheck || $dateCheck->format('Y-m-d') !== $requestedBusinessDate) {
        json_error('営業日が不正です。');
    }
}

$storeTz = 'Asia/Tokyo';
$cutoff = '05:00:00';
$reportedAt = '';
try {
    $storeCols = [];
    $stmtCols = $pdo->query("SHOW COLUMNS FROM `stores`");
    foreach ($stmtCols->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $storeCols[] = (string)($r['Field'] ?? '');
    }

    $select = [];
    if (in_array('payroll_tz', $storeCols, true)) {
        $select[] = "COALESCE(payroll_tz, 'Asia/Tokyo') AS payroll_tz";
    } else {
        $select[] = "'Asia/Tokyo' AS payroll_tz";
    }
    if (in_array('business_day_cutoff_time', $storeCols, true)) {
        $select[] = "COALESCE(business_day_cutoff_time, '05:00:00') AS business_day_cutoff_time";
    } else {
        $select[] = "'05:00:00' AS business_day_cutoff_time";
    }

    $st = $pdo->prepare("
        SELECT " . implode(', ', $select) . "
        FROM stores
        WHERE tenant_id = :t AND id = :s
        LIMIT 1
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId]);
    $storeRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $storeTz = (string)($storeRow['payroll_tz'] ?? 'Asia/Tokyo');
    $cutoff = (string)($storeRow['business_day_cutoff_time'] ?? '05:00:00');
} catch (Throwable $e) {
    $storeTz = 'Asia/Tokyo';
    $cutoff = '05:00:00';
}

$businessDate = '';
if ($requestedBusinessDate !== '') {
    $businessDate = $requestedBusinessDate;
    $reportedAt = $requestedBusinessDate . ' 12:00:00';
} else {
    try {
        $lastPunch = $pdo->prepare("
            SELECT punched_at
            FROM time_punches
            WHERE tenant_id = :t
              AND store_id = :s
              AND employee_id = :e
              AND punch_type IN ('clock_in','clock_out')
            ORDER BY punched_at DESC, id DESC
            LIMIT 1
        ");
        $lastPunch->execute([
            ':t' => $tenantId,
            ':s' => $storeId,
            ':e' => $employeeId,
        ]);
        $punchAt = (string)($lastPunch->fetchColumn() ?: '');
        if ($punchAt !== '') {
            $reportedAt = $punchAt;
            $businessDate = business_date_from_datetime(new DateTimeImmutable($punchAt, new DateTimeZone($storeTz)), $cutoff);
        }
    } catch (Throwable $e) {
        $businessDate = '';
    }
}

if ($businessDate === '') {
    $nowDt = new DateTimeImmutable('now', new DateTimeZone($storeTz));
    $reportedAt = $nowDt->format('Y-m-d H:i:s');
    $businessDate = business_date_from_datetime($nowDt, $cutoff);
}

$hasSalesConfirmed = has_column($pdo, 'daily_store_reports', 'sales_confirmed');
$hasReportedAt = has_column($pdo, 'daily_store_reports', 'reported_at');
$cols = "tenant_id, store_id, business_date, sales_yen, visitors, updated_by_employee_id";
$vals = ":t, :s, :d, :sales, :visitors, :eid";
$updates = "
      sales_yen = VALUES(sales_yen),
      visitors = VALUES(visitors),
      updated_by_employee_id = VALUES(updated_by_employee_id),
      updated_at = NOW()
";
$params = [
    ':t' => $tenantId,
    ':s' => $storeId,
    ':d' => $businessDate,
    ':sales' => $sales,
    ':visitors' => $visitors,
    ':eid' => $employeeId,
];

if ($hasSalesConfirmed) {
    $cols .= ", sales_confirmed";
    $vals .= ", :confirmed";
    $updates = "
      sales_yen = VALUES(sales_yen),
      visitors = VALUES(visitors),
      sales_confirmed = VALUES(sales_confirmed),
      updated_by_employee_id = VALUES(updated_by_employee_id),
      updated_at = NOW()
    ";
    $params[':confirmed'] = 1;
}

if ($hasReportedAt) {
    $cols .= ", reported_at";
    $vals .= ", :reported_at";
    $updates = "
      sales_yen = VALUES(sales_yen),
      visitors = VALUES(visitors),
      " . ($hasSalesConfirmed ? "sales_confirmed = VALUES(sales_confirmed)," : "") . "
      reported_at = VALUES(reported_at),
      updated_by_employee_id = VALUES(updated_by_employee_id),
      updated_at = NOW()
    ";
    $params[':reported_at'] = $reportedAt;
}

try {
    $up = $pdo->prepare("
        INSERT INTO daily_store_reports
          ({$cols})
        VALUES
          ({$vals})
        ON DUPLICATE KEY UPDATE
          {$updates}
    ");
    $up->execute($params);
} catch (Throwable $e) {
    json_error('保存に失敗しました。', 500);
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
