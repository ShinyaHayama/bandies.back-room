<?php

declare(strict_types=1);
require_once __DIR__ . '/_auth.php';

/**
 * admin/payslip_generate_pdf.php
 * 給与明細HTMLをPDF化して pay_slips に保存する
 */

require_once __DIR__ . '/../vendor/autoload.php'; // composerのvendor位置に合わせて調整
use Dompdf\Dompdf;
use Dompdf\Options;

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

// db.php 読み込み（あなたの構成に合わせて）
$paths = [
    __DIR__ . '/../lib/db.php',
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if (!$dbFile) {
    http_response_code(500);
    exit("db.php not found");
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function tableColumns(PDO $pdo, string $table): array
{
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols[] = (string)$r['Field'];
    }
    return $cols;
}

function ensurePaySlipNightPremiumColumn(PDO $pdo): void
{
    $cols = tableColumns($pdo, 'pay_slips');
    if (!in_array('night_premium_yen', $cols, true)) {
        $pdo->exec("ALTER TABLE pay_slips ADD COLUMN night_premium_yen INT NOT NULL DEFAULT 0");
    }
}

function hasEmployeeWageHistoriesTable(PDO $pdo): bool
{
    try {
        $db = (string)($pdo->query("SELECT DATABASE()")->fetchColumn() ?: '');
        if ($db === '') return false;
        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'employee_wage_histories'
            LIMIT 1
        ");
        $st->execute([':db' => $db]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function buildDateRangeYmd(string $from, string $to): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) return [];
    if ($from > $to) return [];
    $out = [];
    $cur = strtotime($from . ' 00:00:00');
    $end = strtotime($to . ' 00:00:00');
    if ($cur === false || $end === false) return [];
    while ($cur <= $end) {
        $out[] = date('Y-m-d', $cur);
        $cur = strtotime('+1 day', $cur);
        if ($cur === false) break;
    }
    return $out;
}

function buildDailyHourlyWageMap(PDO $pdo, int $tenantId, int $storeId, int $employeeId, string $fromYmd, string $toYmd, int $defaultHourly): array
{
    $days = buildDateRangeYmd($fromYmd, $toYmd);
    if (empty($days)) return [];

    if (!hasEmployeeWageHistoriesTable($pdo)) {
        $map = [];
        foreach ($days as $d) $map[$d] = $defaultHourly;
        return $map;
    }

    try {
        $st = $pdo->prepare("
            SELECT effective_business_day, hourly_wage_yen
            FROM employee_wage_histories
            WHERE tenant_id = :t
              AND store_id  = :s
              AND employee_id = :e
              AND effective_business_day <= :to
            ORDER BY effective_business_day ASC, id ASC
        ");
        $st->execute([
            ':t' => $tenantId,
            ':s' => $storeId,
            ':e' => $employeeId,
            ':to' => $toYmd,
        ]);

        $byDay = [];
        $firstWage = null;
        foreach ($st->fetchAll() as $r) {
            $d = (string)($r['effective_business_day'] ?? '');
            if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
            $w = (int)($r['hourly_wage_yen'] ?? 0);
            if ($firstWage === null) $firstWage = $w;
            $byDay[$d] = $w;
        }

        $map = [];
        $current = ($firstWage !== null) ? $firstWage : $defaultHourly;
        foreach ($days as $d) {
            if (array_key_exists($d, $byDay)) {
                $current = (int)$byDay[$d];
            }
            $map[$d] = $current;
        }
        return $map;
    } catch (Throwable $e) {
        $map = [];
        foreach ($days as $d) $map[$d] = $defaultHourly;
        return $map;
    }
}

function cutoffToSeconds(string $cutoff): int
{
    $cutoff = trim($cutoff);
    if ($cutoff === '') return 0;
    if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $cutoff)) return 0;
    $parts = explode(':', $cutoff);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    $s = (int)($parts[2] ?? 0);
    if ($h < 0 || $h > 23) return 0;
    if ($m < 0 || $m > 59) return 0;
    if ($s < 0 || $s > 59) return 0;
    return $h * 3600 + $m * 60 + $s;
}

function businessDateFromTs(int $ts, int $cutoffSeconds): string
{
    $dayStart = strtotime(date('Y-m-d 00:00:00', $ts));
    if ($dayStart === false) return date('Y-m-d', $ts);
    if ($cutoffSeconds <= 0) return date('Y-m-d', $ts);
    $cutoffTs = (int)$dayStart + $cutoffSeconds;
    if ($ts < $cutoffTs) {
        return date('Y-m-d', strtotime('-1 day', (int)$dayStart));
    }
    return date('Y-m-d', $ts);
}

function ceilToMinutes(int $ts, int $unitMinutes): int
{
    if ($unitMinutes <= 0) return $ts;
    $unit = $unitMinutes * 60;
    return (int)(ceil($ts / $unit) * $unit);
}
function floorToMinutes(int $ts, int $unitMinutes): int
{
    if ($unitMinutes <= 0) return $ts;
    $unit = $unitMinutes * 60;
    return (int)(floor($ts / $unit) * $unit);
}
function roundTsForCalc(int $ts, int $unitMinutes, string $type): int
{
    if ($unitMinutes <= 0) return $ts;
    if ($type === 'clock_out' || $type === 'break_out') return floorToMinutes($ts, $unitMinutes);
    return ceilToMinutes($ts, $unitMinutes);
}

function calcNightSeconds(int $startTs, int $endTs): int
{
    if ($endTs <= $startTs) return 0;
    $total = 0;
    $dayStart = strtotime(date('Y-m-d 00:00:00', $startTs));
    if ($dayStart === false) return 0;
    while ($dayStart < $endTs) {
        $nightStart = $dayStart + 22 * 3600;
        $nightEnd = $dayStart + 24 * 3600 + 5 * 3600;
        $s = max($startTs, $nightStart);
        $e = min($endTs, $nightEnd);
        if ($e > $s) {
            $total += ($e - $s);
        }
        $dayStart += 86400;
    }
    return $total;
}

function buildDailyNetSecondsForEmployee(PDO $pdo, int $tenantId, int $storeId, int $employeeId, string $from, string $to, int $roundUnit, int $cutoffSeconds, bool $nightOnly = false): array
{
    $startDt = date('Y-m-d', strtotime($from . ' -1 day')) . ' 00:00:00';
    $endDt   = date('Y-m-d', strtotime($to . ' +2 day')) . ' 00:00:00';

    $stmt = $pdo->prepare("
        SELECT tp.punch_type, tp.punched_at
        FROM time_punches tp
        WHERE tp.tenant_id = :tid
          AND tp.store_id  = :sid
          AND tp.employee_id = :eid
          AND tp.punched_at >= :start_dt
          AND tp.punched_at <  :end_dt
        ORDER BY tp.punched_at ASC, tp.id ASC
    ");
    $stmt->execute([
        ':tid' => $tenantId,
        ':sid' => $storeId,
        ':eid' => $employeeId,
        ':start_dt' => $startDt,
        ':end_dt'   => $endDt,
    ]);
    $rows = $stmt->fetchAll();

    try {
        $bp = $pdo->prepare("
            SELECT break_start_at, break_end_at
            FROM break_punches
            WHERE tenant_id = :tid
              AND store_id  = :sid
              AND employee_id = :eid
              AND COALESCE(break_end_at, break_start_at) >= :start_dt
              AND break_start_at <  :end_dt
            ORDER BY break_start_at ASC, id ASC
        ");
        $bp->execute([
            ':tid' => $tenantId,
            ':sid' => $storeId,
            ':eid' => $employeeId,
            ':start_dt' => $startDt,
            ':end_dt'   => $endDt,
        ]);
        foreach ($bp->fetchAll() as $r) {
            $bs = (string)($r['break_start_at'] ?? '');
            $be = $r['break_end_at'];
            if ($bs !== '') {
                $rows[] = ['punch_type' => 'break_in', 'punched_at' => $bs];
            }
            if ($be !== null && (string)$be !== '' && (string)$be !== $bs) {
                $rows[] = ['punch_type' => 'break_out', 'punched_at' => (string)$be];
            }
        }
        usort($rows, function ($a, $b) {
            $at = strtotime((string)$a['punched_at']) ?: 0;
            $bt = strtotime((string)$b['punched_at']) ?: 0;
            if ($at !== $bt) return $at <=> $bt;
            return strcmp((string)$a['punch_type'], (string)$b['punch_type']);
        });
    } catch (Throwable $e) {
        // break_punches 無しでも壊さない
    }

    $daily = []; // ymd => ['work'=>sec,'break'=>sec,'night_work'=>sec,'night_break'=>sec]
    $openIn = null;
    $openBreak = null;

    foreach ($rows as $r) {
        $type = (string)$r['punch_type'];
        $tsRaw = strtotime((string)$r['punched_at']);
        if ($tsRaw === false) continue;
        $tsRaw = (int)$tsRaw;

        $day = businessDateFromTs($tsRaw, $cutoffSeconds);
        if (!isset($daily[$day])) $daily[$day] = ['work' => 0, 'break' => 0, 'night_work' => 0, 'night_break' => 0];

        $tsCalc = roundTsForCalc($tsRaw, $roundUnit, $type);

        if ($type === 'clock_in') {
            $openIn = $tsCalc;
            $openBreak = null;
            continue;
        }
        if ($type === 'break_in') {
            if ($openIn !== null) $openBreak = $tsCalc;
            continue;
        }
        if ($type === 'break_out') {
            if ($openIn !== null && $openBreak !== null) {
                $daily[$day]['break'] += max(0, $tsCalc - (int)$openBreak);
                $daily[$day]['night_break'] += calcNightSeconds((int)$openBreak, $tsCalc);
                $openBreak = null;
            }
            continue;
        }
        if ($type === 'clock_out') {
            if ($openIn !== null) {
                $daily[$day]['work'] += max(0, $tsCalc - (int)$openIn);
                $daily[$day]['night_work'] += calcNightSeconds((int)$openIn, $tsCalc);
            }
            $openIn = null;
            $openBreak = null;
            continue;
        }
    }

    $out = [];
    foreach ($daily as $day => $wb) {
        if ($day < $from || $day > $to) continue;
        $work = (int)$wb['work'];
        $break = (int)$wb['break'];
        $nightWork = (int)$wb['night_work'];
        $nightBreak = (int)$wb['night_break'];
        $net = $nightOnly ? max(0, $nightWork - $nightBreak) : max(0, $work - $break);
        $out[$day] = $net;
    }
    ksort($out);
    return $out;
}

$tenantId   = (int)($_GET['tenant_id'] ?? 1);
$storeId    = (int)($_GET['store_id'] ?? 1);
$employeeId = (int)($_GET['employee_id'] ?? 0);
$ym         = (string)($_GET['ym'] ?? date('Y-m'));
$round15    = (int)($_GET['round15'] ?? 0);

if ($employeeId <= 0) {
    http_response_code(400);
    exit("employee_id required");
}
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    http_response_code(400);
    exit("ym invalid");
}

try {
    ensurePaySlipNightPremiumColumn($pdo);
} catch (Throwable $e) {
    // 列追加に失敗してもPDF生成は止めない
}

// ===== 集計に必要なマスタ =====
$storeCols = tableColumns($pdo, 'stores');
$selectRound = in_array('payroll_round_unit_minutes', $storeCols, true) ? "COALESCE(payroll_round_unit_minutes,0) AS round_unit" : "0 AS round_unit";
$selectCutoff = in_array('business_day_cutoff_time', $storeCols, true) ? "COALESCE(business_day_cutoff_time,'00:00:00') AS cutoff" : "'00:00:00' AS cutoff";
$stStore = $pdo->prepare("SELECT {$selectRound}, {$selectCutoff} FROM stores WHERE tenant_id=:tid AND id=:sid LIMIT 1");
$stStore->execute([':tid' => $tenantId, ':sid' => $storeId]);
$store = $stStore->fetch() ?: ['round_unit' => 0, 'cutoff' => '00:00:00'];
$roundUnit = (int)($store['round_unit'] ?? 0);
$cutoffSeconds = cutoffToSeconds((string)($store['cutoff'] ?? '00:00:00'));

$empCols = tableColumns($pdo, 'employees');
$selectNightEnabled = in_array('night_premium_enabled', $empCols, true) ? "COALESCE(night_premium_enabled,0) AS night_premium_enabled" : "0 AS night_premium_enabled";
$selectNightRate = in_array('night_premium_rate_percent', $empCols, true) ? "COALESCE(night_premium_rate_percent,25) AS night_premium_rate_percent" : "25 AS night_premium_rate_percent";
$empSt = $pdo->prepare("
    SELECT hourly_wage_yen, {$selectNightEnabled}, {$selectNightRate}
    FROM employees
    WHERE tenant_id=:tid AND store_id=:sid AND id=:eid
    LIMIT 1
");
$empSt->execute([':tid' => $tenantId, ':sid' => $storeId, ':eid' => $employeeId]);
$emp = $empSt->fetch() ?: ['hourly_wage_yen' => 0, 'night_premium_enabled' => 0, 'night_premium_rate_percent' => 25];
$defaultHourly = (int)($emp['hourly_wage_yen'] ?? 0);
$nightEnabled = ((int)($emp['night_premium_enabled'] ?? 0) === 1);
$nightRate = (int)($emp['night_premium_rate_percent'] ?? 25);
if (!in_array($nightRate, [25, 30, 35, 40, 45, 50], true)) $nightRate = 25;

// 対象期間（ymの1日〜末日）
$periodFrom = $ym . '-01';
$periodTo = date('Y-m-t', strtotime($periodFrom));

// 日別時給（履歴優先）
$hourlyByDay = buildDailyHourlyWageMap($pdo, $tenantId, $storeId, $employeeId, $periodFrom, $periodTo, $defaultHourly);

// 日別実働 & 深夜実働
$dailyNetSec = buildDailyNetSecondsForEmployee($pdo, $tenantId, $storeId, $employeeId, $periodFrom, $periodTo, $roundUnit, $cutoffSeconds, false);
$dailyNightNetSec = buildDailyNetSecondsForEmployee($pdo, $tenantId, $storeId, $employeeId, $periodFrom, $periodTo, $roundUnit, $cutoffSeconds, true);

// 日別 bonus/back
$bonusStmt = $pdo->prepare("
    SELECT business_date, SUM(bonus_yen) AS bonus_yen
    FROM daily_wage_adjustments
    WHERE tenant_id=:tid AND employee_id=:eid
      AND business_date >= :fromd AND business_date <= :tod
    GROUP BY business_date
");
$bonusStmt->execute([':tid' => $tenantId, ':eid' => $employeeId, ':fromd' => $periodFrom, ':tod' => $periodTo]);
$bonusMap = [];
foreach ($bonusStmt->fetchAll() as $r) {
    $bonusMap[(string)$r['business_date']] = (int)($r['bonus_yen'] ?? 0);
}

$backStmt = $pdo->prepare("
    SELECT business_date, SUM(amount_yen) AS cashback_yen
    FROM back_events
    WHERE tenant_id=:tid AND store_id=:sid AND employee_id=:eid
      AND status='confirmed'
      AND business_date >= :fromd AND business_date <= :tod
    GROUP BY business_date
");
$backStmt->execute([':tid' => $tenantId, ':sid' => $storeId, ':eid' => $employeeId, ':fromd' => $periodFrom, ':tod' => $periodTo]);
$backMap = [];
foreach ($backStmt->fetchAll() as $r) {
    $backMap[(string)$r['business_date']] = (int)($r['cashback_yen'] ?? 0);
}

$grossPay = 0;
$nightPremiumYen = 0;
foreach ($dailyNetSec as $day => $sec) {
    $hourly = (int)($hourlyByDay[$day] ?? $defaultHourly);
    $base = (int)round(($sec / 3600) * $hourly);
    $bonus = (int)($bonusMap[$day] ?? 0);
    $back = (int)($backMap[$day] ?? 0);
    $nightSec = (int)($dailyNightNetSec[$day] ?? 0);
    $nightAdd = 0;
    if ($nightEnabled && $nightSec > 0) {
        $nightMinutes = (int)floor($nightSec / 60);
        if ($nightMinutes > 0) {
            $nightAdd = (int)round($nightMinutes * ($hourly / 60.0) * ($nightRate / 100.0));
        }
    }
    $nightPremiumYen += $nightAdd;
    $grossPay += ($base + $nightAdd + $bonus + $back);
}

// 税/社保はこのファイルでは計算しない（0扱い）
$netPay = $grossPay;

// 明細HTMLは既存の表示ページを流用（=同じ内容をPDF化できる）
$payslipUrl = sprintf(
    'https://%s/admin/payslip_view.php?tenant_id=%d&store_id=%d&employee_id=%d&ym=%s&round15=%d',
    $_SERVER['HTTP_HOST'],
    $tenantId,
    $storeId,
    $employeeId,
    urlencode($ym),
    $round15
);

// サーバ内HTTPでHTML取得（allow_url_fopenがOFFならcurlに切替）
$html = @file_get_contents($payslipUrl);
if ($html === false) {
    http_response_code(500);
    exit("Failed to fetch HTML: " . $payslipUrl);
}

// PDF生成
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfBinary = $dompdf->output();

// 保存先（公開直下に置かない方が安全。まずは /admin/pdfs でOK）
$dir = __DIR__ . '/pdfs';
if (!is_dir($dir)) mkdir($dir, 0775, true);

$filename = sprintf('payslip_%d_%s_%s.pdf', $employeeId, $ym, date('Ymd_His'));
$fullpath = $dir . '/' . $filename;

// ファイル保存
file_put_contents($fullpath, $pdfBinary);

// DB保存（pay_slips を使うなら pay_periods を先に作る設計が本筋だが、まずは pdf_path 保存でもOK）
$stmt = $pdo->prepare("
  INSERT INTO pay_slips (tenant_id, employee_id, pay_period_id, total_work_minutes, rounded_work_minutes, gross_pay, night_premium_yen, net_pay, pdf_path, generated_at)
  VALUES (:tenant_id, :employee_id, 1, 0, 0, :gross_pay, :night_premium_yen, :net_pay, :pdf_path, NOW())
");
$stmt->execute([
    ':tenant_id' => $tenantId,
    ':employee_id' => $employeeId,
    ':gross_pay' => $grossPay,
    ':night_premium_yen' => $nightPremiumYen,
    ':net_pay' => $netPay,
    ':pdf_path' => 'admin/pdfs/' . $filename,
]);

// ダウンロードさせる（もしくは生成完了画面にする）
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $pdfBinary;
