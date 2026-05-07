<?php
declare(strict_types=1);

function public_calendar_hours_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS store_public_hours (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            store_id INT NOT NULL,
            weekday TINYINT NOT NULL,
            is_closed TINYINT(1) NOT NULL DEFAULT 0,
            open_time TIME NULL,
            close_time TIME NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_store_weekday (tenant_id, store_id, weekday)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS store_public_hour_overrides (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            store_id INT NOT NULL,
            business_date DATE NOT NULL,
            is_closed TINYINT(1) NOT NULL DEFAULT 0,
            open_time TIME NULL,
            close_time TIME NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_store_date (tenant_id, store_id, business_date),
            KEY idx_store_month (tenant_id, store_id, business_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS store_public_hour_slots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            store_id INT NOT NULL,
            weekday TINYINT NOT NULL,
            slot_no TINYINT NOT NULL,
            open_time TIME NOT NULL,
            close_time TIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_store_weekday_slot (tenant_id, store_id, weekday, slot_no),
            KEY idx_store_weekday (tenant_id, store_id, weekday)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS store_public_hour_override_slots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            store_id INT NOT NULL,
            business_date DATE NOT NULL,
            slot_no TINYINT NOT NULL,
            open_time TIME NOT NULL,
            close_time TIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_store_date_slot (tenant_id, store_id, business_date, slot_no),
            KEY idx_store_date (tenant_id, store_id, business_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function public_calendar_hours_valid_time(string $time): bool
{
    return $time === '' || (bool)preg_match('/^\d{2}:\d{2}$/', $time);
}

function public_calendar_hours_valid_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $date;
}
