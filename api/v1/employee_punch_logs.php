<?php

declare(strict_types=1);

/**
 * ✅ /api/v1/employee_punch_logs.php
 * 従業員PINで本人の打刻履歴を返す
 */

header('Content-Type: application/json; charset=utf-8');

// ✅ ここが重要：v1配下からは ../lib/db.php
require_once __DIR__ . '/../lib/db.php';

function jexit(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId   = (int)($_GET['tenant_id'] ?? 0);
$storeId    = (int)($_GET['store_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);
$pin        = trim((string)($_GET['pin'] ?? ''));
$limit      = (int)($_GET['limit'] ?? 50);

if ($tenantId <= 0 || $storeId <= 0 || $employeeId <= 0) {
    jexit(['ok' => false, 'message' => 'bad request'], 400);
}
if (!preg_match('/^\d{4}$/', $pin)) {
    jexit(['ok' => false, 'message' => 'PINは4桁の数字で入力してください'], 400);
}
$limit = max(1, min(200, $limit));

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // 1) employee
    $empStmt = $pdo->prepare("
        SELECT id, auth_pin_code, auth_pin_hash, auth_pin_salt
        FROM employees
        WHERE tenant_id = :tenant_id
          AND store_id  = :store_id
          AND id        = :id
          AND employment_status = 'active'
        LIMIT 1
    ");
    $empStmt->execute([
        ':tenant_id' => $tenantId,
        ':store_id'  => $storeId,
        ':id'        => $employeeId,
    ]);
    $emp = $empStmt->fetch();

    if (!$emp) {
        jexit(['ok' => false, 'message' => '従業員が見つかりません'], 404);
    }

    // 2) PIN verify
    $okPin = false;

    $hash = $emp['auth_pin_hash'] ?? null; // varbinary(32)
    $salt = $emp['auth_pin_salt'] ?? null; // varbinary(16)

    if (!empty($hash) && !empty($salt)) {
        $calc = hash('sha256', $salt . $pin, true);
        $okPin = hash_equals($hash, $calc);
    } else {
        $code = (string)($emp['auth_pin_code'] ?? '');
        if ($code !== '') $okPin = hash_equals($code, $pin);
    }

    if (!$okPin) {
        jexit(['ok' => false, 'message' => 'PINが違います'], 401);
    }

    // 3) logs
    $logStmt = $pdo->prepare("
        SELECT id, punch_type, punched_at
        FROM time_punches
        WHERE tenant_id = :tenant_id
          AND store_id  = :store_id
          AND employee_id = :employee_id
          AND deleted_at IS NULL
        ORDER BY punched_at DESC, id DESC
        LIMIT {$limit}
    ");
    $logStmt->execute([
        ':tenant_id' => $tenantId,
        ':store_id'  => $storeId,
        ':employee_id' => $employeeId,
    ]);

    $logs = $logStmt->fetchAll();

    // 4) to ISO8601 (+09:00)
    $out = [];
    foreach ($logs as $l) {
        $t = (string)$l['punched_at']; // "YYYY-mm-dd HH:ii:ss"
        $iso = str_replace(' ', 'T', $t) . '+09:00';

        $out[] = [
            'id' => (int)$l['id'],
            'punch_type' => (string)$l['punch_type'],
            'punched_at' => $iso,
        ];
    }

    jexit(['ok' => true, 'logs' => $out]);
} catch (Throwable $e) {
    // ✅ 500でも必ずJSON返す（body空対策）
    jexit(['ok' => false, 'message' => 'server error: ' . $e->getMessage()], 500);
}