<?php

/**
 * ✅ ファイル名: /admin/payslip_simple_view.php
 * ✅ 書き込み場所: 既存のこのファイルを「丸ごと置き換え」
 *
 * ✅ 変更点（デザインは維持 / 表示最適化のみ）
 * - payrollContext が無い場合は「view error: payrollContext missing」を必ず表示して終了（原因特定用）
 * - 「課税支給額」は taxable_pay_yen を最優先で表示（無ければ従来互換で gross）
 * - 「控除合計 / 差引支給額」はPDF側計算値（net_pay_yen 等）を最優先（無ければ従来互換）
 * - フッターに「参照 pay_cycle / table_id / taxable / tax_type」を出す（原因特定用）
 *
 * ✅ 追加（時給履歴のデバッグ表示）
 * - payrollContext に hourly_wage_source / hourly_wage_effective_business_day が入っていればフッターに出す
 *   - 無い場合は表示しない（既存互換・既存を壊さない）
 */

declare(strict_types=1);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * ✅ 数値フォーマット（null/空でも壊れない）
 */
function nfmt($v): string
{
    $i = (int)($v ?? 0);
    return number_format($i);
}

/**
 * ✅ payrollContext が無いときは、PDF生成側（payslip_simple_pdf.php）からの include が不正。
 *    ここで落とすことで「view error: payrollContext missing」の原因が明確になる。
 */
$ctx = $payrollContext ?? null;
if (!is_array($ctx) || !$ctx) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"></head><body>';
    echo '<div style="font-family: sans-serif; font-size: 14px; color:#b00000; font-weight: 800;">view error: payrollContext missing</div>';
    echo '</body></html>';
    exit;
}

$store = $ctx['store'] ?? [];
$emp   = $ctx['employee'] ?? [];

$employeeName = (string)($emp['display_name'] ?? '');
$storeName    = (string)($store['name'] ?? '');

$periodFrom   = (string)($ctx['period_from'] ?? '');
$periodTo     = (string)($ctx['period_to'] ?? '');
$payDateLabel = (string)($ctx['pay_date_label'] ?? ''); // 空のことがある
$periodLabel  = (string)($ctx['period_label'] ?? '');

$hourly = (int)($ctx['hourly_wage_display_yen'] ?? ($ctx['hourly_wage_yen'] ?? 0));

$workHM  = (string)($ctx['work_hm'] ?? '0:00');
$breakHM = (string)($ctx['break_hm'] ?? '0:00');
$netHM   = (string)($ctx['net_hm'] ?? '0:00');

$basePay  = (int)($ctx['base_pay_yen'] ?? 0);
$nightPremium = (int)($ctx['night_premium_yen'] ?? 0);
$bonus    = (int)($ctx['bonus_yen'] ?? 0);
$cashback = (int)($ctx['cashback_yen'] ?? 0);

// ✅ 支給合計は「総支給（gross）」を優先（無ければ従来互換）
$grossPay = (int)($ctx['gross_pay_yen'] ?? ($basePay + $bonus + $cashback));

// ✅ 課税支給額は「PDF側が計算した taxable」を最優先（無ければ従来互換で gross）
$taxablePay = (int)($ctx['taxable_pay_yen'] ?? $grossPay);

// ✅ 源泉所得税（PDF側で計算した値）
$withholding = (int)($ctx['withholding_tax_yen'] ?? 0);

// ✅ 社保（PDF側で計算した値）
$healthIns = (int)($ctx['health_insurance_yen'] ?? 0);
$careIns = (int)($ctx['care_insurance_yen'] ?? 0);
$pension = (int)($ctx['pension_yen'] ?? 0);
$employmentIns = (int)($ctx['employment_insurance_yen'] ?? 0);
$childcareSupport = (int)($ctx['childcare_support_yen'] ?? 0);

// ✅ 控除合計（PDF側の合計を優先）
$deductTotal = (int)($ctx['deduct_total_yen'] ?? ($withholding + $healthIns + $careIns + $pension + $employmentIns + $childcareSupport));

