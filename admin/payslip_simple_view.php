<?php

/**
 * ✅ ファイル名: /admin/payslip_simple_view.php
 * ✅ 書き込み場所: 既存のこのファイルを「丸ごと置き換え」
 *
 * ✅ 変更点（デザインは維持 / 表示最適化のみ）
 * - payrollContext が無い場合は「view error: payrollContext missing」を必ず表示して終了（原因特定用）
 * - 「課税支給額」は taxable_pay_yen を最優先で表示（無ければ従来互換で gross）
 * - 「控除合計 / 差引支給額」はPDF側計算値（net_pay_yen 等）を最優先（無ければ従来互換）
 * - フッターに「参照 pay_cycle / table_id / taxable / tax_type」を必ず出す（原因特定用）
 *
 * ✅ 追加（2枚目：日別詳細）
 * - ctx['daily_rows'] があれば 2枚目に「日別詳細」を表示
 * - 最大31日で1ページに収まるように、フォント/余白/行高を最適化（既存CSS維持）
 * - daily_rows が空のときは 2枚目を出さない（真っ白ページ防止）
 *
 * ✅ 互換強化（重要）
 * - daily_rows の行が「HTML済み（clock_in_html / base_yen_html 等）」でも
 *   「生値（clock_in / base_yen 等）」でも表示できるようにする
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
 * ✅ HH:MM 表記を安全に
 */
function hmh(string $hm): string
{
    $hm = trim($hm);
    if ($hm === '') return '0:00';
    if (!preg_match('/^\d+:\d{2}$/', $hm)) return '0:00';
    return $hm;
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

$workHM  = hmh((string)($ctx['work_hm'] ?? '0:00'));
$breakHM = hmh((string)($ctx['break_hm'] ?? '0:00'));
$netHM   = hmh((string)($ctx['net_hm'] ?? '0:00'));

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

/* =========================
   ✅ 2枚目（日別詳細）
   ========================= */
$dailyRows = $ctx['daily_rows'] ?? null;
$hasDaily = is_array($dailyRows) && count($dailyRows) > 0;

// ✅ 表示は最大31行（PDF側でもクランプしているが、view側も保険でクランプ）
if ($hasDaily && count($dailyRows) > 31) {
    $dailyRows = array_slice($dailyRows, 0, 31);
}

$dailyNote = (string)($ctx['daily_note'] ?? '');
$dailyClamped = (bool)($ctx['daily_clamped'] ?? false);

/**
 * ✅ 日付を「m/d（曜）」に
 */
function mdw(string $ymd): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return $ymd;
    $ts = strtotime($ymd . ' 00:00:00');
    if ($ts === false) return $ymd;
    $w = (int)date('w', $ts);
    $wd = ['日', '月', '火', '水', '木', '金', '土'];
    $m = (int)date('n', $ts);
    $d = (int)date('j', $ts);
    return sprintf('%d/%d（%s）', $m, $d, $wd[$w] ?? '');
}

/**
 * ✅ daily_rows 互換表示:
 * - *_html があれば「HTMLとしてそのまま出す」（既にescape済み前提）
 * - 無ければ生値を escape して出す
 */
function cellHtml(array $r, string $htmlKey, string $plainKey, string $fallbackPlain = '—'): string
{
    $vHtml = (string)($r[$htmlKey] ?? '');
    if (trim($vHtml) !== '') {
        // ✅ 既に htmlJoinLines 等で escape 済みの前提。ここでは二重エスケープしない。
        return $vHtml;
    }

    $v = (string)($r[$plainKey] ?? '');
    $v = trim($v);
    if ($v === '') $v = $fallbackPlain;
    return h($v);
}

function cellHmHtml(array $r, string $htmlKey, string $plainKey, string $fallback = '0:00'): string
{
    $vHtml = (string)($r[$htmlKey] ?? '');
    if (trim($vHtml) !== '') {
        return $vHtml;
    }
    $v = (string)($r[$plainKey] ?? '');
    $v = hmh($v);
    return h($v);
}

