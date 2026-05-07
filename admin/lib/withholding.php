<?php

declare(strict_types=1);

/**
 * ✅ 源泉徴収「確定保存」ユーティリティ（最小）
 *
 * 目的:
 * - PDF出力の直前に「pay_slips」へ taxable_pay / withholding_tax / net_pay を保存する
 * - PDFは「保存済みの値」を印字するだけ（再計算しない）
 *
 * 前提:
 * - employees.tax_type（ko/otsu）
 * - pay_slips.pay_cycle（monthly/weekly/daily）
 * - tax_withholding_tables / tax_withholding_rows
 *
 * ✅ 重要:
 * - tax_overridden=1 の場合は一切変更しない（手動上書きを尊重）
 * - #1267（照合順序混在）回避のため、比較は COLLATE を明示する
 */

/**
 * 源泉計算して pay_slips に保存する（PDF出力の直前に呼ぶ）
 *
 * @param PDO    $pdo
 * @param int    $slipId
 * @param string $versionLabel 例: 'v1'
 * @return array{ok:bool, slip_id:int, taxable_pay:int, withholding_tax:int, net_pay:int, info?:string}
 */
function finalize_withholding_for_slip(PDO $pdo, int $slipId, string $versionLabel = 'v1'): array
{
    if ($slipId <= 0) {
        return ['ok' => false, 'slip_id' => $slipId, 'taxable_pay' => 0, 'withholding_tax' => 0, 'net_pay' => 0, 'info' => 'invalid slip id'];
    }

    // ===== 1) まず pay_slips を取得 =====
    $stmt = $pdo->prepare("
        SELECT
            ps.id,
            ps.employee_id,
            ps.pay_cycle,
            ps.gross_pay,
            ps.non_taxable_total,
            ps.tax_overridden
        FROM pay_slips ps
        WHERE ps.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $slipId]);
    $ps = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ps) {
        return ['ok' => false, 'slip_id' => $slipId, 'taxable_pay' => 0, 'withholding_tax' => 0, 'net_pay' => 0, 'info' => 'pay_slips not found'];
    }

    // tax_overridden=1 は絶対に触らない
    if ((int)($ps['tax_overridden'] ?? 0) === 1) {
        return [
            'ok' => true,
            'slip_id' => (int)$ps['id'],
            'taxable_pay' => (int)max(((int)$ps['gross_pay']) - ((int)$ps['non_taxable_total']), 0),
            'withholding_tax' => 0,
            'net_pay' => 0,
            'info' => 'SKIP: tax_overridden=1',
        ];
    }

    $employeeId = (int)$ps['employee_id'];
    $payCycle   = (string)($ps['pay_cycle'] ?? 'monthly');

    $gross = (int)($ps['gross_pay'] ?? 0);
    $nonTax = (int)($ps['non_taxable_total'] ?? 0);

    // 課税対象
    $taxable = $gross - $nonTax;
    if ($taxable < 0) $taxable = 0;

    // ===== 2) employees.tax_type を取得 =====
    $stmt = $pdo->prepare("SELECT tax_type FROM employees WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);

    $taxType = (string)($emp['tax_type'] ?? 'otsu'); // 未設定は otsu 扱い

    // ===== 3) 税額表から税額を引く =====
    // #1267 回避: 文字列比較に COLLATE を明示（DB内で general/unicode が混在しても落ちにくくする）
    $stmt = $pdo->prepare("
        SELECT
            tr.tax_yen
        FROM tax_withholding_tables tt
        JOIN tax_withholding_rows tr ON tr.table_id = tt.id
        WHERE
              (tt.pay_cycle COLLATE utf8mb4_unicode_ci) = (:pay_cycle COLLATE utf8mb4_unicode_ci)
          AND (tt.tax_type  COLLATE utf8mb4_unicode_ci) = (:tax_type  COLLATE utf8mb4_unicode_ci)
          AND (tt.version_label COLLATE utf8mb4_unicode_ci) = (:version_label COLLATE utf8mb4_unicode_ci)
          AND :taxable >= tr.lower_yen
          AND (tr.upper_yen IS NULL OR :taxable <= tr.upper_yen)
        ORDER BY tr.lower_yen DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':pay_cycle' => $payCycle,
        ':tax_type' => $taxType,
        ':version_label' => $versionLabel,
        ':taxable' => $taxable,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $withholding = (int)($row['tax_yen'] ?? 0);

    // 差引支給（社保は今回 0 想定）
    $net = $gross - $withholding;
    if ($net < 0) $net = 0;

    // ===== 4) pay_slips に確定保存 =====
    $stmt = $pdo->prepare("
        UPDATE pay_slips
        SET
            taxable_pay     = :taxable_pay,
            withholding_tax = :withholding_tax,
            net_pay         = :net_pay
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':taxable_pay' => $taxable,
        ':withholding_tax' => $withholding,
        ':net_pay' => $net,
        ':id' => $slipId,
    ]);

    return [
        'ok' => true,
        'slip_id' => $slipId,
        'taxable_pay' => $taxable,
        'withholding_tax' => $withholding,
        'net_pay' => $net,
    ];
}