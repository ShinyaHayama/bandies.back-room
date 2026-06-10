<?php
// /worker/qr_clock.php

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
require_once __DIR__ . '/../lib/punch_source.php';
require_once __DIR__ . '/../lib/punch_capture.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
punch_source_ensure_column($pdo);
punch_capture_ensure_columns($pdo);

require_once __DIR__ . '/../admin/_business_day.php';

function tableColumnMeta(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $meta = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $field = (string)($r['Field'] ?? '');
        if ($field === '') continue;
        $meta[$field] = $r;
    }
    $cache[$table] = $meta;
    return $meta;
}

function tableColumns(PDO $pdo, string $table): array
{
    $meta = tableColumnMeta($pdo, $table);
    return array_keys($meta);
}

function safeInsert(PDO $pdo, string $table, array $data): void
{
    $cols = tableColumns($pdo, $table);
    $use = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $cols, true)) $use[$k] = $v;
    }
    if (empty($use)) {
        throw new RuntimeException("INSERT failed: no matching columns for table {$table}");
    }
    $fields = array_keys($use);
    $ph = array_map(fn($f) => ':' . $f, $fields);

    $sql = "INSERT INTO `{$table}` (" . implode(',', array_map(fn($f) => "`{$f}`", $fields)) . ") VALUES (" . implode(',', $ph) . ")";
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($use as $k => $v) $params[':' . $k] = $v;
    $stmt->execute($params);
}

function resolveDeviceIdForTimePunch(PDO $pdo, int $tenantId, int $storeId, int $employeeId): ?int
{
    try {
        $stmt = $pdo->prepare("
            SELECT device_id
            FROM time_punches
            WHERE tenant_id = :tenant_id
              AND store_id  = :store_id
              AND employee_id = :employee_id
              AND device_id IS NOT NULL
              AND device_id <> 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':store_id' => $storeId,
            ':employee_id' => $employeeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['device_id'])) {
            $v = (int)$row['device_id'];
            if ($v > 0) return $v;
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("
            SELECT device_id
            FROM time_punches
            WHERE tenant_id = :tenant_id
              AND store_id  = :store_id
              AND device_id IS NOT NULL
              AND device_id <> 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':store_id' => $storeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['device_id'])) {
            $v = (int)$row['device_id'];
            if ($v > 0) return $v;
        }
    } catch (Throwable $e) {
        // ignore
    }

    return null;
}

function extract_token(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    if (strpos($raw, 'token=') !== false) {
        $parts = parse_url($raw);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['token'])) return (string)$q['token'];
        }
        if (preg_match('/token=([A-Za-z0-9_-]+)/', $raw, $m)) return $m[1];
    }
    if (preg_match('/^[A-Za-z0-9_-]{8,}$/', $raw)) return $raw;
    return '';
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
$tokenRaw = (string)($payload['token'] ?? $_POST['token'] ?? $_GET['token'] ?? '');
$token = extract_token($tokenRaw);

$storeCols = tableColumns($pdo, 'stores');
if ($token !== '' && !in_array('clock_qr_token', $storeCols, true)) {
    json_error('QR機能が未設定です。');
}

$storeCols = tableColumns($pdo, 'stores');
$hasPromptCol = in_array('clock_qr_sales_prompt', $storeCols, true);
$hasTzCol = in_array('payroll_tz', $storeCols, true);
$hasCutoffCol = in_array('business_day_cutoff_time', $storeCols, true);

$selectPrompt = $hasPromptCol ? "COALESCE(clock_qr_sales_prompt, 0) AS clock_qr_sales_prompt" : "0 AS clock_qr_sales_prompt";
$selectTz = $hasTzCol ? "COALESCE(payroll_tz, 'Asia/Tokyo') AS payroll_tz" : "'Asia/Tokyo' AS payroll_tz";
$selectCutoff = $hasCutoffCol ? "COALESCE(business_day_cutoff_time, '05:00:00') AS business_day_cutoff_time" : "'05:00:00' AS business_day_cutoff_time";

$storeWhere = $token !== ''
    ? "clock_qr_token = :token"
    : "tenant_id = :tenant_id AND id = :store_id";
