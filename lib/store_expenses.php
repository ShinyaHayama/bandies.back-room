<?php
declare(strict_types=1);

function store_expenses_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS store_fixed_expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            store_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            amount_yen INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_store_active (tenant_id, store_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS store_monthly_expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            store_id INT NOT NULL,
            expense_month CHAR(7) NOT NULL,
            name VARCHAR(120) NOT NULL,
            amount_yen INT NOT NULL DEFAULT 0,
            memo VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_store_month (tenant_id, store_id, expense_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function store_expenses_valid_month(string $ym): bool
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return false;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ym . '-01');
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m') === $ym;
}

function store_expenses_months_between(string $startYmd, string $endYmd): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startYmd);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endYmd);
    if (!$start || !$end || $start > $end) return [];

    $cur = $start->modify('first day of this month');
    $last = $end->modify('first day of this month');
    $months = [];
    while ($cur <= $last) {
        $months[] = $cur->format('Y-m');
        $cur = $cur->modify('+1 month');
    }
    return $months;
}

function store_expenses_month_overlap_ratio(string $ym, string $startYmd, string $endYmd): float
{
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $ym . '-01');
    $rangeStart = DateTimeImmutable::createFromFormat('!Y-m-d', $startYmd);
    $rangeEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $endYmd);
    if (!$monthStart || !$rangeStart || !$rangeEnd || $rangeStart > $rangeEnd) return 0.0;

    $monthEnd = $monthStart->modify('last day of this month');
    $from = $rangeStart > $monthStart ? $rangeStart : $monthStart;
    $to = $rangeEnd < $monthEnd ? $rangeEnd : $monthEnd;
    if ($from > $to) return 0.0;

    $usedDays = ((int)$from->diff($to)->format('%a')) + 1;
    $monthDays = (int)$monthStart->format('t');
    return $monthDays > 0 ? ($usedDays / $monthDays) : 0.0;
}

function store_expenses_summary(PDO $pdo, int $tenantId, int $storeId, string $startYmd, string $endYmd): array
{
    store_expenses_ensure_schema($pdo);
    $months = store_expenses_months_between($startYmd, $endYmd);
    if (!$months) {
        return [
            'has_settings' => false,
            'fixed_total' => 0,
            'monthly_total' => 0,
            'total' => 0,
            'fixed_items' => [],
            'monthly_items' => [],
        ];
    }

    $fixedRowsStmt = $pdo->prepare("
        SELECT id, name, amount_yen
        FROM store_fixed_expenses
        WHERE tenant_id = :t
          AND store_id = :s
          AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $fixedRowsStmt->execute([':t' => $tenantId, ':s' => $storeId]);
    $fixedRows = $fixedRowsStmt->fetchAll(PDO::FETCH_ASSOC);
    $fixedMonthly = array_sum(array_map(fn($r) => (int)$r['amount_yen'], $fixedRows));

    $anyFixedStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM store_fixed_expenses
        WHERE tenant_id = :t AND store_id = :s
    ");
    $anyFixedStmt->execute([':t' => $tenantId, ':s' => $storeId]);
    $hasFixedRows = ((int)$anyFixedStmt->fetchColumn() > 0);

    $monthlyByMonth = [];
    $monthlyRows = [];
    $placeholders = implode(',', array_fill(0, count($months), '?'));
    $monthlyStmt = $pdo->prepare("
        SELECT expense_month, name, amount_yen, memo
        FROM store_monthly_expenses
        WHERE tenant_id = ?
          AND store_id = ?
          AND expense_month IN ({$placeholders})
        ORDER BY expense_month ASC, id ASC
    ");
    $monthlyStmt->execute(array_merge([$tenantId, $storeId], $months));
    foreach ($monthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ym = (string)$row['expense_month'];
        $monthlyByMonth[$ym] = (int)($monthlyByMonth[$ym] ?? 0) + (int)$row['amount_yen'];
        $monthlyRows[] = $row;
    }

    $anyMonthlyStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM store_monthly_expenses
        WHERE tenant_id = :t AND store_id = :s
    ");
    $anyMonthlyStmt->execute([':t' => $tenantId, ':s' => $storeId]);
    $hasMonthlyRows = ((int)$anyMonthlyStmt->fetchColumn() > 0);

    $fixedTotal = 0;
    $monthlyTotal = 0;
    $fixedItems = [];
    $monthlyItems = [];
    foreach ($months as $ym) {
        $ratio = store_expenses_month_overlap_ratio($ym, $startYmd, $endYmd);
        $fixedTotal += (int)round($fixedMonthly * $ratio);
        $monthlyTotal += (int)round(((int)($monthlyByMonth[$ym] ?? 0)) * $ratio);

        foreach ($fixedRows as $row) {
            $amount = (int)$row['amount_yen'];
            $allocated = (int)round($amount * $ratio);
            if ($allocated <= 0 && $amount <= 0) continue;
            $fixedItems[] = [
                'month' => $ym,
                'name' => (string)$row['name'],
                'monthly_amount' => $amount,
                'allocated_amount' => $allocated,
                'ratio' => $ratio,
            ];
        }
    }

    foreach ($monthlyRows as $row) {
        $ym = (string)$row['expense_month'];
        $ratio = store_expenses_month_overlap_ratio($ym, $startYmd, $endYmd);
        $amount = (int)$row['amount_yen'];
        $allocated = (int)round($amount * $ratio);
        if ($allocated <= 0 && $amount <= 0) continue;
        $monthlyItems[] = [
            'month' => $ym,
            'name' => (string)$row['name'],
            'amount' => $amount,
            'allocated_amount' => $allocated,
            'memo' => (string)($row['memo'] ?? ''),
            'ratio' => $ratio,
        ];
    }

    return [
        'has_settings' => ($hasFixedRows || $hasMonthlyRows),
        'fixed_total' => $fixedTotal,
        'monthly_total' => $monthlyTotal,
        'total' => $fixedTotal + $monthlyTotal,
        'fixed_items' => $fixedItems,
        'monthly_items' => $monthlyItems,
    ];
}
