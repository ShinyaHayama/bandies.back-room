<?php

declare(strict_types=1);

/**
 * ✅ 源泉徴収（最小）計算ユーティリティ
 * - taxable_pay = gross_pay - non_taxable_total
 * - 税額表（tax_withholding_tables/rows）から税額を引く
 * - 社保は今回は原則0（= net_pay = gross - withholding）
 */

function wh_normalize_cycle(string $cycle): string
{
    $cycle = strtolower(trim($cycle));
    if (!in_array($cycle, ['monthly', 'weekly', 'daily'], true)) {
        $cycle = 'monthly';
    }
    return $cycle;
}

function wh_normalize_tax_type(string $taxType): string
{
    $taxType = strtolower(trim($taxType));
    return ($taxType === 'ko') ? 'ko' : 'otsu';
}

function wh_taxable_pay(int $grossPay, int $nonTaxableTotal): int
{
    $v = $grossPay - $nonTaxableTotal;
    return ($v > 0) ? $v : 0;
}

/**
 * 税額表から税額を取得
 * @return int 税額（円）
 */
function wh_lookup_tax_yen(PDO $pdo, string $payCycle, string $taxType, int $taxablePay, string $versionLabel = 'v1', ?string $paymentDate = null): int
{
    $payCycle = wh_normalize_cycle($payCycle);
    $taxType  = wh_normalize_tax_type($taxType);

    // ✅ テーブル種別を特定（支給日で有効期間がある場合に対応）
    $sql = "
        SELECT id
        FROM tax_withholding_tables
        WHERE pay_cycle = :cycle
          AND tax_type  = :type
          AND version_label = :v
          AND (
                :pdate IS NULL
             OR ( (effective_from IS NULL OR effective_from <= :pdate)
              AND (effective_to   IS NULL OR effective_to   >= :pdate) )
          )
        ORDER BY
          CASE WHEN effective_from IS NULL THEN 1 ELSE 0 END,
          effective_from DESC,
          id DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':cycle' => $payCycle,
        ':type'  => $taxType,
        ':v'     => $versionLabel,
        ':pdate' => $paymentDate,
    ]);
    $tableId = (int)($stmt->fetchColumn() ?: 0);
    if ($tableId <= 0) {
        return 0; // ✅ 税額表がまだ未投入なら 0 扱い
    }

    // ✅ 金額帯レンジを検索（lower <= taxable <= upper（NULLは上限なし））
    $sql = "
        SELECT tax_yen
        FROM tax_withholding_rows
        WHERE table_id = :tid
          AND :taxable >= lower_yen
          AND (upper_yen IS NULL OR :taxable <= upper_yen)
        ORDER BY lower_yen DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tid'     => $tableId,
        ':taxable' => $taxablePay,
    ]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * ✅ 確定値を作る（DB保存用）
 * @return array{taxable_pay:int, withholding_tax:int, net_pay:int}
 */
function wh_build_payroll(int $grossPay, int $nonTaxableTotal, int $withholdingTax, int $socialInsurance = 0): array
{
    $taxable = wh_taxable_pay($grossPay, $nonTaxableTotal);

    // 今回の前提：社保なし（0想定）
    $net = $grossPay - $withholdingTax - $socialInsurance;
    if ($net < 0) $net = 0;

    return [
        'taxable_pay'     => $taxable,
        'withholding_tax' => $withholdingTax,
        'net_pay'         => $net,
    ];
}