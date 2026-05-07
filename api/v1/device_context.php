<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/db.php';

function has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $st->execute([':col' => $column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $devKey = trim((string)($_GET['dev_key'] ?? ''));
    if ($devKey === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'dev_key is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = db();

    // ✅ devices から dev_key で引く（あなたのdevices構成に合わせる）
    $stmt = $pdo->prepare("
        SELECT id, tenant_id, store_id, status
        FROM devices
        WHERE dev_key = :dev_key
        LIMIT 1
    ");
    $stmt->execute([':dev_key' => $devKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode([
            'ok' => true,
            'message' => 'not provisioned',
            'tenant_id' => 0,
            'store_id' => 0,
            'device_id' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ✅ 店舗設定（必要ならここで返す。なければ null でOK）
    $hasEnableSlot = has_column($pdo, 'stores', 'enable_slot');
    $selectEnableSlot = $hasEnableSlot ? ", COALESCE(enable_slot, 1) AS enable_slot" : ", 1 AS enable_slot";
    $hasRequireSales = has_column($pdo, 'stores', 'require_sales_on_clockout');
    $selectRequireSales = $hasRequireSales ? ", COALESCE(require_sales_on_clockout, 1) AS require_sales_on_clockout" : ", 1 AS require_sales_on_clockout";

    $storeStmt = $pdo->prepare("
        SELECT id {$selectEnableSlot} {$selectRequireSales}
        FROM stores
        WHERE id = :store_id AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $storeStmt->execute([
        ':store_id' => (int)$row['store_id'],
        ':tenant_id' => (int)$row['tenant_id'],
    ]);
    $storeRow = $storeStmt->fetch(PDO::FETCH_ASSOC);
    $storeExists = (bool)$storeRow;
    $enableSlot = $storeExists ? (int)($storeRow['enable_slot'] ?? 1) : 1;
    $requireSales = $storeExists ? (int)($storeRow['require_sales_on_clockout'] ?? 1) : 1;

    echo json_encode([
        'ok' => true,
        'message' => 'provisioned',
        'tenant_id' => (int)$row['tenant_id'],
        'store_id' => (int)$row['store_id'],
        'device_id' => (int)$row['id'],
        'store_config' => $storeExists ? (object)[
            'enable_slot' => ($enableSlot === 1),
            'require_sales_on_clockout' => ($requireSales === 1),
        ] : null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'server error: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
