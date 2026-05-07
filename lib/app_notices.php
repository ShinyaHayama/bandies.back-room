<?php

declare(strict_types=1);

function app_notices_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            published_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_status_published (status, published_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_notice_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notice_id INT NOT NULL,
            tenant_admin_user_id INT NOT NULL,
            read_at DATETIME NOT NULL,
            UNIQUE KEY uniq_notice_user (notice_id, tenant_admin_user_id),
            KEY idx_user_read (tenant_admin_user_id, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function app_notices_latest(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min(30, $limit));
    $st = $pdo->prepare("
        SELECT id, title, body, status, published_at, created_at, updated_at
        FROM app_notices
        WHERE status = 'published'
          AND published_at <= NOW()
        ORDER BY published_at DESC, id DESC
        LIMIT {$limit}
    ");
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function app_notices_unread_count(PDO $pdo, int $tenantAdminUserId): int
{
    if ($tenantAdminUserId <= 0) return 0;

    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM app_notices n
        LEFT JOIN app_notice_reads r
          ON r.notice_id = n.id
         AND r.tenant_admin_user_id = :uid
        WHERE n.status = 'published'
          AND n.published_at <= NOW()
          AND r.id IS NULL
    ");
    $st->execute([':uid' => $tenantAdminUserId]);
    return (int)$st->fetchColumn();
}

function app_notices_mark_all_read(PDO $pdo, int $tenantAdminUserId): void
{
    if ($tenantAdminUserId <= 0) return;

    $st = $pdo->prepare("
        INSERT IGNORE INTO app_notice_reads (notice_id, tenant_admin_user_id, read_at)
        SELECT id, :uid, NOW()
        FROM app_notices
        WHERE status = 'published'
          AND published_at <= NOW()
    ");
    $st->execute([':uid' => $tenantAdminUserId]);
}
