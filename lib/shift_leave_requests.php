<?php
declare(strict_types=1);

function shift_leave_requests_ensure_schema(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `stores` LIKE 'leave_request_notification_email'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN leave_request_notification_email VARCHAR(255) NULL");
        }
    } catch (Throwable $e) {
        // stores を変更できない環境でも申請テーブル作成は続ける
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shift_leave_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            store_id INT NOT NULL,
            employee_id INT NOT NULL,
            request_date DATE NOT NULL,
            reason TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            token CHAR(64) NOT NULL,
            requested_at DATETIME NOT NULL,
            reviewed_at DATETIME NULL,
            reviewed_by_admin_user_id INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_leave_token (token),
            KEY idx_leave_lookup (tenant_id, store_id, request_date, status),
            KEY idx_leave_employee (tenant_id, store_id, employee_id, request_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function shift_leave_requests_valid_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $date;
}

function shift_leave_requests_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'shimenavi.com');
    return $scheme . '://' . $host;
}

function shift_leave_requests_admin_emails(PDO $pdo, int $tenantId, int $storeId): array
{
    try {
        $emails = [];
        $stmt = $pdo->prepare("
            SELECT email
            FROM tenant_admin_users
            WHERE tenant_id = :t
              AND status = 'active'
              AND email IS NOT NULL
              AND email <> ''
            ORDER BY id ASC
        ");
        $stmt->execute([':t' => $tenantId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = true;
            }
        }
        return array_keys($emails);
    } catch (Throwable $e) {
        return [];
    }
}

function shift_leave_requests_notification_emails(PDO $pdo, int $tenantId, int $storeId): array
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `stores` LIKE 'leave_request_notification_email'");
        $stmt->execute();
        if ($stmt->fetch()) {
            $st = $pdo->prepare("
                SELECT leave_request_notification_email
                FROM stores
                WHERE tenant_id = :t AND id = :s
                LIMIT 1
            ");
            $st->execute([':t' => $tenantId, ':s' => $storeId]);
            $email = trim((string)($st->fetchColumn() ?: ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [$email];
            }
        }
    } catch (Throwable $e) {
    }
    return [];
}

function shift_leave_requests_fetch_by_token(PDO $pdo, string $token): array
{
    $stmt = $pdo->prepare("
        SELECT lr.*,
               e.display_name AS employee_name,
               s.name AS store_name
        FROM shift_leave_requests lr
        LEFT JOIN employees e
          ON e.tenant_id = lr.tenant_id
         AND e.store_id = lr.store_id
         AND e.id = lr.employee_id
        LEFT JOIN stores s
          ON s.tenant_id = lr.tenant_id
         AND s.id = lr.store_id
        WHERE lr.token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
