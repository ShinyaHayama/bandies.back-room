<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/payslip_simple_pdf.php
 * ✅ 書き込み場所: 既存のこのファイルを「丸ごと置き換え」
 *
 * ✅ 目的（既存機能は維持）
 * - 画面で選択した from/to（指定期間）を最優先で給与計算し、PDFを出す
 * - 源泉徴収（所得税）を tax_withholding_tables / tax_withholding_rows から自動計算し、明細に反映
 *
 * ✅ 今回の修正（既存機能は壊さない）
 * - 日別配列(dailyDetails)の「出勤/退勤」を “ペア(in→out)” で紐付ける（既に実装済み）
 *   → 1/3 の退勤(04:03) が 1/4 側に吸われて 1/3 が「—」になる問題を解消
 * - ✅ 時給参照を employee_wage_histories 優先に変更（過去が変わらないように）
 *   - effective_business_day <= 対象営業日の最新を採用（履歴が無い日は employees.hourly_wage_yen にフォールバック）
 *   - テーブルが無い環境でも壊さない
 *
 * ✅ 既存仕様は維持
 * - 打刻調整：clock_in 切り上げ / clock_out 切り捨て（payroll_round_unit_minutes）
 * - break_punches：確定勤務区間（in/outが揃った区間）に重なる分だけ控除
 * - 源泉：employees.withholding_pay_cycle 優先 → 無ければ店舗 payroll_cycle_type
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;
require_once __DIR__ . '/lib/social_insurance.php';

// ★ PDFにPHP警告が混ざると壊れるので表示OFF
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

function tableColumns(PDO $pdo, string $table): array
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

function ensureStoreInsuranceColumns(PDO $pdo): void
{
    $cols = tableColumns($pdo, 'stores');
    $changes = [];
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
    if (!in_array('prefecture_code', $cols, true)) {
        $changes[] = "ADD COLUMN prefecture_code CHAR(2) NULL";
    }
    if (!in_array('employment_insurance_business_type', $cols, true)) {
        $changes[] = "ADD COLUMN employment_insurance_business_type VARCHAR(20) NOT NULL DEFAULT 'general'";
    }
    if ($changes) {
        $pdo->exec("ALTER TABLE stores " . implode(', ', $changes));
    }
}

function ensureEmployeeInsuranceColumns(PDO $pdo): void
{
    $cols = tableColumns($pdo, 'employees');
    $changes = [];
    if (!in_array('standard_monthly_remuneration', $cols, true)) {
        $changes[] = "ADD COLUMN standard_monthly_remuneration INT NOT NULL DEFAULT 0";
    }
    if (!in_array('health_ins_enrolled', $cols, true)) {
        $changes[] = "ADD COLUMN health_ins_enrolled TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!in_array('pension_enrolled', $cols, true)) {
        $changes[] = "ADD COLUMN pension_enrolled TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!in_array('birth_date', $cols, true)) {
        $changes[] = "ADD COLUMN birth_date DATE NULL";
    }
    if ($changes) {
        $pdo->exec("ALTER TABLE employees " . implode(', ', $changes));
    }
}

// ★ warning/notice も例外化（PDF破損防止）
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/* =========================
   DB
   ========================= */
