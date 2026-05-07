<?php

declare(strict_types=1);

/**
 * Social insurance helper (Phase 1).
 * Rates are expected as percent values (e.g. 9.15 for 9.15%).
 */

function si_table_columns(PDO $pdo, string $table): array
{
    try {
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[] = (string)$r['Field'];
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function si_ensure_schema(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) return;
    $done[$key] = true;

    $storeCols = si_table_columns($pdo, 'stores');
    $changes = [];
    if (!in_array('prefecture_code', $storeCols, true)) {
        $changes[] = "ADD COLUMN prefecture_code CHAR(2) NULL";
    }
    if (!in_array('employment_insurance_business_type', $storeCols, true)) {
        $changes[] = "ADD COLUMN employment_insurance_business_type VARCHAR(20) NOT NULL DEFAULT 'general'";
    }
    if ($changes) {
        $pdo->exec("ALTER TABLE stores " . implode(', ', $changes));
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS insurance_rate_sets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NULL,
            scheme_code VARCHAR(30) NOT NULL,
            scope_type VARCHAR(30) NOT NULL,
            scope_key VARCHAR(30) NULL,
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            employee_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
            employer_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rate_lookup (scheme_code, scope_type, scope_key, effective_from, effective_to),
            KEY idx_tenant_effective (tenant_id, effective_from)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function si_round(float $amount, string $mode): int
{
    if ($amount <= 0) return 0;
    if ($mode === 'ceil') return (int)ceil($amount);
    if ($mode === 'round') return (int)round($amount);
    return (int)floor($amount);
}

function si_normalize_rounding(?string $mode): string
{
    $mode = (string)$mode;
    if (in_array($mode, ['floor', 'round', 'ceil'], true)) return $mode;
    return 'floor';
}

function si_age_at(?string $birthYmd, string $onYmd): ?int
{
    if ($birthYmd === null || trim($birthYmd) === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthYmd)) return null;
    try {
        $birth = new DateTimeImmutable($birthYmd);
        $on = new DateTimeImmutable($onYmd);
    } catch (Throwable $e) {
        return null;
    }
    return (int)$birth->diff($on)->y;
}

function si_normalize_business_type(?string $v): string
{
    $v = strtolower(trim((string)$v));
    if (in_array($v, ['general', 'agri', 'construction'], true)) return $v;
    return 'general';
}

function si_target_month_start(string $ymd): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return substr($ymd, 0, 7) . '-01';
    }
    return date('Y-m-01');
}

/**
 * @return array{id:int, employee_rate:float, employer_rate:float}|null
 */
function si_find_rate_set(PDO $pdo, int $tenantId, string $schemeCode, string $scopeType, ?string $scopeKey, string $targetYmd): ?array
{
    si_ensure_schema($pdo);

    $scopeKey = ($scopeKey === null || trim($scopeKey) === '') ? null : trim($scopeKey);

    $sql = "
        SELECT id, employee_rate, employer_rate
        FROM insurance_rate_sets
        WHERE tenant_id IS NULL
          AND scheme_code = :scheme_code
          AND scope_type = :scope_type
          AND (
                (:scope_key IS NULL AND scope_key IS NULL)
                OR scope_key = :scope_key
              )
          AND effective_from <= :target_ymd
          AND (effective_to IS NULL OR effective_to >= :target_ymd)
        ORDER BY effective_from DESC, id DESC
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $params = [
        ':scheme_code' => $schemeCode,
        ':scope_type' => $scopeType,
        ':scope_key' => $scopeKey,
        ':target_ymd' => $targetYmd,
    ];
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return null;

    return [
        'id' => (int)($row['id'] ?? 0),
        'employee_rate' => (float)($row['employee_rate'] ?? 0),
        'employer_rate' => (float)($row['employer_rate'] ?? 0),
    ];
}

/**
 * @return array{health:int, care:int, pension:int, employment:int, childcare_support:int, total:int, rounding:string, health_rate:float, care_rate:float, pension_rate:float, employment_rate:float, childcare_support_rate:float, health_master_id:int, care_master_id:int, pension_master_id:int, employment_master_id:int, childcare_master_id:int}
 */
