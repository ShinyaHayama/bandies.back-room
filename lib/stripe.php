<?php
declare(strict_types=1);

require_once __DIR__ . '/billing.php';

function stripe_load_env_once(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    if (class_exists('Dotenv\\Dotenv')) {
        $paths = [
            dirname(__DIR__),
            dirname(__DIR__, 2),
            dirname(__DIR__, 3),
        ];
        foreach ($paths as $path) {
            if (!is_file($path . '/.env')) continue;
            try {
                $dotenv = Dotenv\Dotenv::createUnsafeImmutable($path);
                $dotenv->safeLoad();
                break;
            } catch (Throwable $e) {
            }
        }
    }
}

function stripe_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false && $v !== null && $v !== '') return (string)$v;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    return $default;
}

function stripe_config(): array
{
    stripe_load_env_once();
    return [
        'secret_key' => stripe_env('STRIPE_SECRET_KEY'),
        'publishable_key' => stripe_env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => stripe_env('STRIPE_WEBHOOK_SECRET'),
    ];
}

function stripe_api_request(string $method, string $path, array $params, string &$error = '', ?string $idempotencyKey = null): ?array
{
    $cfg = stripe_config();
    if ($cfg['secret_key'] === '') {
        $error = 'stripe_secret_missing';
        return null;
    }

    $url = 'https://api.stripe.com' . $path;
    $ch = curl_init();
    $headers = [
        'Authorization: Bearer ' . $cfg['secret_key'],
    ];
    if ($idempotencyKey) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    if (strtoupper($method) === 'GET') {
        if ($params) $url .= '?' . http_build_query($params);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $error = 'curl_failed:' . curl_error($ch);
        curl_close($ch);
        return null;
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($status < 200 || $status >= 300) {
        $error = $data['error']['message'] ?? ('stripe_http_' . $status);
        return null;
    }
    return $data;
}

function stripe_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :col");
    $stmt->execute([':col' => $column]);
    return (bool)$stmt->fetchColumn();
}

function stripe_ensure_tenant_fields(PDO $pdo): void
{
    $cols = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN, 0);
    $colSet = array_flip($cols);
    $changes = [];

    if (!isset($colSet['stripe_customer_id'])) $changes[] = "ADD COLUMN stripe_customer_id VARCHAR(255) NULL";
    if (!isset($colSet['stripe_subscription_id'])) $changes[] = "ADD COLUMN stripe_subscription_id VARCHAR(255) NULL";
    if (!isset($colSet['stripe_subscription_status'])) $changes[] = "ADD COLUMN stripe_subscription_status VARCHAR(50) NULL";
    if (!isset($colSet['stripe_subscription_item_id'])) $changes[] = "ADD COLUMN stripe_subscription_item_id VARCHAR(255) NULL";
    if (!isset($colSet['stripe_trial_ends_at'])) $changes[] = "ADD COLUMN stripe_trial_ends_at DATETIME NULL";
    if (!isset($colSet['stripe_current_period_end'])) $changes[] = "ADD COLUMN stripe_current_period_end DATETIME NULL";
    if (!isset($colSet['stripe_checkout_session_id'])) $changes[] = "ADD COLUMN stripe_checkout_session_id VARCHAR(255) NULL";

    if ($changes) {
        $pdo->exec("ALTER TABLE tenants " . implode(', ', $changes));
    }
}

function stripe_ensure_catalog(PDO $pdo, int $baseAmount, int $seatAmount): array
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stripe_catalog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id VARCHAR(255) NOT NULL,
            base_price_id VARCHAR(255) NOT NULL,
            seat_price_id VARCHAR(255) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'jpy',
            base_amount INT NOT NULL,
            seat_amount INT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $row = $pdo->query("SELECT * FROM stripe_catalog ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['base_amount'] === $baseAmount && (int)$row['seat_amount'] === $seatAmount) {
        return $row;
    }

    $error = '';
    $product = stripe_api_request('POST', '/v1/products', [
        'name' => 'SHIMENABI サブスクリプション',
        'metadata[billing_kind]' => 'tenant_seat',
    ], $error);
    if (!$product) throw new RuntimeException('Stripe product create failed: ' . $error);

    $basePrice = stripe_api_request('POST', '/v1/prices', [
        'product' => $product['id'],
        'unit_amount' => $baseAmount,
        'currency' => 'jpy',
        'recurring[interval]' => 'month',
        'nickname' => 'Base fee',
    ], $error);
    if (!$basePrice) throw new RuntimeException('Stripe base price create failed: ' . $error);

    $seatPrice = stripe_api_request('POST', '/v1/prices', [
        'product' => $product['id'],
        'unit_amount' => $seatAmount,
        'currency' => 'jpy',
        'recurring[interval]' => 'month',
        'nickname' => 'Seat fee',
    ], $error);
    if (!$seatPrice) throw new RuntimeException('Stripe seat price create failed: ' . $error);

    $stmt = $pdo->prepare("
        INSERT INTO stripe_catalog (
            product_id, base_price_id, seat_price_id,
            currency, base_amount, seat_amount, created_at, updated_at
        ) VALUES (
            :product_id, :base_price_id, :seat_price_id,
            'jpy', :base_amount, :seat_amount, NOW(), NOW()
        )
    ");
    $stmt->execute([
        ':product_id' => $product['id'],
        ':base_price_id' => $basePrice['id'],
        ':seat_price_id' => $seatPrice['id'],
        ':base_amount' => $baseAmount,
        ':seat_amount' => $seatAmount,
    ]);

    return $pdo->query("SELECT * FROM stripe_catalog ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

function stripe_find_seat_item_id(array $subscription, string $seatPriceId): ?string
{
    $items = $subscription['items']['data'] ?? [];
    foreach ($items as $item) {
        $priceId = $item['price']['id'] ?? '';
        if ($priceId === $seatPriceId) {
            return (string)($item['id'] ?? '');
        }
    }
    return null;
}

function stripe_verify_webhook(string $payload, string $sigHeader, string $secret): bool
{
    if ($secret === '' || $sigHeader === '') return false;
    $parts = explode(',', $sigHeader);
    $timestamp = null;
    $signature = null;
    foreach ($parts as $p) {
        if (str_starts_with($p, 't=')) $timestamp = substr($p, 2);
        if (str_starts_with($p, 'v1=')) $signature = substr($p, 3);
    }
    if ($timestamp === null || $signature === null) return false;
    $signed = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    return hash_equals($expected, $signature);
}

function stripe_update_tenant_subscription(PDO $pdo, int $tenantId, array $subscription, ?string $seatItemId = null): void
{
    $trialEnd = isset($subscription['trial_end']) ? (int)$subscription['trial_end'] : 0;
    $periodEnd = isset($subscription['current_period_end']) ? (int)$subscription['current_period_end'] : 0;
    $status = (string)($subscription['status'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE tenants
           SET stripe_subscription_id = :sub_id,
               stripe_subscription_status = :status,
               stripe_subscription_item_id = COALESCE(:item_id, stripe_subscription_item_id),
               stripe_trial_ends_at = :trial_end,
               stripe_current_period_end = :period_end
         WHERE id = :id
         LIMIT 1
    ");
    $stmt->execute([
        ':sub_id' => (string)($subscription['id'] ?? ''),
        ':status' => $status,
        ':item_id' => $seatItemId,
        ':trial_end' => $trialEnd > 0 ? date('Y-m-d H:i:s', $trialEnd) : null,
        ':period_end' => $periodEnd > 0 ? date('Y-m-d H:i:s', $periodEnd) : null,
        ':id' => $tenantId,
    ]);
}
