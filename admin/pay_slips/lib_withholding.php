<?php

declare(strict_types=1);

/**
 * ✅ 源泉徴収（最小）ユーティリティ
 *
 * 仕様（あなたの前提どおり）：
 * - アルバイト社保なし → 社保控除は原則 0
 * - 交通費などの非課税は課税対象から除外
 * - PDF出力前に pay_slips に「確定値」を保存し、PDFは保存値を印字するだけ
 *
 * このファイルは “計算と確定保存” だけ担当。
 */

require_once __DIR__ . '/../lib/social_insurance.php';

function wh_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[] = (string)$r['Field'];
        }
        $cache[$table] = $cols;
        return $cols;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }
}

function wh_ensure_pay_slip_insurance_columns(PDO $pdo): void
{
    $cols = wh_table_columns($pdo, 'pay_slips');
    $changes = [];
    if (!in_array('health_insurance_yen', $cols, true)) {
        $changes[] = "ADD COLUMN health_insurance_yen INT NOT NULL DEFAULT 0";
    }
    if (!in_array('care_insurance_yen', $cols, true)) {
        $changes[] = "ADD COLUMN care_insurance_yen INT NOT NULL DEFAULT 0";
    }
    if (!in_array('pension_yen', $cols, true)) {
        $changes[] = "ADD COLUMN pension_yen INT NOT NULL DEFAULT 0";
    }
    if (!in_array('employment_insurance_yen', $cols, true)) {
        $changes[] = "ADD COLUMN employment_insurance_yen INT NOT NULL DEFAULT 0";
    }
    if (!in_array('childcare_support_yen', $cols, true)) {
        $changes[] = "ADD COLUMN childcare_support_yen INT NOT NULL DEFAULT 0";
    }
    if (!in_array('health_ins_rate', $cols, true)) {
        $changes[] = "ADD COLUMN health_ins_rate DECIMAL(6,3) NOT NULL DEFAULT 0";
    }
    if (!in_array('care_ins_rate', $cols, true)) {
        $changes[] = "ADD COLUMN care_ins_rate DECIMAL(6,3) NOT NULL DEFAULT 0";
    }
    if (!in_array('pension_rate', $cols, true)) {
        $changes[] = "ADD COLUMN pension_rate DECIMAL(6,3) NOT NULL DEFAULT 0";
    }
    if (!in_array('employment_ins_rate', $cols, true)) {
        $changes[] = "ADD COLUMN employment_ins_rate DECIMAL(6,3) NOT NULL DEFAULT 0";
    }
    if (!in_array('childcare_support_rate', $cols, true)) {
        $changes[] = "ADD COLUMN childcare_support_rate DECIMAL(7,4) NOT NULL DEFAULT 0";
    }
    if (!in_array('insurance_rounding', $cols, true)) {
        $changes[] = "ADD COLUMN insurance_rounding VARCHAR(10) NOT NULL DEFAULT 'floor'";
    }
    if (!in_array('night_premium_yen', $cols, true)) {
        $changes[] = "ADD COLUMN night_premium_yen INT NOT NULL DEFAULT 0";
    }
    if ($changes) {
        $pdo->exec("ALTER TABLE pay_slips " . implode(', ', $changes));
    }
}

/**
 * pay_slips を確定保存する（PDF出力前に必ず呼ぶ）
 *
 * @return array{pay_slip_id:int, pay_cycle:string, tax_type:string, gross_pay:int, non_taxable_total:int, taxable_pay:int, withholding_tax:int, net_pay:int, tax_overridden:int}
 */