function si_calc(array $emp, array $store, int $taxablePay, string $periodEndYmd, ?PDO $pdo = null): array
{
    $std = (int)($emp['standard_monthly_remuneration'] ?? 0);
    $healthEnrolled = ((int)($emp['health_ins_enrolled'] ?? 0) === 1);
    $pensionEnrolled = ((int)($emp['pension_enrolled'] ?? 0) === 1);

    $healthRate = (float)($store['health_ins_rate'] ?? 0.0);
    $careRate = (float)($store['care_ins_rate'] ?? 0.0);
    $pensionRate = (float)($store['pension_rate'] ?? 0.0);
    $employmentRate = (float)($store['employment_ins_rate'] ?? 0.0);
    $childcareRate = (float)($store['childcare_support_rate'] ?? 0.0);

    $rounding = si_normalize_rounding((string)($store['insurance_rounding'] ?? 'floor'));

    $healthMasterId = 0;
    $careMasterId = 0;
    $pensionMasterId = 0;
    $employmentMasterId = 0;
    $childcareMasterId = 0;

    if ($pdo instanceof PDO) {
        $tenantId = (int)($store['tenant_id'] ?? 0);
        $targetMonth = si_target_month_start($periodEndYmd);
        $prefectureCode = trim((string)($store['prefecture_code'] ?? ''));
        $businessType = si_normalize_business_type((string)($store['employment_insurance_business_type'] ?? 'general'));

        $healthSet = $prefectureCode !== '' ? si_find_rate_set($pdo, $tenantId, 'health', 'prefecture', $prefectureCode, $targetMonth) : null;
        $careSet = si_find_rate_set($pdo, $tenantId, 'care', 'national', null, $targetMonth);
        $pensionSet = si_find_rate_set($pdo, $tenantId, 'pension', 'national', null, $targetMonth);
        $employmentSet = si_find_rate_set($pdo, $tenantId, 'employment', 'business_type', $businessType, $targetMonth);
        $childcareSet = si_find_rate_set($pdo, $tenantId, 'childcare', 'national', null, $targetMonth);

        if ($healthSet) {
            $healthRate = (float)$healthSet['employee_rate'];
            $healthMasterId = (int)$healthSet['id'];
        }
        if ($careSet) {
            $careRate = (float)$careSet['employee_rate'];
            $careMasterId = (int)$careSet['id'];
        }
        if ($pensionSet) {
            $pensionRate = (float)$pensionSet['employee_rate'];
            $pensionMasterId = (int)$pensionSet['id'];
        }
        if ($employmentSet) {
            $employmentRate = (float)$employmentSet['employee_rate'];
            $employmentMasterId = (int)$employmentSet['id'];
        }
        if ($childcareSet) {
            $childcareRate = (float)$childcareSet['employee_rate'];
            $childcareMasterId = (int)$childcareSet['id'];
        }
    }

    $health = 0;
    $care = 0;
    $pension = 0;
    $employment = 0;
    $childcareSupport = 0;

    if ($healthEnrolled && $std > 0 && $healthRate > 0) {
        $health = si_round($std * ($healthRate / 100), $rounding);
    }

    $age = si_age_at((string)($emp['birth_date'] ?? ''), $periodEndYmd);
    $careEligible = ($age !== null && $age >= 40 && $age < 65 && $healthEnrolled);
    if ($careEligible && $std > 0 && $careRate > 0) {
        $care = si_round($std * ($careRate / 100), $rounding);
    }

    if ($pensionEnrolled && $std > 0 && $pensionRate > 0) {
        $pension = si_round($std * ($pensionRate / 100), $rounding);
    }

    if ($taxablePay > 0 && $employmentRate > 0) {
        $employment = si_round($taxablePay * ($employmentRate / 100), $rounding);
    }

    if ($healthEnrolled && $std > 0 && $childcareRate > 0 && si_target_month_start($periodEndYmd) >= '2026-04-01') {
        $childcareSupport = si_round($std * ($childcareRate / 100), $rounding);
    }

    $total = $health + $care + $pension + $employment + $childcareSupport;

    return [
        'health' => $health,
        'care' => $care,
        'pension' => $pension,
        'employment' => $employment,
        'childcare_support' => $childcareSupport,
        'total' => $total,
        'rounding' => $rounding,
        'health_rate' => $healthRate,
        'care_rate' => $careRate,
        'pension_rate' => $pensionRate,
        'employment_rate' => $employmentRate,
        'childcare_support_rate' => $childcareRate,
        'health_master_id' => $healthMasterId,
        'care_master_id' => $careMasterId,
        'pension_master_id' => $pensionMasterId,
        'employment_master_id' => $employmentMasterId,
        'childcare_master_id' => $childcareMasterId,
    ];
}