$storeStmt = $pdo->prepare("
    SELECT id, tenant_id,
           {$selectPrompt},
           {$selectTz},
           {$selectCutoff}
    FROM stores
    WHERE {$storeWhere}
    LIMIT 1
");
$storeParams = $token !== ''
    ? [':token' => $token]
    : [':tenant_id' => $tenantId, ':store_id' => $storeId];
$storeStmt->execute($storeParams);
$storeRow = $storeStmt->fetch();
if (!$storeRow) {
    json_error($token !== '' ? 'QRが無効です。' : '店舗情報を確認できません。');
}
if ((int)$storeRow['tenant_id'] !== $tenantId || (int)$storeRow['id'] !== $storeId) {
    json_error('このQRは別店舗のため使用できません。');
}

$lastStmt = $pdo->prepare("
    SELECT punch_type, punched_at
    FROM time_punches
    WHERE tenant_id = :t
      AND store_id  = :s
      AND employee_id = :e
      AND punch_type IN ('clock_in','clock_out')
    ORDER BY punched_at DESC, id DESC
    LIMIT 1
");
$lastStmt->execute([
    ':t' => $tenantId,
    ':s' => $storeId,
    ':e' => $employeeId,
]);
$last = $lastStmt->fetch();
$nextType = ($last && (string)$last['punch_type'] === 'clock_in') ? 'clock_out' : 'clock_in';

$tpMeta = tableColumnMeta($pdo, 'time_punches');
$tpHasDeviceId = isset($tpMeta['device_id']);
$tpDeviceNullable = true;
if ($tpHasDeviceId) {
    $nullFlag = (string)($tpMeta['device_id']['Null'] ?? '');
    $tpDeviceNullable = ($nullFlag === 'YES');
}
$resolvedDeviceId = $tpHasDeviceId ? resolveDeviceIdForTimePunch($pdo, $tenantId, $storeId, $employeeId) : null;
if ($tpHasDeviceId && !$tpDeviceNullable && ($resolvedDeviceId === null || $resolvedDeviceId <= 0)) {
    json_error('端末が未登録のため打刻できません。');
}

$now = date('Y-m-d H:i:s');
try {
    $photoPath = null;
    $lat = null;
    $lng = null;
    $accuracy = null;
    $address = null;

    if ($nextType === 'clock_in') {
        $photoData = (string)($payload['face_photo_data'] ?? '');
        if ($photoData === '') {
            json_error('出勤には顔写真の撮影が必要です。');
        }

        $latRaw = $payload['latitude'] ?? null;
        $lngRaw = $payload['longitude'] ?? null;
        $accRaw = $payload['location_accuracy_m'] ?? $payload['accuracy'] ?? null;
        if (!is_numeric($latRaw) || !is_numeric($lngRaw)) {
            json_error('出勤には位置情報の取得が必要です。');
        }
        $lat = (float)$latRaw;
        $lng = (float)$lngRaw;
        $accuracy = is_numeric($accRaw) ? (float)$accRaw : null;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            json_error('位置情報が不正です。');
        }
        if ($accuracy !== null && ($accuracy < 0 || $accuracy > 100000)) {
            $accuracy = null;
        }

        $photoPath = punch_capture_save_photo_from_data_url($photoData, $tenantId, $storeId, $employeeId, $now);
        $address = punch_capture_reverse_geocode($lat, $lng);
    }

    $data = [
        'tenant_id' => $tenantId,
        'store_id' => $storeId,
        'employee_id' => $employeeId,
        'punch_source' => 'mypage',
        'punch_type' => $nextType,
        'punched_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    if ($tpHasDeviceId) {
        $data['device_id'] = ($resolvedDeviceId !== null && $resolvedDeviceId > 0) ? $resolvedDeviceId : null;
    }
    if ($nextType === 'clock_in') {
        $data['punch_face_photo_path'] = $photoPath;
        $data['punch_latitude'] = $lat;
        $data['punch_longitude'] = $lng;
        $data['punch_location_accuracy_m'] = $accuracy;
        $data['punch_location_captured_at'] = $now;
        $data['punch_location_address'] = $address;
    }
    safeInsert($pdo, 'time_punches', $data);
} catch (Throwable $e) {
    json_error($e->getMessage() !== '' ? $e->getMessage() : '打刻に失敗しました。');
}

$promptSales = ((int)($storeRow['clock_qr_sales_prompt'] ?? 0) === 1);
$businessDate = '';
$prefillSales = 0;
$prefillVisitors = 0;
try {
    $tz = new DateTimeZone((string)($storeRow['payroll_tz'] ?? 'Asia/Tokyo'));
    $cutoff = (string)($storeRow['business_day_cutoff_time'] ?? '05:00:00');
    $dt = new DateTimeImmutable($now, $tz);
    $businessDate = business_date_from_datetime($dt, $cutoff);

    $st = $pdo->prepare("
        SELECT sales_yen, visitors
        FROM daily_store_reports
        WHERE tenant_id = :t AND store_id = :s AND business_date = :d
        LIMIT 1
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':d' => $businessDate]);
    $r = $st->fetch();
    if ($r) {
        $prefillSales = (int)($r['sales_yen'] ?? 0);
        $prefillVisitors = (int)($r['visitors'] ?? 0);
    }
} catch (Throwable $e) {
    $businessDate = '';
}

echo json_encode([
    'ok' => true,
    'punch_type' => $nextType,
    'punched_at' => $now,
    'prompt_sales' => $promptSales,
    'business_date' => $businessDate,
    'sales_yen' => $prefillSales,
    'visitors' => $prefillVisitors,
], JSON_UNESCAPED_UNICODE);