function wh_finalize_pay_slip(PDO $pdo, int $paySlipId, string $versionLabel = 'v1'): array
{
    // ✅ pay_slips を取得
    $slip = wh_get_pay_slip($pdo, $paySlipId);

    // ✅ employee を取得（tax_type を見る）
    $employeeId = (int)($slip['employee_id'] ?? 0);
    if ($employeeId <= 0) {
        throw new RuntimeException('pay_slips.employee_id missing');
    }
    $emp = wh_get_employee($pdo, $employeeId);

    // ✅ 支給サイクル（未設定なら monthly）
    $payCycle = (string)($slip['pay_cycle'] ?? '');
    if ($payCycle === '') $payCycle = 'monthly';
    if (!in_array($payCycle, ['monthly', 'weekly', 'daily'], true)) {
        $payCycle = 'monthly';
    }

    // ✅ 甲/乙（未設定なら otsu）
    $taxType = (string)($emp['tax_type'] ?? 'otsu');
    if (!in_array($taxType, ['ko', 'otsu'], true)) {
        $taxType = 'otsu';
    }

    // ✅ 金額（NULLを0扱い）
    $grossPay = (int)($slip['gross_pay'] ?? 0);
    $nonTaxableTotal = (int)($slip['non_taxable_total'] ?? 0);
    if ($grossPay < 0) $grossPay = 0;
    if ($nonTaxableTotal < 0) $nonTaxableTotal = 0;

    // ✅ 課税対象 = 総支給 - 非課税合計（マイナスなら0）
    $taxablePay = $grossPay - $nonTaxableTotal;
    if ($taxablePay < 0) $taxablePay = 0;

    // ✅ 手動上書きフラグ
    $taxOverridden = (int)($slip['tax_overridden'] ?? 0);

    // ✅ 源泉税額（上書きでない場合のみ税額表から引く）
    if ($taxOverridden === 1) {
        $withholdingTax = (int)($slip['withholding_tax'] ?? 0);
        if ($withholdingTax < 0) $withholdingTax = 0;
    } else {
        $withholdingTax = wh_lookup_withholding_tax_yen($pdo, $payCycle, $taxType, $taxablePay, $versionLabel);
        if ($withholdingTax < 0) $withholdingTax = 0;
    }

    // ===== 社会保険（Phase1）=====
    wh_ensure_pay_slip_insurance_columns($pdo);
    $ins = [
        'health' => 0,
        'care' => 0,
        'pension' => 0,
        'employment' => 0,
        'childcare_support' => 0,
        'total' => 0,
        'rounding' => 'floor',
        'health_rate' => 0.0,
        'care_rate' => 0.0,
        'pension_rate' => 0.0,
        'employment_rate' => 0.0,
        'childcare_support_rate' => 0.0,
    ];
    $periodEndYmd = date('Y-m-d');
    $payPeriodId = (int)($slip['pay_period_id'] ?? 0);
    $storeId = 0;
    if ($payPeriodId > 0) {
        $ppCols = wh_table_columns($pdo, 'pay_periods');
        if ($ppCols) {
            $selectStore = in_array('store_id', $ppCols, true) ? 'store_id' : '0 AS store_id';
            $selectTo = in_array('to_date', $ppCols, true) ? 'to_date' : (in_array('end_date', $ppCols, true) ? 'end_date' : 'NULL AS to_date');
            $pp = $pdo->prepare("SELECT {$selectStore}, {$selectTo} FROM pay_periods WHERE id = :id LIMIT 1");
            $pp->execute([':id' => $payPeriodId]);
            $ppRow = $pp->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($ppRow) {
                $storeId = (int)($ppRow['store_id'] ?? 0);
                $periodEndYmd = (string)($ppRow['to_date'] ?? $ppRow['end_date'] ?? $periodEndYmd);
            }
        }
    }
    if ($storeId > 0) {
        $stCols = wh_table_columns($pdo, 'stores');
        $selectHealth = in_array('health_ins_rate', $stCols, true) ? 'health_ins_rate' : '0 AS health_ins_rate';
        $selectCare = in_array('care_ins_rate', $stCols, true) ? 'care_ins_rate' : '0 AS care_ins_rate';
        $selectPension = in_array('pension_rate', $stCols, true) ? 'pension_rate' : '0 AS pension_rate';
        $selectEmployment = in_array('employment_ins_rate', $stCols, true) ? 'employment_ins_rate' : '0 AS employment_ins_rate';
        $selectChildcare = in_array('childcare_support_rate', $stCols, true) ? 'childcare_support_rate' : '0 AS childcare_support_rate';
        $selectRounding = in_array('insurance_rounding', $stCols, true) ? "COALESCE(insurance_rounding,'floor') AS insurance_rounding" : "'floor' AS insurance_rounding";
        $selectPrefecture = in_array('prefecture_code', $stCols, true) ? 'prefecture_code' : 'NULL AS prefecture_code';
        $selectBusinessType = in_array('employment_insurance_business_type', $stCols, true) ? "COALESCE(employment_insurance_business_type,'general') AS employment_insurance_business_type" : "'general' AS employment_insurance_business_type";
        $st = $pdo->prepare("
            SELECT tenant_id, {$selectHealth}, {$selectCare}, {$selectPension}, {$selectEmployment}, {$selectChildcare}, {$selectPrefecture}, {$selectBusinessType}, {$selectRounding}
            FROM stores
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $storeId]);
        $store = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($store) {
            $ins = si_calc($emp, $store, $taxablePay, $periodEndYmd, $pdo);
        }
    }

    // ✅ 差引支給 = 総支給 - 源泉税 - 社保
    $netPay = $grossPay - $withholdingTax - (int)$ins['total'];
    if ($netPay < 0) $netPay = 0;

    // ✅ DBへ確定保存（PDFは必ずこの保存値を使う）
    $sql = "UPDATE pay_slips SET
              pay_cycle         = :pay_cycle,
              taxable_pay       = :taxable_pay,
              withholding_tax   = :withholding_tax,
              health_insurance_yen = :health,
              care_insurance_yen = :care,
              pension_yen = :pension,
              employment_insurance_yen = :employment,
              childcare_support_yen = :childcare_support,
              health_ins_rate = :health_rate,
              care_ins_rate = :care_rate,
              pension_rate = :pension_rate,
              employment_ins_rate = :employment_rate,
              childcare_support_rate = :childcare_support_rate,
              insurance_rounding = :insurance_rounding,
              net_pay           = :net_pay
            WHERE id = :id
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':pay_cycle'       => $payCycle,
        ':taxable_pay'     => $taxablePay,
        ':withholding_tax' => $withholdingTax,
        ':health'          => (int)$ins['health'],
        ':care'            => (int)$ins['care'],
        ':pension'         => (int)$ins['pension'],
        ':employment'      => (int)$ins['employment'],
        ':childcare_support' => (int)$ins['childcare_support'],
        ':health_rate'     => (float)$ins['health_rate'],
        ':care_rate'       => (float)$ins['care_rate'],
        ':pension_rate'    => (float)$ins['pension_rate'],
        ':employment_rate' => (float)$ins['employment_rate'],
        ':childcare_support_rate' => (float)$ins['childcare_support_rate'],
        ':insurance_rounding' => (string)$ins['rounding'],
        ':net_pay'         => $netPay,
        ':id'              => $paySlipId,
    ]);

    return [
        'pay_slip_id'       => $paySlipId,
        'pay_cycle'         => $payCycle,
        'tax_type'          => $taxType,
        'gross_pay'         => $grossPay,
        'non_taxable_total' => $nonTaxableTotal,
        'taxable_pay'       => $taxablePay,
        'withholding_tax'   => $withholdingTax,
        'net_pay'           => $netPay,
        'tax_overridden'    => $taxOverridden,
    ];
}

