<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/_db.php';

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    http_response_code(400);
    exit('tenant_id missing');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$row = [];
$trialRestricted = false;
try {
    $st = $pdo->prepare("
        SELECT id, created_at, trial_started_at, trial_ends_at,
               stripe_subscription_id, stripe_subscription_status, billing_status,
               (trial_ends_at IS NOT NULL AND trial_ends_at <= NOW()) AS trial_expired,
               NOW() AS db_now
        FROM tenants WHERE id = :id LIMIT 1
    ");
    $st->execute([':id' => $tenantId]);
    $row = $st->fetch() ?: [];
    if (function_exists('admin_is_trial_restricted')) {
        $trialRestricted = admin_is_trial_restricted($tenantId);
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('db error');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'tenant_id' => $tenantId,
    'row' => $row,
    'trial_restricted' => $trialRestricted,
    'session' => [
        'tenant_id' => $_SESSION['tenant_id'] ?? null,
        'admin_role' => $_SESSION['admin_role'] ?? null,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
