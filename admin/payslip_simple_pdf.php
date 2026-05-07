<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/payslip_simple_pdf.php
 * ✅ 書き込み場所: 既存のこのファイルを「丸ごと置き換え」
 *
 * ✅ 今回の修正（追加）
 * - 期間内に時給変更があっても過去が変わらないように、employee_wage_histories を参照して計算する
 *   - effective_business_day <= 対象営業日 の最新履歴を採用
 *   - 履歴が無い/テーブルが無い場合は employees.hourly_wage_yen にフォールバック（既存を壊さない）
 *
 * ✅ 既存のあなたの要望（維持）
 * - 2枚目（日別詳細）の「日額(概算)」に打刻調整（clock_in切り上げ / clock_out切り捨て）を確実に反映
 * - 誤認防止のため、2枚目の「出勤/退勤」も “打刻調整後の時刻” で表示し、計算と表示を一致させる
 *
 * ✅ 既存維持
 * - 1枚目の表示構造・出勤/休憩/実働の表示・源泉計算の流れは維持
 * - 休憩は break_punches の実時間（打刻調整なし）で控除（従来通り）
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
   helpers（HTML/入力）
   ========================= */

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function nfmt($v): string
{
    $i = (int)($v ?? 0);
    return number_format($i);
}

function hmh(string $hm): string
{
    $hm = trim($hm);
    if ($hm === '') return '0:00';
    if (!preg_match('/^\d+:\d{2}$/', $hm)) return '0:00';
    return $hm;
}

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

/**
 * ✅ break_punches の休憩を「期間」かつ「確定勤務区間」に重なる分だけ秒で合算
 */
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
            // ✅ 終了が無い休憩は集計しない
            continue;
        }

        // break は実時間（打刻調整無し）
        $bsCalc = roundTsForCalc((int)$bsRaw, 0, 'break_in');
        $beCalc = roundTsForCalc((int)$beRaw, 0, 'break_out');

        // まず期間でクリップ
        $bsClip = max($bsCalc, $periodStartTs);
        $beClip = min($beCalc, $periodEndTs);
        if ($beClip <= $bsClip) continue;

        // 次に勤務区間との重なりだけ足す
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

/**
 * ✅ "05:00:00" を秒へ
 */
function timeHmsToSeconds(string $hms): int
{
    $hms = trim($hms);
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hms)) return 0;
    [$h, $m, $s] = array_map('intval', explode(':', $hms));
    if ($h < 0 || $h > 23) $h = 0;
    if ($m < 0 || $m > 59) $m = 0;
    if ($s < 0 || $s > 59) $s = 0;
    return $h * 3600 + $m * 60 + $s;
}

/**
 * ✅ 営業日（cutoff）基準の「その日の開始/終了TS」を返す
 *
 * @return array{0:int,1:int} [startTs,endTs]
 */
function businessDayWindowTs(string $ymd, int $cutoffSec, DateTimeZone $tz): array
{
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ymd . ' 00:00:00', $tz);
    if (!$start) {
        $ts = strtotime($ymd . ' 00:00:00');
        if ($ts === false) $ts = time();
        $startTs = $ts + $cutoffSec;
        return [$startTs, $startTs + 86400 - 1];
    }
    $startTs = $start->getTimestamp() + $cutoffSec;
    return [$startTs, $startTs + 86400 - 1];
}

/**
 * ✅ punched_at(ts) から「営業日(business_date)」を算出
 * - その日の cutoff より前は「前日」を営業日として返す
 */
function businessDateFromTs(int $ts, int $cutoffSeconds): string
{
    $dayStart = strtotime(date('Y-m-d 00:00:00', $ts));
    if ($dayStart === false) return date('Y-m-d', $ts);

    $cutoffTs = (int)$dayStart + $cutoffSeconds;
    if ($ts < $cutoffTs) {
        return date('Y-m-d', strtotime('-1 day', (int)$dayStart));
    }
    return date('Y-m-d', (int)$dayStart);
}

/**
 * ✅ 営業日リスト
 * @return string[] Y-m-d
 */