/**
 * pay_slips 1件取得
 * @return array<string,mixed>
 */
function wh_get_pay_slip(PDO $pdo, int $paySlipId): array
{
    $stmt = $pdo->prepare("SELECT * FROM pay_slips WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $paySlipId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('pay_slips not found: id=' . $paySlipId);
    }
    return $row;
}

/**
 * employees 1件取得
 * @return array<string,mixed>
 */
function wh_get_employee(PDO $pdo, int $employeeId): array
{
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $employeeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('employees not found: id=' . $employeeId);
    }
    return $row;
}

/**
 * 税額表から税額（円）を引く
 *
 * 仕様：
 * - tax_withholding_tables で (pay_cycle, tax_type, version_label) を特定
 * - tax_withholding_rows で lower_yen <= taxablePay AND (upper_yen IS NULL OR taxablePay <= upper_yen) の1行を引く
 * - 見つからない場合は 0（= まだ税額表未投入として扱う）
 */
function wh_lookup_withholding_tax_yen(PDO $pdo, string $payCycle, string $taxType, int $taxablePay, string $versionLabel = 'v1'): int
{
    // ✅ table_id
    $stmt = $pdo->prepare("
        SELECT id
        FROM tax_withholding_tables
        WHERE pay_cycle = :pay_cycle
          AND tax_type  = :tax_type
          AND version_label = :ver
        LIMIT 1
    ");
    $stmt->execute([
        ':pay_cycle' => $payCycle,
        ':tax_type'  => $taxType,
        ':ver'       => $versionLabel,
    ]);
    $tableId = (int)($stmt->fetchColumn() ?: 0);

    if ($tableId <= 0) {
        // 種別が無い＝未作成/未投入 → 0
        return 0;
    }

    // ✅ range hit
    $stmt = $pdo->prepare("
        SELECT tax_yen
        FROM tax_withholding_rows
        WHERE table_id = :tid
          AND lower_yen <= :amt
          AND (upper_yen IS NULL OR :amt <= upper_yen)
        ORDER BY lower_yen DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':tid' => $tableId,
        ':amt' => $taxablePay,
    ]);
    $tax = $stmt->fetchColumn();

    return (int)($tax !== false ? $tax : 0);
}
