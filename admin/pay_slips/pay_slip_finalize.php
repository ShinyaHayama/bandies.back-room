<?php

declare(strict_types=1);

/**
 * ✅ 支給確定（PDF出力前に必ず保存する）
 * - gross_pay / non_taxable_total / taxable_pay / withholding_tax / net_pay を pay_slips に保存
 * - PDFは「保存済みの値」を印字するだけ（再計算しない）
 *
 * 想定：
 * - 既存の認証/DB接続ユーティリティがある前提（例：require_admin_login(), db() など）
 * - pay_slips に id があり、employee_id が紐付いている前提
 */

require_once __DIR__ . '/../_auth.php';
require_admin_login();

require_once __DIR__ . '/../_tenant_context.php'; // $tenantId
$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    http_response_code(403);
    echo 'tenant missing';
    exit;
}

require_once __DIR__ . '/../lib/db.php';
$pdo = db(); // ←あなたの環境の関数名に合わせてください

require_once __DIR__ . '/_withholding_calc.php';

header('Content-Type: application/json; charset=utf-8');

function hasTableColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $db = (string)($pdo->query("SELECT DATABASE()")->fetchColumn() ?: '');
        if ($db === '') return false;
        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c
            LIMIT 1
        ");
        $st->execute([':db' => $db, ':t' => $table, ':c' => $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$slipId = (int)($_POST['pay_slip_id'] ?? 0);
if ($slipId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'pay_slip_id missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) 対象 pay_slip を取得（tenantで絞る）
$stmt = $pdo->prepare("
    SELECT ps.*
    FROM pay_slips ps
    WHERE ps.id = :id
      AND ps.tenant_id = :tid
    LIMIT 1
");
$stmt->execute([':id' => $slipId, ':tid' => $tenantId]);
$ps = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ps) {
    echo json_encode(['ok' => false, 'error' => 'pay_slip not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$employeeId = (int)($ps['employee_id'] ?? 0);
if ($employeeId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'employee_id missing on pay_slip'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2) employee.tax_type を取得（甲/乙）
$stmt = $pdo->prepare("
    SELECT tax_type, tax_manual_override_allowed
    FROM employees
    WHERE id = :eid AND tenant_id = :tid
    LIMIT 1
");
$stmt->execute([':eid' => $employeeId, ':tid' => $tenantId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    echo json_encode(['ok' => false, 'error' => 'employee not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$taxType = wh_normalize_tax_type((string)($emp['tax_type'] ?? 'otsu'));

// 3) 入力（gross / non_taxable / pay_cycle / payment_date）
//    ※ 既存UIの値をPOSTで受ける想定（無ければ pay_slips の既存値を優先）
$grossPay        = (int)($_POST['gross_pay'] ?? ($ps['gross_pay'] ?? 0));
$nonTaxableTotal = (int)($_POST['non_taxable_total'] ?? ($ps['non_taxable_total'] ?? 0));
$payCycle        = (string)($_POST['pay_cycle'] ?? ($ps['pay_cycle'] ?? 'monthly'));
$paymentDate     = (string)($_POST['payment_date'] ?? ($ps['payment_date'] ?? ''));
$paymentDate     = (trim($paymentDate) !== '') ? $paymentDate : null;
$nightPremiumYen = (int)($_POST['night_premium_yen'] ?? ($ps['night_premium_yen'] ?? 0));

$taxablePay = wh_taxable_pay($grossPay, $nonTaxableTotal);

// 4) 税額表から税額を引く（まだ税額表未投入なら 0）
$withholdingTax = wh_lookup_tax_yen($pdo, $payCycle, $taxType, $taxablePay, 'v1', $paymentDate);

// 5) 確定値（保存用）
$calc = wh_build_payroll($grossPay, $nonTaxableTotal, $withholdingTax, 0);

// 6) pay_slips に保存（重要：PDFは保存値を印字する）
$setNight = hasTableColumn($pdo, 'pay_slips', 'night_premium_yen');
$sql = "
    UPDATE pay_slips
    SET
      pay_cycle = :cycle,
      payment_date = :pdate,
      gross_pay = :gross,
      non_taxable_total = :nontax,
      taxable_pay = :taxable,
      withholding_tax = :whtax,
      net_pay = :net,
      tax_overridden = 0,
      tax_override_reason = NULL,
      updated_at = NOW()
";
if ($setNight) {
    $sql .= ", night_premium_yen = :night_premium_yen";
}
$sql .= " WHERE id = :id AND tenant_id = :tid";
$stmt = $pdo->prepare($sql);
$params = [
    ':cycle'   => wh_normalize_cycle($payCycle),
    ':pdate'   => $paymentDate,
    ':gross'   => $grossPay,
    ':nontax'  => $nonTaxableTotal,
    ':taxable' => $calc['taxable_pay'],
    ':whtax'   => $calc['withholding_tax'],
    ':net'     => $calc['net_pay'],
    ':id'      => $slipId,
    ':tid'     => $tenantId,
];
if ($setNight) {
    $params[':night_premium_yen'] = $nightPremiumYen;
}
$stmt->execute($params);

echo json_encode([
    'ok' => true,
    'pay_slip_id' => $slipId,
    'employee_id' => $employeeId,
    'tax_type' => $taxType,
    'pay_cycle' => wh_normalize_cycle($payCycle),
    'payment_date' => $paymentDate,
    'gross_pay' => $grossPay,
    'non_taxable_total' => $nonTaxableTotal,
    'taxable_pay' => $calc['taxable_pay'],
    'withholding_tax' => $calc['withholding_tax'],
    'net_pay' => $calc['net_pay'],
], JSON_UNESCAPED_UNICODE);
