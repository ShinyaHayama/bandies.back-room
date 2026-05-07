<?php

declare(strict_types=1);

/**
 * ✅ 目的（今回の「0円のまま」の直し）
 * - slip_id=9 が “作成されたけど集計してない” 状態なので、pay_slips が全部0のまま
 * - この画面で「勤怠（time_punches / break_punches）＋ bonus / back」から日給を積み上げて
 *   pay_slips.gross_pay 等へ反映する（= PDFが0円のままを解消）
 *
 * ✅ 設計上の流れ（統合）
 * time_punch_daily.php は「日別サマリ表示」のために内部で計算しているだけ
 * → payroll_run.php が “同じロジックで” 期間集計して pay_slips を更新するのが正解
 *
 * ✅ 注意
 * - ここでは「源泉徴収」まで自動更新も行う（tax_overridden=1 の場合は源泉だけ触らない）
 * - tax_withholding_rows が未投入の table_id だと源泉は 0 のまま（今回もそこが未完の可能性あり）
 */

// =========================
// ✅ 500時に必ず理由を出す（本番でも一旦オン）
// =========================
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n\n";
    echo "Exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    echo $e->getTraceAsString();
    exit;
});
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

date_default_timezone_set('Asia/Tokyo');

// =========================
// 認証
// =========================
require_once __DIR__ . '/_auth.php';
require_admin_login();

// =========================
// tenant
// =========================
require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

// =========================
// DB（admin/_db.php を使う）
// =========================
$dbFile = __DIR__ . '/_db.php';
if (!is_file($dbFile)) {
    throw new RuntimeException("_db.php not found: {$dbFile}");
}
require_once $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException("PDO handle not available (_db.php did not set \$pdo)");
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
require_once __DIR__ . '/lib/social_insurance.php';

// =========================
// helpers
// =========================
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function tableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols[] = (string)$r['Field'];
    }
    $cache[$table] = $cols;
    return $cols;
}

function ensurePaySlipInsuranceColumns(PDO $pdo): void
{
    $cols = tableColumns($pdo, 'pay_slips');
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
 * ✅ “このファイル内で完結” する INSERT（lastInsertId を返す）
 * - 既存DBの列差異にも耐える（存在する列だけ入れる）
 */
function safeInsertReturnId(PDO $pdo, string $table, array $data): int
{
    $cols = tableColumns($pdo, $table);

    $use = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $cols, true)) {
            $use[$k] = $v;
        }
    }
    if (empty($use)) {
        throw new RuntimeException("INSERT failed: no matching columns for table {$table}");
    }

    $fields = array_keys($use);
    $ph = array_map(fn($f) => ':' . $f, $fields);

    $sql = "INSERT INTO `{$table}` (" . implode(',', array_map(fn($f) => "`{$f}`", $fields)) . ")
            VALUES (" . implode(',', $ph) . ")";
    $stmt = $pdo->prepare($sql);

    $params = [];
    foreach ($use as $k => $v) $params[':' . $k] = $v;
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

try {
    ensurePaySlipInsuranceColumns($pdo);
} catch (Throwable $e) {
    throw new RuntimeException('pay_slips の社保列作成に失敗しました: ' . $e->getMessage());
}

/**
 * ✅ business_day_cutoff_time を秒に変換（"05:00" / "05:00:00" どちらもOK）
 */
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

/**
 * ✅ punched_at(ts) から「営業日(business_date)」を算出
 * - その日の cutoff より前（例: 00:00〜04:59）は「前日」を営業日として返す
 */
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
function roundTsForCalc(int $ts, int $unitMinutes, string $type): int
{
    // ✅ 休憩は打刻調整ない（実時間）
    if ($type === 'break_in' || $type === 'break_out') return $ts;

    if ($unitMinutes <= 0) return $ts;
    if ($type === 'clock_out') return floorToMinutes($ts, $unitMinutes);
    return ceilToMinutes($ts, $unitMinutes);
}