function cellYenHtml(array $r, string $htmlKey, array $plainKeys): string
{
    $vHtml = (string)($r[$htmlKey] ?? '');
    if (trim($vHtml) !== '') {
        return $vHtml;
    }

    // ✅ plainKeys の順で最初に見つかった数値を採用（base_yen / base_pay_yen / day_total_yen 等の互換）
    foreach ($plainKeys as $k) {
        if (array_key_exists($k, $r)) {
            return h(nfmt((int)($r[$k] ?? 0)));
        }
    }
    return h(nfmt(0));
}

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

    /* =========================
       ✅ 2枚目（日別詳細）用
       ========================= */

    .pageBreak {
        page-break-before: always;
    }

    .page2Title {
        font-size: 16px;
        font-weight: 900;
        text-align: center;
        margin-top: 6px;
    }

    .page2Meta {
        margin-top: 10px;
        font-size: 11px;
        color: #333;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
    }

    .dailyTable {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        table-layout: fixed;
    }

    .dailyTable th,
    .dailyTable td {
        border: 1px solid #0b6b8a;
        padding: 4px 6px;
        /* ✅ 行を詰めて31行収める */
        font-size: 9.5px;
        /* ✅ 31行収める */
        line-height: 0.9;
        /* ✅ 31行収める */
        font-weight: 700;
        word-break: break-word;
        vertical-align: top;
    }

    .dailyTable th {
        background: #f7fbfd;
        font-weight: 900;
        vertical-align: middle;
    }

    .right {
        text-align: right;
    }

    .center {
        text-align: center;
    }

    .noteBox {
        margin-top: 8px;
        font-size: 10.5px;
        color: #333;
        line-height: 1.3;
    }
    </style>
</head>

<body>
    <!-- =========================
         1枚目：給与支払明細書（既存デザイン）
         ========================= -->
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
            taxable=<?= h((string)$taxablePay) ?> / tax_type=<?= h($usedTaxType) ?>
        </div>
    </div>

    <!-- =========================
         2枚目：日別詳細（最大31日・1ページ）
         ========================= -->
    <?php if ($hasDaily): ?>
    <div class="pageBreak"></div>

    <div class="page2Title">日別詳細</div>

    <div class="page2Meta">
        <div>
            氏名：<?= h($employeeName) ?>　
            店舗：<?= h($storeName) ?>
        </div>
        <div>
            期間：<?= h($periodFrom) ?> 〜 <?= h($periodTo) ?>
        </div>
    </div>

    <?php if ($dailyClamped && trim($dailyNote) !== ''): ?>
    <div class="noteBox">※ <?= h($dailyNote) ?></div>
    <?php endif; ?>

    <table class="dailyTable">
        <tr>
            <th style="width: 16%;">日付</th>
            <th style="width: 10%;">出勤</th>
            <th style="width: 10%;">退勤</th>
            <th style="width: 12%;">労働</th>
            <th style="width: 12%;">休憩</th>
            <th style="width: 12%;">実働</th>
            <th style="width: 10%;">時給</th>
            <th style="width: 18%;">日額(概算)</th>
        </tr>

        <?php foreach ($dailyRows as $r): ?>
        <?php
                $d = trim((string)($r['date'] ?? $r['business_date'] ?? ''));
                if ($d === '') $d = '—';

                // ✅ HTML済み or 生値、両対応
                $cinHtml  = cellHtml($r, 'clock_in_html',  'clock_in',  '—');
                $coutHtml = cellHtml($r, 'clock_out_html', 'clock_out', '—');

                $workHtml  = cellHmHtml($r, 'work_hm_html',  'work_hm',  '0:00');
                $breakHtml = cellHmHtml($r, 'break_hm_html', 'break_hm', '0:00');
                $netHtml   = cellHmHtml($r, 'net_hm_html',   'net_hm',   '0:00');
                $hourlyHtml = cellYenHtml($r, 'hourly_yen_html', ['hourly_yen']);

                // ✅ 日額：HTML済み or 生値（base_yen/base_pay_yen/day_total_yen 等）
                $yenHtml = cellYenHtml($r, 'base_yen_html', ['base_yen', 'base_pay_yen', 'day_total_yen', 'day_total']);
                ?>
        <tr>
            <td class="left"><?= h(mdw($d)) ?></td>
            <td class="center"><?= $cinHtml ?></td>
            <td class="center"><?= $coutHtml ?></td>
            <td class="center"><?= $workHtml ?></td>
            <td class="center"><?= $breakHtml ?></td>
            <td class="center"><?= $netHtml ?></td>
            <td class="right"><?= $hourlyHtml ?></td>
            <td class="right"><?= $yenHtml ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="noteBox">
        ※ 日額(概算) は「実働 × 時給」の概算です。ボーナス/源泉等の配賦は含みません。<br>
    </div>
    <?php endif; ?>
</body>

</html>
