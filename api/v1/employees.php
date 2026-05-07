<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/db.php';

try {
    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    $storeId  = (int)($_GET['store_id'] ?? 0);

    if ($tenantId <= 0 || $storeId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_params']);
        exit;
    }

    $pdo = db();
    $stmt = $pdo->prepare("
    SELECT id, display_name, sort_order
    FROM employees
    WHERE tenant_id = :tenant_id
      AND store_id = :store_id
      AND employment_status = 'active'
    ORDER BY sort_order ASC, id ASC
  ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':store_id' => $storeId,
    ]);

    $rows = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'employees' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}