/**
 * ✅ YYYY-mm-dd の連続日配列を作る
 * - なぜ必要か：時給履歴の「最新<=日」を日ごとに適用するため（過去が変わらない設計にする）
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

/**
 * ✅ employee_wage_histories を使って「日別の時給」を決める
 * - ルール：effective_business_day <= 対象日の最新の履歴をその日の時給として採用
 * - 履歴が無い日は employees.hourly_wage_yen（現在設定）へフォールバック（既存を壊さない）
 *
 * なぜこれが必要か：
 * - 履歴が「変更日だけ」保存される運用でも、期間内の各日へ時給を引き継いで適用できる
 * - employees.hourly_wage_yen だけを見ると、過去分が新時給で再計算されてしまう
 */
function buildDailyHourlyWageMap(PDO $pdo, int $tenantId, int $storeId, int $employeeId, string $from, string $to, int $defaultHourly): array
{
    $days = buildDateRangeYmd($from, $to);
    if (empty($days)) return [];

    // ✅ employee_wage_histories が無い/列違いでも壊さない（既存挙動にフォールバック）
    try {
        $cols = tableColumns($pdo, 'employee_wage_histories');
        $need = ['tenant_id', 'store_id', 'employee_id', 'effective_business_day', 'hourly_wage_yen'];
        foreach ($need as $c) {
            if (!in_array($c, $cols, true)) {
                return []; // 仕様が違うなら諦めてフォールバック（壊さない）
            }
        }

        // from より前の「直近履歴」も必要（期間開始時点の時給を引き継ぐため）
        $stmt = $pdo->prepare("
            SELECT effective_business_day, hourly_wage_yen
            FROM employee_wage_histories
            WHERE tenant_id = :tid
              AND store_id  = :sid
              AND employee_id = :eid
              AND effective_business_day <= :tod
            ORDER BY effective_business_day ASC, id ASC
        ");
        $stmt->execute([
            ':tid' => $tenantId,
            ':sid' => $storeId,
            ':eid' => $employeeId,
            ':tod' => $to,
        ]);
        $rows = $stmt->fetchAll();

        // 日付=>時給（その日に変更がある場合の値）
        $byDay = [];
        $firstWage = null;
        foreach ($rows as $r) {
            $d = (string)($r['effective_business_day'] ?? '');
            if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
            $w = (int)($r['hourly_wage_yen'] ?? 0);
            if ($firstWage === null) $firstWage = $w;
            $byDay[$d] = $w;
        }

        // ✅ 引き継ぎ適用（ステップ関数）
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
 * ✅ 1従業員×期間の「日別 実働秒」を作る（time_punch_daily.php と同じ発想で最小実装）
 */
function buildDailyNetSecondsForEmployee(PDO $pdo, int $tenantId, int $storeId, int $employeeId, string $from, string $to, int $roundUnit, int $cutoffSeconds, bool $nightOnly = false): array
{
    // 深夜跨ぎ拾い（営業日があるので少し広め）
    $startDt = date('Y-m-d', strtotime($from . ' -1 day')) . ' 00:00:00';
    $endDt   = date('Y-m-d', strtotime($to . ' +2 day')) . ' 00:00:00';

    // 1) time_punches
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

    // 2) break_punches を break_in/out として混ぜる（環境差異に耐える）
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
            $be = $r['break_end_at']; // null あり
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
        // break_punches が無い/列違いでも壊さない
    }

    // 3) 走査して work/break を計算（営業日キーで日別集計）
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
            // 既に open があるなら切り捨て（退勤なし）
            $openIn = $tsCalc;
            $openBreak = null;
            continue;
        }
        if ($type === 'break_in') {
            // 出勤がある前提で休憩開始（なければ無視）
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

    // 4) 実働（work-break）にして、期間内だけ返す
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

/**
 * ✅ 1従業員×期間の給与を集計して pay_slips を更新
 */
function recomputeSlip(PDO $pdo, int $tenantId, int $storeId, int $employeeId, string $from, string $to, int $slipId): array
{
    // 従業員の時給・税区分（✅ 基本フォールバック値として取得）
    $empCols = tableColumns($pdo, 'employees');
    $selectStd = in_array('standard_monthly_remuneration', $empCols, true) ? "standard_monthly_remuneration" : "0 AS standard_monthly_remuneration";
    $selectHealth = in_array('health_ins_enrolled', $empCols, true) ? "health_ins_enrolled" : "0 AS health_ins_enrolled";
    $selectPension = in_array('pension_enrolled', $empCols, true) ? "pension_enrolled" : "0 AS pension_enrolled";
    $selectBirth = in_array('birth_date', $empCols, true) ? "birth_date" : "NULL AS birth_date";
    $selectNightEnabled = in_array('night_premium_enabled', $empCols, true) ? "night_premium_enabled" : "0 AS night_premium_enabled";
    $selectNightRate = in_array('night_premium_rate_percent', $empCols, true) ? "night_premium_rate_percent" : "25 AS night_premium_rate_percent";
    $emp = $pdo->prepare("
        SELECT id, hourly_wage_yen, tax_type,
               {$selectStd},
               {$selectHealth},
               {$selectPension},
               {$selectBirth},
               {$selectNightEnabled},
               {$selectNightRate}
        FROM employees
        WHERE tenant_id=:tid AND store_id=:sid AND id=:eid
        LIMIT 1
    ");
    $emp->execute([':tid' => $tenantId, ':sid' => $storeId, ':eid' => $employeeId]);
    $e = $emp->fetch();
    if (!$e) {
        throw new RuntimeException("employee not found: {$employeeId}");
    }
    $defaultHourly = (int)($e['hourly_wage_yen'] ?? 0);
    $taxType = (string)($e['tax_type'] ?? 'ko');
    $nightPremiumEnabled = ((int)($e['night_premium_enabled'] ?? 0) === 1);
    $nightPremiumRate = (int)($e['night_premium_rate_percent'] ?? 25);
    if (!in_array($nightPremiumRate, [25, 30, 35, 40, 45, 50], true)) $nightPremiumRate = 25;

    // 店舗の打刻調整・営業日切替
    $storeCols = tableColumns($pdo, 'stores');
    $hasCutoff = in_array('business_day_cutoff_time', $storeCols, true);
    $hasRound  = in_array('payroll_round_unit_minutes', $storeCols, true);
    $hasHealthRate = in_array('health_ins_rate', $storeCols, true);
    $hasCareRate = in_array('care_ins_rate', $storeCols, true);
    $hasPensionRate = in_array('pension_rate', $storeCols, true);
    $hasEmploymentRate = in_array('employment_ins_rate', $storeCols, true);
    $hasChildcareRate = in_array('childcare_support_rate', $storeCols, true);
    $hasInsuranceRounding = in_array('insurance_rounding', $storeCols, true);
    $hasPrefectureCode = in_array('prefecture_code', $storeCols, true);
    $hasEmploymentBusinessType = in_array('employment_insurance_business_type', $storeCols, true);

    $selectCutoff = $hasCutoff ? "COALESCE(business_day_cutoff_time,'00:00:00') AS cutoff" : "'00:00:00' AS cutoff";
    $selectRound  = $hasRound ? "COALESCE(payroll_round_unit_minutes,0) AS round_unit" : "0 AS round_unit";
    $selectHealthRate = $hasHealthRate ? "COALESCE(health_ins_rate,0) AS health_ins_rate" : "0 AS health_ins_rate";
    $selectCareRate = $hasCareRate ? "COALESCE(care_ins_rate,0) AS care_ins_rate" : "0 AS care_ins_rate";
    $selectPensionRate = $hasPensionRate ? "COALESCE(pension_rate,0) AS pension_rate" : "0 AS pension_rate";
    $selectEmploymentRate = $hasEmploymentRate ? "COALESCE(employment_ins_rate,0) AS employment_ins_rate" : "0 AS employment_ins_rate";
    $selectChildcareRate = $hasChildcareRate ? "COALESCE(childcare_support_rate,0) AS childcare_support_rate" : "0 AS childcare_support_rate";
    $selectInsuranceRounding = $hasInsuranceRounding ? "COALESCE(insurance_rounding,'floor') AS insurance_rounding" : "'floor' AS insurance_rounding";
    $selectPrefectureCode = $hasPrefectureCode ? "prefecture_code" : "NULL AS prefecture_code";
    $selectEmploymentBusinessType = $hasEmploymentBusinessType ? "COALESCE(employment_insurance_business_type,'general') AS employment_insurance_business_type" : "'general' AS employment_insurance_business_type";

    $st = $pdo->prepare("
        SELECT tenant_id, {$selectCutoff}, {$selectRound},
               {$selectHealthRate}, {$selectCareRate}, {$selectPensionRate}, {$selectEmploymentRate}, {$selectChildcareRate},
               {$selectPrefectureCode}, {$selectEmploymentBusinessType},
               {$selectInsuranceRounding}
        FROM stores
        WHERE tenant_id=:tid AND id=:sid
        LIMIT 1
    ");
    $st->execute([':tid' => $tenantId, ':sid' => $storeId]);
    $s = $st->fetch() ?: ['cutoff' => '00:00:00', 'round_unit' => 0];

    $roundUnit = (int)($s['round_unit'] ?? 0);
    $cutoffSeconds = cutoffToSeconds((string)($s['cutoff'] ?? '00:00:00'));

    // 日別 実働秒（キーは business_date）
    $dailyNetSec = buildDailyNetSecondsForEmployee($pdo, $tenantId, $storeId, $employeeId, $from, $to, $roundUnit, $cutoffSeconds, false);
    // 深夜割増用の実働秒（キーは business_date）
    $dailyNightNetSec = buildDailyNetSecondsForEmployee(
        $pdo,
        $tenantId,
        $storeId,
        $employeeId,
        $from,
        $to,
        $roundUnit,
        $cutoffSeconds,
        true
    );

    // ✅ 追加：日別の時給（履歴）を作る（履歴が無ければ空配列→フォールバック）
    $hourlyByDay = buildDailyHourlyWageMap($pdo, $tenantId, $storeId, $employeeId, $from, $to, $defaultHourly);

    // bonus/back（あなたの time_punch_daily.php と同じテーブル）
    $bonusStmt = $pdo->prepare("
        SELECT business_date, SUM(bonus_yen) AS bonus_yen
        FROM daily_wage_adjustments
        WHERE tenant_id=:tid
          AND employee_id=:eid
          AND business_date >= :fromd
          AND business_date <= :tod
        GROUP BY business_date
    ");
    $bonusStmt->execute([':tid' => $tenantId, ':eid' => $employeeId, ':fromd' => $from, ':tod' => $to]);
    $bonusMap = [];
    foreach ($bonusStmt->fetchAll() as $r) {
        $bonusMap[(string)$r['business_date']] = (int)($r['bonus_yen'] ?? 0);
    }

    $backStmt = $pdo->prepare("
        SELECT business_date, SUM(amount_yen) AS cashback_yen
        FROM back_events
        WHERE tenant_id=:tid
          AND store_id=:sid
          AND employee_id=:eid
          AND status='confirmed'
          AND business_date >= :fromd
          AND business_date <= :tod
        GROUP BY business_date
    ");
    $backStmt->execute([':tid' => $tenantId, ':sid' => $storeId, ':eid' => $employeeId, ':fromd' => $from, ':tod' => $to]);
    $backMap = [];
    foreach ($backStmt->fetchAll() as $r) {
        $backMap[(string)$r['business_date']] = (int)($r['cashback_yen'] ?? 0);
    }

    // 総支給（= 日給の合計）
    $gross = 0;
    $nightPremiumYen = 0;
    foreach ($dailyNetSec as $day => $sec) {
        // ✅ ここが本対応：その日の時給（履歴）を使用。無ければ employees の現在時給。
        $hourly = (int)($hourlyByDay[$day] ?? $defaultHourly);

        $base = (int)round(($sec / 3600) * $hourly);
        $bonus = (int)($bonusMap[$day] ?? 0);
        $back  = (int)($backMap[$day] ?? 0);
        $nightSec = (int)($dailyNightNetSec[$day] ?? 0);
        $nightAdd = 0;
        if ($nightPremiumEnabled && $nightSec > 0) {
            $nightMinutes = (int)floor($nightSec / 60);
            if ($nightMinutes > 0) {
                $nightAdd = (int)round($nightMinutes * ($hourly / 60.0) * ($nightPremiumRate / 100.0));
            }
        }
        $nightPremiumYen += $nightAdd;
        $gross += ($base + $nightAdd + $bonus + $back);
    }

    // 非課税（交通費など）は今は 0 のまま（運用が決まったら pay_slips に積む）
    $nonTaxable = 0;
    $taxable = max(0, $gross - $nonTaxable);

    // 源泉（税額表が入っていれば当たる / 入ってなければ0）
    $withholding = 0;

    // tax_overridden を見て、0なら自動計算
    $slip = $pdo->prepare("SELECT id, tax_overridden, pay_cycle FROM pay_slips WHERE tenant_id=:tid AND id=:sid LIMIT 1");
    $slip->execute([':tid' => $tenantId, ':sid' => $slipId]);
    $ps = $slip->fetch();
    if (!$ps) throw new RuntimeException("pay_slips not found: {$slipId}");
    $taxOverridden = (int)($ps['tax_overridden'] ?? 0);

    if ($taxOverridden === 0 && $taxable > 0) {
        // version_label はあなたが作った v2026_01 を採用（必要なら切替）
        $q = $pdo->prepare("
            SELECT tr.tax_yen
            FROM tax_withholding_tables tt
            JOIN tax_withholding_rows tr ON tr.table_id = tt.id
            WHERE tt.pay_cycle = :pay_cycle
              AND tt.tax_type  = :tax_type
              AND tt.version_label = :v
              AND :taxable >= tr.lower_yen
              AND (tr.upper_yen IS NULL OR :taxable <= tr.upper_yen)
            LIMIT 1
        ");
        $q->execute([
            ':pay_cycle' => (string)($ps['pay_cycle'] ?? 'daily'),
            ':tax_type'  => $taxType,
            ':v'         => 'v2026_01',
            ':taxable'   => $taxable,
        ]);
        $withholding = (int)($q->fetchColumn() ?: 0);
    }

    $ins = si_calc($e, $s, $taxable, $to, $pdo);
    $deductTotal = $withholding + (int)$ins['total'];
    $net = max(0, $gross - $deductTotal);

    // pay_slips 反映
    $upd = $pdo->prepare("
        UPDATE pay_slips
        SET gross_pay=:g,
            night_premium_yen=:night_premium_yen,
            non_taxable_total=:nt,
            taxable_pay=:tp,
            withholding_tax=:wt,
            health_insurance_yen=:health,
            care_insurance_yen=:care,
            pension_yen=:pension,
            employment_insurance_yen=:employment,
            childcare_support_yen=:childcare_support,
            health_ins_rate=:health_rate,
            care_ins_rate=:care_rate,
            pension_rate=:pension_rate,
            employment_ins_rate=:employment_rate,
            childcare_support_rate=:childcare_support_rate,
            insurance_rounding=:insurance_rounding,
            net_pay=:np,
            updated_at=:now
        WHERE tenant_id=:tid AND id=:id
    ");
    $upd->execute([
        ':g' => $gross,
        ':night_premium_yen' => $nightPremiumYen,
        ':nt' => $nonTaxable,
        ':tp' => $taxable,
        ':wt' => $withholding,
        ':health' => (int)$ins['health'],
        ':care' => (int)$ins['care'],
        ':pension' => (int)$ins['pension'],
        ':employment' => (int)$ins['employment'],
        ':childcare_support' => (int)$ins['childcare_support'],
        ':health_rate' => (float)$ins['health_rate'],
        ':care_rate' => (float)$ins['care_rate'],
        ':pension_rate' => (float)$ins['pension_rate'],
        ':employment_rate' => (float)$ins['employment_rate'],
        ':childcare_support_rate' => (float)$ins['childcare_support_rate'],
        ':insurance_rounding' => (string)$ins['rounding'],
        ':np' => $net,
        ':now' => date('Y-m-d H:i:s'),
        ':tid' => $tenantId,
        ':id' => $slipId,
    ]);

    return [
        'gross' => $gross,
        'non_taxable' => $nonTaxable,
        'taxable' => $taxable,
        'withholding' => $withholding,
        'net' => $net,
        'tax_overridden' => $taxOverridden,
        'days' => count($dailyNetSec),
        'insurance' => $ins,
    ];
}

// =========================
// input (GET)
// =========================
$storeId = (int)($_GET['store_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);

$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-d');

$from = (string)($_GET['from'] ?? $defaultFrom);
$to   = (string)($_GET['to']   ?? $defaultTo);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = $defaultTo;
if ($from > $to) [$from, $to] = [$to, $from];

$backUrl = (string)($_GET['back_url'] ?? '/admin/time_punch_daily.php');

// CSRF（簡易）
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

$msg = '';

// =========================
// マスタ読み込み：stores / employees
// =========================
$storesStmt = $pdo->prepare("
    SELECT id, name
    FROM stores
    WHERE tenant_id = :tid
    ORDER BY id ASC
");
$storesStmt->execute([':tid' => $tenantId]);
$stores = $storesStmt->fetchAll();

if (empty($stores)) {
    throw new RuntimeException("stores がありません。tenant_id={$tenantId}");
}

$validStoreIds = array_map(fn($r) => (int)$r['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $validStoreIds, true)) {
    $storeId = (int)$stores[0]['id'];
}

$empStmt = $pdo->prepare("
    SELECT id, display_name
    FROM employees
    WHERE tenant_id = :tid AND store_id = :sid
    ORDER BY sort_order ASC, id ASC
");
$empStmt->execute([':tid' => $tenantId, ':sid' => $storeId]);
$employees = $empStmt->fetchAll();

$validEmpIds = array_map(fn($r) => (int)$r['id'], $employees);
if ($employeeId <= 0 || !in_array($employeeId, $validEmpIds, true)) {
    $employeeId = 0;
}

// =========================
// POST：明細作成（または既存再利用）→ 集計 → PDFへ遷移
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_slip') {
        $csrfPost = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrf, $csrfPost)) {
            throw new RuntimeException('CSRFエラー');
        }

        $pStoreId = (int)($_POST['store_id'] ?? 0);
        $pEmpId   = (int)($_POST['employee_id'] ?? 0);
        $pFrom    = (string)($_POST['from'] ?? '');
        $pTo      = (string)($_POST['to'] ?? '');

        if (!in_array($pStoreId, $validStoreIds, true)) {
            throw new RuntimeException('店舗が不正です');
        }
        if (!in_array($pEmpId, $validEmpIds, true)) {
            throw new RuntimeException('従業員が不正です');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pTo)) {
            throw new RuntimeException('期間が不正です');
        }
        if ($pFrom > $pTo) [$pFrom, $pTo] = [$pTo, $pFrom];

        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            // 1) pay_periods を “同一期間で既存優先” で取得（列差異に耐える）
            $ppCols = tableColumns($pdo, 'pay_periods');

            $where = ['tenant_id = :tid'];
            $params = [':tid' => $tenantId];

            if (in_array('store_id', $ppCols, true)) {
                $where[] = 'store_id = :sid';
                $params[':sid'] = $pStoreId;
            }

            if (in_array('from_date', $ppCols, true) && in_array('to_date', $ppCols, true)) {
                $where[] = 'from_date = :fromd';
                $where[] = 'to_date = :tod';
                $params[':fromd'] = $pFrom;
                $params[':tod']   = $pTo;
            } elseif (in_array('start_date', $ppCols, true) && in_array('end_date', $ppCols, true)) {
                $where[] = 'start_date = :fromd';
                $where[] = 'end_date = :tod';
                $params[':fromd'] = $pFrom;
                $params[':tod']   = $pTo;
            }

            $ppId = 0;
            $ppFindSql = "SELECT id FROM pay_periods WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 1";
            $ppPick = $pdo->prepare($ppFindSql);
            $ppPick->execute($params);
            $ppId = (int)($ppPick->fetchColumn() ?: 0);

            if ($ppId <= 0) {
                $ppId = safeInsertReturnId($pdo, 'pay_periods', [
                    'tenant_id'  => $tenantId,
                    'store_id'   => $pStoreId,
                    'from_date'  => $pFrom,
                    'to_date'    => $pTo,
                    'start_date' => $pFrom,
                    'end_date'   => $pTo,
                    'pay_cycle'  => 'daily',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 2) uq_slip（同一従業員×同一期間）は “既存再利用”
            $findSlip = $pdo->prepare("
                SELECT id
                FROM pay_slips
                WHERE tenant_id = :tid
                  AND employee_id = :eid
                  AND pay_period_id = :ppid
                LIMIT 1
            ");
            $findSlip->execute([
                ':tid'  => $tenantId,
                ':eid'  => $pEmpId,
                ':ppid' => $ppId,
            ]);
            $slipId = (int)($findSlip->fetchColumn() ?: 0);

            if ($slipId <= 0) {
                $slipId = safeInsertReturnId($pdo, 'pay_slips', [
                    'tenant_id'         => $tenantId,
                    'pay_period_id'     => $ppId,
                    'employee_id'       => $pEmpId,
                    'pay_cycle'         => 'daily',
                    'gross_pay'         => 0,
                    'non_taxable_total' => 0,
                    'taxable_pay'       => 0,
                    'withholding_tax'   => 0,
                    'net_pay'           => 0,
                    'payment_date'      => date('Y-m-d'),
                    'tax_overridden'    => 0,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
                $msg = "pay_slips を作成しました（slip_id={$slipId}）";
            } else {
                $msg = "既存の pay_slips を再利用します（slip_id={$slipId}）";
            }

            // ✅ 3) ここが今回の本丸：勤怠から集計して pay_slips を更新
            $sum = recomputeSlip($pdo, $tenantId, $pStoreId, $pEmpId, $pFrom, $pTo, $slipId);

            $pdo->commit();

            // PDFへ
            header('Location: /admin/pay_slip_pdf.php?slip_id=' . $slipId);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

// =========================
// 表示（フォーム）
// =========================
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>給与明細 作成</title>
    <style>
    body {
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans JP", sans-serif;
        margin: 0;
        background: #fff;
        color: #111;
    }

    .wrap {
        padding: 16px 18px;
    }

    h1 {
        margin: 0 0 12px;
        font-size: 18px;
        font-weight: 800;
    }

    .box {
        border: 1px solid #e5e5e5;
        padding: 12px;
        margin: 12px 0;
    }

    .row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
    }

    label {
        font-weight: 700;
        color: #333;
    }

    select,
    input {
        height: 34px;
        padding: 0 10px;
        border: 1px solid #ddd;
        border-radius: 0;
    }

    .btn {
        height: 34px;
        padding: 0 14px;
        border: 1px solid #111;
        background: #111;
        color: #fff;
        font-weight: 800;
        cursor: pointer;
    }

    .btnSub {
        height: 34px;
        padding: 0 14px;
        border: 1px solid #ddd;
        background: #f3f3f3;
        color: #111;
        font-weight: 800;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

    .muted {
        color: #666;
        font-weight: 600;
        font-size: 12px;
    }
    </style>
</head>

<body>
    <?php if (is_file(__DIR__ . '/_header.php')) require_once __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <h1>給与明細 作成（従業員×期間）</h1>

        <div class="box">
            <form method="get" class="row">
                <label>店舗</label>
                <select name="store_id" onchange="this.form.submit()">
                    <?php foreach ($stores as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= ((int)$st['id'] === $storeId) ? 'selected' : '' ?>>
                        <?= h((string)$st['name']) ?> (<?= (int)$st['id'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>

                <label>従業員</label>
                <select name="employee_id">
                    <option value="0" <?= $employeeId === 0 ? 'selected' : '' ?>>選択してください</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?= (int)$e['id'] ?>" <?= ((int)$e['id'] === $employeeId) ? 'selected' : '' ?>>
                        <?= h((string)$e['display_name']) ?> (<?= (int)$e['id'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>

                <label>from</label>
                <input type="date" name="from" value="<?= h($from) ?>">
                <label>to</label>
                <input type="date" name="to" value="<?= h($to) ?>">

                <input type="hidden" name="back_url" value="<?= h($backUrl) ?>">

                <button class="btnSub" type="submit">更新</button>
                <a class="btnSub" href="<?= h($backUrl) ?>">戻る</a>
            </form>

            <div class="muted" style="margin-top:10px;">
                ・「作成してPDFへ」を押すと、勤怠→日給→総支給を集計して pay_slips に反映してからPDFへ遷移します。<br>
                ・源泉徴収は tax_overridden=1 の場合は触りません（手入力優先）。<br>
                ・税額表（tax_withholding_rows）が未投入の table_id だと源泉は 0 のままです（給与は表示されます）。
            </div>
        </div>

        <div class="box">
            <form method="post" class="row">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="create_slip">
                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
                <input type="hidden" name="from" value="<?= h($from) ?>">
                <input type="hidden" name="to" value="<?= h($to) ?>">

                <button class="btn" type="submit" <?= ($employeeId <= 0 ? 'disabled' : '') ?>>
                    作成してPDFへ
                </button>

                <?php if ($employeeId <= 0): ?>
                <div class="muted">※ 従業員を選択してください</div>
                <?php endif; ?>
            </form>
        </div>

    </div>
</body>

</html>