// ✅ 差引支給額（PDF側の net_pay_yen を最優先）
$netPay = (int)($ctx['net_pay_yen'] ?? max(0, $grossPay - $deductTotal));

$genAt     = (string)($ctx['generated_at'] ?? '');
$roundUnit = (int)($ctx['round_unit_minutes'] ?? 0);

// ✅ 調査用（必ず出す）
$usedPayCycle = (string)($ctx['withholding_pay_cycle'] ?? '');
$usedTableId  = (int)($ctx['withholding_table_id'] ?? 0);
$usedTaxType  = (string)($ctx['employee_tax_type'] ?? '');

// ✅ 追加：時給履歴デバッグ（入っていれば出す / 無ければ従来互換）
$wageSource = trim((string)($ctx['hourly_wage_source'] ?? ''));
$wageEffective = trim((string)($ctx['hourly_wage_effective_business_day'] ?? ''));

// 令和表示（令和=西暦-2018）
$ym = substr($periodFrom, 0, 7);
$y = (int)substr($ym, 0, 4);
$m = (int)substr($ym, 5, 2);
$reiwa = $y - 2018;
$eraLabel = ($reiwa > 0) ? ("令和{$reiwa}年{$m}月分") : ($y . "年{$m}月分");

// 打刻調整表示
$roundLabel = ($roundUnit > 0) ? ($roundUnit . '分') : '0分';

// payDateLabel が空の時に空行が出るのを避ける（デザインは維持しつつ、表示だけ最適化）
$payDateRowHtml = '';
if (trim($payDateLabel) !== '') {
    $payDateRowHtml = '<div class="row">' . h($payDateLabel) . '</div>';
}

// ✅ wage debug 行（存在すれば表示）
$wageDebugParts = [];
if ($wageSource !== '') $wageDebugParts[] = 'wage_source=' . h($wageSource);
if ($wageEffective !== '') $wageDebugParts[] = 'wage_effective=' . h($wageEffective);
$wageDebugLine = $wageDebugParts ? (' / ' . implode(' / ', $wageDebugParts)) : '';

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <style>
    @font-face {
        font-family: "NotoSansJP";
        src: url("fonts/NotoSansJP-Regular.ttf") format("truetype");
        font-weight: 400;
        font-style: normal;
    }

    @font-face {
        font-family: "NotoSansJP";
        src: url("fonts/NotoSansJP-Bold.ttf") format("truetype");
        font-weight: 700;
        font-style: normal;
    }

    body {
        font-family: "NotoSansJP", sans-serif;
        font-size: 12px;
        margin: 28px;
        color: #111;
    }

    .topRow {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }

    .nameBox {
        font-size: 14px;
        font-weight: 700;
    }

    .title {
        font-size: 20px;
        font-weight: 800;
        text-align: center;
        margin-top: 8px;
    }

    .infoWrap {
        width: 360px;
        border: 1px solid #111;
        margin-top: 14px;
    }

    .infoWrap .row {
        padding: 10px 12px;
        border-top: 1px solid #bbb;
    }

    .infoWrap .row:first-child {
        border-top: 0;
        font-weight: 700;
        text-align: center;
    }

    .companyRow {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 18px;
    }

    .companyLeft {
        font-size: 14px;
        font-weight: 700;
    }

    .companyRight {
        font-size: 12px;
        color: #333;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
    }

    th,
    td {
        border: 2px solid #0b6b8a;
        padding: 10px;
    }

    th {
        background: #f7fbfd;
        font-weight: 800;
        text-align: center;
    }

    td {
        text-align: center;
        font-weight: 700;
    }

    .sectionHead {
        width: 70px;
        font-weight: 900;
        background: #fff;
    }

    .grayBar {
        background: #e5e5e5;
        font-weight: 900;
    }

    .left {
        text-align: left;
    }

    .foot {
        margin-top: 12px;
        font-size: 12px;
        line-height: 1.6;
    }

    .footSmall {
        font-size: 11px;
        color: #333;
    }
    </style>
