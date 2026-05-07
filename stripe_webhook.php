<?php
declare(strict_types=1);

require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/lib/stripe.php';

$payload = file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$cfg = stripe_config();

if (!stripe_verify_webhook($payload, $sig, $cfg['webhook_secret'])) {
    http_response_code(400);
    echo 'invalid_signature';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) {
    http_response_code(400);
    echo 'invalid_payload';
    exit;
}

$pdo = db();
stripe_ensure_tenant_fields($pdo);

function stripe_find_tenant(PDO $pdo, ?string $subscriptionId, ?string $customerId, ?int $tenantId): ?array
{
    if ($tenantId) {
        $st = $pdo->prepare("SELECT * FROM tenants WHERE id=:id LIMIT 1");
        $st->execute([':id' => $tenantId]);
        return $st->fetch() ?: null;
    }
    if ($subscriptionId) {
        $st = $pdo->prepare("SELECT * FROM tenants WHERE stripe_subscription_id=:sid LIMIT 1");
        $st->execute([':sid' => $subscriptionId]);
        $row = $st->fetch();
        if ($row) return $row;
    }
    if ($customerId) {
        $st = $pdo->prepare("SELECT * FROM tenants WHERE stripe_customer_id=:cid LIMIT 1");
        $st->execute([':cid' => $customerId]);
        $row = $st->fetch();
        if ($row) return $row;
    }
    return null;
}

function stripe_update_seat_quantity(PDO $pdo, array $tenantRow, array $catalog, int $seatCount, int $periodStart, int $periodEnd): void
{
    $subscriptionId = (string)($tenantRow['stripe_subscription_id'] ?? '');
    if ($subscriptionId === '') return;

    $itemId = (string)($tenantRow['stripe_subscription_item_id'] ?? '');
    if ($itemId === '') {
        $err = '';
        $sub = stripe_api_request('GET', '/v1/subscriptions/' . urlencode($subscriptionId), [], $err);
        if (!$sub) return;
        $itemId = stripe_find_seat_item_id($sub, (string)$catalog['seat_price_id']) ?? '';
        if ($itemId !== '') {
            $stmt = $pdo->prepare("UPDATE tenants SET stripe_subscription_item_id=:iid WHERE id=:id LIMIT 1");
            $stmt->execute([':iid' => $itemId, ':id' => (int)$tenantRow['id']]);
        }
    }

    if ($itemId === '') return;
    $err = '';
    stripe_api_request('POST', '/v1/subscription_items/' . urlencode($itemId), [
        'quantity' => $seatCount,
    ], $err, 'seat_qty_' . $tenantRow['id'] . '_' . $periodStart . '_' . $periodEnd);
}

$type = (string)$event['type'];
$obj = $event['data']['object'] ?? [];

if ($type === 'checkout.session.completed') {
    if (($obj['mode'] ?? '') === 'subscription') {
        $tenantId = isset($obj['client_reference_id']) ? (int)$obj['client_reference_id'] : 0;
        $tenantId = $tenantId > 0 ? $tenantId : (int)($obj['metadata']['tenant_id'] ?? 0);
        $subscriptionId = (string)($obj['subscription'] ?? '');
        $customerId = (string)($obj['customer'] ?? '');

        $tenantRow = stripe_find_tenant($pdo, $subscriptionId, $customerId, $tenantId);
        if ($tenantRow) {
            $stmt = $pdo->prepare("UPDATE tenants SET stripe_customer_id=:cid WHERE id=:id LIMIT 1");
            $stmt->execute([':cid' => $customerId, ':id' => (int)$tenantRow['id']]);

            $err = '';
            $sub = stripe_api_request('GET', '/v1/subscriptions/' . urlencode($subscriptionId), [], $err);
            if ($sub) {
                $catalog = stripe_ensure_catalog($pdo, 3300, 400);
                $seatItemId = stripe_find_seat_item_id($sub, (string)$catalog['seat_price_id']);
                stripe_update_tenant_subscription($pdo, (int)$tenantRow['id'], $sub, $seatItemId);
            }
        }
    }
} elseif (in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
    $subscriptionId = (string)($obj['id'] ?? '');
    $customerId = (string)($obj['customer'] ?? '');
    $tenantId = (int)($obj['metadata']['tenant_id'] ?? 0);
    $tenantRow = stripe_find_tenant($pdo, $subscriptionId, $customerId, $tenantId);
    if ($tenantRow) {
        $catalog = stripe_ensure_catalog($pdo, 3300, 400);
        $seatItemId = stripe_find_seat_item_id($obj, (string)$catalog['seat_price_id']);
        stripe_update_tenant_subscription($pdo, (int)$tenantRow['id'], $obj, $seatItemId);
    }
} elseif ($type === 'invoice.upcoming') {
    $subscriptionId = (string)($obj['subscription'] ?? '');
    $customerId = (string)($obj['customer'] ?? '');
    $tenantRow = stripe_find_tenant($pdo, $subscriptionId, $customerId, null);
    if ($tenantRow) {
        $catalog = stripe_ensure_catalog($pdo, 3300, 400);
        $periodStart = (int)($obj['period_start'] ?? 0);
        $periodEnd = (int)($obj['period_end'] ?? 0);
        if ($periodStart > 0 && $periodEnd > 0) {
            $tz = new DateTimeZone(billing_tenant_timezone($pdo, (int)$tenantRow['id']));
            $start = (new DateTimeImmutable('@' . $periodStart))->setTimezone($tz);
            $end = (new DateTimeImmutable('@' . $periodEnd))->setTimezone($tz);
            $seatCount = billing_employee_count($pdo, (int)$tenantRow['id'], $start, $end);
            stripe_update_seat_quantity($pdo, $tenantRow, $catalog, $seatCount, $periodStart, $periodEnd);
        }
    }
}

http_response_code(200);
echo 'ok';
