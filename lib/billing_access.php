<?php

declare(strict_types=1);

function billing_access_has_table(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function billing_access_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $st->execute([':col' => $column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function billing_access_ensure_status_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tenant_billing_statuses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            billing_month CHAR(7) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tenant_billing_month (tenant_id, billing_month),
            KEY idx_tenant_month (tenant_id, billing_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function billing_access_tenant_timezone(PDO $pdo, int $tenantId): string
{
    if (function_exists('billing_tenant_timezone')) {
        try {
            return billing_tenant_timezone($pdo, $tenantId);
        } catch (Throwable $e) {
        }
    }

    try {
        if (billing_access_has_column($pdo, 'tenants', 'timezone')) {
            $st = $pdo->prepare("SELECT timezone FROM tenants WHERE id = :id LIMIT 1");
            $st->execute([':id' => $tenantId]);
            $tz = (string)($st->fetchColumn() ?: '');
            if ($tz !== '') return $tz;
        }
    } catch (Throwable $e) {
    }

    return 'Asia/Tokyo';
}

function billing_access_current_month(PDO $pdo, int $tenantId): string
{
    $tz = new DateTimeZone(billing_access_tenant_timezone($pdo, $tenantId));
    return (new DateTimeImmutable('now', $tz))->format('Y-m');
}

function billing_access_month_status(PDO $pdo, int $tenantId, ?string $billingMonth = null): ?string
{
    try {
        billing_access_ensure_status_table($pdo);
        $month = $billingMonth ?: billing_access_current_month($pdo, $tenantId);
        $st = $pdo->prepare("
            SELECT status
            FROM tenant_billing_statuses
            WHERE tenant_id = :tenant_id
              AND billing_month = :billing_month
            LIMIT 1
        ");
        $st->execute([
            ':tenant_id' => $tenantId,
            ':billing_month' => $month,
        ]);
        $status = $st->fetchColumn();
        return is_string($status) && $status !== '' ? $status : null;
    } catch (Throwable $e) {
        return null;
    }
}

function billing_access_should_allow(PDO $pdo, int $tenantId): bool
{
    try {
        $manual = billing_access_month_status($pdo, $tenantId);
        if ($manual === 'paid') return true;
        if ($manual === 'unpaid' || $manual === 'stopped') return false;

        $select = ['billing_status', 'stripe_subscription_status', 'stripe_subscription_id'];
        $hasTrialEndsAt = billing_access_has_column($pdo, 'tenants', 'trial_ends_at');
        if ($hasTrialEndsAt) {
            $select[] = 'trial_ends_at';
        }

        $st = $pdo->prepare("
            SELECT " . implode(', ', $select) . "
            FROM tenants
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $tenantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        if ((string)($row['billing_status'] ?? 'active') !== 'active') {
            return false;
        }

        if (!$hasTrialEndsAt) {
            return true;
        }

        $trialEndsAt = (string)($row['trial_ends_at'] ?? '');
        if ($trialEndsAt === '') {
            return true;
        }

        $tz = new DateTimeZone(billing_access_tenant_timezone($pdo, $tenantId));
        $trialEnd = new DateTimeImmutable($trialEndsAt, $tz);
        $now = new DateTimeImmutable('now', $tz);
        if ($trialEnd > $now) {
            return true;
        }

        $status = strtolower((string)($row['stripe_subscription_status'] ?? ''));
        $subId = (string)($row['stripe_subscription_id'] ?? '');
        $paidStatuses = ['active', 'trialing', 'past_due', 'unpaid'];
        return $subId !== '' && in_array($status, $paidStatuses, true);
    } catch (Throwable $e) {
        return true;
    }
}

function billing_access_sync_tenant_status(PDO $pdo, int $tenantId, string $billingMonth, string $status): void
{
    if ($billingMonth !== billing_access_current_month($pdo, $tenantId)) {
        return;
    }

    $tenantBillingStatus = ($status === 'paid') ? 'active' : 'inactive';
    $st = $pdo->prepare("
        UPDATE tenants
        SET billing_status = :billing_status
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([
        ':billing_status' => $tenantBillingStatus,
        ':id' => $tenantId,
    ]);
}