</head>

<body>
    <div class="topRow">
        <div class="nameBox">
            氏名 <?= h($employeeName) ?> 様
            <div style="border-top:1px solid #111; width:160px; margin-top:6px;"></div>
        </div>
        <div style="width: 360px;"></div>
    </div>

    <div class="title">給与支払明細書</div>

    <div class="infoWrap">
        <div class="row"><?= h($eraLabel) ?></div>
        <div class="row">期間：<?= h($periodFrom) ?> 〜 <?= h($periodTo) ?></div>
        <div class="row">店舗：<?= h($storeName) ?></div>
        <?= $payDateRowHtml ?>
    </div>

    <div class="companyRow">
        <div class="companyLeft">株式会社 Fader</div>
        <div class="companyRight">今月もご苦労さまでした。</div>
    </div>

    <table>
        <tr>
            <th class="sectionHead" rowspan="3">支給</th>
            <th>基本給</th>
            <th>深夜割増</th>
            <th>各種手当1</th>
            <th>各種手当2</th>
            <th>非課税</th>
            <th colspan="2">支給合計</th>
        </tr>
        <tr>
            <td><?= nfmt($basePay) ?></td>
            <td><?= nfmt($nightPremium) ?></td>
            <td><?= nfmt($bonus) ?></td>
            <td><?= nfmt($cashback) ?></td>
            <td>0</td>
            <td colspan="2"><?= nfmt($grossPay) ?></td>
        </tr>
        <tr>
            <td colspan="6" class="grayBar">課税支給額</td>
            <td><?= nfmt($taxablePay) ?></td>
        </tr>

        <tr>
            <th class="sectionHead" rowspan="2">控除</th>
            <th>健康保険料</th>
            <th>厚生年金</th>
            <th>雇用保険</th>
            <th>所得税</th>
            <th>介護保険</th>
            <th>こども支援金</th>
            <th>控除合計</th>
        </tr>
        <tr>
            <td><?= nfmt($healthIns) ?></td>
            <td><?= nfmt($pension) ?></td>
            <td><?= nfmt($employmentIns) ?></td>
            <td><?= nfmt($withholding) ?></td>
            <td><?= nfmt($careIns) ?></td>
            <td><?= nfmt($childcareSupport) ?></td>
            <td><?= nfmt($deductTotal) ?></td>
        </tr>

        <tr>
            <td colspan="7" class="grayBar">差引支給額</td>
            <td><?= nfmt($netPay) ?></td>
        </tr>

        <tr>
            <th class="sectionHead" rowspan="2">勤怠</th>
            <th>出勤日数</th>
            <th>労働時間</th>
            <th>欠勤日数</th>
            <th>有休日数</th>
            <th>備考</th>
            <th colspan="2">打刻調整</th>
        </tr>
        <tr>
            <td>—</td>
            <td><?= h($netHM) ?></td>
            <td>—</td>
            <td>—</td>
            <td class="left">休憩: <?= h($breakHM) ?></td>
            <td colspan="2"><?= h($roundLabel) ?></td>
        </tr>
    </table>

    <div class="foot">
        時間給内訳 @<?= nfmt($hourly) ?>円（実働 <?= h($netHM) ?>）
        / 休憩合計 <?= h($breakHM) ?>
        / 深夜割増 <?= nfmt($nightPremium) ?>円
        / bonus <?= nfmt($bonus) ?>円
        / back <?= nfmt($cashback) ?>円
        / 課税支給額 <?= nfmt($taxablePay) ?>円
        / 源泉 <?= nfmt($withholding) ?>円
        / 出力日時：<?= h($genAt) ?>
        <div class="footSmall">
            （debug）pay_cycle=<?= h($usedPayCycle) ?> / table_id=<?= h((string)$usedTableId) ?> /
            taxable=<?= h((string)$taxablePay) ?> / tax_type=<?= h($usedTaxType) ?><?= $wageDebugLine ?>
        </div>
    </div>
</body>

</html>
