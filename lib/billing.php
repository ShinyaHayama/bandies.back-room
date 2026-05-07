<?php
declare(strict_types=1);

function billing_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :col");
    $stmt->execute([':col' => $column]);
    return (bool)$stmt->fetchColumn();
}

function billing_tenant_timezone(PDO $pdo, int $tenantId): string
{
    if (!billing_has_column($pdo, 'tenants', 'timezone')) return 'Asia/Tokyo';
    $st = $pdo->prepare("SELECT timezone FROM tenants WHERE id=:id LIMIT 1");
    $st->execute([':id' => $tenantId]);
    $tz = (string)($st->fetchColumn() ?? '');
    return $tz !== '' ? $tz : 'Asia/Tokyo';
}

function billing_parse_dt(?string $val, DateTimeZone $tz): ?DateTimeImmutable
{
    if ($val === null || $val === '') return null;
    try {
        return new DateTimeImmutable($val, $tz);
    } catch (Throwable $e) {
        return null;
    }
}

function billing_employee_count(PDO $pdo, int $tenantId, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): int
{
    $tz = $periodStart->getTimezone();
    $cols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN, 0);
    $colSet = array_flip($cols);

    $hasCreated = isset($colSet['created_at']);
    $hasUpdated = isset($colSet['updated_at']);
    $hasStatus = isset($colSet['employment_status']);
    $hasPinSet = isset($colSet['auth_pin_set_at']);
    $hasPinCode = isset($colSet['auth_pin_code']);
    $hasRetired = isset($colSet['retired_at']);
    $hasTerminated = isset($colSet['terminated_at']);
    $hasDeleted = isset($colSet['deleted_at']);

    $fields = ['id'];
    if ($hasCreated) $fields[] = 'created_at';
    if ($hasUpdated) $fields[] = 'updated_at';
    if ($hasStatus) $fields[] = 'employment_status';
    if ($hasPinSet) $fields[] = 'auth_pin_set_at';
    if ($hasPinCode) $fields[] = 'auth_pin_code';
    if ($hasRetired) $fields[] = 'retired_at';
    if ($hasTerminated) $fields[] = 'terminated_at';
    if ($hasDeleted) $fields[] = 'deleted_at';

    $sql = "SELECT " . implode(', ', $fields) . " FROM employees WHERE tenant_id = :tenant_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tenant_id' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($rows as $r) {
        $pinSetAt = $hasPinSet ? (string)($r['auth_pin_set_at'] ?? '') : '';
        $pinCode = $hasPinCode ? (string)($r['auth_pin_code'] ?? '') : '';
        if ($pinSetAt === '' && $pinCode === '') continue;

        $start = null;
        if ($hasCreated) $start = billing_parse_dt((string)($r['created_at'] ?? ''), $tz);
        if ($start === null && $hasPinSet) $start = billing_parse_dt((string)($r['auth_pin_set_at'] ?? ''), $tz);
        if ($start === null) continue;

        $end = null;
        if ($hasRetired) $end = billing_parse_dt((string)($r['retired_at'] ?? ''), $tz);
        if ($end === null && $hasTerminated) $end = billing_parse_dt((string)($r['terminated_at'] ?? ''), $tz);
        if ($end === null && $hasDeleted) $end = billing_parse_dt((string)($r['deleted_at'] ?? ''), $tz);
        if ($end === null && $hasStatus && (string)($r['employment_status'] ?? '') === 'inactive' && $hasUpdated) {
            $end = billing_parse_dt((string)($r['updated_at'] ?? ''), $tz);
        }

        $overlapStart = $start > $periodStart ? $start : $periodStart;
        $overlapEnd = ($end === null || $end > $periodEnd) ? $periodEnd : $end;
        if ($overlapEnd <= $overlapStart) continue;

        $hours = ($overlapEnd->getTimestamp() - $overlapStart->getTimestamp()) / 3600;
        if ($hours >= 24) $count++;
    }

    return $count;
}