function buildBusinessDateList(string $fromYmd, string $toYmd, DateTimeZone $tz): array
{
    $s = DateTimeImmutable::createFromFormat('Y-m-d', $fromYmd, $tz);
    $e = DateTimeImmutable::createFromFormat('Y-m-d', $toYmd, $tz);
    if (!$s || !$e) return [];

    if ($s > $e) {
        [$s, $e] = [$e, $s];
    }

    $out = [];
    for ($d = $s; $d <= $e; $d = $d->modify('+1 day')) {
        $out[] = $d->format('Y-m-d');
        if (count($out) > 400) break; // 保険
    }
    return $out;
}

/**
 * ✅ break_punches 行から「確定している休憩区間」を返す（クリップ無し）
 *
 * @return array<int, array{0:int,1:int}> [[bs,be], ...]
 */
function buildBreakSegmentsRaw(array $breakRows): array
{
    $segs = [];
    foreach ($breakRows as $r) {
        $bs = (string)($r['break_start_at'] ?? '');
        $be = (string)($r['break_end_at'] ?? '');

        $bsTs = $bs !== '' ? strtotime($bs) : false;
        if ($bsTs === false) continue;

        $beTs = $be !== '' ? strtotime($be) : false;
        if ($beTs === false) {
            continue;
        }

        if ((int)$beTs > (int)$bsTs) {
            $segs[] = [(int)$bsTs, (int)$beTs];
        }
    }
    return $segs;
}

/**
 * ✅ 打刻から「営業日単位」の勤務区間を作る（clock_in の営業日基準）
 *
 * @return array<string, array<int, array{in:int,out:int,work:int,break:int,net:int}>>
 */
function buildIntervalsByBusinessDay(array $punchRows, array $breakSegments, int $roundUnitMinutes, int $cutoffSeconds): array
{
    $out = [];
    $openStack = [];

    foreach ($punchRows as $r) {
        $type = (string)($r['punch_type'] ?? '');
        $tsRaw = strtotime((string)($r['punched_at'] ?? ''));
        if ($tsRaw === false) continue;

        $tsCalc = roundTsForCalc((int)$tsRaw, $roundUnitMinutes, $type);

        if ($type === 'clock_in') {
            $openStack[] = [
                'in_calc' => (int)$tsCalc,
                'in_raw' => (int)$tsRaw,
            ];
            continue;
        }

        if ($type === 'clock_out') {
            if (empty($openStack)) continue;
            $open = array_pop($openStack);
            $inCalc = (int)($open['in_calc'] ?? 0);
            $inRaw = (int)($open['in_raw'] ?? 0);

            if ($tsCalc <= $inCalc) continue;

            $workSec = $tsCalc - $inCalc;
            $breakSec = overlapSecondsWithSegments($inCalc, (int)$tsCalc, $breakSegments);
            $netSec = max(0, $workSec - $breakSec);

            $bizDay = businessDateFromTs($inRaw, $cutoffSeconds);
            $out[$bizDay][] = [
                'in' => $inCalc,
                'out' => (int)$tsCalc,
                'work' => $workSec,
                'break' => $breakSec,
                'net' => $netSec,
            ];
            continue;
        }
    }

    return $out;
}

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
 * ✅ 源泉徴収計算
 * @return array{withholding:int, used_table_id:int, used_version:string}
 */
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

/**
 * ✅ break_punches 行から「確定している休憩区間」だけを、対象日windowでクリップして返す
 *
 * @return array<int, array{0:int,1:int}> [[bs,be], ...]
 */
function buildBreakSegmentsClipped(array $breakRows, int $dayStartTs, int $dayEndTs): array
{
    $segs = [];
    foreach ($breakRows as $r) {
        $bs = (string)($r['break_start_at'] ?? '');
        $be = (string)($r['break_end_at'] ?? '');

        $bsTs = $bs !== '' ? strtotime($bs) : false;
        if ($bsTs === false) continue;

        $beTs = $be !== '' ? strtotime($be) : false;
        if ($beTs === false) {
            // ✅ 終了が無い休憩は日別表示/計算から除外
            continue;
        }

        $s = max((int)$bsTs, $dayStartTs);
        $e = min((int)$beTs, $dayEndTs);
        if ($e <= $s) continue;

        $segs[] = [$s, $e];
    }
    return $segs;
}