$paths = [
    __DIR__ . '/../../api/lib/db.php',
    __DIR__ . '/../../lib/db.php',
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if ($dbFile === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "db.php not found.\nTried:\n" . implode("\n", $paths);
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* =========================
   dompdf
   ========================= */
$dompdfCandidates = [
    __DIR__ . '/../../dompdf/autoload.inc.php',
    __DIR__ . '/../dompdf/autoload.inc.php',
    __DIR__ . '/dompdf/autoload.inc.php',
];
$autoload = null;
foreach ($dompdfCandidates as $p) {
    if (is_file($p)) {
        $autoload = $p;
        break;
    }
}
if ($autoload === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "dompdf autoload not found.\nTried:\n" . implode("\n", $dompdfCandidates);
    exit;
}
require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

/* =========================
   helpers
   ========================= */

function mustYmd(?string $s): ?string
{
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

function mustYm(?string $s): ?string
{
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;
    return preg_match('/^\d{4}-\d{2}$/', $s) ? $s : null;
}

function lastDayOfMonth(string $ym): int
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01', new DateTimeZone('Asia/Tokyo'));
    if (!$dt) return 31;
    return (int)$dt->format('t');
}

function normalizeCloseDay(int $closeDay, string $ym): int
{
    $t = lastDayOfMonth($ym);
    if ($closeDay >= 31) return $t;
    if ($closeDay < 1) return 1;
    return min($closeDay, $t);
}

function normalizePayDay(int $payDay, string $ym): int
{
    $t = lastDayOfMonth($ym);
    if ($payDay >= 31) return $t;
    if ($payDay < 1) return 1;
    return min($payDay, $t);
}

function weekday0Sun(DateTimeImmutable $dt): int
{
    return (int)$dt->format('w'); // 0..6
}

function recentWeekCloseDate(DateTimeImmutable $base, int $closeWday0Sun): DateTimeImmutable
{
    $w = weekday0Sun($base);
    $diff = ($w - $closeWday0Sun);
    if ($diff < 0) $diff += 7;
    return $base->modify("-{$diff} day");
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

/**
 * ✅ 打刻調整ルール
 * - clock_in : 切り上げ
 * - clock_out: 切り捨て
 * - break は打刻調整ない（実時間）
 */
function roundTsForCalc(int $ts, int $unitMinutes, string $type): int
{
    if ($type === 'break_in' || $type === 'break_out') return $ts;
    if ($unitMinutes <= 0) return $ts;

    if ($type === 'clock_out') return floorToMinutes($ts, $unitMinutes);
    return ceilToMinutes($ts, $unitMinutes);
}

function secToHM(int $sec): string
{
    $m = (int)floor($sec / 60);
    $h = (int)floor($m / 60);
    $mm = $m % 60;
    return sprintf('%d:%02d', $h, $mm);
}

/**
 * ✅ YYYY-mm-dd の連続日配列
 * - なぜ必要か：時給履歴（変更日だけ保存）を日毎へ引き継いで適用するため
 */
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

/** ✅ employee_wage_histories があるか（無い環境でも壊さない） */
function hasEmployeeWageHistoriesTable(PDO $pdo): bool
{
    try {
        $cols = tableColumns($pdo, 'employee_wage_histories');
        $need = ['tenant_id', 'store_id', 'employee_id', 'effective_business_day', 'hourly_wage_yen'];
        foreach ($need as $col) {
            if (!in_array($col, $cols, true)) {
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** ✅ テーブルの列があるか（無い環境でも壊さない） */
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

/**
 * ✅ 日別時給マップ（履歴優先）
 * - ルール：effective_business_day <= 日 の最新履歴を採用
 * - 履歴が無い日は defaultHourly（employees.hourly_wage_yen）へフォールバック
 * - テーブルが無い環境は空配列を返す（呼び出し側でフォールバック）
 */
function buildDailyHourlyWageMap(PDO $pdo, int $tenantId, int $storeId, int $employeeId, string $fromYmd, string $toYmd, int $defaultHourly): array
{
    $days = buildDateRangeYmd($fromYmd, $toYmd);
    if (empty($days)) return [];

    if (!hasEmployeeWageHistoriesTable($pdo)) {
        return [];
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

        // ステップ関数（引き継ぎ）
        $map = [];
        // ✅ 最古履歴を過去に引き継ぐ（過去が新時給で汚染されないようにする）
        $current = ($firstWage !== null) ? $firstWage : $defaultHourly;
        foreach ($days as $d) {
            if (array_key_exists($d, $byDay)) {
                $current = (int)$byDay[$d];
            }
            $map[$d] = $current;
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * ✅ time_punches(clock_in/out) から「確定した勤務区間」を作る
 * - clock_in と clock_out のペアが揃ったものだけ区間化
 * - 退勤が無い出勤中（openInが残る）は区間に入れない
 *
 * @return array<int, array{0:int,1:int}>  [[startTs,endTs], ...]
 */
function buildWorkIntervalsFromPunches(array $punchRows, int $roundUnitMinutes): array
{
    $intervals = [];
    $openIn = null;

    foreach ($punchRows as $r) {
        $type = (string)($r['punch_type'] ?? '');
        $tsRaw = strtotime((string)($r['punched_at'] ?? ''));
        if ($tsRaw === false) continue;

        $tsCalc = roundTsForCalc((int)$tsRaw, $roundUnitMinutes, $type);

        if ($type === 'clock_in') {
            $openIn = $tsCalc;
            continue;
        }
        if ($type === 'clock_out') {
            if ($openIn !== null && $tsCalc > (int)$openIn) {
                $intervals[] = [(int)$openIn, (int)$tsCalc];
            }
            $openIn = null;
            continue;
        }
    }

    return $intervals;
}

function clipIntervals(array $intervals, int $winStartTs, int $winEndTs): array
{
    $out = [];
    foreach ($intervals as $it) {
        $s = (int)($it[0] ?? 0);
        $e = (int)($it[1] ?? 0);
        if ($e <= $s) continue;

        $cs = max($s, $winStartTs);
        $ce = min($e, $winEndTs);
        if ($ce > $cs) $out[] = [$cs, $ce];
    }
    return $out;
}

function sumIntervalsSeconds(array $intervals): int
{
    $sum = 0;
    foreach ($intervals as $it) {
        $s = (int)($it[0] ?? 0);
        $e = (int)($it[1] ?? 0);
        if ($e > $s) $sum += ($e - $s);
    }
    return $sum;
}

function calcBreakSecondsOverIntervals(array $breakRows, array $workIntervals, int $periodStartTs, int $periodEndTs): int
{
    if (!$workIntervals) return 0;

    $sum = 0;

    foreach ($breakRows as $r) {
        $bs = (string)($r['break_start_at'] ?? '');
        $be = (string)($r['break_end_at'] ?? '');

        $bsRaw = $bs !== '' ? strtotime($bs) : false;
        if ($bsRaw === false) continue;

        $beRaw = $be !== '' ? strtotime($be) : false;
        if ($beRaw === false) {
            continue;
        }

        $bsCalc = roundTsForCalc((int)$bsRaw, 0, 'break_in');
        $beCalc = roundTsForCalc((int)$beRaw, 0, 'break_out');

        $bsClip = max($bsCalc, $periodStartTs);
        $beClip = min($beCalc, $periodEndTs);
        if ($beClip <= $bsClip) continue;

        foreach ($workIntervals as $it) {
            $ws = (int)$it[0];
            $we = (int)$it[1];

            $s = max($bsClip, $ws);
            $e = min($beClip, $we);
            if ($e > $s) $sum += ($e - $s);
        }
    }

    return $sum;
}

function buildNightIntervalsFromWorkIntervals(array $workIntervals): array
{
    $out = [];
    foreach ($workIntervals as $it) {
        $s = (int)($it[0] ?? 0);
        $e = (int)($it[1] ?? 0);
        if ($e <= $s) continue;

        $dayStart = strtotime(date('Y-m-d 00:00:00', $s));
        if ($dayStart === false) continue;

        while ($dayStart < $e) {
            $nightStart = $dayStart + 22 * 3600;
            $nightEnd = $dayStart + 24 * 3600 + 5 * 3600;

            $ns = max($s, $nightStart);
            $ne = min($e, $nightEnd);
            if ($ne > $ns) {
                $out[] = [$ns, $ne];
            }

            $dayStart += 86400;
        }
    }
    return $out;
}

function calcWithholdingYen(PDO $pdo, string $payCycle, string $taxType, int $taxableYen, ?string $preferredVersionLabel = null): array
{
    if ($taxableYen <= 0) {
        return ['withholding' => 0, 'used_table_id' => 0, 'used_version' => ''];
    }

    try {
        $pdo->query("SELECT 1 FROM tax_withholding_tables LIMIT 1");
        $pdo->query("SELECT 1 FROM tax_withholding_rows LIMIT 1");
    } catch (Throwable $e) {
        return ['withholding' => 0, 'used_table_id' => 0, 'used_version' => ''];
    }

    $taxType = trim($taxType);
    if ($taxType === '') $taxType = 'ko';

    $candidates = [];
    if ($preferredVersionLabel !== null && trim($preferredVersionLabel) !== '') $candidates[] = trim($preferredVersionLabel);
    $candidates[] = '';

    foreach ($candidates as $v) {
        if ($v !== '') {
            $tSt = $pdo->prepare("
                SELECT id, COALESCE(version_label,'') AS version_label
                FROM tax_withholding_tables
                WHERE pay_cycle = :pc AND tax_type = :tt AND version_label = :v
                ORDER BY id DESC
                LIMIT 1
            ");
            $tSt->execute([':pc' => $payCycle, ':tt' => $taxType, ':v' => $v]);
            $row = $tSt->fetch();
            $tableId = (int)($row['id'] ?? 0);
            $vv = (string)($row['version_label'] ?? '');
            if ($tableId <= 0) continue;

            $rSt = $pdo->prepare("
                SELECT tax_yen
                FROM tax_withholding_rows
                WHERE table_id = :tid
                  AND :x >= lower_yen
                  AND (upper_yen IS NULL OR :x <= upper_yen)
                LIMIT 1
            ");
            $rSt->execute([':tid' => $tableId, ':x' => $taxableYen]);
            $tax = (int)($rSt->fetchColumn() ?: 0);
            return ['withholding' => $tax, 'used_table_id' => $tableId, 'used_version' => $vv];
        }

        $tSt = $pdo->prepare("
            SELECT id, COALESCE(version_label,'') AS version_label
            FROM tax_withholding_tables
            WHERE pay_cycle = :pc AND tax_type = :tt
            ORDER BY id DESC
            LIMIT 1
        ");
        $tSt->execute([':pc' => $payCycle, ':tt' => $taxType]);
        $row = $tSt->fetch();
        $tableId = (int)($row['id'] ?? 0);
        $vv = (string)($row['version_label'] ?? '');
        if ($tableId <= 0) continue;

        $rSt = $pdo->prepare("
            SELECT tax_yen
            FROM tax_withholding_rows
            WHERE table_id = :tid
              AND :x >= lower_yen
              AND (upper_yen IS NULL OR :x <= upper_yen)
            LIMIT 1
        ");
        $rSt->execute([':tid' => $tableId, ':x' => $taxableYen]);
        $tax = (int)($rSt->fetchColumn() ?: 0);
        return ['withholding' => $tax, 'used_table_id' => $tableId, 'used_version' => $vv];
    }

    return ['withholding' => 0, 'used_table_id' => 0, 'used_version' => ''];
}

function cutoffToSeconds(string $hhmmss): int
{
    $hhmmss = trim($hhmmss);
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hhmmss)) return 0;
    [$h, $m, $s] = array_map('intval', explode(':', $hhmmss));
    if ($h < 0 || $h > 23) return 0;
    if ($m < 0 || $m > 59) return 0;
    if ($s < 0 || $s > 59) return 0;
    return $h * 3600 + $m * 60 + $s;
}

function buildBusinessWindow(string $fromYmd, string $toYmd, string $cutoffHms, DateTimeZone $tz): array
{
    $cutSec = cutoffToSeconds($cutoffHms);

    $fromBase = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $fromYmd . ' 00:00:00', $tz);
    $toBase   = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $toYmd   . ' 00:00:00', $tz);
    if (!$fromBase || !$toBase) {
        $fromBase = new DateTimeImmutable($fromYmd . ' 00:00:00', $tz);
        $toBase   = new DateTimeImmutable($toYmd   . ' 00:00:00', $tz);
    }

    $start = $fromBase->modify("+{$cutSec} second");
    $end   = $toBase->modify('+1 day')->modify("+{$cutSec} second")->modify('-1 second');

    return [$start->getTimestamp(), $end->getTimestamp(), $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function listBusinessDates(string $fromYmd, string $toYmd, DateTimeZone $tz): array
{
    $from = DateTimeImmutable::createFromFormat('Y-m-d', $fromYmd, $tz);
    $to   = DateTimeImmutable::createFromFormat('Y-m-d', $toYmd, $tz);
    if (!$from || !$to) return [];

    if ($fromYmd > $toYmd) {
        [$from, $to] = [$to, $from];
    }

    $out = [];
    $cur = $from;
    while ($cur <= $to) {
        $out[] = $cur->format('Y-m-d');
        $cur = $cur->modify('+1 day');
    }
    return $out;
}

function buildOneBusinessDayWindow(string $ymd, string $cutoffHms, DateTimeZone $tz): array
{
    return buildBusinessWindow($ymd, $ymd, $cutoffHms, $tz);
}

function businessDateFromTs(int $ts, int $cutoffSeconds, DateTimeZone $tz): string
{
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->modify("-{$cutoffSeconds} second");
    return $dt->format('Y-m-d');
}

function tsToHi(int $ts, DateTimeZone $tz): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('H:i');
}

/* =========================
   main
   ========================= */
try {
    $storeId    = (int)($_GET['store_id'] ?? 0);
    $employeeId = (int)($_GET['employee_id'] ?? 0);

    if ($storeId <= 0) throw new RuntimeException('store_id が不正です');
    if ($employeeId <= 0) throw new RuntimeException('employee_id が不正です（従業員を選択してください）');

    $fromRaw = mustYmd($_GET['from'] ?? null);
    $toRaw   = mustYmd($_GET['to'] ?? null);
    $ymIn    = mustYm($_GET['ym'] ?? null) ?? date('Y-m');

    // ===== 店舗/従業員設定 =====
    ensureStoreInsuranceColumns($pdo);
    try {
        ensureEmployeeInsuranceColumns($pdo);
    } catch (Throwable $e) {
        // 列追加できない場合は後続のSELECTでフォールバックする
    }
    $st = $pdo->prepare("
        SELECT
            tenant_id, id, name,
            COALESCE(payroll_cycle_type, 'monthly') AS payroll_cycle_type,
            COALESCE(payroll_close_day, 31) AS payroll_close_day,
            COALESCE(payroll_pay_day, 25)   AS payroll_pay_day,
            COALESCE(payroll_week_close_wday, 0) AS payroll_week_close_wday,
            COALESCE(payroll_week_pay_offset_days, 0) AS payroll_week_pay_offset_days,
            COALESCE(payroll_round_unit_minutes, 15) AS payroll_round_unit_minutes,
            COALESCE(business_day_cutoff_time, '00:00:00') AS business_day_cutoff_time,
            COALESCE(health_ins_rate, 0) AS health_ins_rate,
            COALESCE(care_ins_rate, 0) AS care_ins_rate,
            COALESCE(pension_rate, 0) AS pension_rate,
            COALESCE(employment_ins_rate, 0) AS employment_ins_rate,
            COALESCE(childcare_support_rate, 0) AS childcare_support_rate,
            prefecture_code,
            COALESCE(employment_insurance_business_type, 'general') AS employment_insurance_business_type,
            COALESCE(insurance_rounding, 'floor') AS insurance_rounding
        FROM stores
        WHERE tenant_id = :tenant_id AND id = :store_id
        LIMIT 1
    ");
    $st->execute([
        ':tenant_id' => $tenantId,
        ':store_id'  => $storeId,
    ]);
    $store = $st->fetch();
    if (!$store) throw new RuntimeException('店舗が見つかりません（tenant不一致の可能性）');

    $cycleType = (string)$store['payroll_cycle_type'];

    $allowedUnits = [0, 5, 10, 15, 20, 25, 30];
    $roundUnit = (int)($store['payroll_round_unit_minutes'] ?? 15);
    if (!in_array($roundUnit, $allowedUnits, true)) $roundUnit = 15;

    $cutoffHms = (string)($store['business_day_cutoff_time'] ?? '00:00:00');
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $cutoffHms)) $cutoffHms = '00:00:00';
    $cutSec = cutoffToSeconds($cutoffHms);

    // ===== 期間確定 =====
    $periodFrom = '';
    $periodTo   = '';
    $periodLabel = '';
    $payDateLabel = '';

    if ($fromRaw !== null && $toRaw !== null) {
        if ($fromRaw > $toRaw) [$fromRaw, $toRaw] = [$toRaw, $fromRaw];
        $periodFrom = $fromRaw;
        $periodTo   = $toRaw;
        $periodLabel = "指定期間（{$periodFrom} 〜 {$periodTo}）";
        $payDateLabel = '';
    } else {
        if ($cycleType === 'weekly') {
            $base = DateTimeImmutable::createFromFormat('Y-m-d', date('Y-m-d'), new DateTimeZone('Asia/Tokyo'));
            if (!$base) $base = new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));

            $closeWday = (int)$store['payroll_week_close_wday'];
            if ($closeWday < 0 || $closeWday > 6) $closeWday = 0;

            $closeDt = recentWeekCloseDate($base, $closeWday);
            $startDt = $closeDt->modify('-6 day');

            $periodFrom = $startDt->format('Y-m-d');
            $periodTo   = $closeDt->format('Y-m-d');

            $offsetDays = (int)$store['payroll_week_pay_offset_days'];
            if ($offsetDays < 0) $offsetDays = 0;
            if ($offsetDays > 60) $offsetDays = 60;

            $payDt = $closeDt->modify("+{$offsetDays} day");
            $periodLabel = "週払い（{$periodFrom} 〜 {$periodTo}）";
            $payDateLabel = "支払日: " . $payDt->format('Y-m-d');
        } else {
            $ym = $ymIn;

            $closeDay = normalizeCloseDay((int)$store['payroll_close_day'], $ym);
            $closeDt = DateTimeImmutable::createFromFormat(
                'Y-m-d',
                sprintf('%s-%02d', $ym, $closeDay),
                new DateTimeZone('Asia/Tokyo')
            );
            if (!$closeDt) throw new RuntimeException('月払いの締日計算に失敗しました');

            $prevYmDt = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01', new DateTimeZone('Asia/Tokyo'));
            if (!$prevYmDt) throw new RuntimeException('月払いの前月計算に失敗しました');
            $prevYm = $prevYmDt->modify('-1 month')->format('Y-m');

            $prevCloseDay = normalizeCloseDay((int)$store['payroll_close_day'], $prevYm);
            $prevCloseDt = DateTimeImmutable::createFromFormat(
                'Y-m-d',
                sprintf('%s-%02d', $prevYm, $prevCloseDay),
                new DateTimeZone('Asia/Tokyo')
            );
            if (!$prevCloseDt) throw new RuntimeException('前月締日の計算に失敗しました');

            $startDt = $prevCloseDt->modify('+1 day');

            $periodFrom = $startDt->format('Y-m-d');
            $periodTo   = $closeDt->format('Y-m-d');

            $payDayRaw = (int)$store['payroll_pay_day'];
            $closeDayEff = $closeDay;

            $payMonthBase = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01', new DateTimeZone('Asia/Tokyo'));
            if (!$payMonthBase) throw new RuntimeException('支払月計算に失敗しました');

            $payMonth = ($payDayRaw <= $closeDayEff)
                ? $payMonthBase->modify('+1 month')->format('Y-m')
                : $payMonthBase->format('Y-m');

            $payDay = normalizePayDay($payDayRaw, $payMonth);
            $payDt = DateTimeImmutable::createFromFormat(
                'Y-m-d',
                sprintf('%s-%02d', $payMonth, $payDay),
                new DateTimeZone('Asia/Tokyo')
            );

            $periodLabel = "月払い（{$periodFrom} 〜 {$periodTo}）";
            $payDateLabel = $payDt ? ("支払日: " . $payDt->format('Y-m-d')) : '';
        }
    }

    if ($periodFrom === '' || $periodTo === '') {
        throw new RuntimeException('期間の確定に失敗しました（from/to または 店舗設定を確認してください）');
    }

    // ===== employee =====
    $empCols = tableColumns($pdo, 'employees');
    $selectStd = in_array('standard_monthly_remuneration', $empCols, true) ? "COALESCE(standard_monthly_remuneration, 0) AS standard_monthly_remuneration" : "0 AS standard_monthly_remuneration";
    $selectHealth = in_array('health_ins_enrolled', $empCols, true) ? "COALESCE(health_ins_enrolled, 0) AS health_ins_enrolled" : "0 AS health_ins_enrolled";
    $selectPension = in_array('pension_enrolled', $empCols, true) ? "COALESCE(pension_enrolled, 0) AS pension_enrolled" : "0 AS pension_enrolled";
    $selectBirth = in_array('birth_date', $empCols, true) ? "birth_date" : "NULL AS birth_date";
    $selectNightEnabled = in_array('night_premium_enabled', $empCols, true) ? "COALESCE(night_premium_enabled, 0) AS night_premium_enabled" : "0 AS night_premium_enabled";
    $selectNightRate = in_array('night_premium_rate_percent', $empCols, true) ? "COALESCE(night_premium_rate_percent, 25) AS night_premium_rate_percent" : "25 AS night_premium_rate_percent";
    $eSt = $pdo->prepare("
        SELECT
          id,
          display_name,
          hourly_wage_yen,
          COALESCE(tax_type,'ko') AS tax_type,
          withholding_pay_cycle,
          {$selectStd},
          {$selectHealth},
          {$selectPension},
          {$selectBirth},
          {$selectNightEnabled},
          {$selectNightRate}
        FROM employees
        WHERE tenant_id = :t AND store_id = :s AND id = :eid
        LIMIT 1
    ");
    $eSt->execute([':t' => $tenantId, ':s' => $storeId, ':eid' => $employeeId]);
    $emp = $eSt->fetch();
    if (!$emp) throw new RuntimeException('従業員が見つかりません（tenant/store/employee_id の不一致の可能性）');

    $defaultHourlyWage = (int)($emp['hourly_wage_yen'] ?? 0);
    $taxType = (string)($emp['tax_type'] ?? 'ko');
    $nightPremiumEnabled = ((int)($emp['night_premium_enabled'] ?? 0) === 1);
    $nightPremiumRate = (int)($emp['night_premium_rate_percent'] ?? 25);
    if (!in_array($nightPremiumRate, [25, 30, 35, 40, 45, 50], true)) $nightPremiumRate = 25;

    $tz = new DateTimeZone('Asia/Tokyo');

    // ✅ 追加：日別時給マップ（履歴優先 / 無ければ employees）
    $hourlyByBusinessDate = buildDailyHourlyWageMap($pdo, $tenantId, $storeId, $employeeId, $periodFrom, $periodTo, $defaultHourlyWage);
    $hourlyDisplayWage = (int)($hourlyByBusinessDate[$periodTo] ?? $defaultHourlyWage);

    // ===== ✅ 営業日切替を考慮した「実集計ウィンドウ」 =====
    [$winStartTs, $winEndTs, $winStartDtStr, $winEndDtStr] = buildBusinessWindow($periodFrom, $periodTo, $cutoffHms, $tz);

    // ===== time_punches（clock_in/out）=====
    $tpSt = $pdo->prepare("
        SELECT id, punch_type, punched_at
        FROM time_punches
        WHERE tenant_id = :t AND store_id = :s AND employee_id = :eid
          AND punched_at >= :start_dt
          AND punched_at <= :end_dt
          AND punch_type IN ('clock_in','clock_out')
        ORDER BY punched_at ASC, id ASC
    ");
    $tpSt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':eid' => $employeeId,
        ':start_dt' => $winStartDtStr,
        ':end_dt'   => $winEndDtStr,
    ]);
    $tpRows = $tpSt->fetchAll();

    // ✅ 勤務区間（計算用）
    $workIntervalsAll = buildWorkIntervalsFromPunches($tpRows, $roundUnit);
    $workIntervals    = clipIntervals($workIntervalsAll, $winStartTs, $winEndTs);
    $workSeconds      = sumIntervalsSeconds($workIntervals);

    // ===== break_punches =====
    $bpSt = $pdo->prepare("
        SELECT id, break_start_at, break_end_at
        FROM break_punches
        WHERE tenant_id = :t AND store_id = :s AND employee_id = :eid
          AND break_start_at <  :end_dt
          AND (break_end_at IS NULL OR break_end_at > :start_dt)
        ORDER BY break_start_at ASC, id ASC
    ");
    $bpSt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':eid' => $employeeId,
        ':start_dt' => $winStartDtStr,
        ':end_dt'   => date('Y-m-d H:i:s', $winEndTs + 1),
    ]);
    $bpRows = $bpSt->fetchAll();

    $breakSeconds = calcBreakSecondsOverIntervals($bpRows, $workIntervals, $winStartTs, $winEndTs);
    $netSeconds   = max(0, $workSeconds - $breakSeconds);

    // ===== 手当（期間合計）=====
    $bonusYen = 0;
    try {
        $bonusHasStore = hasTableColumn($pdo, 'daily_wage_adjustments', 'store_id');
        $sql = "
            SELECT COALESCE(SUM(bonus_yen),0) AS bonus_yen
            FROM daily_wage_adjustments
            WHERE tenant_id = :t AND employee_id = :eid
              AND business_date >= :from
              AND business_date <= :to
        ";
        if ($bonusHasStore) $sql .= " AND store_id = :s";
        $bSt = $pdo->prepare($sql);
        $params = [':t' => $tenantId, ':eid' => $employeeId, ':from' => $periodFrom, ':to' => $periodTo];
        if ($bonusHasStore) $params[':s'] = $storeId;
        $bSt->execute($params);
        $bonusYen = (int)($bSt->fetch()['bonus_yen'] ?? 0);
    } catch (Throwable $e) {
        $bonusYen = 0;
    }

    $cashbackYen = 0;
    try {
        $cSt = $pdo->prepare("
            SELECT COALESCE(SUM(amount_yen),0) AS cashback_yen
            FROM back_events
            WHERE tenant_id = :t AND store_id = :s AND employee_id = :eid
              AND status = 'confirmed'
              AND business_date >= :from
              AND business_date <= :to
        ");
        $cSt->execute([':t' => $tenantId, ':s' => $storeId, ':eid' => $employeeId, ':from' => $periodFrom, ':to' => $periodTo]);
        $cashbackYen = (int)($cSt->fetch()['cashback_yen'] ?? 0);
    } catch (Throwable $e) {
        $cashbackYen = 0;
    }

    // ===== ✅ 日別：bonus/back（営業日 business_date 前提） =====
    $bonusByDate = [];
    try {
        $bonusHasStore = hasTableColumn($pdo, 'daily_wage_adjustments', 'store_id');
        $sql = "
            SELECT business_date, COALESCE(SUM(bonus_yen),0) AS bonus_yen
            FROM daily_wage_adjustments
            WHERE tenant_id=:t AND employee_id=:eid
              AND business_date >= :from AND business_date <= :to
        ";
        if ($bonusHasStore) $sql .= " AND store_id=:s";
        $sql .= " GROUP BY business_date";
        $b2 = $pdo->prepare($sql);
        $params = [':t' => $tenantId, ':eid' => $employeeId, ':from' => $periodFrom, ':to' => $periodTo];
        if ($bonusHasStore) $params[':s'] = $storeId;
        $b2->execute($params);
        foreach ($b2->fetchAll() as $r) {
            $d = (string)($r['business_date'] ?? '');
            if ($d !== '') $bonusByDate[$d] = (int)($r['bonus_yen'] ?? 0);
        }
    } catch (Throwable $e) {
        $bonusByDate = [];
    }

    $cashbackByDate = [];
    try {
        $c2 = $pdo->prepare("
            SELECT business_date, COALESCE(SUM(amount_yen),0) AS cashback_yen
            FROM back_events
            WHERE tenant_id=:t AND store_id=:s AND employee_id=:eid
              AND status='confirmed'
              AND business_date >= :from AND business_date <= :to
            GROUP BY business_date
        ");
        $c2->execute([':t' => $tenantId, ':s' => $storeId, ':eid' => $employeeId, ':from' => $periodFrom, ':to' => $periodTo]);
        foreach ($c2->fetchAll() as $r) {
            $d = (string)($r['business_date'] ?? '');
            if ($d !== '') $cashbackByDate[$d] = (int)($r['cashback_yen'] ?? 0);
        }
    } catch (Throwable $e) {
        $cashbackByDate = [];
    }

    // ===== ✅ 日別：出勤/退勤（“ペア(in→out)”で in側営業日に寄せる） =====
    // なぜ必要か：
    // - 深夜退勤(04:03)が暦日翌日になっても、「その勤務の出勤日」に表示したい
    $inOutByDate = []; // ['YYYY-MM-DD' => ['in_ts'=>int|null,'out_ts'=>int|null]]

    $openInTs = null;

    foreach ($tpRows as $r) {
        $type = (string)($r['punch_type'] ?? '');
        $ts = strtotime((string)($r['punched_at'] ?? ''));
        if ($ts === false) continue;
        $ts = (int)$ts;

        if ($type === 'clock_in') {
            // 連続inが来たら「最新in」で上書き（壊れデータの保険）
            $openInTs = $ts;
            continue;
        }

        if ($type === 'clock_out') {
            if ($openInTs === null) {
                // out単発は表示上の紐付け先が無いので捨てる（壊れデータ保険）
                continue;
            }

            // ✅ 退勤の紐付け先は “in側” の営業日
            $bizDate = businessDateFromTs((int)$openInTs, $cutSec, $tz);

            if (!isset($inOutByDate[$bizDate])) {
                $inOutByDate[$bizDate] = ['in_ts' => null, 'out_ts' => null];
            }

            // その日の最初のin / 最後のout を保持
            $curIn = $inOutByDate[$bizDate]['in_ts'];
            if ($curIn === null || (int)$openInTs < (int)$curIn) $inOutByDate[$bizDate]['in_ts'] = (int)$openInTs;

            $curOut = $inOutByDate[$bizDate]['out_ts'];
            if ($curOut === null || (int)$ts > (int)$curOut) $inOutByDate[$bizDate]['out_ts'] = (int)$ts;

            $openInTs = null;
            continue;
        }
    }

    // 末尾にinだけ残った場合も「出勤だけ」表示できるようにする
    if ($openInTs !== null) {
        $bizDate = businessDateFromTs((int)$openInTs, $cutSec, $tz);
        if (!isset($inOutByDate[$bizDate])) {
            $inOutByDate[$bizDate] = ['in_ts' => null, 'out_ts' => null];
        }
        $curIn = $inOutByDate[$bizDate]['in_ts'];
        if ($curIn === null || (int)$openInTs < (int)$curIn) $inOutByDate[$bizDate]['in_ts'] = (int)$openInTs;
    }

    // ===== ✅ 日別明細（最大31日） =====
    $businessDates = listBusinessDates($periodFrom, $periodTo, $tz);
    $dailyDetails = [];

    // ✅ 合計基本給は「日別時給」で積み上げる（過去が変わらないため）
    $basePayYen = 0;
    $nightPremiumYen = 0;

    foreach ($businessDates as $d) {
        [$dStartTs, $dEndTs, $dStartStr, $dEndStr] = buildOneBusinessDayWindow($d, $cutoffHms, $tz);

        $dStartTs = max($dStartTs, $winStartTs);
        $dEndTs   = min($dEndTs,   $winEndTs);

        $dayWorkIntervals = clipIntervals($workIntervalsAll, $dStartTs, $dEndTs);
        $dayWorkSec = sumIntervalsSeconds($dayWorkIntervals);

        $dayBreakSec = 0;
        if ($dayWorkSec > 0) {
            $dayBreakSec = calcBreakSecondsOverIntervals($bpRows, $dayWorkIntervals, $dStartTs, $dEndTs);
        }
        $dayNetSec = max(0, $dayWorkSec - $dayBreakSec);
        $dayNightIntervals = $dayWorkSec > 0 ? buildNightIntervalsFromWorkIntervals($dayWorkIntervals) : [];
        $dayNightWorkSec = $dayNightIntervals ? sumIntervalsSeconds($dayNightIntervals) : 0;
        $dayNightBreakSec = $dayNightWorkSec > 0
            ? calcBreakSecondsOverIntervals($bpRows, $dayNightIntervals, $dStartTs, $dEndTs)
            : 0;
        $dayNightNetSec = max(0, $dayNightWorkSec - $dayNightBreakSec);

        // ✅ その日の時給（履歴優先、無ければemployees）
        $dayHourlyWage = (int)($hourlyByBusinessDate[$d] ?? $defaultHourlyWage);

        $dayBasePay = (int)round(($dayNetSec / 3600) * $dayHourlyWage);
        $basePayYen += $dayBasePay;
        $dayNightPremium = 0;
        if ($nightPremiumEnabled && $dayNightNetSec > 0) {
            $nightMinutes = (int)floor($dayNightNetSec / 60);
            if ($nightMinutes > 0) {
                $dayNightPremium = (int)round($nightMinutes * ($dayHourlyWage / 60.0) * ($nightPremiumRate / 100.0));
            }
        }
        $nightPremiumYen += $dayNightPremium;

        $dayBonus   = (int)($bonusByDate[$d] ?? 0);
        $dayBack    = (int)($cashbackByDate[$d] ?? 0);

        $dayTotal = $dayBasePay + $dayNightPremium + $dayBonus + $dayBack;

        $inTs  = $inOutByDate[$d]['in_ts']  ?? null;
        $outTs = $inOutByDate[$d]['out_ts'] ?? null;

        $clockInStr  = ($inTs  !== null) ? tsToHi((int)$inTs,  $tz) : '—';
        $clockOutStr = ($outTs !== null) ? tsToHi((int)$outTs, $tz) : '—';

        $dailyDetails[] = [
            'business_date' => $d,

            'clock_in'      => $clockInStr,
            'clock_out'     => $clockOutStr,
            'clock_in_str'  => $clockInStr,
            'clock_out_str' => $clockOutStr,

            'work_seconds'  => $dayWorkSec,
            'break_seconds' => $dayBreakSec,
            'net_seconds'   => $dayNetSec,

            'work_hm'  => secToHM($dayWorkSec),
            'break_hm' => secToHM($dayBreakSec),
            'net_hm'   => secToHM($dayNetSec),

            // ✅ 日別時給
            'hourly_wage_yen' => $dayHourlyWage,

            'base_pay_yen'    => $dayBasePay,
            'night_premium_yen' => $dayNightPremium,
            'bonus_yen'       => $dayBonus,
            'cashback_yen'    => $dayBack,
            'day_total_yen'   => $dayTotal,

            'day_window_start' => $dStartStr,
            'day_window_end'   => $dEndStr,
            'cutoff_time'      => $cutoffHms,
            'cutoff_seconds'   => $cutSec,
        ];
    }

    $grossPayYen = $basePayYen + $nightPremiumYen + $bonusYen + $cashbackYen;

    $nonTaxableTotalYen = 0;
    $taxablePayYen = max(0, $grossPayYen - $nonTaxableTotalYen);

    // ===== 源泉 =====
    $payCycleForTax = trim((string)($emp['withholding_pay_cycle'] ?? ''));
    if ($payCycleForTax === '') $payCycleForTax = $cycleType;
    if (!in_array($payCycleForTax, ['daily', 'weekly', 'monthly'], true)) $payCycleForTax = 'monthly';

    $wh = calcWithholdingYen($pdo, $payCycleForTax, $taxType, $taxablePayYen, null);
    $withholdingTaxYen = (int)$wh['withholding'];

    $ins = si_calc($emp, $store, $taxablePayYen, $periodTo, $pdo);
    $healthInsYen = (int)$ins['health'];
    $careInsYen = (int)$ins['care'];
    $pensionYen = (int)$ins['pension'];
    $employmentInsYen = (int)$ins['employment'];
    $childcareSupportYen = (int)$ins['childcare_support'];
    $deductTotalYen = $withholdingTaxYen + $healthInsYen + $careInsYen + $pensionYen + $employmentInsYen + $childcareSupportYen;

    $netPayYen = max(0, $grossPayYen - $deductTotalYen);

    // ===== view =====
    $viewFile = __DIR__ . '/payslip_simple_view.php';
    if (!is_file($viewFile)) throw new RuntimeException("view not found: {$viewFile}");

    $payrollContext = [
        'tenant_id'   => $tenantId,
        'store_id'    => $storeId,
        'employee_id' => $employeeId,

        'store'       => $store,
        'employee'    => $emp,

        'cycle_type'      => $cycleType,
        'period_from'     => $periodFrom,
        'period_to'       => $periodTo,
        'period_label'    => $periodLabel,
        'pay_date_label'  => $payDateLabel,

        'round_unit_minutes' => $roundUnit,

        'business_day_cutoff_time' => $cutoffHms,
        'punch_window_start' => $winStartDtStr,
        'punch_window_end'   => $winEndDtStr,

        'work_seconds'  => $workSeconds,
        'break_seconds' => $breakSeconds,
        'net_seconds'   => $netSeconds,
        'work_hm'  => secToHM($workSeconds),
        'break_hm' => secToHM($breakSeconds),
        'net_hm'   => secToHM($netSeconds),

        // ✅ ヘッダ等で使う可能性があるため「現在時給（employees）」はそのまま渡す（既存viewを壊さない）
        'hourly_wage_yen' => $defaultHourlyWage,
        'hourly_wage_display_yen' => $hourlyDisplayWage,

        'base_pay_yen'    => $basePayYen,
        'night_premium_yen' => $nightPremiumYen,
        'bonus_yen'       => $bonusYen,
        'cashback_yen'    => $cashbackYen,

        'gross_pay_yen'          => $grossPayYen,
        'non_taxable_total_yen'  => $nonTaxableTotalYen,
        'taxable_pay_yen'        => $taxablePayYen,
        'withholding_tax_yen'    => $withholdingTaxYen,
        'health_insurance_yen'   => $healthInsYen,
        'care_insurance_yen'     => $careInsYen,
        'pension_yen'            => $pensionYen,
        'employment_insurance_yen' => $employmentInsYen,
        'childcare_support_yen'  => $childcareSupportYen,
        'deduct_total_yen'       => $deductTotalYen,
        'net_pay_yen'            => $netPayYen,

        // ✅ 日別（出勤/退勤 + 日別時給入り）
        'daily_details'      => $dailyDetails,
        'daily_detail_rows'  => $dailyDetails,
        'daily_rows'         => $dailyDetails,
        'daily_breakdown'    => $dailyDetails,

        'daily_bonus_by_date'    => $bonusByDate,
        'daily_cashback_by_date' => $cashbackByDate,

        'withholding_table_id'   => (int)$wh['used_table_id'],
        'withholding_version'    => (string)$wh['used_version'],
        'withholding_pay_cycle'  => $payCycleForTax,
        'employee_tax_type'      => $taxType,

        'generated_at' => date('Y-m-d H:i'),
    ];

    ob_start();
    include $viewFile;
    $html = ob_get_clean();

    if (trim((string)$html) === '') {
        throw new RuntimeException("HTML is empty. view produced no output.");
    }

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'NotoSansJP');
    $options->set('chroot', __DIR__);
    $options->set('isFontSubsettingEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->setBasePath(__DIR__);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = sprintf('payslip_%d_%s_to_%s.pdf', $employeeId, $periodFrom, $periodTo);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    echo $dompdf->output();
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PDF generation failed.\n\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    echo $e->getTraceAsString();
    exit;
}
