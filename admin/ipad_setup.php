<?php

declare(strict_types=1);

/**
 * ✅ /admin/ipad_setup.php（新規）
 * 目的:
 * - 既存の /admin/device_activation_qr.php を「tenant_id/store_id 自動付与」で開く中継
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

// 現在選択中の store_id を優先（URL > 既存変数 > セッション > 1）
$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0 && isset($storeIdFromContext)) $storeId = (int)$storeIdFromContext;
if ($storeId <= 0 && isset($storeId)) $storeId = (int)$storeId;
if ($storeId <= 0) $storeId = (int)($_SESSION['store_id'] ?? 1);

header('Location: /admin/device_activation_qr.php?tenant_id=' . rawurlencode((string)$tenantId) . '&store_id=' . rawurlencode((string)$storeId));
exit;