/**
 * ✅ 1つの勤務区間に対して、休憩区間の重なり秒を返す
 */
function overlapSecondsWithSegments(int $workStartTs, int $workEndTs, array $breakSegments): int
{
    if ($workEndTs <= $workStartTs) return 0;
    $sum = 0;
    foreach ($breakSegments as $seg) {
        $bs = (int)($seg[0] ?? 0);
        $be = (int)($seg[1] ?? 0);
        if ($be <= $bs) continue;

        $s = max($workStartTs, $bs);
        $e = min($workEndTs, $be);
        if ($e > $s) $sum += ($e - $s);
    }
    return $sum;
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

/**
 * ✅ 複数行表示用：各行を escape して <br> で連結した「安全なHTML」を返す
 */
function htmlJoinLines(array $lines): string
{
    $out = [];
    foreach ($lines as $s) {
        $out[] = h((string)$s);
    }
    return implode('<br>', $out);
}

/**
 * ✅ employee_wage_histories があるか（無い環境でも壊さない）
 */
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
 * ✅ 期間(from..to)に対して「日別の時給」を作る
 * - effective_business_day <= 対象日 の最新を採用（ステップ関数）
 * - 履歴が無い場合は defaultHourly を使う（既存を壊さない）
 *
 * @return array<string,int> ['Y-m-d' => hourly_wage_yen, ...]
 */
function buildDailyHourlyWageMap(PDO $pdo, int $tenantId, int $storeId, int $employeeId, string $from, string $to, int $defaultHourly, bool $hasWageHistories, DateTimeZone $tz): array
{
    $days = buildBusinessDateList($from, $to, $tz);
    if (!$days) return [];

    if (!$hasWageHistories) {
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
            ':to' => $to,
        ]);

        $byDay = [];
        $firstWage = null;
        foreach ($st->fetchAll() as $r) {
            $d = (string)($r['effective_business_day'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
            $w = (int)($r['hourly_wage_yen'] ?? 0);
            if ($firstWage === null) $firstWage = $w;
            $byDay[$d] = $w;
        }

        $map = [];
        // ✅ 最古履歴を過去に引き継ぐ（過去が新時給で汚染されないようにする）
        $cur = ($firstWage !== null) ? $firstWage : $defaultHourly;
        foreach ($days as $d) {
            if (array_key_exists($d, $byDay)) {
                $cur = (int)$byDay[$d];
            }
            $map[$d] = $cur;
        }
        return $map;
    } catch (Throwable $e) {
        $map = [];
        foreach ($days as $d) $map[$d] = $defaultHourly;
        return $map;
    }
}

/* =========================
   main
   ========================= */
try {
    // ===== 入力 =====
    $storeId    = (int)($_GET['store_id'] ?? 0);
    $employeeId = (int)($_GET['employee_id'] ?? 0);

    if ($storeId <= 0) throw new RuntimeException('store_id が不正です');
    if ($employeeId <= 0) throw new RuntimeException('employee_id が不正です（従業員を選択してください）');

    // ✅ 画面で選択された from/to（存在すればそれを優先）
    $fromRaw = mustYmd($_GET['from'] ?? null);
    $toRaw   = mustYmd($_GET['to'] ?? null);

    // ✅ ym は「from/to が無い場合」にだけ使う（従来互換）
    $ymIn = mustYm($_GET['ym'] ?? null) ?? date('Y-m');

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

    // ✅ 打刻調整単位（0/5/10/15/20/25/30）
    $allowedUnits = [0, 5, 10, 15, 20, 25, 30];
    $roundUnit = (int)($store['payroll_round_unit_minutes'] ?? 15);
    if (!in_array($roundUnit, $allowedUnits, true)) $roundUnit = 15;

    // ✅ 営業日切替（cutoff）
    $cutoffHms = (string)($store['business_day_cutoff_time'] ?? '00:00:00');
    $cutoffSec = timeHmsToSeconds($cutoffHms);
    $tz = new DateTimeZone('Asia/Tokyo');

    // ===== 期間確定 =====
    $periodFrom = '';
    $periodTo   = '';
    $periodLabel = '';
    $payDateLabel = '';

    if ($fromRaw !== null && $toRaw !== null) {
        if ($fromRaw > $toRaw) {
            [$fromRaw, $toRaw] = [$toRaw, $fromRaw];
        }
        $periodFrom = $fromRaw;
        $periodTo   = $toRaw;
        $periodLabel = "指定期間（{$periodFrom} 〜 {$periodTo}）";
        $payDateLabel = '';
    } else {
        if ($cycleType === 'weekly') {
            $base = DateTimeImmutable::createFromFormat('Y-m-d', date('Y-m-d'), $tz);
            if (!$base) $base = new DateTimeImmutable('today', $tz);

            $closeWday = (int)$store['payroll_week_close_wday']; // 0..6
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
                $tz
            );
            if (!$closeDt) throw new RuntimeException('月払いの締日計算に失敗しました');

            $prevYmDt = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01', $tz);
            if (!$prevYmDt) throw new RuntimeException('月払いの前月計算に失敗しました');
            $prevYm = $prevYmDt->modify('-1 month')->format('Y-m');

            $prevCloseDay = normalizeCloseDay((int)$store['payroll_close_day'], $prevYm);
            $prevCloseDt = DateTimeImmutable::createFromFormat(
                'Y-m-d',
                sprintf('%s-%02d', $prevYm, $prevCloseDay),
                $tz
            );
            if (!$prevCloseDt) throw new RuntimeException('前月締日の計算に失敗しました');

            $startDt = $prevCloseDt->modify('+1 day');

            $periodFrom = $startDt->format('Y-m-d');
            $periodTo   = $closeDt->format('Y-m-d');

            $payDayRaw = (int)$store['payroll_pay_day'];
            $closeDayEff = $closeDay;

            $payMonthBase = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01', $tz);
            if (!$payMonthBase) throw new RuntimeException('支払月計算に失敗しました');

            $payMonth = ($payDayRaw <= $closeDayEff)
                ? $payMonthBase->modify('+1 month')->format('Y-m')
                : $payMonthBase->format('Y-m');

            $payDay = normalizePayDay($payDayRaw, $payMonth);
            $payDt = DateTimeImmutable::createFromFormat(
                'Y-m-d',
                sprintf('%s-%02d', $payMonth, $payDay),
                $tz
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

    $employeeName = (string)($emp['display_name'] ?? '');
    $storeName    = (string)($store['name'] ?? '');

    // ✅ フォールバック（従来の現在時給）
    $defaultHourlyWage = (int)($emp['hourly_wage_yen'] ?? 0);
    $taxType = (string)($emp['tax_type'] ?? 'ko');
    $nightPremiumEnabled = ((int)($emp['night_premium_enabled'] ?? 0) === 1);
    $nightPremiumRate = (int)($emp['night_premium_rate_percent'] ?? 25);
    if (!in_array($nightPremiumRate, [25, 30, 35, 40, 45, 50], true)) $nightPremiumRate = 25;

    // ✅ 履歴の有無（無い環境でも壊さない）
    $hasWageHistory = hasEmployeeWageHistoriesTable($pdo);

    // ✅ 期間内の日別時給（履歴優先）
    $hourlyWageByDay = buildDailyHourlyWageMap(
        $pdo,
        $tenantId,
        $storeId,
        $employeeId,
        $periodFrom,
        $periodTo,
        $defaultHourlyWage,
        $hasWageHistory,
        $tz
    );

    // ✅ 表示用の “時給” は、期間最終営業日の時給を採用（履歴が無い場合は現在時給）
    $hourlyWage = (int)($hourlyWageByDay[$periodTo] ?? $defaultHourlyWage);

    // ===== 期間TS（営業日cutoff基準に合わせる）=====
    [$periodStartTs, $periodEndTs] = businessDayWindowTs($periodFrom, $cutoffSec, $tz);
    [, $periodToEndTs] = businessDayWindowTs($periodTo, $cutoffSec, $tz);
    $periodEndTs = $periodToEndTs;

    $periodStartDtStr = date('Y-m-d H:i:s', $periodStartTs);
    $periodEndDtStr   = date('Y-m-d H:i:s', $periodEndTs);

    // ✅ cutoffまたぎの勤務が落ちないように、期間を少し広げて取得
    $rangeStartDtStr = date('Y-m-d 00:00:00', strtotime($periodFrom . ' -1 day'));
    $rangeEndDtStr   = date('Y-m-d 00:00:00', strtotime($periodTo . ' +2 day'));

    // ===== time_punches（clock_in/out）=====
    $tpSt = $pdo->prepare("
        SELECT id, punch_type, punched_at
        FROM time_punches
        WHERE tenant_id = :t AND store_id = :s AND employee_id = :eid
          AND punched_at >= :start_dt
          AND punched_at <  :end_dt
          AND punch_type IN ('clock_in','clock_out')
        ORDER BY punched_at ASC, id ASC
    ");
    $tpSt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':eid' => $employeeId,
        ':start_dt' => $rangeStartDtStr,
        ':end_dt'   => $rangeEndDtStr,
    ]);
    $tpRows = $tpSt->fetchAll();

    // ===== break_punches =====
    $bpSt = $pdo->prepare("
        SELECT id, break_start_at, break_end_at
        FROM break_punches
        WHERE tenant_id = :t AND store_id = :s AND employee_id = :eid
          AND COALESCE(break_end_at, break_start_at) >= :start_dt
          AND break_start_at <  :end_dt
        ORDER BY break_start_at ASC, id ASC
    ");
    $bpSt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':eid' => $employeeId,
        ':start_dt' => $rangeStartDtStr,
        ':end_dt'   => $rangeEndDtStr,
    ]);
    $bpRows = $bpSt->fetchAll();

    // ✅ 営業日単位の勤務区間
    $breakSegments = buildBreakSegmentsRaw($bpRows);
    $dayIntervals = buildIntervalsByBusinessDay($tpRows, $breakSegments, $roundUnit, $cutoffSec);

    // ✅ 期間合計（表示用）
    $periodBizDatesAll = buildBusinessDateList($periodFrom, $periodTo, $tz);
    $workSeconds = 0;
    $breakSeconds = 0;
    $netSeconds = 0;
    $nightNetSeconds = 0;
    foreach ($periodBizDatesAll as $bizYmd) {
        foreach ($dayIntervals[$bizYmd] ?? [] as $it) {
            $workSeconds += (int)($it['work'] ?? 0);
            $breakSeconds += (int)($it['break'] ?? 0);
            $netSeconds += (int)($it['net'] ?? 0);
            $inTs = (int)($it['in'] ?? 0);
            $outTs = (int)($it['out'] ?? 0);
            if ($outTs > $inTs) {
                $nightWork = calcNightSeconds($inTs, $outTs);
                $nightBreak = overlapSecondsWithSegments($inTs, $outTs, $breakSegments);
                $nightNetSeconds += max(0, $nightWork - $nightBreak);
            }
        }
    }

    // ===== 手当 =====
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

    /* =========================
       ✅ 基本給（期間合算）を履歴時給で再計算
       - なぜ必要か：
         employees.hourly_wage_yen（現在時給）だけで計算すると、過去分が変わるため
       - 期間内の各営業日について、打刻調整後の実働(net)を算出し、その日の時給（履歴）で円換算して合算
       ========================= */
    $basePayYen = 0;
    $nightPremiumYen = 0;

    foreach ($periodBizDatesAll as $bizYmd) {
        // その営業日の時給（履歴優先）
        $dayHourly = (int)($hourlyWageByDay[$bizYmd] ?? $defaultHourlyWage);
        $dayNetSec = 0;
        $dayNightNetSec = 0;
        foreach ($dayIntervals[$bizYmd] ?? [] as $it) {
            $dayNetSec += (int)($it['net'] ?? 0);
            $inTs = (int)($it['in'] ?? 0);
            $outTs = (int)($it['out'] ?? 0);
            if ($outTs > $inTs) {
                $nightWork = calcNightSeconds($inTs, $outTs);
                $nightBreak = overlapSecondsWithSegments($inTs, $outTs, $breakSegments);
                $dayNightNetSec += max(0, $nightWork - $nightBreak);
            }
        }
        if ($dayNetSec > 0) {
            $basePayYen += (int)round(($dayNetSec / 3600) * $dayHourly);
        }
        if ($nightPremiumEnabled && $dayNightNetSec > 0) {
            $nightMinutes = (int)floor($dayNightNetSec / 60);
            if ($nightMinutes > 0) {
                $nightPremiumYen += (int)round($nightMinutes * ($dayHourly / 60.0) * ($nightPremiumRate / 100.0));
            }
        }
    }

    $grossPayYen = $basePayYen + $nightPremiumYen + $bonusYen + $cashbackYen;
    $nonTaxableTotalYen = 0;
    $taxablePayYen = max(0, $grossPayYen - $nonTaxableTotalYen);

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

    /* =========================
       ✅ 2枚目：日別詳細（営業日cutoff基準）
       - 「日額(概算)」は “打刻調整後の実働(net)” × 「その日の時給（履歴）」から算出
       - 出勤/退勤表示も “打刻調整後時刻” に統一（計算と表示を一致）
       ========================= */
    $dateList = buildBusinessDateList($periodFrom, $periodTo, $tz);

    $dailyClamped = false;
    $dailyNote = '';

    if (count($dateList) > 31) {
        $dailyClamped = true;
        $dailyNote = '日別詳細は最大31日まで表示します（期間が長い場合は省略されます）。';
        $dateList = array_slice($dateList, 0, 31);
    }

    $dailyRows = [];
    $maxLinesInDay = 1;

    if ($dateList) {
        foreach ($dateList as $bizYmd) {
            // ✅ その日の時給（履歴優先）
            $dayHourly = (int)($hourlyWageByDay[$bizYmd] ?? $defaultHourlyWage);

            $shifts = [];
            foreach ($dayIntervals[$bizYmd] ?? [] as $it) {
                $inTs = (int)($it['in'] ?? 0);
                $outTs = (int)($it['out'] ?? 0);
                $workSec = (int)($it['work'] ?? 0);
                $breakSec = (int)($it['break'] ?? 0);
                $netSec = (int)($it['net'] ?? 0);

                if ($outTs <= $inTs) continue;

                $dispIn  = date('H:i', $inTs);
                $dispOut = date('H:i', $outTs);

                $nightWork = calcNightSeconds($inTs, $outTs);
                $nightBreak = overlapSecondsWithSegments($inTs, $outTs, $breakSegments);
                $nightNetSec = max(0, $nightWork - $nightBreak);
                $nightPremiumYenShift = 0;
                if ($nightPremiumEnabled && $nightNetSec > 0) {
                    $nightMinutes = (int)floor($nightNetSec / 60);
                    if ($nightMinutes > 0) {
                        $nightPremiumYenShift = (int)round($nightMinutes * ($dayHourly / 60.0) * ($nightPremiumRate / 100.0));
                    }
                }

                // ✅ 日額(概算) = 調整後の実働(netSec) × その日の時給（履歴）
                $baseYen = (int)round(($netSec / 3600) * $dayHourly);
                $dayYen = $baseYen + $nightPremiumYenShift;

                $shifts[] = [
                    'in'       => $dispIn,
                    'out'      => $dispOut,
                    'work_hm'  => secToHM($workSec),
                    'break_hm' => secToHM($breakSec),
                    'net_hm'   => secToHM($netSec),
                    'base_yen' => $dayYen,
                    'night_yen' => $nightPremiumYenShift,
                ];
            }

            if (!$shifts) {
                $shifts = [[
                    'in'       => '—',
                    'out'      => '—',
                    'work_hm'  => '0:00',
                    'break_hm' => '0:00',
                    'net_hm'   => '0:00',
                    'base_yen' => 0,
                ]];
            }

            $maxLinesInDay = max($maxLinesInDay, count($shifts));

            $inLines = [];
            $outLines = [];
            $workLines = [];
            $breakLines = [];
            $netLines = [];
            $hourlyLines = [];
            $yenLines = [];
            foreach ($shifts as $s) {
                $inLines[] = (string)($s['in'] ?? '—');
                $outLines[] = (string)($s['out'] ?? '—');
                $workLines[] = (string)($s['work_hm'] ?? '0:00');
                $breakLines[] = (string)($s['break_hm'] ?? '0:00');
                $netLines[] = (string)($s['net_hm'] ?? '0:00');
                $hourlyLines[] = nfmt($dayHourly);
                $yen = (int)($s['base_yen'] ?? 0);
                $night = (int)($s['night_yen'] ?? 0);
                if ($night > 0) {
                    $yenLines[] = nfmt($yen) . '（夜+' . nfmt($night) . '）';
                } else {
                    $yenLines[] = nfmt($yen);
                }
            }

            $dailyRows[] = [
                'date'           => $bizYmd,
                'clock_in_html'  => htmlJoinLines($inLines),
                'clock_out_html' => htmlJoinLines($outLines),
                'work_hm_html'   => htmlJoinLines($workLines),
                'break_hm_html'  => htmlJoinLines($breakLines),
                'net_hm_html'    => htmlJoinLines($netLines),
                'hourly_yen_html'=> htmlJoinLines($hourlyLines),
                'base_yen_html'  => htmlJoinLines($yenLines),
            ];
        }
    }

    // ===== 表示用（1枚目） =====
    $workHM  = hmh(secToHM($workSeconds));
    $breakHM = hmh(secToHM($breakSeconds));
    $netHM   = hmh(secToHM($netSeconds));

    $ym = substr($periodFrom, 0, 7);
    $y = (int)substr($ym, 0, 4);
    $m = (int)substr($ym, 5, 2);
    $reiwa = $y - 2018;
    $eraLabel = ($reiwa > 0) ? ("令和{$reiwa}年{$m}月分") : ($y . "年{$m}月分");

    $roundLabel = ($roundUnit > 0) ? ($roundUnit . '分') : '0分';

    // ===== 2枚目テーブルCSS自動調整（段数が多いほど詰める）=====
    $dailyFontPx = 9.5;
    $dailyPadY   = 4;
    $dailyLineH  = 1.00;

    if ($maxLinesInDay === 2) {
        $dailyFontPx = 8.8;
        $dailyPadY   = 3;
        $dailyLineH  = 1.00;
    } elseif ($maxLinesInDay >= 3) {
        $dailyFontPx = 8.1;
        $dailyPadY   = 2;
        $dailyLineH  = 0.98;
    }

    $genAt = date('Y-m-d H:i');

    $payDateRowHtml = '';
    if (trim($payDateLabel) !== '') {
        $payDateRowHtml = '<div class="row">' . h($payDateLabel) . '</div>';
    }

    $hasDaily = is_array($dailyRows) && count($dailyRows) > 0;

    $dailyCutoffNote = '※ 日付は営業日（営業日切替 ' . h($cutoffHms) . ' 基準）で集計しています。';
    $dailyRoundNote  = '※ 出勤/退勤・日額(概算) は打刻調整（' . h($roundLabel) . '）を反映しています。';

    $html = '<!doctype html>
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

th, td {
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

.left { text-align: left; }
.right { text-align: right; }
.center { text-align: center; }

.foot {
  margin-top: 12px;
  font-size: 12px;
  line-height: 1.6;
}

/* =========================
   ✅ 2枚目（日別詳細）
   ========================= */
.pageBreak { page-break-before: always; }

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

.dailyTable th, .dailyTable td {
  border: 1px solid #0b6b8a;
  padding: ' . (int)$dailyPadY . 'px 6px;
  font-size: ' . (float)$dailyFontPx . 'px;
  line-height: ' . (float)$dailyLineH . ';
  font-weight: 700;
  word-break: break-word;
  vertical-align: top;
}

.dailyTable th {
  background: #f7fbfd;
  font-weight: 900;
  vertical-align: middle;
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
     1枚目：給与支払明細書
     ========================= -->
<div class="topRow">
  <div class="nameBox">
    氏名 ' . h($employeeName) . ' 様
    <div style="border-top:1px solid #111; width:160px; margin-top:6px;"></div>
  </div>
  <div style="width: 360px;"></div>
</div>

<div class="title">給与支払明細書</div>

<div class="infoWrap">
  <div class="row">' . h($eraLabel) . '</div>
  <div class="row">期間：' . h($periodFrom) . ' 〜 ' . h($periodTo) . '</div>
  <div class="row">店舗：' . h($storeName) . '</div>
  ' . $payDateRowHtml . '
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
    <td>' . nfmt($basePayYen) . '</td>
    <td>' . nfmt($nightPremiumYen) . '</td>
    <td>' . nfmt($bonusYen) . '</td>
    <td>' . nfmt($cashbackYen) . '</td>
    <td>0</td>
    <td colspan="2">' . nfmt($grossPayYen) . '</td>
  </tr>
  <tr>
    <td colspan="6" class="grayBar">課税支給額</td>
    <td>' . nfmt($taxablePayYen) . '</td>
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
    <td>' . nfmt($healthInsYen) . '</td>
    <td>' . nfmt($pensionYen) . '</td>
    <td>' . nfmt($employmentInsYen) . '</td>
    <td>' . nfmt($withholdingTaxYen) . '</td>
    <td>' . nfmt($careInsYen) . '</td>
    <td>' . nfmt($childcareSupportYen) . '</td>
    <td>' . nfmt($deductTotalYen) . '</td>
  </tr>

  <tr>
    <td colspan="7" class="grayBar">差引支給額</td>
    <td>' . nfmt($netPayYen) . '</td>
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
    <td>' . h($netHM) . '</td>
    <td>—</td>
    <td>—</td>
    <td class="left">休憩: ' . h($breakHM) . '</td>
    <td colspan="2">' . h($roundLabel) . '</td>
  </tr>
</table>

<div class="foot">
  時間給内訳 @' . nfmt($hourlyWage) . '円（実働 ' . h($netHM) . '）
  / 休憩合計 ' . h($breakHM) . '
  / 深夜割増 ' . nfmt($nightPremiumYen) . '円
  / bonus ' . nfmt($bonusYen) . '円
  / back ' . nfmt($cashbackYen) . '円
  / 課税支給額 ' . nfmt($taxablePayYen) . '円
  / 源泉 ' . nfmt($withholdingTaxYen) . '円
  / 出力日時：' . h($genAt) . '
</div>
';

    if ($hasDaily) {
        $html .= '
<!-- =========================
     2枚目：日別詳細
     ========================= -->
<div class="pageBreak"></div>

<div class="page2Title">日別詳細</div>

<div class="page2Meta">
  <div>
    氏名：' . h($employeeName) . '　
    店舗：' . h($storeName) . '
  </div>
  <div>
    期間：' . h($periodFrom) . ' 〜 ' . h($periodTo) . '<br>
    打刻調整：' . h($roundLabel) . '
  </div>
</div>
';

        if ($dailyClamped && trim($dailyNote) !== '') {
            $html .= '<div class="noteBox">※ ' . h($dailyNote) . '</div>';
        }

        $html .= '
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
';

        foreach ($dailyRows as $r) {
            $d = (string)($r['date'] ?? '');
            $cinHtml  = (string)($r['clock_in_html'] ?? h('—'));
            $coutHtml = (string)($r['clock_out_html'] ?? h('—'));
            $workHtml = (string)($r['work_hm_html'] ?? h('0:00'));
            $breakHtml = (string)($r['break_hm_html'] ?? h('0:00'));
            $netHtml = (string)($r['net_hm_html'] ?? h('0:00'));
            $hourlyHtml = (string)($r['hourly_yen_html'] ?? h(nfmt(0)));
            $yenHtml = (string)($r['base_yen_html'] ?? h(nfmt(0)));

            $html .= '
  <tr>
    <td class="left">' . h(mdw($d)) . '</td>
    <td class="center">' . $cinHtml . '</td>
    <td class="center">' . $coutHtml . '</td>
    <td class="center">' . $workHtml . '</td>
    <td class="center">' . $breakHtml . '</td>
    <td class="center">' . $netHtml . '</td>
    <td class="right">' . $hourlyHtml . '</td>
    <td class="right">' . $yenHtml . '</td>
  </tr>
';
        }

        $html .= '
</table>

<div class="noteBox">
  ※ 日額(概算) は「打刻調整後の実働 × その日の時給（履歴）」＋「深夜割増」の概算です。bonus/back/源泉等の配賦は含みません。<br>
  ' . $dailyRoundNote . '<br>
  ' . $dailyCutoffNote . '
</div>
';
    }

    $html .= '
</body>
</html>
';

    if (trim($html) === '') {
        throw new RuntimeException("HTML is empty.");
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
