<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/index.php
 * ✅ 書き込み場所: 既存の /admin/index.php を「丸ごと置き換え」
 *
 * ✅ 今回の修正（あなたの要望）
 * - 「直近30日」固定だった一覧を、プルダウンで切り替え可能にする
 *   1) 一番上：直近30日
 *   2) その下：過去12ヶ月（YYYY年MM度）
 * - KPI/テーブル/チャートの期間を、選択した期間に揃える
 * - 15分打刻調整（round15）と store_id も維持
 *
 * ✅ 既存の仕様維持
 * - 売上/客数は daily_store_reports の reported_at がある場合のみ cutoff で表示日補正
 * - created_at/updated_at は dt_key に使わない（深夜保存で行がズレる原因）
 * - AI 用の従業員サマリーは「直近30日固定」のまま（影響範囲を最小化）
 *
 * ✅ 今回の追加（あなたの依頼）
 * - 「AIに改善案を聞く」ボタンとチャットの見た目だけを “小学生でも分かる” デザインに変更
 * - 機能（JS/POST/取得ロジック）は一切変更しない
 *
 * ✅ 今回の追加（UIのみ）
 * - 画面をタブ分け（ダッシュボード / 日別一覧 / AI）
 * - 用語（各項目の意味）を見ながらチャットできるよう、各タブに「用語」パネル（折りたたみ）を追加
 * - タブ状態を localStorage に保存（UIのみ）
 */

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_business_day.php';

require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

// ===== DB =====
$paths = [
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
    echo "500 Internal Server Error\n\n";
    echo "db.php not found. tried:\n" . implode("\n", $paths);
    exit;
}
require_once $dbFile;
require_once __DIR__ . '/../lib/store_expenses.php';
require_once __DIR__ . '/../lib/admin_theme.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
store_expenses_ensure_schema($pdo);
$adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);
$adminTheme = admin_theme_current($pdo, $adminUserId);
$adminThemeBodyClass = admin_theme_body_class($adminTheme);
$adminThemeChartPalette = admin_theme_chart_palette((string)$adminTheme['accent']);
$adminThemeChartPalette['axis'] = ($adminTheme['mode'] === 'dark') ? 'rgba(226,232,240,0.66)' : 'rgba(17,17,17,0.55)';
$adminThemeChartPalette['legend'] = ($adminTheme['mode'] === 'dark') ? 'rgba(226,232,240,0.78)' : 'rgba(17,17,17,0.75)';
$adminThemeChartPalette['grid'] = ($adminTheme['mode'] === 'dark') ? 'rgba(148,163,184,0.16)' : 'rgba(0,0,0,0.06)';
$adminThemeChartPalette['axisLine'] = ($adminTheme['mode'] === 'dark') ? 'rgba(148,163,184,0.20)' : 'rgba(0,0,0,0.10)';
$adminThemeChartPalette['bg0'] = ($adminTheme['mode'] === 'dark') ? '#0b1020' : '#ffffff';
$adminThemeChartPalette['bg1'] = ($adminTheme['mode'] === 'dark') ? '#111827' : '#fbfbff';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $st->execute([':col' => $column]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function date_with_weekday_html(string $ymd): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    if (!$dt) return h($ymd);

    $weekJa = ['日', '月', '火', '水', '木', '金', '土'];
    $w = (int)$dt->format('w'); // 0=日, 6=土

    $class = '';
    if ($w === 0) $class = 'sun';
    if ($w === 6) $class = 'sat';

    return sprintf(
        '<span class="date %s">%s（%s）</span>',
        $class,
        h($dt->format('Y-m-d')),
        $weekJa[$w]
    );
}

function labor_color_from_thresholds(float $rate, float $greenMax, float $yellowMax): array
{
    if ($yellowMax < $greenMax) $yellowMax = $greenMax;
    if ($rate <= $greenMax) return ['green', 'success'];
    if ($rate <= $yellowMax) return ['yellow', 'warning'];
    return ['red', 'danger'];
}

// =========================================================
// tenant timezone
// =========================================================
$tz = 'Asia/Tokyo';
try {
    $tzStmt = $pdo->prepare("SELECT timezone FROM tenants WHERE id = :tenant_id");
    $tzStmt->execute([':tenant_id' => $tenantId]);
    $tzDb = (string)($tzStmt->fetchColumn() ?: '');
    if ($tzDb !== '') $tz = $tzDb;
} catch (Throwable $e) {
    $tz = 'Asia/Tokyo';
}
date_default_timezone_set($tz);

$now   = new DateTimeImmutable('now', new DateTimeZone($tz));
$today = $now->format('Y-m-d');

// =========================================================
// stores
// =========================================================
$storesStmt = $pdo->prepare("
    SELECT id, name
    FROM stores
    WHERE tenant_id = :tenant_id
    ORDER BY id ASC
");
$storesStmt->execute([':tenant_id' => $tenantId]);
$stores = $storesStmt->fetchAll();

if (empty($stores)) {
    echo '店舗がありません';
    exit;
}

$storeId = (int)($_GET['store_id'] ?? $stores[0]['id']);
$storeIds = array_map('intval', array_column($stores, 'id'));
if (!in_array($storeId, $storeIds, true)) {
    $storeId = (int)$stores[0]['id'];
}

/* =========================================================
   ✅ business day cutoff（店舗設定）
   ========================================================= */
$cutoffTime = '05:00:00'; // デフォルト
try {
    if (has_column($pdo, 'stores', 'business_day_cutoff_time')) {
        $st = $pdo->prepare("
            SELECT business_day_cutoff_time
            FROM stores
            WHERE tenant_id = :t AND id = :s
            LIMIT 1
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId]);
        $v = (string)($st->fetchColumn() ?: '');
        if ($v !== '') {
            if (preg_match('/^\d{2}:\d{2}$/', $v)) $v .= ':00';
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $v)) $cutoffTime = $v;
        }
    }
} catch (Throwable $e) {
    // 失敗してもデフォルトで継続
}

/**
 * ✅ 日時から business_date を計算（cutoff 対応）
 * 例：cutoff=05:00:00 なら 00:00〜04:59 は前日扱い
 */
function business_date_from_dt(string $dtStr, string $tz, string $cutoffTime): ?string
{
    try {
        $dt = new DateTimeImmutable($dtStr, new DateTimeZone($tz));
    } catch (Throwable $e) {
        return null;
    }

    $cut = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $dt->format('Y-m-d') . ' ' . $cutoffTime,
        new DateTimeZone($tz)
    );
    if (!$cut) return $dt->format('Y-m-d');

    if ($dt < $cut) {
        return $dt->modify('-1 day')->format('Y-m-d');
    }
    return $dt->format('Y-m-d');
}

$businessToday = business_date_from_dt($now->format('Y-m-d H:i:s'), $tz, $cutoffTime) ?: $today;
$businessNow = DateTimeImmutable::createFromFormat('Y-m-d', $businessToday, new DateTimeZone($tz));
if (!$businessNow) {
    $businessNow = new DateTimeImmutable($businessToday, new DateTimeZone($tz));
}

// ✅ 打刻調整スイッチ：人件費だけに適用
// ✅ ただし「大元の打刻調整がOFF」の場合は、UIも出さず、強制OFFにする
function detect_round_feature_enabled(PDO $pdo, int $tenantId, int $storeId): bool
{
    $candidates = [
        'labor_round15_enabled',
        'round15_enabled',
        'rounding_enabled',
        'labor_rounding_enabled',
        'enable_round15',
        'is_round15_enabled',
    ];

    $foundCol = null;
    foreach ($candidates as $col) {
        if (has_column($pdo, 'stores', $col)) {
            $foundCol = $col;
            break;
        }
    }

    if ($foundCol === null) return true;

    try {
        $st = $pdo->prepare("
            SELECT COALESCE(`{$foundCol}`, 1) AS v
            FROM stores
            WHERE tenant_id = :t AND id = :s
            LIMIT 1
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId]);
        $v = $st->fetchColumn();
        return ((int)$v === 1);
    } catch (Throwable $e) {
        return true;
    }
}

$roundFeatureEnabled = detect_round_feature_enabled($pdo, $tenantId, $storeId);
$roundOn = ($roundFeatureEnabled && ((int)($_GET['round15'] ?? 1) === 1));

// =========================================================
// ✅ 表示期間（プルダウン）
// - period=30d（デフォルト）: 直近30日
// - period=ym:YYYY-MM       : 指定月（過去12ヶ月）
// =========================================================
$periodParam = (string)($_GET['period'] ?? '30d');

// 選択肢（先頭=直近30日、以下=過去12ヶ月）
$periodOptions = [];
$periodOptions[] = ['value' => '30d', 'label' => '直近30日'];
$periodOptions[] = ['value' => '90d', 'label' => '過去90日'];
$periodOptions[] = ['value' => '180d', 'label' => '過去180日'];
$periodOptions[] = ['value' => '365d', 'label' => '過去1年'];

for ($i = 0; $i < 12; $i++) {
    $dt = $now->modify("-{$i} month");
    $ym = $dt->format('Y-m');
    $label = $dt->format('Y年m月') . '度';
    $periodOptions[] = ['value' => "ym:{$ym}", 'label' => $label];
}

// 妥当化（未知の値は 30d）
$validValues = array_column($periodOptions, 'value');
if (!in_array($periodParam, $validValues, true)) {
    $periodParam = '30d';
}

$periodStart = '';
$periodEnd   = '';
$periodTitle = '';

if ($periodParam === '30d' || $periodParam === '90d' || $periodParam === '180d' || $periodParam === '365d') {
    $days = (int)str_replace('d', '', $periodParam);
    $days = ($days > 0) ? $days : 30;
    $periodStart = $businessNow->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $periodEnd   = $businessToday;
    $periodTitle = ($periodParam === '30d') ? '直近30日'
        : (($periodParam === '90d') ? '過去90日'
        : (($periodParam === '180d') ? '過去180日' : '過去1年'));
} else {
    $ym = substr($periodParam, 3); // "ym:YYYY-MM" -> YYYY-MM
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = $now->format('Y-m');

    $dtFirst = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01', new DateTimeZone($tz));
    if (!$dtFirst) $dtFirst = new DateTimeImmutable('first day of this month', new DateTimeZone($tz));

    $periodStart = $dtFirst->format('Y-m-d');
    $periodEnd   = $dtFirst->modify('last day of this month')->format('Y-m-d');
    if ($dtFirst->format('Y-m') === substr($businessToday, 0, 7) && $periodEnd > $businessToday) {
        $periodEnd = $businessToday;
    }
    $periodTitle = $dtFirst->format('Y年m月') . '度';
}

// =========================================================
// labor_mvp.php
// =========================================================
$laborMvpPath = __DIR__ . '/../api/lib/labor_mvp.php';
if (!is_file($laborMvpPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n\n";
    echo "labor_mvp.php not found: {$laborMvpPath}\n";
    exit;
}
require_once $laborMvpPath;

/**
 * ✅ 売上/客数 map（表示用）
 * - 基本は保存済み daily_store_reports.business_date を表示に使う
 * - ただし旧データ互換として、reported_at があればそれを優先
 * - reported_at が無い環境では created_at を見て「cutoff 前に自動保存された誤日付」を表示時だけ補正
 */
function sales_map_with_customers(PDO $pdo, int $tenantId, int $storeId, string $startYmd, string $endYmd, string $tz, string $cutoffTime): array
{
    $hasVisitors   = has_column($pdo, 'daily_store_reports', 'visitors');
    $hasConfirmed  = has_column($pdo, 'daily_store_reports', 'sales_confirmed');
    $hasReportedAt = has_column($pdo, 'daily_store_reports', 'reported_at');
    $hasCreatedAt  = has_column($pdo, 'daily_store_reports', 'created_at');

    $selectVisitors = $hasVisitors ? ", COALESCE(visitors,0) AS customers" : ", NULL AS customers";
    $confirmSelect = $hasConfirmed ? ", COALESCE(sales_confirmed,0) AS sales_confirmed" : ", 0 AS sales_confirmed";
    $reportedAtSelect = $hasReportedAt ? ", reported_at" : ", NULL AS reported_at";
    $createdAtSelect = $hasCreatedAt ? ", created_at" : ", NULL AS created_at";

    $st = $pdo->prepare("
        SELECT business_date, COALESCE(sales_yen,0) AS sales_yen {$selectVisitors} {$confirmSelect} {$reportedAtSelect} {$createdAtSelect}
        FROM daily_store_reports
        WHERE tenant_id=:t AND store_id=:s
          AND business_date BETWEEN DATE_SUB(:start, INTERVAL 1 DAY) AND DATE_ADD(:end, INTERVAL 1 DAY)
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':start' => $startYmd, ':end' => $endYmd]);

    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $d = (string)$r['business_date'];
        $reportedAt = (string)($r['reported_at'] ?? '');
        $createdAt = (string)($r['created_at'] ?? '');
        if ($reportedAt !== '') {
            $corrected = business_date_from_dt($reportedAt, $tz, $cutoffTime);
            if ($corrected !== null && $corrected !== '') {
                $d = $corrected;
            }
        } elseif ($createdAt !== '') {
            $corrected = business_date_from_dt($createdAt, $tz, $cutoffTime);
            $createdCalendarDay = substr($createdAt, 0, 10);
            if (
                $corrected !== null && $corrected !== '' &&
                $corrected !== $createdCalendarDay &&
                $d === $createdCalendarDay
            ) {
                $d = $corrected;
            }
        }
        if ($d < $startYmd || $d > $endYmd) continue;

        if (!isset($map[$d])) {
            $map[$d] = [
                'sales'     => 0,
                'customers' => null,
                'confirmed' => 0,
            ];
        }
        $map[$d]['sales'] += (int)$r['sales_yen'];
        if (isset($r['customers']) && $r['customers'] !== null) {
            $map[$d]['customers'] = (int)($map[$d]['customers'] ?? 0) + (int)$r['customers'];
        }
        $map[$d]['confirmed'] = max((int)$map[$d]['confirmed'], (int)($r['sales_confirmed'] ?? 0));
    }
    return $map;
}

function attendance_map_range(PDO $pdo, int $tenantId, int $storeId, string $startYmd, string $endYmd): array
{
    $table = 'time_punches';
    if (!table_exists($pdo, $table)) return [];

    $hasWorkDate  = has_column($pdo, $table, 'work_date');
    $hasStoreId   = has_column($pdo, $table, 'store_id');
    $hasTenantId  = has_column($pdo, $table, 'tenant_id');
    $hasClockIn   = has_column($pdo, $table, 'clock_in_at');
    $hasPunchedAt = has_column($pdo, $table, 'punched_at');

    if (!$hasWorkDate) return [];
    $timeCol = $hasClockIn ? 'clock_in_at' : ($hasPunchedAt ? 'punched_at' : null);
    if ($timeCol === null) return [];

    $where = [];
    $params = [];

    if ($hasTenantId) {
        $where[] = "tenant_id = :t";
        $params[':t'] = $tenantId;
    }
    if ($hasStoreId) {
        $where[] = "store_id = :s";
        $params[':s'] = $storeId;
    }

    $where[] = "work_date BETWEEN :start AND :end";
    $params[':start'] = $startYmd;
    $params[':end']   = $endYmd;

    $where[] = "`{$timeCol}` IS NOT NULL";

    $sql = "
        SELECT work_date, COUNT(*) AS cnt
        FROM `{$table}`
        WHERE " . implode(' AND ', $where) . "
        GROUP BY work_date
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $d = (string)$r['work_date'];
        $map[$d] = ((int)$r['cnt'] > 0);
    }
    return $map;
}

function employee_attendance_summary(PDO $pdo, int $tenantId, int $storeId, string $startYmd, string $endYmd): array
{
    $punchTable = 'time_punches';
    if (!table_exists($pdo, $punchTable)) return [];

    $hasEmployeeId = has_column($pdo, $punchTable, 'employee_id');
    $hasWorkDate   = has_column($pdo, $punchTable, 'work_date');
    if (!$hasEmployeeId || !$hasWorkDate) return [];

    $hasTenantId = has_column($pdo, $punchTable, 'tenant_id');
    $hasStoreId  = has_column($pdo, $punchTable, 'store_id');

    $hasClockIn   = has_column($pdo, $punchTable, 'clock_in_at');
    $hasPunchedAt = has_column($pdo, $punchTable, 'punched_at');
    $timeCol = $hasClockIn ? 'clock_in_at' : ($hasPunchedAt ? 'punched_at' : null);
    if ($timeCol === null) return [];

    $empTable = table_exists($pdo, 'employees') ? 'employees' : null;
    $empNameCol = null;
    if ($empTable) {
        if (has_column($pdo, $empTable, 'name')) $empNameCol = 'name';
        elseif (has_column($pdo, $empTable, 'full_name')) $empNameCol = 'full_name';
        elseif (has_column($pdo, $empTable, 'nickname')) $empNameCol = 'nickname';
    }

    $where = [];
    $params = [
        ':start' => $startYmd,
        ':end'   => $endYmd,
        ':t'     => $tenantId,
        ':s'     => $storeId,
    ];

    if ($hasTenantId) $where[] = "p.tenant_id = :t";
    if ($hasStoreId)  $where[] = "p.store_id = :s";

    $where[] = "p.work_date BETWEEN :start AND :end";
    $where[] = "p.`{$timeCol}` IS NOT NULL";

    if ($empTable && $empNameCol) {
        $sql = "
            SELECT
                p.employee_id,
                COALESCE(e.`{$empNameCol}`, CONCAT('ID:', p.employee_id)) AS employee_name,
                COUNT(DISTINCT p.work_date) AS attend_days
            FROM `{$punchTable}` p
            LEFT JOIN `{$empTable}` e
              ON e.id = p.employee_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY p.employee_id, employee_name
            ORDER BY attend_days DESC, p.employee_id ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
    } else {
        $sql = "
            SELECT
                p.employee_id,
                CONCAT('ID:', p.employee_id) AS employee_name,
                COUNT(DISTINCT p.work_date) AS attend_days
            FROM `{$punchTable}` p
            WHERE " . implode(' AND ', $where) . "
            GROUP BY p.employee_id
            ORDER BY attend_days DESC, p.employee_id ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
    }

    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'employee_id'   => (int)$r['employee_id'],
            'employee_name' => (string)$r['employee_name'],
            'attend_days'   => (int)$r['attend_days'],
        ];
    }
    return $out;
}

function ymd_range(string $startYmd, string $endYmd, string $tz): array
{
    $dates = [];
    $dt  = new DateTimeImmutable($startYmd, new DateTimeZone($tz));
    $end = new DateTimeImmutable($endYmd, new DateTimeZone($tz));
    for ($i = 0; $i < 400; $i++) {
        $d = $dt->format('Y-m-d');
        $dates[] = $d;
        if ($d === $end->format('Y-m-d')) break;
        $dt = $dt->modify('+1 day');
    }
    return $dates;
}

/**
 * ✅ 売上/人件費/営業日 に加えて、来客数も合計する版
 * - 定休日（売上0＆人件費0）→除外
 * - 異常日（売上0＆人件費>0）→除外（売上未入力疑い）
 */
function monthly_metrics_excluding_holidays(array $dates, array $salesMap, array $laborMap): array
{
    $salesSum = 0;
    $laborSum = 0;
    $customersSum = 0;
    $customersDays = 0;
    $openDays = 0;

    $holidayDays = [];
    $anomalyDays = [];

    foreach ($dates as $d) {
        $s = (int)(($salesMap[$d]['sales'] ?? 0));
        $l = (int)($laborMap[$d] ?? 0);
        $c = $salesMap[$d]['customers'] ?? null;
        $confirmed = (int)($salesMap[$d]['confirmed'] ?? 0) === 1;

        $isHoliday = ($s <= 0 && $l <= 0);
        if ($isHoliday) {
            $holidayDays[] = $d;
            continue;
        }

        $isAnomaly = ($s <= 0 && $l > 0 && !$confirmed);
        if ($isAnomaly) {
            $anomalyDays[] = $d;
            continue;
        }

        $salesSum += $s;
        $laborSum += $l;
        $openDays++;

        if (is_int($c)) {
            $customersSum += $c;
            $customersDays++;
        }
    }

    $rate = ($salesSum > 0) ? ($laborSum / $salesSum * 100.0) : 0.0;

    return [
        'sales_sum'        => $salesSum,
        'labor_sum'        => $laborSum,
        'customers_sum'    => $customersSum,
        'customers_days'   => $customersDays,
        'rate'             => $rate,
        'open_days'        => $openDays,
        'holiday_days'     => $holidayDays,
        'anomaly_days'     => $anomalyDays,
    ];
}

function sales_map_has_customers(array $salesMap): bool
{
    foreach ($salesMap as $row) {
        if (is_array($row) && array_key_exists('customers', $row) && is_int($row['customers'])) {
            return true;
        }
    }
    return false;
}

function sales_customer_totals_for_dates(array $dates, array $salesMap, bool $customersAvailable): array
{
    $sales = 0;
    $customers = $customersAvailable ? 0 : null;

    foreach ($dates as $d) {
        $sales += (int)($salesMap[$d]['sales'] ?? 0);
        if ($customersAvailable) {
            $customers += (int)($salesMap[$d]['customers'] ?? 0);
        }
    }

    return [
        'sales' => $sales,
        'customers' => $customers,
    ];
}

function kpi_delta_class(?int $delta): string
{
    if ($delta === null || $delta === 0) return 'isFlat';
    return ($delta > 0) ? 'isUp' : 'isDown';
}

function kpi_delta_text(?int $delta, string $unit): string
{
    if ($delta === null) return '-';
    $prefix = ($delta > 0) ? '+' : (($delta < 0) ? '-' : '±');
    return $prefix . number_format(abs($delta)) . $unit;
}

// =========================================================
// ✅ KPI：表示期間（$periodStart〜$periodEnd）で算出
// =========================================================
$datesPeriod = ymd_range($periodStart, $periodEnd, $tz);

$salesMapPeriod   = sales_map_with_customers($pdo, $tenantId, $storeId, $periodStart, $periodEnd, $tz, $cutoffTime);
$laborDailyDetail = mvp_daily_labor_detail($pdo, $tenantId, $storeId, $periodStart, $periodEnd, $roundOn);
$laborDailyPeriod = $laborDailyDetail['total'] ?? [];
$laborNightPeriod = $laborDailyDetail['night_premium'] ?? [];

$mm = monthly_metrics_excluding_holidays($datesPeriod, $salesMapPeriod, $laborDailyPeriod);

$salesMonth = (int)$mm['sales_sum']; // 既存UI変数名を流用
$laborMonth = (int)$mm['labor_sum'];
$laborNightMonth = array_sum(array_map('intval', $laborNightPeriod));
$customersMonth = (int)$mm['customers_sum'];
$customersDaysMonth = (int)$mm['customers_days'];

$rate       = (float)$mm['rate'];
$openDaysMonth = (int)$mm['open_days'];
$holidayDaysMonth = $mm['holiday_days'];
$anomalyDaysMonth = $mm['anomaly_days'];

$salesAvgPerDay = ($openDaysMonth > 0) ? (int)round($salesMonth / $openDaysMonth) : 0;
$laborAvgPerDay = ($openDaysMonth > 0) ? (int)round($laborMonth / $openDaysMonth) : 0;

$customersAvgPerDay = ($customersDaysMonth > 0) ? (int)round($customersMonth / $customersDaysMonth) : null;
$avgTicketMonth = ($customersMonth > 0 && $salesMonth > 0) ? (int)floor($salesMonth / $customersMonth) : null;

$compareEndDt = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd, new DateTimeZone($tz));
if (!$compareEndDt) {
    $compareEndDt = new DateTimeImmutable($periodEnd, new DateTimeZone($tz));
}
$compareDate = $compareEndDt->format('Y-m-d');
$comparePrevDay = $compareEndDt->modify('-1 day')->format('Y-m-d');
$compareMonthStartDt = $compareEndDt->modify('first day of this month');
$compareMonthStart = $compareMonthStartDt->format('Y-m-d');

$comparePrevMonthStartDt = $compareEndDt->modify('first day of previous month');
$comparePrevMonthLastDt = $comparePrevMonthStartDt->modify('last day of this month');
$compareDayOffset = max(0, (int)$compareEndDt->format('j') - 1);
$comparePrevMonthEndDt = $comparePrevMonthStartDt->modify('+' . $compareDayOffset . ' days');
if ($comparePrevMonthEndDt > $comparePrevMonthLastDt) {
    $comparePrevMonthEndDt = $comparePrevMonthLastDt;
}
$comparePrevMonthStart = $comparePrevMonthStartDt->format('Y-m-d');
$comparePrevMonthEnd = $comparePrevMonthEndDt->format('Y-m-d');

$salesCompareMap = sales_map_with_customers($pdo, $tenantId, $storeId, $comparePrevMonthStart, $compareDate, $tz, $cutoffTime);
$customersCompareAvailable = ($customersDaysMonth > 0 || sales_map_has_customers($salesCompareMap));

$salesPrevDayDiff = (int)($salesCompareMap[$compareDate]['sales'] ?? 0) - (int)($salesCompareMap[$comparePrevDay]['sales'] ?? 0);
$customersPrevDayDiff = $customersCompareAvailable
    ? ((int)($salesCompareMap[$compareDate]['customers'] ?? 0) - (int)($salesCompareMap[$comparePrevDay]['customers'] ?? 0))
    : null;

$compareCurrentTotals = sales_customer_totals_for_dates(ymd_range($compareMonthStart, $compareDate, $tz), $salesCompareMap, $customersCompareAvailable);
$comparePrevMonthTotals = sales_customer_totals_for_dates(ymd_range($comparePrevMonthStart, $comparePrevMonthEnd, $tz), $salesCompareMap, $customersCompareAvailable);

$salesPrevMonthDiff = (int)$compareCurrentTotals['sales'] - (int)$comparePrevMonthTotals['sales'];
$customersPrevMonthDiff = $customersCompareAvailable
    ? ((int)$compareCurrentTotals['customers'] - (int)$comparePrevMonthTotals['customers'])
    : null;

$expenseSummary = store_expenses_summary($pdo, $tenantId, $storeId, $periodStart, $periodEnd);
$expenseHasSettings = (bool)($expenseSummary['has_settings'] ?? false);
$fixedExpenseMonth = (int)($expenseSummary['fixed_total'] ?? 0);
$monthlyExpenseMonth = (int)($expenseSummary['monthly_total'] ?? 0);
$expenseMonth = (int)($expenseSummary['total'] ?? 0);
$expenseFixedItems = is_array($expenseSummary['fixed_items'] ?? null) ? $expenseSummary['fixed_items'] : [];
$expenseMonthlyItems = is_array($expenseSummary['monthly_items'] ?? null) ? $expenseSummary['monthly_items'] : [];
$estimatedProfit = $salesMonth - $laborMonth - $expenseMonth;
$estimatedProfitRate = ($salesMonth > 0) ? ($estimatedProfit / $salesMonth * 100.0) : null;

// stores のしきい値
$greenMax = 30.0;
$yellowMax = 35.0;
try {
    $st = $pdo->prepare("
        SELECT
            COALESCE(labor_green_max_rate, 30.00)  AS g,
            COALESCE(labor_yellow_max_rate, 35.00) AS y
        FROM stores
        WHERE tenant_id = :tenant_id AND id = :store_id
        LIMIT 1
    ");
    $st->execute([':tenant_id' => $tenantId, ':store_id' => $storeId]);
    $row = $st->fetch();
    if ($row) {
        $greenMax  = (float)$row['g'];
        $yellowMax = (float)$row['y'];
    }
} catch (Throwable $e) {
}

[$colorKey, $badge] = labor_color_from_thresholds($rate, $greenMax, $yellowMax);

// =========================================================
// ✅ 表/チャート：表示期間（$periodStart〜$periodEnd）で算出
// =========================================================
$salesMap30   = sales_map_with_customers($pdo, $tenantId, $storeId, $periodStart, $periodEnd, $tz, $cutoffTime);
$laborMap30   = $laborDailyPeriod;
$laborNightMap30 = $laborNightPeriod;
$attendMap30  = attendance_map_range($pdo, $tenantId, $storeId, $periodStart, $periodEnd);

$dates30 = ymd_range($periodStart, $periodEnd, $tz);
$dates30Desc = $dates30;
rsort($dates30Desc);

// =========================================================
// ✅ AI用：従業員サマリーは「直近30日固定」
// =========================================================
$employeeSummary30 = [];
$aiFrom = $businessNow->modify('-29 days')->format('Y-m-d');
$aiTo   = $businessToday;
try {
    $employeeSummary30 = employee_attendance_summary($pdo, $tenantId, $storeId, $aiFrom, $aiTo);
} catch (Throwable $e) {
    $employeeSummary30 = [];
}

// =========================================================
// ラベル
// =========================================================
$labelMap = [
    'green'   => '適正',
    'yellow'  => '注意',
    'red'     => '要改善',
    'holiday' => '定休日',
    'anom'    => '売上未入力?',
    'noatt'   => '出勤なし',
    'zero'    => '売上0（確定）',
];

$noteMap = [
    'green'  => '目標範囲内',
    'yellow' => '人件費率が上昇傾向（シフト・売上を確認）',
    'red'    => '基準超過（早めに調整が必要）',
];

function badge_label(string $key, array $labelMap): string
{
    return $labelMap[$key] ?? '不明';
}
function badge_note(string $key, array $noteMap): string
{
    return $noteMap[$key] ?? '';
}

function svg_line_chart_30days(array $dates, array $salesMap, array $laborMap, array $palette): string
{
    $axisText = (string)($palette['axis'] ?? 'rgba(17,17,17,0.55)');
    $legendText = (string)($palette['legend'] ?? 'rgba(17,17,17,0.75)');
    $gridColor = (string)($palette['grid'] ?? 'rgba(0,0,0,0.06)');
    $axisLineColor = (string)($palette['axisLine'] ?? 'rgba(0,0,0,0.10)');
    $bg0 = (string)($palette['bg0'] ?? '#ffffff');
    $bg1 = (string)($palette['bg1'] ?? '#fbfbff');
    $sales = [];
    $labor = [];
    $rate  = [];

    $maxYen  = 0;
    $maxRate = 0.0;

    foreach ($dates as $d) {
        $s = (int)(($salesMap[$d]['sales'] ?? 0));
        $l = (int)($laborMap[$d] ?? 0);
        $r = ($s > 0) ? ($l / $s * 100.0) : 0.0;

        $sales[$d] = $s;
        $labor[$d] = $l;
        $rate[$d]  = $r;

        $maxYen  = max($maxYen, $s, $l);
        $maxRate = max($maxRate, $r);
    }

    $maxYen  = (int)max(1, (int)ceil($maxYen * 1.1));
    $maxRate = (float)max(10.0, ceil($maxRate * 1.1));

    $w = 1100;
    $h = 280;
    $padL = 56;
    $padR = 56;
    $padT = 18;
    $padB = 52;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;

    $n = max(1, count($dates));
    $stepX = ($n <= 1) ? 0 : ($plotW / ($n - 1));

    $xOf = fn(int $i): float => $padL + $i * $stepX;

    $yOfYen  = fn(int $v): float  => $padT + (1.0 - ($v / $maxYen)) * $plotH;
    $yOfRate = fn(float $v): float => $padT + (1.0 - ($v / $maxRate)) * $plotH;

    $pointsSales = [];
    $pointsLabor = [];
    $pointsRate  = [];

    $i = 0;
    foreach ($dates as $d) {
        $x = $xOf($i);
        $pointsSales[] = sprintf('%.1f,%.1f', $x, $yOfYen($sales[$d]));
        $pointsLabor[] = sprintf('%.1f,%.1f', $x, $yOfYen($labor[$d]));
        $pointsRate[]  = sprintf('%.1f,%.1f', $x, $yOfRate($rate[$d]));
        $i++;
    }

    $tickEvery = ($n <= 10) ? 1 : (($n <= 20) ? 2 : (($n <= 60) ? 5 : (int)ceil($n / 12)));
    if ($tickEvery < 1) $tickEvery = 1;

    $gridLines = '';
    for ($g = 0; $g <= 5; $g++) {
        $yy = $padT + ($plotH / 5) * $g;
        $gridLines .= '<line x1="' . $padL . '" y1="' . sprintf('%.1f', $yy) . '" x2="' . ($w - $padR) . '" y2="' . sprintf('%.1f', $yy) . '" stroke="' . $gridColor . '" stroke-width="1" />';
    }

    $leftTicks = [0, (int)round($maxYen / 2), $maxYen];
    $leftLabels = '';
    foreach ($leftTicks as $v) {
        $yy = $yOfYen($v);
        $leftLabels .= '<text x="' . ($padL - 10) . '" y="' . sprintf('%.1f', $yy + 4) . '" text-anchor="end" font-size="11" fill="' . $axisText . '">' . number_format($v) . '</text>';
    }

    $rightTicks = [0.0, round($maxRate / 2, 0), $maxRate];
    $rightLabels = '';
    foreach ($rightTicks as $v) {
        $yy = $yOfRate((float)$v);
        $rightLabels .= '<text x="' . ($w - $padR + 10) . '" y="' . sprintf('%.1f', $yy + 4) . '" text-anchor="start" font-size="11" fill="' . $axisText . '">' . number_format((float)$v, 0) . '%</text>';
    }

    $xLabels = '';
    $i = 0;
    foreach ($dates as $d) {
        if ($i % $tickEvery === 0 || $i === $n - 1) {
            $x = $xOf($i);
            $label = substr($d, 5);
            $xLabels .= '<text x="' . sprintf('%.1f', $x) . '" y="' . ($h - 18) . '" text-anchor="middle" font-size="11" fill="' . $axisText . '">' . $label . '</text>';
        }
        $i++;
    }

    $colSales = (string)($palette['sales'] ?? '#A855F7');
    $colLabor = (string)($palette['labor'] ?? '#FB7185');
    $colRate  = (string)($palette['muted'] ?? '#A855F7');

    $dots = '';
    $i = 0;
    foreach ($dates as $d) {
        $x = $xOf($i);
        $dots .= '<circle cx="' . sprintf('%.1f', $x) . '" cy="' . sprintf('%.1f', $yOfYen($sales[$d])) . '" r="2.8" fill="' . $colSales . '" opacity="0.85" />';
        $dots .= '<circle cx="' . sprintf('%.1f', $x) . '" cy="' . sprintf('%.1f', $yOfYen($labor[$d])) . '" r="2.8" fill="' . $colLabor . '" opacity="0.85" />';
        $dots .= '<circle cx="' . sprintf('%.1f', $x) . '" cy="' . sprintf('%.1f', $yOfRate($rate[$d])) . '" r="2.8" fill="' . $colRate  . '" opacity="0.85" />';
        $i++;
    }

    $legend = '
      <g font-size="12" fill="#111">
        <rect x="0" y="0" width="14" height="4" rx="2" fill="' . $colSales . '" /><text x="20" y="5" fill="' . $legendText . '">売上(円)</text>
        <rect x="96" y="0" width="14" height="4" rx="2" fill="' . $colLabor . '" /><text x="116" y="5" fill="' . $legendText . '">人件費(円)</text>
        <rect x="210" y="0" width="14" height="4" rx="2" fill="' . $colRate  . '" /><text x="230" y="5" fill="' . $legendText . '">人件費率(%)</text>
      </g>';

    return '
<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="auto" role="img" aria-label="売上・人件費・率の推移">
  <defs>
    <linearGradient id="bgGrad" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="' . $bg0 . '"/>
      <stop offset="100%" stop-color="' . $bg1 . '"/>
    </linearGradient>
    <filter id="softShadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="6" stdDeviation="8" flood-color="rgba(0,0,0,0.10)"/>
    </filter>
  </defs>

  <g transform="translate(' . $padL . ',12)">' . $legend . '</g>

  ' . $gridLines . '

  <line x1="' . $padL . '" y1="' . $padT . '" x2="' . $padL . '" y2="' . ($h - $padB) . '" stroke="' . $axisLineColor . '" />
  <line x1="' . ($w - $padR) . '" y1="' . $padT . '" x2="' . ($w - $padR) . '" y2="' . ($h - $padB) . '" stroke="' . $axisLineColor . '" />

  ' . $leftLabels . '
  ' . $rightLabels . '
  ' . $xLabels . '

  <polyline fill="none" stroke="' . $colSales . '" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.8" points="' . implode(' ', $pointsSales) . '" />
  <polyline fill="none" stroke="' . $colLabor . '" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.8" points="' . implode(' ', $pointsLabor) . '" />
  <polyline fill="none" stroke="' . $colRate  . '" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.8" points="' . implode(' ', $pointsRate)  . '" />

  ' . $dots . '
</svg>';
}

function svg_rate_chart_30days(array $dates, array $salesMap, array $laborMap, float $greenMax, float $yellowMax, array $palette): string
{
    $axisText = (string)($palette['axis'] ?? 'rgba(17,17,17,0.55)');
    $gridColor = (string)($palette['grid'] ?? 'rgba(0,0,0,0.06)');
    $axisLineColor = (string)($palette['axisLine'] ?? 'rgba(0,0,0,0.10)');
    $rate = [];
    $maxRate = 0.0;

    foreach ($dates as $d) {
        $s = (int)(($salesMap[$d]['sales'] ?? 0));
        $l = (int)($laborMap[$d] ?? 0);
        $r = ($s > 0) ? ($l / $s * 100.0) : 0.0;
        $rate[$d] = $r;
        $maxRate = max($maxRate, $r, $yellowMax, $greenMax);
    }

    $maxRate = (float)max(10.0, ceil($maxRate * 1.1));

    $w = 1100;
    $h = 260;
    $padL = 56;
    $padR = 56;
    $padT = 18;
    $padB = 48;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;

    $n = max(1, count($dates));
    $stepX = ($n <= 1) ? 0 : ($plotW / ($n - 1));
    $xOf = fn(int $i): float => $padL + $i * $stepX;
    $yOfRate = fn(float $v): float => $padT + (1.0 - ($v / $maxRate)) * $plotH;

    $pointsRate = [];
    $i = 0;
    foreach ($dates as $d) {
        $x = $xOf($i);
        $pointsRate[] = sprintf('%.1f,%.1f', $x, $yOfRate($rate[$d]));
        $i++;
    }

    $tickEvery = ($n <= 10) ? 1 : (($n <= 20) ? 2 : (($n <= 60) ? 5 : (int)ceil($n / 12)));
    if ($tickEvery < 1) $tickEvery = 1;

    $gridLines = '';
    for ($g = 0; $g <= 5; $g++) {
        $yy = $padT + ($plotH / 5) * $g;
        $gridLines .= '<line x1="' . $padL . '" y1="' . sprintf('%.1f', $yy) . '" x2="' . ($w - $padR) . '" y2="' . sprintf('%.1f', $yy) . '" stroke="' . $gridColor . '" stroke-width="1" />';
    }

    $rightTicks = [0.0, round($maxRate / 2, 0), $maxRate];
    $rightLabels = '';
    foreach ($rightTicks as $v) {
        $yy = $yOfRate((float)$v);
        $rightLabels .= '<text x="' . ($w - $padR + 10) . '" y="' . sprintf('%.1f', $yy + 4) . '" text-anchor="start" font-size="11" fill="' . $axisText . '">' . number_format((float)$v, 0) . '%</text>';
    }

    $xLabels = '';
    $i = 0;
    foreach ($dates as $d) {
        if ($i % $tickEvery === 0 || $i === $n - 1) {
            $x = $xOf($i);
            $label = substr($d, 5);
            $xLabels .= '<text x="' . sprintf('%.1f', $x) . '" y="' . ($h - 16) . '" text-anchor="middle" font-size="11" fill="' . $axisText . '">' . $label . '</text>';
        }
        $i++;
    }

    $colRate = (string)($palette['accent'] ?? '#A855F7');
    $colWarn = (string)($palette['warn'] ?? '#FACC15');
    $colDanger = (string)($palette['danger'] ?? '#FB4D6D');

    $dots = '';
    $i = 0;
    foreach ($dates as $d) {
        $x = $xOf($i);
        $r = (float)$rate[$d];
        $col = ($r > $yellowMax) ? $colDanger : (($r > $greenMax) ? $colWarn : $colRate);
        $size = ($r > $yellowMax) ? 3.6 : 2.6;
        $dots .= '<circle cx="' . sprintf('%.1f', $x) . '" cy="' . sprintf('%.1f', $yOfRate($r)) . '" r="' . $size . '" fill="' . $col . '" opacity="0.9" />';
        $i++;
    }

    $lineGreen = $yOfRate($greenMax);
    $lineYellow = $yOfRate($yellowMax);

    return '
<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="auto" role="img" aria-label="人件費率の推移">
  ' . $gridLines . '
  <line x1="' . $padL . '" y1="' . $padT . '" x2="' . $padL . '" y2="' . ($h - $padB) . '" stroke="' . $axisLineColor . '" />
  <line x1="' . ($w - $padR) . '" y1="' . $padT . '" x2="' . ($w - $padR) . '" y2="' . ($h - $padB) . '" stroke="' . $axisLineColor . '" />

  <line x1="' . $padL . '" y1="' . sprintf('%.1f', $lineGreen) . '" x2="' . ($w - $padR) . '" y2="' . sprintf('%.1f', $lineGreen) . '" stroke="rgba(34,197,94,0.5)" stroke-dasharray="4,4" />
  <line x1="' . $padL . '" y1="' . sprintf('%.1f', $lineYellow) . '" x2="' . ($w - $padR) . '" y2="' . sprintf('%.1f', $lineYellow) . '" stroke="rgba(251,146,60,0.6)" stroke-dasharray="4,4" />

  ' . $rightLabels . '
  ' . $xLabels . '

  <polyline fill="none" stroke="' . $colRate . '" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4" points="' . implode(' ', $pointsRate) . '" />
  ' . $dots . '
</svg>';
}

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>管理画面 TOP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
    :root {
        --bg: #f6f7fb;
        --card: #ffffff;
        --text: #111;
        --muted: rgba(17, 17, 17, .58);
        --line: rgba(0, 0, 0, .10);
        --radius: 16px;
        --radius2: 14px;
        --blue: #365EAB;
        --violet: #7c3aed;
        --black: #111;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        font-family: system-ui, -apple-system, sans-serif;
        background: #fff;
        color: #1f2937;
    }

    /* ページ全体 */
    .page {
        padding: 14px;
        padding-bottom: 4px;
    }

    /* 上：タイトル行 */
    .topRow {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin: 0 0 10px;
    }

    .titleBlock {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 240px;
    }

    .pageTitle {
        font-size: 18px;
        font-weight: 1000;
        letter-spacing: .02em;
        margin: 0;
    }

    .pageSub {
        font-size: 14px;
        font-weight: 900;
        align-items: center;
        justify-content: center;
        color: var(--muted);
    }

    /* タブバー */
    .tabsBar {
        position: sticky;
        top: 0;
        z-index: 20;
        background: rgba(246, 247, 251, .86);
        backdrop-filter: blur(8px);
        margin-top: -6px;
        padding: 10px 0;
        border-bottom: none;
    }

    .tabs {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    .tabBtn {
        appearance: none;
        border: 1px solid var(--line);
        background: #fff;
        color: #111;
        padding: 8px 12px;
        border-radius: 999px;
        font-weight: 1000;
        font-size: 12px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
        user-select: none;
    }

    .tabBtn[aria-selected="true"] {
        background: var(--black);
        color: #fff;
        border-color: rgba(0, 0, 0, .14);
    }

    .tabBtn:active {
        transform: translateY(1px);
    }

    /* レイアウト：メイン + 用語(サイド) */
    .grid {
        display: grid;
        grid-template-columns: 1fr 360px;
        gap: 14px;
        align-items: start;
        margin-top: 14px;
    }

    @media (max-width: 1100px) {
        .grid {
            grid-template-columns: 1fr;
        }
    }

    /* カード */
    .card {
        background: var(--card);
        border: 1px solid rgba(0, 0, 0, .08);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .tableCard {
        overflow: visible;
    }

    .cardInner {
        padding: 14px;
    }

    .cardHead {
        padding: 12px 14px;
        border-bottom: 1px solid rgba(0, 0, 0, .06);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        background: linear-gradient(180deg, #fff, #fbfbff);
    }

    .cardHeadTitle {
        font-weight: 1000;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .pageLead {
        margin-top: 6px;
        font-size: 12px;
        font-weight: 900;
        color: var(--muted);
    }

    .flowHint {
        font-size: 12px;
        font-weight: 900;
        color: rgba(17, 17, 17, .62);
        margin: 4px 0 10px;
    }

    .filterLabel {
        font-size: 12px;
        font-weight: 900;
        color: rgba(17, 17, 17, .62);
    }

    .chartToggle {
        display: inline-flex;
        gap: 6px;
        padding: 6px 8px;
        border-radius: 999px;
        border: 1px solid rgba(0, 0, 0, .08);
        background: #fff;
    }

    .chartBtn {
        border: 0;
        background: transparent;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        color: var(--text);
        cursor: pointer;
    }

    .chartBtn.active {
        background: #111827;
        color: #fff;
    }

    .chartPanel.is-hidden {
        display: none;
    }

    .rightCol {
        display: grid;
        gap: 14px;
    }

    .actionCardBody {
        padding: 12px 14px 14px;
        display: grid;
        gap: 10px;
        font-size: 12px;
        font-weight: 900;
        color: rgba(17, 17, 17, .72);
    }

    .actionBtn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid rgba(0, 0, 0, .14);
        background: #111827;
        color: #fff;
        font-size: 12px;
        font-weight: 900;
        text-decoration: none;
    }

    .actionBtn[aria-disabled=\"true\"] {
        background: #e5e7eb;
        color: #9ca3af;
        border-color: #e5e7eb;
        pointer-events: none;
    }

    .pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 1000;
        border: 1px solid rgba(0, 0, 0, .10);
        color: rgba(17, 17, 17, .75);
        background: #fff;
        white-space: nowrap;
    }

    /* KPI */
    .kpiGrid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }

    @media (max-width: 1100px) {
        .kpiGrid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 650px) {
        .kpiGrid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 650px) {
        html,
        body {
            overflow-x: hidden;
        }

        .topRow {
            flex-direction: column;
            align-items: stretch;
        }

        .filters {
            width: 100%;
        }

        .pill {
            max-width: 100%;
            white-space: normal;
            word-break: break-word;
        }

        details.chartAcc summary {
            flex-wrap: wrap;
        }

        .tableWrap {
            max-width: 100%;
            touch-action: pan-x;
        }

        .table {
            width: 860px;
        }

        .tableWrap {
            display: none;
        }

        .dailyCards {
            display: grid;
            gap: 10px;
            padding: 8px 14px 14px;
        }
    }

    .kpiCard {
        border: 1px solid rgba(0, 0, 0, .08);
        border-radius: var(--radius2);
        padding: 14px;
        background: #fff;
    }

    .kpiLabel {
        font-size: 12px;
        font-weight: 1000;
        color: var(--muted);
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .kpiValue {
        font-size: clamp(18px, 2.6vw, 30px);
        font-weight: 900;
        line-height: 1.1;
        letter-spacing: .01em;
        font-variant-numeric: tabular-nums;
    }

    .kpiUnit {
        font-size: 12px;
        font-weight: 900;
        color: var(--muted);
        margin-left: 6px;
    }

    .kpiSub {
        margin-top: 8px;
        font-size: 12px;
        font-weight: 900;
        color: var(--muted);
        line-height: 1.4;
    }

    .kpiCompareStack {
        display: grid;
        gap: 8px;
        margin-top: 12px;
    }

    .kpiCompareRow {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-height: 44px;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid rgba(15, 23, 42, .08);
        background: rgba(248, 250, 252, .88);
        font-size: 13px;
        font-weight: 1000;
    }

    .kpiCompareLabel {
        color: var(--muted);
        white-space: nowrap;
    }

    .kpiCompareValue {
        color: #64748b;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
        font-size: 15px;
        line-height: 1;
    }

    .kpiCompareValue.isUp {
        color: #059669;
    }

    .kpiCompareValue.isDown {
        color: #dc2626;
    }

    .kpiCompareValue.isFlat {
        color: #64748b;
    }

    .kpiConditions {
        margin-top: 6px;
        font-size: 12px;
        font-weight: 900;
        color: #666;
    }

    .kpiDetail {
        margin-top: 8px;
    }

    .kpiDetailSummary {
        cursor: pointer;
        user-select: none;
        font-size: 11px;
        font-weight: 900;
        color: rgba(17, 17, 17, .6);
        list-style: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 2px 0;
    }

    .kpiDetailSummary::-webkit-details-marker {
        display: none;
    }

    .kpiDetailBody {
        margin-top: 6px;
        font-size: 12px;
        font-weight: 900;
        color: var(--muted);
        line-height: 1.4;
    }

    .kpiMainBadge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 28px;
        padding: 0 12px;
        border-radius: 999px;
        font-weight: 1000;
        font-size: 12px;
        border: 1px solid rgba(0, 0, 0, .10);
        white-space: nowrap;
    }

    .bg-success {
        background: #c8f7dc;
    }

    .bg-warning {
        background: #fff3c4;
    }

    .bg-danger {
        background: #ffd6d6;
    }

    .bg-muted {
        background: #e9e9e9;
        color: #666;
    }

    .bg-zero {
        background: #d0d0d0;
        color: #333;
    }

    .metaLine {
        margin-top: 10px;
        font-size: 11px;
        font-weight: 900;
        color: rgba(17, 17, 17, .52);
        line-height: 1.45;
    }

    .warnBox {
        margin-top: 10px;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid rgba(179, 38, 30, 0.25);
        background: rgba(255, 0, 0, 0.04);
        color: #8a1f1a;
        font-size: 12px;
        font-weight: 900;
        line-height: 1.45;
    }

    /* チャート */
    .chartWrap {
        padding: 14px;
    }

    details.chartAcc summary {
        list-style: none;
        cursor: pointer;
        user-select: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 12px 14px;
        border-bottom: 1px solid rgba(0, 0, 0, .06);
        background: #fff;
        font-weight: 1000;
    }

    details.chartAcc summary::-webkit-details-marker {
        display: none;
    }

    .chev {
        opacity: .65;
        font-weight: 1000;
    }

    details.chartAcc[open] .chev {
        transform: rotate(180deg);
    }

    /* フィルター（一覧タブ用） */
    .filtersBar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 12px 14px;
        border-bottom: 1px solid rgba(0, 0, 0, .06);
        background: #fff;
        flex-wrap: wrap;
    }

    .filtersBar h3 {
        margin: 0;
        font-size: 16px;
        font-weight: 1000;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .filters {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
        margin: 0;
    }

    .topRow .filters {
        margin-left: auto;
    }

    .kpiFilters {
        margin-left: auto;
    }

    .filters label {
        font-size: 12px;
        font-weight: 1000;
        color: var(--muted);
    }

    .filters select {
        padding: 9px 10px;
        border-radius: 12px;
        border: 1px solid rgba(0, 0, 0, .14);
        background: #fff;
        font-size: 12px;
        font-weight: 1000;
    }

    .filters button {
        padding: 9px 12px;
        height: 34px;
        border-radius: 12px;
        border: 0;
        background: #111;
        color: #fff;
        font-weight: 1000;
        cursor: pointer;
    }

    .filters button:active {
        transform: translateY(1px);
    }

    /* テーブル */
    .tableWrap {
        padding: 8px 14px 14px;
        overflow: visible;
    }

    .table {
        width: 100%;
        min-width: 720px;
        border-collapse: separate;
        border-spacing: 0;
        table-layout: fixed;
        border: 0;
        border-radius: 0;
        overflow: visible;
        background: transparent;
    }

    .table thead {
        position: sticky;
        top: 56px;
        z-index: 14;
        background: #fafafe;
    }

    .table th,
    .table td {
        border-bottom: 1px solid rgba(0, 0, 0, .06);
        padding: 10px 10px;
        vertical-align: middle;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 12px;
        font-weight: 900;
    }

    .table th.laborCol,
    .table td.laborCol {
        white-space: normal;
    }

    .table tbody tr.row-highlight {
        box-shadow: inset 0 0 0 1px #fecaca;
    }

    .table thead {
        position: sticky;
        top: 56px;
        z-index: 14;
    }

    .table thead th {
        background: #fafafe;
        font-size: 12px;
        font-weight: 1000;
        color: rgba(17, 17, 17, .72);
        position: sticky;
        top: 56px;
        z-index: 15;
    }

    .tableStickyWrap {
        position: fixed;
        display: none;
        z-index: 30;
        pointer-events: none;
    }

    .tableSticky {
        background: #fff;
        border: 1px solid rgba(0, 0, 0, .08);
        border-radius: 14px 14px 0 0;
        overflow: hidden;
        box-shadow: var(--shadow);
    }

    .table tbody tr:last-child td {
        border-bottom: 0;
    }

    .text-end {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }

    .deepNightSub {
        color: var(--muted);
    }

    .editTd {
        text-align: center;
    }

    .editLink {
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 42px;
        height: 34px;
        border-radius: 10px;
        border: 1px solid rgba(0, 0, 0, .12);
        background: #fff;
        font-size: 14px;
    }

    .editLink:hover {
        background: #f3f6ff;
        border-color: rgba(37, 99, 235, .28);
    }

    /* 日別カード（スマホ用） */
    .dailyCards {
        display: none;
    }

    .dailyCard {
        border: 1px solid rgba(0, 0, 0, .08);
        border-radius: 14px;
        padding: 12px;
        background: #fff;
    }

    .dailyCardHead {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
        font-weight: 1000;
        font-size: 12px;
    }

    .dailyRow {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 6px 0;
        font-size: 12px;
        font-weight: 900;
        border-bottom: 1px dashed rgba(0, 0, 0, .06);
    }

    .dailyRow:last-child {
        border-bottom: 0;
    }

    .dailyKey {
        color: var(--muted);
        font-weight: 1000;
    }

    @media (max-width: 650px) {
        .dailyCards {
            display: grid;
        }
    }

    /* 判定バッジ */
    .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 1000;
        line-height: 1;
        white-space: nowrap;
        border: 1px solid rgba(0, 0, 0, .10);
    }

    .judgeCell {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .judgeCell .badge {
        font-size: 10px;
        padding: 4px 6px;
    }

    /* 行の状態（色は控えめに“意味だけ伝える”） */
    .row-no-att td {
        color: rgba(17, 17, 17, .42) !important;
    }

    .row-sales-zero td {
        color: #1f6feb !important;
    }

    .row-anom td {
        color: #b3261e !important;
    }

    .date {
        font-weight: 1000;
    }

    .date.sat {
        color: #1f6feb;
    }

    .date.sun {
        color: #d1242f;
    }

    /* タブ本体 */
    .tabPanel {
        display: none;
    }

    .tabPanel.isActive {
        display: block;
    }

    /* 用語（右側） */
    .helpBox {
        position: sticky;
        top: 64px;
        border-radius: var(--radius);
        overflow: hidden;
    }

    @media (max-width:1100px) {
        .helpBox {
            position: static;
            top: auto;
        }
    }

    .helpBody {
        padding: 12px 14px 14px;
    }

    .helpBody details {
        border: 1px solid rgba(0, 0, 0, .08);
        border-radius: 14px;
        background: #fff;
        overflow: hidden;
        margin-bottom: 10px;
    }

    .helpBody summary {
        cursor: pointer;
        user-select: none;
        padding: 12px 12px;
        font-weight: 1000;
        font-size: 13px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        list-style: none;
        background: #fafafe;
        border-bottom: 1px solid rgba(0, 0, 0, .06);
    }

    .helpBody summary::-webkit-details-marker {
        display: none;
    }

    .helpList {
        margin: 0;
        padding: 10px 12px 12px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .helpItemTitle {
        font-size: 12px;
        font-weight: 1000;
        margin-bottom: 4px;
    }

    .helpItemText {
        font-size: 12px;
        font-weight: 900;
        color: rgba(17, 17, 17, .64);
        line-height: 1.5;
    }

    .helpTip {
        margin-top: 10px;
        padding: 10px 12px;
        border-radius: 14px;
        border: 1px solid rgba(37, 99, 235, .18);
        background: rgba(37, 99, 235, .06);
        font-size: 12px;
        font-weight: 1000;
        color: rgba(11, 42, 98, .88);
        line-height: 1.5;
    }

    /* =========================================================
     * ✅ AIボタン＆チャット（既存JSはそのまま / 見た目だけ）
     * ========================================================= */
    .aiCta {
        padding: 14px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .aiBtn {
        width: 100%;
        border: 0;
        cursor: pointer;
        background: var(--blue) !important;
        color: #fff;
        font-weight: 1000;
        letter-spacing: .02em;
        padding: 16px 18px;
        font-size: 18px;
        line-height: 1.2;
        border-radius: 16px !important;
        box-shadow: 0 10px 24px rgba(37, 99, 235, .18) !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .aiBtn:hover {
        filter: brightness(1.02);
        transform: translateY(-1px);
    }

    .aiBtn:active {
        transform: translateY(0px);
        filter: brightness(.98);
    }

    .aiBtnHint {
        font-size: 12px;
        font-weight: 1000;
        color: rgba(17, 17, 17, .56);
    }

    .aiBox {
        margin-top: 10px;
        border: 1px solid rgba(0, 0, 0, 0.10);
        background: #ffffff;
        border-radius: 18px !important;
        padding: 12px;
        box-shadow: 0 10px 26px rgba(0, 0, 0, .06);
    }

    .aiHeaderRow {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 10px;
        border-radius: 14px !important;
        background: #f6f8ff;
        border: 1px solid rgba(37, 99, 235, 0.15);
    }

    .aiHeaderTitle {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        font-weight: 1000;
        color: #111;
    }

    .aiHeaderBadge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 26px;
        padding: 0 10px;
        border-radius: 999px !important;
        background: var(--blue);
        color: #fff;
        font-weight: 1000;
        font-size: 12px;
        white-space: nowrap;
    }

    .spinner {
        width: 14px;
        height: 14px;
        border-radius: 999px !important;
        border: 2px solid rgba(0, 0, 0, 0.12);
        border-top-color: rgba(37, 99, 235, .95);
        animation: spin .8s linear infinite;
        display: none;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .aiInsight {
        margin-top: 10px;
        padding: 12px 12px;
        border-radius: 16px !important;
        background: #f7f5ff;
        border: 1px solid rgba(124, 58, 237, 0.18);
        color: #111;
        font-size: 15px;
        line-height: 1.75;
        letter-spacing: .02em;
        white-space: normal;
    }

    .aiChatLog {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 12px;
        padding-top: 8px;
        border-top: 1px dashed rgba(0, 0, 0, .10);
    }

    .aiTurn {
        border: 0 !important;
        background: transparent !important;
        overflow: visible !important;
    }

    .aiTurnHead {
        background: transparent !important;
        border: 0 !important;
        padding: 0 4px 6px !important;
        font-size: 11px !important;
        color: rgba(17, 17, 17, .55) !important;
        display: flex;
        gap: 8px;
        align-items: center;
        justify-content: space-between;
    }

    .aiTurnHead .tag {
        background: #111 !important;
        color: #fff !important;
        border-radius: 999px !important;
        padding: 3px 8px;
        font-weight: 1000;
        font-size: 11px;
    }

    .aiTurnQ {
        display: inline-flex !important;
        align-self: flex-end;
        max-width: min(680px, 92%);
        gap: 8px;
        padding: 10px 12px !important;
        border-radius: 16px !important;
        background: #e9f2ff;
        border: 1px solid rgba(37, 99, 235, 0.18);
        color: #0b2a62;
        font-weight: 1000;
        line-height: 1.5;
        white-space: normal !important;
        margin-left: auto;
        position: relative;
    }

    .aiTurnQ::after {
        content: "🙂";
        position: absolute;
        right: -36px;
        top: 8px;
        width: 26px;
        height: 26px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #f1f1f1;
        border: 1px solid rgba(0, 0, 0, .08);
        font-size: 14px;
    }

    .aiTurnA {
        margin-top: 8px;
        max-width: min(720px, 92%);
        padding: 10px 12px !important;
        border-radius: 16px !important;
        background: #f7f5ff;
        border: 1px solid rgba(124, 58, 237, 0.18);
        color: #111;
        line-height: 1.75;
        white-space: normal !important;
        position: relative;
        padding-left: 46px !important;
    }

    .aiTurnA::before {
        content: "🤖";
        position: absolute;
        left: 10px;
        top: 10px;
        width: 26px;
        height: 26px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #fff;
        border: 1px solid rgba(0, 0, 0, .08);
        font-size: 14px;
    }

    .aiAskRow {
        margin-top: 10px;
        display: flex;
        gap: 8px;
        align-items: stretch;
        flex-wrap: wrap;
    }

    .aiAskInput {
        flex: 1;
        min-width: 240px;
        padding: 12px 12px;
        border: 1px solid rgba(0, 0, 0, 0.14);
        border-radius: 14px !important;
        font-size: 14px;
        font-weight: 1000;
        outline: none;
        background: #fff;
        color: #111;
    }

    .aiAskInput::placeholder {
        color: rgba(17, 17, 17, .45);
        font-weight: 1000;
    }

    .aiAskSend {
        padding: 12px 14px;
        border-radius: 14px !important;
        border: 0;
        background: #111;
        color: #fff;
        font-weight: 1000;
        cursor: pointer;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .aiAskSend:disabled {
        opacity: .5;
        cursor: not-allowed;
    }

    .btnSpinner {
        width: 12px;
        height: 12px;
        border-radius: 999px !important;
        border: 2px solid rgba(255, 255, 255, .45);
        border-top-color: #fff;
        animation: spin .8s linear infinite;
        display: none;
    }

    .deepBtnsRow {
        margin-top: 10px;
        display: flex;
        gap: 10px;
        align-items: center;
        justify-content: flex-start;
        flex-wrap: wrap;
        padding: 10px;
        border-radius: 14px !important;
        background: #f7f7f7;
        border: 1px solid rgba(0, 0, 0, .08);
    }

    .deepBtnsLabel {
        font-size: 12px;
        font-weight: 1000;
        color: rgba(17, 17, 17, .60);
        white-space: nowrap;
    }

    .deepBtns {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .deepBtn {
        height: 36px;
        padding: 0 14px;
        border-radius: 12px !important;
        border: 1px solid rgba(0, 0, 0, .14);
        background: #fff;
        font-weight: 1000;
        cursor: pointer;
        white-space: nowrap;
    }

    .deepBtn:hover {
        background: #f3f6ff;
        border-color: rgba(37, 99, 235, .28);
    }

    .deepBtn:active {
        transform: translateY(1px);
    }

    .aiCloseRow {
        margin-top: 10px;
        display: flex;
        justify-content: flex-end;
    }

    .aiCloseBtn {
        padding: 10px 12px;
        border-radius: 12px !important;
        border: 1px solid rgba(0, 0, 0, .14);
        background: #fff;
        font-size: 12px;
        font-weight: 1000;
        cursor: pointer;
    }

    .aiCloseBtn:hover {
        background: #f3f3f3;
    }

    .adminTheme.themeAccentPurple { --accent:#7c3aed; --accent2:#9333ea; --accentGlow:rgba(124,58,237,.34); --accentSoft:rgba(124,58,237,.12); --accentBorder:rgba(167,139,250,.34); }
    .adminTheme.themeAccentBlue { --accent:#2563eb; --accent2:#0ea5e9; --accentGlow:rgba(37,99,235,.34); --accentSoft:rgba(37,99,235,.12); --accentBorder:rgba(96,165,250,.34); }
    .adminTheme.themeAccentGreen { --accent:#059669; --accent2:#10b981; --accentGlow:rgba(5,150,105,.34); --accentSoft:rgba(5,150,105,.12); --accentBorder:rgba(52,211,153,.34); }
    .adminTheme.themeAccentOrange { --accent:#ea580c; --accent2:#f59e0b; --accentGlow:rgba(234,88,12,.34); --accentSoft:rgba(234,88,12,.12); --accentBorder:rgba(251,146,60,.34); }
    .adminTheme.themeAccentRed { --accent:#dc2626; --accent2:#f43f5e; --accentGlow:rgba(220,38,38,.34); --accentSoft:rgba(220,38,38,.12); --accentBorder:rgba(248,113,113,.34); }
    .adminTheme.themeAccentPink { --accent:#db2777; --accent2:#ec4899; --accentGlow:rgba(219,39,119,.34); --accentSoft:rgba(219,39,119,.12); --accentBorder:rgba(244,114,182,.34); }
    .adminTheme.themeAccentGold { --accent:#b8860b; --accent2:#f5d06f; --accentGlow:rgba(212,175,55,.38); --accentSoft:rgba(212,175,55,.13); --accentBorder:rgba(245,208,111,.44); }
    .adminTheme.themeAccentSilver { --accent:#94a3b8; --accent2:#f8fafc; --accentGlow:rgba(226,232,240,.36); --accentSoft:rgba(226,232,240,.12); --accentBorder:rgba(248,250,252,.40); }
    .adminTheme.themeAccentCyan { --accent:#0891b2; --accent2:#06b6d4; --accentGlow:rgba(8,145,178,.34); --accentSoft:rgba(8,145,178,.12); --accentBorder:rgba(34,211,238,.34); }
    .adminTheme { --blue: var(--accent); --violet: var(--accent); --black: var(--accent); }

    body.adminHomeDark {
        --bg: #080b12;
        --card: rgba(15, 20, 31, .88);
        --text: #eef2ff;
        --muted: rgba(203, 213, 225, .72);
        --line: rgba(148, 163, 184, .18);
        --blue: var(--accent);
        --violet: var(--accent);
        --black: var(--accent);
        --shadow: 0 18px 48px rgba(0, 0, 0, .34);
        background:
            radial-gradient(760px 420px at 18% -10%, var(--accentSoft), transparent 62%),
            radial-gradient(760px 520px at 82% 8%, var(--accentSoft), transparent 62%),
            linear-gradient(180deg, #070a11 0%, #0b111d 48%, #070a11 100%);
        color: var(--text);
    }

    body.adminHomeDark .azHd {
        background: rgba(8, 11, 18, .88) !important;
        border-bottom-color: rgba(148, 163, 184, .16) !important;
        backdrop-filter: blur(14px);
    }

    body.adminHomeDark .azVLine,
    body.adminHomeDark .azNav .azVLine { background: rgba(148, 163, 184, .16) !important; }

    body.adminHomeDark .azLogo,
    body.adminHomeDark .azNav a,
    body.adminHomeDark .azNoticeBtn,
    body.adminHomeDark .azMenuBtn,
    body.adminHomeDark .azStore,
    body.adminHomeDark .azUserBtn,
    body.adminHomeDark .azUserBtn .azUserEmail { color: #e5e7eb !important; }

    body.adminHomeDark .azNav,
    body.adminHomeDark .azNav a,
    body.adminHomeDark .azNoticeBtn,
    body.adminHomeDark .azUserBtn { background: transparent !important; }

    body.adminHomeDark .azNav a:hover,
    body.adminHomeDark .azNoticeBtn:hover,
    body.adminHomeDark .azUserBtn:hover { background: rgba(148, 163, 184, .10) !important; }

    body.adminHomeDark .azNav a.is-active,
    body.adminHomeDark .tabBtn[aria-selected="true"],
    body.adminHomeDark .chartBtn.active,
    body.adminHomeDark .filters button,
    body.adminHomeDark .actionBtn,
    body.adminHomeDark .aiBtn,
    body.adminHomeDark .aiAskSend {
        background: linear-gradient(135deg, var(--accent), var(--accent2)) !important;
        color: #fff !important;
        border-color: var(--accentBorder) !important;
        box-shadow: 0 12px 30px var(--accentGlow) !important;
    }

    body.adminHomeDark .azStore select,
    body.adminHomeDark .azUserDropdown,
    body.adminHomeDark .azNoticeDropdown,
    body.adminHomeDark .tabBtn,
    body.adminHomeDark .pill,
    body.adminHomeDark .chartToggle,
    body.adminHomeDark .filters select,
    body.adminHomeDark .editLink,
    body.adminHomeDark .deepBtn,
    body.adminHomeDark .aiCloseBtn {
        background: rgba(15, 20, 31, .92) !important;
        border-color: rgba(148, 163, 184, .20) !important;
        color: rgba(226, 232, 240, .88) !important;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .04);
    }

    body.adminHomeDark .card,
    body.adminHomeDark .kpiCard,
    body.adminHomeDark .dailyCard,
    body.adminHomeDark .helpBody details,
    body.adminHomeDark .aiBox {
        background: linear-gradient(180deg, rgba(18, 24, 38, .92), rgba(11, 17, 29, .92));
        border-color: rgba(148, 163, 184, .18);
        box-shadow: var(--shadow);
    }

    body.adminHomeDark .cardHead,
    body.adminHomeDark .filtersBar,
    body.adminHomeDark details.chartAcc summary,
    body.adminHomeDark .helpBody summary,
    body.adminHomeDark .table thead,
    body.adminHomeDark .table thead th {
        background: linear-gradient(180deg, rgba(20, 27, 43, .96), rgba(12, 18, 30, .96));
        border-color: rgba(148, 163, 184, .14);
        color: #e5e7eb;
    }

    body.adminHomeDark .pageTitle,
    body.adminHomeDark .cardHeadTitle,
    body.adminHomeDark .filtersBar h3,
    body.adminHomeDark .kpiValue,
    body.adminHomeDark .dailyCardHead,
    body.adminHomeDark .helpItemTitle,
    body.adminHomeDark .aiHeaderTitle,
    body.adminHomeDark .azNoticeTitle,
    body.adminHomeDark .azNoticeHead strong,
    body.adminHomeDark .azUserDropdown a { color: #f8fafc !important; }

    body.adminHomeDark .kpiLabel,
    body.adminHomeDark .kpiUnit,
    body.adminHomeDark .kpiSub,
    body.adminHomeDark .kpiCompareLabel,
    body.adminHomeDark .kpiConditions,
    body.adminHomeDark .kpiDetailSummary,
    body.adminHomeDark .kpiDetailBody,
    body.adminHomeDark .metaLine,
    body.adminHomeDark .filterLabel,
    body.adminHomeDark .filters label,
    body.adminHomeDark .flowHint,
    body.adminHomeDark .pageLead,
    body.adminHomeDark .pageSub,
    body.adminHomeDark .actionCardBody,
    body.adminHomeDark .helpItemText,
    body.adminHomeDark .aiBtnHint,
    body.adminHomeDark .deepBtnsLabel,
    body.adminHomeDark .deepNightSub,
    body.adminHomeDark .dailyKey,
    body.adminHomeDark .azNoticeBody,
    body.adminHomeDark .azNoticeDate,
    body.adminHomeDark .azNoticeEmpty { color: var(--muted) !important; }

    body.adminHomeDark .kpiCompareRow {
        background: rgba(15, 20, 31, .78);
        border-color: rgba(148, 163, 184, .18);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .04);
    }

    body.adminHomeDark .kpiCompareValue.isUp {
        color: #34d399;
    }

    body.adminHomeDark .kpiCompareValue.isDown {
        color: #fb7185;
    }

    body.adminHomeDark .kpiCompareValue.isFlat {
        color: rgba(226, 232, 240, .72);
    }

    body.adminHomeDark .kpiCard { position: relative; overflow: hidden; }
    body.adminHomeDark .kpiCard::after {
        content: "";
        position: absolute;
        right: -28px;
        top: -28px;
        width: 92px;
        height: 92px;
        border-radius: 999px;
        background: radial-gradient(circle, var(--accentGlow), transparent 68%);
        pointer-events: none;
    }

    body.adminHomeDark .chartWrap {
        background: radial-gradient(620px 240px at 52% 100%, var(--accentSoft), transparent 72%), transparent;
    }

    body.adminHomeDark .table th,
    body.adminHomeDark .table td,
    body.adminHomeDark .dailyRow,
    body.adminHomeDark .aiChatLog {
        border-color: rgba(148, 163, 184, .13);
        color: rgba(226, 232, 240, .88);
    }

    body.adminHomeDark .table tbody tr:hover td { background: rgba(148, 163, 184, .06); }
    body.adminHomeDark .row-no-att td { color: rgba(148, 163, 184, .46) !important; }
    body.adminHomeDark .row-sales-zero td,
    body.adminHomeDark .date.sat { color: #60a5fa !important; }
    body.adminHomeDark .row-anom td,
    body.adminHomeDark .date.sun { color: #fb7185 !important; }

    body.adminHomeDark .warnBox {
        background: rgba(251, 191, 36, .08);
        border-color: rgba(251, 191, 36, .28);
        color: #fde68a;
    }

    body.adminHomeDark .bg-success { background: rgba(16,185,129,.18); color:#6ee7b7; border-color:rgba(16,185,129,.30); }
    body.adminHomeDark .bg-warning { background: rgba(250,204,21,.18); color:#fde68a; border-color:rgba(250,204,21,.30); }
    body.adminHomeDark .bg-danger { background: rgba(251,77,109,.18); color:#fb7185; border-color:rgba(251,77,109,.30); }
    body.adminHomeDark .bg-muted,
    body.adminHomeDark .bg-zero { background: rgba(148,163,184,.14); color:rgba(203,213,225,.78); border-color:rgba(148,163,184,.20); }

    body.adminHomeDark .aiHeaderRow,
    body.adminHomeDark .aiInsight,
    body.adminHomeDark .aiTurnA,
    body.adminHomeDark .deepBtnsRow {
        background: var(--accentSoft);
        border-color: var(--accentBorder);
        color: #e5e7eb;
    }

    body.adminHomeDark .aiHeaderBadge,
    body.adminHomeDark .aiTurnHead .tag { background: linear-gradient(135deg, var(--accent), var(--accent2)) !important; color:#fff !important; }
    body.adminHomeDark .aiTurnQ { background: rgba(34,211,238,.10); border-color: rgba(34,211,238,.22); color:#cffafe; }
    body.adminHomeDark .aiAskInput { background: rgba(8,11,18,.78); border-color: rgba(148,163,184,.22); color:#f8fafc; }
    body.adminHomeDark .aiAskInput::placeholder { color: rgba(203,213,225,.46); }
    body.adminHomeDark .editLink:hover,
    body.adminHomeDark .deepBtn:hover,
    body.adminHomeDark .aiCloseBtn:hover { background: var(--accentSoft); border-color: var(--accentBorder); }

    /* ===== Home layout refresh ===== */
    body.adminHomeDashboard {
        background: #080b12;
    }

    body.adminHomeDashboard .azHd {
        position: fixed;
        inset: 0 auto 0 0;
        width: 230px;
        height: 100vh;
        border-right: 1px solid rgba(148, 163, 184, .16);
        border-bottom: 0 !important;
        z-index: 120;
    }

    body.adminHomeDashboard .azHdInner,
    body.adminHomeDashboard .azHdLeft,
    body.adminHomeDashboard .azHdRight,
    body.adminHomeDashboard .azNav {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
    }

    body.adminHomeDashboard .azHdInner {
        min-height: 100%;
        padding: 16px 10px;
        gap: 10px;
        justify-content: flex-start;
    }

    body.adminHomeDashboard .azHdLeft {
        gap: 10px;
        flex: 1;
    }

    body.adminHomeDashboard .azLogo {
        width: 100%;
        padding: 8px 12px 24px;
    }

    body.adminHomeDashboard .azLogo img {
        height: 34px;
    }

    body.adminHomeDashboard .azLogo::before {
        font-size: 34px;
    }

    body.adminHomeDashboard .azVLine {
        display: none;
    }

    body.adminHomeDashboard .azNav {
        gap: 6px;
        overflow: visible;
    }

    body.adminHomeDashboard .azNav a {
        min-width: 0;
        width: 100%;
        min-height: 46px;
        justify-content: flex-start;
        padding: 0 14px;
        border-radius: 10px;
        font-size: 13px;
    }

    body.adminHomeDashboard .azNav a.is-active::after {
        display: none;
    }

    body.adminHomeDashboard .azHd .azHdRight {
        display: none;
    }

    body.adminHomeDashboard .topRow .azHdRight {
        display: flex;
        position: static !important;
        top: auto !important;
        right: auto !important;
        left: auto !important;
        z-index: auto;
        width: auto;
        max-width: min(560px, 52vw);
        min-width: 0;
        margin: 0 0 0 auto;
        flex-direction: row;
        align-items: center;
        justify-content: flex-end;
        flex-wrap: nowrap;
        gap: 10px;
    }

    body.adminHomeDashboard .azNoticeMenu,
    body.adminHomeDashboard .azStore,
    body.adminHomeDashboard .azUserMenu {
        width: auto;
        min-width: 0;
        min-height: 42px;
    }

    body.adminHomeDashboard .azNoticeBtn,
    body.adminHomeDashboard .azUserBtn {
        width: auto;
        max-width: 260px;
        min-height: 42px;
        border-radius: 10px;
    }

    body.adminHomeDashboard .azUserEmail {
        display: block;
        max-width: 190px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    body.adminHomeDashboard .azStore select {
        width: auto;
        max-width: 180px;
        min-height: 42px;
        border-radius: 10px;
        justify-content: flex-start;
    }

    body.adminHomeDashboard .azNoticeDropdown,
    body.adminHomeDashboard .azUserDropdown {
        left: auto;
        right: 0;
        bottom: auto;
        top: 100%;
        margin: 8px 0 0;
    }

    body.adminHomeDashboard .tabsBar {
        display: none;
    }

    body.adminHomeDashboard .page {
        margin-left: 230px;
        padding: 22px 28px 32px;
    }

    body.adminHomeDashboard .topRow {
        min-height: 54px;
        margin-bottom: 8px;
    }

    body.adminHomeDashboard .topRow::before {
        content: "ダッシュボード";
        color: #f8fafc;
        font-size: 24px;
        font-weight: 1000;
        letter-spacing: .02em;
    }

    body.adminHomeDashboard .grid {
        grid-template-columns: minmax(0, 1fr) 280px;
        gap: 16px;
        margin-top: 0;
        align-items: start;
    }

    body.adminHomeDashboard .rightCol {
        position: sticky;
        top: 18px;
        gap: 14px;
    }

    body.adminHomeDashboard .cardHead {
        border-bottom: 0;
        padding: 10px 0 12px;
        background: transparent;
    }

    body.adminHomeDashboard .cardHeadTitle {
        font-size: 16px;
    }

    body.adminHomeDashboard .kpiFilters select,
    body.adminHomeDashboard .kpiFilters button {
        height: 36px;
        border-radius: 10px;
    }

    body.adminHomeDashboard .cardInner {
        padding: 0;
    }

    body.adminHomeDashboard .kpiGrid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }

    body.adminHomeDashboard .kpiCard {
        min-height: 154px;
        padding: 20px 18px;
        border-radius: 14px;
    }

    body.adminHomeDashboard .kpiCard:nth-child(1) { order: 4; }
    body.adminHomeDashboard .kpiCard:nth-child(2) { order: 1; }
    body.adminHomeDashboard .kpiCard:nth-child(3) { order: 3; }
    body.adminHomeDashboard .kpiCard:nth-child(4) { order: 5; }
    body.adminHomeDashboard .kpiCard:nth-child(5) { order: 6; }
    body.adminHomeDashboard .kpiCard:nth-child(6) { order: 2; }

    body.adminHomeDashboard .kpiCard:nth-child(2),
    body.adminHomeDashboard .kpiCard:nth-child(4),
    body.adminHomeDashboard .kpiCard:nth-child(5),
    body.adminHomeDashboard .kpiCard:nth-child(6) {
        grid-column: auto;
    }

    body.adminHomeDashboard .kpiCard:nth-child(2),
    body.adminHomeDashboard .kpiCard:nth-child(6) {
        grid-column: span 2;
        min-height: 128px;
    }

    body.adminHomeDashboard .kpiWideInner {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(150px, 210px);
        gap: 18px;
        align-items: center;
        height: 100%;
        position: relative;
        z-index: 1;
    }

    body.adminHomeDashboard .kpiWideMain {
        min-width: 0;
    }

    body.adminHomeDashboard .kpiWideInner .kpiCompareStack {
        width: 100%;
        margin-top: 0;
        align-self: center;
    }

    body.adminHomeDashboard .kpiLabel {
        font-size: 13px;
        margin-bottom: 12px;
    }

    body.adminHomeDashboard .kpiValue {
        font-size: clamp(28px, 3.1vw, 40px);
    }

    body.adminHomeDashboard .kpiSub {
        font-size: 12px;
        margin-top: 10px;
    }

    body.adminHomeDashboard .metaLine {
        margin: 14px 0 0;
        padding: 14px 18px;
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 14px;
        background: rgba(15, 20, 31, .62);
    }

    body.adminHomeDashboard .tableCard,
    body.adminHomeDashboard #dailyTable {
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 16px;
        background: linear-gradient(180deg, rgba(18, 24, 38, .88), rgba(11, 17, 29, .88));
        overflow: hidden;
    }

    body.adminHomeDashboard .chartWrap {
        min-height: 300px;
        padding: 10px 18px 18px;
    }

    body.adminHomeDashboard .filtersBar {
        padding: 18px;
    }

    body.adminHomeDashboard .tableWrap {
        padding: 0 18px 18px;
    }

    body.adminHomeDashboard:not(.adminHomeDark) {
        background: #ffffff;
    }

    body.adminHomeDashboard:not(.adminHomeDark) .topRow::before {
        color: #111827;
    }

    body.adminHomeDashboard:not(.adminHomeDark) .metaLine {
        margin-top: 10px;
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        color: #6b7280;
    }

    body.adminHomeDashboard:not(.adminHomeDark) .tableCard,
    body.adminHomeDashboard:not(.adminHomeDark) #dailyTable {
        border: 1px solid rgba(0, 0, 0, .12);
        background: #ffffff;
        box-shadow: none;
    }

    body.adminHomeDashboard:not(.adminHomeDark) details.chartAcc summary,
    body.adminHomeDashboard:not(.adminHomeDark) .filtersBar,
    body.adminHomeDashboard:not(.adminHomeDark) .table thead,
    body.adminHomeDashboard:not(.adminHomeDark) .table thead th {
        background: #ffffff;
        color: #111827;
        border-color: rgba(0, 0, 0, .06);
    }

    body.adminHomeDashboard:not(.adminHomeDark) .chartWrap {
        background: #ffffff;
    }

    body.adminHomeDashboard:not(.adminHomeDark) .tableWrap {
        background: #ffffff;
    }

    body.adminHomeDashboard:not(.adminHomeDark) .table,
    body.adminHomeDashboard:not(.adminHomeDark) .table th,
    body.adminHomeDashboard:not(.adminHomeDark) .table td {
        color: #111827;
        border-color: rgba(0, 0, 0, .06);
    }

    body.adminHomeDashboard:not(.adminHomeDark) .table tbody tr:hover td {
        background: #f8fafc;
    }

    body.adminHomeDashboard .aiSidePanel {
        display: block !important;
    }

    body.adminHomeDashboard .aiSidePanel .card,
    body.adminHomeDashboard.aiConsultMode .tabPanel[data-panel="ai"] .card {
        border-color: var(--accentBorder);
        box-shadow: 0 18px 46px var(--accentSoft);
    }

    body.adminHomeDashboard .aiSidePanel .cardHead {
        padding: 18px 18px 6px;
    }

    body.adminHomeDashboard .aiSidePanel .cardHeadTitle {
        font-size: 18px;
    }

    body.adminHomeDashboard .aiSidePanel .cardHead::after {
        content: "AI";
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 42px;
        height: 36px;
        border-radius: 10px;
        color: #fff;
        font-size: 18px;
        font-weight: 1000;
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        box-shadow: 0 0 26px var(--accentSoft);
    }

    body.adminHomeDashboard .aiSidePanel .pill {
        display: none;
    }

    body.adminHomeDashboard .aiSidePanel .aiCta {
        padding: 12px 18px 18px;
        display: grid;
        gap: 12px;
    }

    body.adminHomeDashboard .aiSideIntro {
        color: #d7def0;
        font-size: 13px;
        line-height: 1.9;
    }

    body.adminHomeDashboard:not(.adminHomeDark) .aiSidePanel .cardHead::after {
        background: #111827;
        color: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .16);
    }

    body.adminHomeDashboard:not(.adminHomeDark) .aiSideIntro {
        color: #334155;
        font-weight: 800;
    }

    body.adminHomeDashboard .aiSideLink {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 42px;
        margin-top: 8px;
        border-radius: 12px;
        border: 1px solid var(--accentBorder);
        color: #fff;
        background: linear-gradient(135deg, rgba(var(--accent-rgb), .34), rgba(var(--accent-2-rgb), .15));
        text-decoration: none;
        font-weight: 900;
    }

    body.adminHomeDashboard .aiSidePanel .aiBtn {
        min-height: 46px;
        padding: 12px 14px;
        font-size: 14px;
        border-radius: 12px !important;
    }

    body.adminHomeDashboard .aiSidePanel .aiBtnHint {
        display: none;
    }

    body.adminHomeDashboard .aiSidePanel .aiBox {
        max-height: min(58vh, 560px);
        overflow: auto;
    }

    body.adminHomeDashboard.aiConsultMode .grid {
        grid-template-columns: minmax(0, 1fr) 280px;
    }

    body.adminHomeDashboard.aiConsultMode .tabPanel[data-panel="dash"] {
        display: none;
    }

    body.adminHomeDashboard.aiConsultMode .rightCol {
        display: grid;
        position: sticky;
        top: 18px;
    }

    body.adminHomeDashboard.aiConsultMode .aiSidePanel {
        display: none !important;
    }

    body.adminHomeDashboard.aiConsultMode .leftCol {
        max-width: none;
        width: 100%;
    }

    body.adminHomeDashboard.aiConsultMode .tabsBar {
        display: block;
        position: static;
        margin-left: 230px;
        background: transparent;
        box-shadow: none;
        border-bottom: 0;
    }

    body.adminHomeDashboard.aiConsultMode .tabsBar .page {
        margin-left: 0;
        padding: 0 28px 4px;
    }

    body.adminHomeDashboard.aiConsultMode .tabs {
        display: flex;
        max-width: 980px;
    }

    body.adminHomeDashboard.aiConsultMode .topRow::before {
        content: "AI（相談・チャット）";
    }

    body.adminHomeDashboard .helpBox .cardHead {
        padding: 16px 18px 8px;
    }

    body.adminHomeDashboard .helpBox .cardHeadTitle {
        font-size: 16px;
    }

    body.adminHomeDashboard .helpBody {
        padding: 10px 18px 18px;
        display: grid;
        gap: 8px;
    }

    body.adminHomeDashboard .helpBody details {
        margin: 0;
        border-radius: 10px;
    }

    body.adminHomeDashboard .helpBody summary {
        min-height: 42px;
        padding: 0 12px;
        font-size: 13px;
    }

    body.adminHomeDashboard .helpList {
        padding: 10px 12px;
        gap: 8px;
    }

    @media (max-width: 1180px) {
        body.adminHomeDashboard .grid {
            grid-template-columns: 1fr;
        }

        body.adminHomeDashboard .rightCol {
            position: static;
        }
    }

    @media (max-width: 900px) {
        body.adminHomeDashboard .azHd {
            position: sticky;
            width: auto;
            height: auto;
        }

        body.adminHomeDashboard .topRow .azHdRight {
            position: static !important;
            top: auto !important;
            right: auto !important;
            left: auto !important;
            width: auto;
            max-width: 100%;
            margin-left: auto;
            flex-direction: row;
            align-items: center;
        }

        body.adminHomeDashboard .azNoticeMenu,
        body.adminHomeDashboard .azStore,
        body.adminHomeDashboard .azUserMenu,
        body.adminHomeDashboard .azNoticeBtn,
        body.adminHomeDashboard .azUserBtn,
        body.adminHomeDashboard .azStore select {
            width: auto;
        }

        body.adminHomeDashboard .azStore {
            display: flex;
        }

        body.adminHomeDashboard .page {
            margin-left: 0;
            padding: 14px;
        }

        body.adminHomeDashboard.aiConsultMode .tabsBar {
            margin-left: 0;
        }

        body.adminHomeDashboard.aiConsultMode .tabsBar .page {
            padding: 0 14px 4px;
        }

        body.adminHomeDashboard .kpiGrid {
            grid-template-columns: 1fr;
        }

        body.adminHomeDashboard .kpiCard:nth-child(4),
        body.adminHomeDashboard .kpiCard:nth-child(5),
        body.adminHomeDashboard .kpiCard:nth-child(6) {
            grid-column: auto;
        }

        body.adminHomeDashboard .kpiCard:nth-child(2) {
            grid-column: auto;
        }

        body.adminHomeDashboard .kpiWideInner {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        body.adminHomeDashboard .kpiWideInner .kpiCompareStack {
            margin-top: 2px;
        }
    }
    </style>
</head>

<body class="<?= h(trim($adminThemeBodyClass . ' adminHomeDashboard')) ?>">
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="tabsBar">
        <div class="page">
            <div class="tabs" role="tablist" aria-label="管理画面タブ">
                <button class="tabBtn" type="button" role="tab" data-tab="dash" aria-selected="true">
                    📊 ダッシュボード
                </button>
                <button class="tabBtn" type="button" role="tab" data-tab="ai" aria-selected="false">
                    🤖 AI（相談・チャット）
                </button>
            </div>
        </div>
    </div>

    <div class="page">
        <div class="topRow">

            <div class="pageSub"></div>
            <?php if (!empty($_SESSION['admin_impersonate']) || !empty($_SESSION['admin_super_sessid'])): ?>
            <a class="pill" href="/admin/stop_impersonate.php">なりすまし終了</a>
            <?php endif; ?>

            <!-- filters moved into KPI header -->
        </div>
        <!-- removed pageLead per request -->

        <div class="grid">

            <!-- ===========================
                 左：メイン（タブ内容）
                 =========================== -->
            <div>

                <!-- ============ タブ：ダッシュボード ============ -->
                <section class="tabPanel isActive" data-panel="dash" role="tabpanel" aria-label="ダッシュボード">
                    <div>
                        <div class="cardHead">
                            <div class="cardHeadTitle">📊 KPI（<?= h($periodTitle) ?>）</div>
                            <form class="filters kpiFilters" method="get" action="/admin/index.php" id="periodFilterForm">
                                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">

                                <?php if ($roundFeatureEnabled): ?>
                                <select name="round15">
                                    <option value="1" <?= $roundOn ? 'selected' : '' ?>>調整ON</option>
                                    <option value="0" <?= !$roundOn ? 'selected' : '' ?>>調整OFF</option>
                                </select>
                                <?php else: ?>
                                <input type="hidden" name="round15" value="<?= $roundOn ? 1 : 0 ?>">
                                <?php endif; ?>

                                <!-- removed label per request -->
                                <select name="period">
                                    <?php foreach ($periodOptions as $opt): ?>
                                    <option value="<?= h($opt['value']) ?>"
                                        <?= ($periodParam === $opt['value']) ? 'selected' : '' ?>>
                                        <?= h($opt['label']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit">表示</button>
                            </form>
                        </div>
                        <div class="kpiConditions">
                            <!-- removed condition line per request -->
                        </div>

                        <div class="cardInner">
                            <!-- removed flowHint per request -->
                            <div class="kpiGrid">

                                <div class="kpiCard">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                                        <div class="kpiLabel">人件費率</div>
                                        <span class="kpiMainBadge bg-<?= h($badge) ?>">
                                            <?= h(badge_label($colorKey, $labelMap)) ?>
                                        </span>
                                    </div>
                                    <div class="kpiValue">
                                        <?= number_format($rate, 1) ?><span class="kpiUnit">%</span>
                                    </div>
                                    <div class="kpiSub"><?= h(badge_note($colorKey, $noteMap)) ?></div>
                                    <details class="kpiDetail">
                                        <summary class="kpiDetailSummary">詳細</summary>
                                        <div class="kpiDetailBody">
                                            基準：適正 ≤ <?= number_format($greenMax, 2) ?>% / 注意 ≤ <?= number_format($yellowMax, 2) ?>%
                                            <?php if (!empty($anomalyDaysMonth)): ?>
                                                <br><span style="color:#b3261e;">未連携の疑いあり</span>
                                                <a href="/admin/sales_edit.php?store_id=<?= (int)$storeId ?>&date=<?= h((string)$anomalyDaysMonth[0]) ?>" style="color:inherit;text-decoration:underline;">この日の売上入力へ</a>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                </div>

                                <div class="kpiCard">
                                    <div class="kpiWideInner">
                                        <div class="kpiWideMain">
                                            <div class="kpiLabel">売上</div>
                                            <div class="kpiValue">
                                                <?= number_format((int)$salesMonth) ?><span class="kpiUnit">円</span>
                                            </div>
                                            <div class="kpiSub">平均：<?= number_format($salesAvgPerDay) ?>円/日</div>
                                            <details class="kpiDetail">
                                                <summary class="kpiDetailSummary">詳細</summary>
                                                <div class="kpiDetailBody">
                                                    <a href="/admin/sales_import.php?store_id=<?= (int)$storeId ?>" style="color:inherit;text-decoration:underline;">売上CSV取り込み</a>
                                                </div>
                                            </details>
                                        </div>
                                        <div class="kpiCompareStack" aria-label="売上比較">
                                            <div class="kpiCompareRow">
                                                <span class="kpiCompareLabel">前日比</span>
                                                <span class="kpiCompareValue <?= h(kpi_delta_class($salesPrevDayDiff)) ?>">
                                                    <?= h(kpi_delta_text($salesPrevDayDiff, '円')) ?>
                                                </span>
                                            </div>
                                            <div class="kpiCompareRow">
                                                <span class="kpiCompareLabel">前月比</span>
                                                <span class="kpiCompareValue <?= h(kpi_delta_class($salesPrevMonthDiff)) ?>">
                                                    <?= h(kpi_delta_text($salesPrevMonthDiff, '円')) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="kpiCard">
                                        <div class="kpiLabel">人件費</div>
                                    <div class="kpiValue">
                                        <?= number_format((int)$laborMonth) ?><span class="kpiUnit">円</span>
                                    </div>
                                    <div class="kpiSub">
                                        平均：<?= number_format($laborAvgPerDay) ?>円/日
                                        <?php if ((int)$laborNightMonth > 0): ?>
                                            <span class="sub deepNightSub">深割: +<?= number_format((int)$laborNightMonth) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="kpiCard">
                                    <div class="kpiLabel">経費（日割）</div>
                                    <div class="kpiValue">
                                        <?php if ($expenseHasSettings): ?>
                                        <?= number_format((int)$expenseMonth) ?><span class="kpiUnit">円</span>
                                        <?php else: ?>
                                        未設定
                                        <?php endif; ?>
                                    </div>
                                    <div class="kpiSub">
                                        <?php if ($expenseHasSettings): ?>
                                        固定：<?= number_format((int)$fixedExpenseMonth) ?>円 / 月別：<?= number_format((int)$monthlyExpenseMonth) ?>円（期間按分）
                                        <?php else: ?>
                                        <a href="/admin/expenses.php?store_id=<?= (int)$storeId ?>" style="color:inherit;text-decoration:underline;">経費を登録</a>すると利益を表示できます
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($expenseHasSettings): ?>
                                    <details class="kpiDetail">
                                        <summary class="kpiDetailSummary">詳細</summary>
                                        <div class="kpiDetailBody">
                                            <?php if ($expenseFixedItems): ?>
                                                <div style="font-weight:1000;color:#111827;margin-bottom:4px;">固定経費（日割）</div>
                                                <?php foreach ($expenseFixedItems as $item): ?>
                                                    <div>
                                                        <?= h((string)$item['month']) ?> /
                                                        <?= h((string)$item['name']) ?>：
                                                        <?= number_format((int)$item['allocated_amount']) ?>円
                                                        <span style="color:#9ca3af;">（月額 <?= number_format((int)$item['monthly_amount']) ?>円）</span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <?php if ($expenseMonthlyItems): ?>
                                                <div style="font-weight:1000;color:#111827;margin:8px 0 4px;">月別経費（日割）</div>
                                                <?php foreach ($expenseMonthlyItems as $item): ?>
                                                    <div>
                                                        <?= h((string)$item['month']) ?> /
                                                        <?= h((string)$item['name']) ?>：
                                                        <?= number_format((int)$item['allocated_amount']) ?>円
                                                        <span style="color:#9ca3af;">（登録 <?= number_format((int)$item['amount']) ?>円<?= trim((string)$item['memo']) !== '' ? ' / ' . h((string)$item['memo']) : '' ?>）</span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <?php if (!$expenseFixedItems && !$expenseMonthlyItems): ?>
                                                表示対象の経費項目はありません。
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                    <?php endif; ?>
                                </div>

                                <div class="kpiCard">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                                        <div class="kpiLabel">推定利益</div>
                                        <?php if ($expenseHasSettings): ?>
                                        <span class="kpiMainBadge <?= $estimatedProfit < 0 ? 'bg-danger' : 'bg-success' ?>">
                                            <?= $estimatedProfit < 0 ? '赤字' : '黒字' ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="kpiValue" style="<?= ($expenseHasSettings && $estimatedProfit < 0) ? 'color:#b3261e;' : '' ?>">
                                        <?php if ($expenseHasSettings): ?>
                                        <?= number_format((int)$estimatedProfit) ?><span class="kpiUnit">円</span>
                                        <?php else: ?>
                                        未計算
                                        <?php endif; ?>
                                    </div>
                                    <div class="kpiSub">
                                        <?php if ($expenseHasSettings && $estimatedProfitRate !== null): ?>
                                        利益率：<?= number_format((float)$estimatedProfitRate, 1) ?>%
                                        <?php elseif ($expenseHasSettings): ?>
                                        利益率：-
                                        <?php else: ?>
                                        経費が未設定です
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="kpiCard">
                                    <div class="kpiWideInner">
                                        <div class="kpiWideMain">
                                            <div class="kpiLabel">来客数</div>
                                            <div class="kpiValue">
                                                <?php if ($customersDaysMonth > 0): ?>
                                                <?= number_format($customersMonth) ?><span class="kpiUnit">人</span>
                                                <?php else: ?>
                                                -<span class="kpiUnit"></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="kpiSub">
                                                平均：
                                                <?php if ($customersAvgPerDay !== null): ?>
                                                <?= number_format($customersAvgPerDay) ?>人/日
                                                <?php else: ?>
                                                -
                                                <?php endif; ?>
                                                <?php if ($avgTicketMonth !== null): ?>
                                                / 客単価 <?= number_format($avgTicketMonth) ?>円
                                                <?php else: ?>
                                                / 客単価 -
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="kpiCompareStack" aria-label="来客数比較">
                                            <div class="kpiCompareRow">
                                                <span class="kpiCompareLabel">前日比</span>
                                                <span class="kpiCompareValue <?= h(kpi_delta_class($customersPrevDayDiff)) ?>">
                                                    <?= h(kpi_delta_text($customersPrevDayDiff, '人')) ?>
                                                </span>
                                            </div>
                                            <div class="kpiCompareRow">
                                                <span class="kpiCompareLabel">前月比</span>
                                                <span class="kpiCompareValue <?= h(kpi_delta_class($customersPrevMonthDiff)) ?>">
                                                    <?= h(kpi_delta_text($customersPrevMonthDiff, '人')) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="metaLine">
                                営業日数: <?= number_format($openDaysMonth) ?>日 /
                                定休日: <?= number_format(count($holidayDaysMonth)) ?>日 /
                                判定基準：適正 ≤ <?= number_format($greenMax, 2) ?>% / 注意 ≤
                                <?= number_format($yellowMax, 2) ?>%
                                <?php if ($customersDaysMonth === 0): ?>
                                / ※来客数（visitors）が未導入のため、来客数・客単価は「-」表示です
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($anomalyDaysMonth)): ?>
                            <div class="warnBox">
                                ⚠ 売上0なのに人件費がある日（売上未確定の疑い）：
                                <?= h(implode(', ', array_slice($anomalyDaysMonth, 0, 10))) ?>
                                <?= count($anomalyDaysMonth) > 10 ? '…' : '' ?>
                                <br>※この日は「人件費率計算」から除外しています（売上確定で正しく反映されます）。
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tableCard" style="margin-top:14px;">
                        <details class="chartAcc" id="chartAccordion" open>
                            <summary>
                                <span style="display:flex;align-items:center;gap:10px;">
                                    📈 <?= h($periodTitle) ?>の推移（発見用）
                                </span>
                                <span class="chev" aria-hidden="true">🔽</span>
                            </summary>
                            <div class="cardInner" style="padding-top:10px;">
                                <div class="chartToggle" role="tablist" aria-label="グラフ切り替え">
                                    <button type="button" class="chartBtn active" data-chart="rate">人件費率</button>
                                    <button type="button" class="chartBtn" data-chart="all">売上と人件費</button>
                                </div>
                            </div>
                            <div class="chartWrap">
                                <div class="chartPanel" data-chart-panel="rate">
                                    <?= svg_rate_chart_30days($dates30, $salesMap30, $laborMap30, (float)$greenMax, (float)$yellowMax, $adminThemeChartPalette) ?>
                                </div>
                                <div class="chartPanel is-hidden" data-chart-panel="all">
                                    <?= svg_line_chart_30days($dates30, $salesMap30, $laborMap30, $adminThemeChartPalette) ?>
                                </div>
                            </div>
                        </details>
                    </div>

                    <div style="margin-top:14px;" id="dailyTable">
                        <div class="filtersBar">
                            <h3>（<?= h($periodTitle) ?>）</h3>
                        </div>

                        <div class="tableWrap">
                            <table class="table">
                                <colgroup>
                                    <col style="width:17%">
                                    <col style="width:14%">
                                    <col style="width:9%">
                                    <col style="width:11%">
                                    <col style="width:20%">
                                    <col style="width:8%">
                                    <col style="width:10%">
                                    <col style="width:11%">
                                </colgroup>

                                <thead>
                                    <tr>
                                        <th>日付</th>
                                        <th class="text-end">売上</th>
                                        <th class="text-end">客数</th>
                                        <th class="text-end">客単価</th>
                                        <th class="text-end laborCol">人件費</th>
                                        <th class="text-end">率</th>
                                        <th>判定</th>
                                        <th class="editTd">編集</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($dates30Desc as $d):
                                        $s = (int)(($salesMap30[$d]['sales'] ?? 0));
                                        $customers = $salesMap30[$d]['customers'] ?? null;
                                        $l = (int)($laborMap30[$d] ?? 0);
                                        $ln = (int)($laborNightMap30[$d] ?? 0);
                                        $confirmed = (int)($salesMap30[$d]['confirmed'] ?? 0) === 1;

                                        $isHoliday = ($s <= 0 && $l <= 0);
                                        $isAnom    = ($s <= 0 && $l > 0 && !$confirmed);

                                        $hasAttend = true;
                                        if (!empty($attendMap30)) {
                                            $hasAttend = (bool)($attendMap30[$d] ?? false);
                                        }

                                        $isSalesZero = ($s <= 0);
                                        $r = ($s > 0) ? ($l / $s * 100.0) : 0.0;

                                        if ($isHoliday) {
                                            $ck = 'holiday';
                                            $bd = 'muted';
                                            $rowClass = 'row-empty';
                                        } elseif ($isAnom) {
                                            $ck = 'anom';
                                            $bd = 'danger';
                                            $rowClass = 'row-anom';
                                        } elseif (!$hasAttend) {
                                            $ck = 'noatt';
                                            $bd = 'muted';
                                            $rowClass = 'row-no-att';
                                        } elseif ($isSalesZero) {
                                            $ck = 'zero';
                                            $bd = 'zero';
                                            $rowClass = 'row-sales-zero';
                                        } else {
                                            [$ck, $bd] = labor_color_from_thresholds($r, $greenMax, $yellowMax);
                                            $rowClass = 'row-' . $bd;
                                        }

                                        $avg = null;
                                        if (is_int($customers) && $customers > 0 && $s > 0) {
                                            $avg = (int)floor($s / $customers);
                                        }

                                        $badgeClass = ($bd === 'zero') ? 'zero' : $bd;
                                    ?>
                                    <tr class="<?= h($rowClass) ?>">
                                        <td><?= date_with_weekday_html($d) ?></td>

                                        <td class="text-end"><?= number_format($s) ?></td>

                                        <td class="text-end laborCol">
                                            <?php if (is_int($customers)): ?>
                                            <?= number_format($customers) ?>
                                            <?php else: ?>
                                            -
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end">
                                            <?= $avg !== null ? number_format($avg) . '円' : '-' ?>
                                        </td>

                                        <td class="text-end">
                                            <?= number_format($l) ?>
                                            <?php if ($ln > 0): ?>
                                                <span class="sub deepNightSub">深割:+<?= number_format($ln) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?= $s > 0 ? number_format($r, 1) . '%' : '-' ?></td>

                                        <td>
                                            <div class="judgeCell">
                                                <span
                                                    class="badge bg-<?= h($badgeClass) ?>"><?= h(badge_label($ck, $labelMap)) ?></span>
                                            </div>
                                        </td>

                                        <td class="editTd">
                                            <a class="editLink"
                                                href="/admin/sales_edit.php?store_id=<?= (int)$storeId ?>&date=<?= h($d) ?>"
                                                title="この日の売上入力へ">✏️</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="dailyCards">
                            <?php foreach ($dates30Desc as $d):
                                $s = (int)(($salesMap30[$d]['sales'] ?? 0));
                                $customers = $salesMap30[$d]['customers'] ?? null;
                                $l = (int)($laborMap30[$d] ?? 0);
                                $ln = (int)($laborNightMap30[$d] ?? 0);
                                $confirmed = (int)($salesMap30[$d]['confirmed'] ?? 0) === 1;

                                $isHoliday = ($s <= 0 && $l <= 0);
                                $isAnom    = ($s <= 0 && $l > 0 && !$confirmed);

                                $hasAttend = true;
                                if (!empty($attendMap30)) {
                                    $hasAttend = (bool)($attendMap30[$d] ?? false);
                                }

                                $isSalesZero = ($s <= 0);
                                $r = ($s > 0) ? ($l / $s * 100.0) : 0.0;

                                if ($isHoliday) {
                                    $ck = 'holiday';
                                    $bd = 'muted';
                                } elseif ($isAnom) {
                                    $ck = 'anom';
                                    $bd = 'danger';
                                } elseif (!$hasAttend) {
                                    $ck = 'noatt';
                                    $bd = 'muted';
                                } elseif ($isSalesZero) {
                                    $ck = 'zero';
                                    $bd = 'zero';
                                } else {
                                    [$ck, $bd] = labor_color_from_thresholds($r, $greenMax, $yellowMax);
                                }

                                $avg = null;
                                if (is_int($customers) && $customers > 0 && $s > 0) {
                                    $avg = (int)floor($s / $customers);
                                }

                                $badgeClass = ($bd === 'zero') ? 'zero' : $bd;
                            ?>
                            <div class="dailyCard">
                                <div class="dailyCardHead">
                                    <div><?= date_with_weekday_html($d) ?></div>
                                    <span class="badge bg-<?= h($badgeClass) ?>"><?= h(badge_label($ck, $labelMap)) ?></span>
                                </div>
                                <div class="dailyRow">
                                    <span class="dailyKey">売上</span>
                                    <span><?= number_format($s) ?>円</span>
                                </div>
                                <div class="dailyRow">
                                    <span class="dailyKey">人件費</span>
                                    <span>
                                        <?= number_format($l) ?>円
                                        <?php if ($ln > 0): ?>
                                            <span class="sub deepNightSub">深割:+<?= number_format($ln) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="dailyRow">
                                    <span class="dailyKey">人件費率</span>
                                    <span><?= $s > 0 ? number_format($r, 1) . '%' : '-' ?></span>
                                </div>
                                <div class="dailyRow">
                                    <span class="dailyKey">客数</span>
                                    <span>
                                        <?php if (is_int($customers)): ?>
                                        <?= number_format($customers) ?>人
                                        <?php else: ?>
                                        -
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="dailyRow">
                                    <span class="dailyKey">客単価</span>
                                    <span><?= $avg !== null ? number_format($avg) . '円' : '-' ?></span>
                                </div>
                                <div class="dailyRow">
                                    <span class="dailyKey">編集</span>
                                    <span>
                                        <a class="editLink"
                                            href="/admin/sales_edit.php?store_id=<?= (int)$storeId ?>&date=<?= h($d) ?>"
                                            title="この日の売上入力へ">✏️</a>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </section>

                <!-- ============ タブ：AI ============ -->
                <section class="tabPanel" data-panel="ai" role="tabpanel" aria-label="AI（相談・チャット）">
                    <div class="card">
                        <div class="cardHead">
                            <div class="cardHeadTitle">🤖 AIに相談（改善案 → 追加質問）</div>
                            <div class="pill">従業員サマリー: 直近30日固定</div>
                        </div>

                        <div class="aiCta">
                            <!-- AI改善案（見た目だけ変更：機能はそのまま） -->
                            <button type="button" class="aiBtn" onclick="loadAiInsights()">🤖 AIに相談する（改善案）</button>
                            <div class="aiBtnHint">ボタンを押す → AIが分析 → 下で質問できます</div>

                            <div id="aiBox" class="aiBox" style="display:none;">
                                <div class="aiHeaderRow">
                                    <div class="aiHeaderTitle">
                                        <span class="aiHeaderBadge">AI</span>
                                        <span id="aiStatusText">しばらくお待ちください…</span>
                                    </div>
                                    <span id="aiSpinner" class="spinner"></span>
                                </div>

                                <div id="aiText" class="aiInsight" style="display:block;"></div>
                                <div id="aiChatLog" class="aiChatLog" style="display:none;"></div>

                                <div id="aiAskWrap" style="display:none; margin-top:10px;">
                                    <div class="aiAskRow">
                                        <input id="aiAskInput" class="aiAskInput" type="text" maxlength="300"
                                            placeholder="例：出勤が少ない人は？ / 率が高い日は？ / 明日なにを直す？" />
                                        <button id="aiAskSend" class="aiAskSend" type="button"
                                            onclick="sendAiQuestion()" disabled>
                                            <span id="btnSpin" class="btnSpinner"></span>
                                            <span id="btnLabel">送る</span>
                                        </button>
                                    </div>
                                    <div class="aiCloseRow">
                                        <button type="button" class="aiCloseBtn" onclick="closeAiChat()">閉じる</button>
                                    </div>
                                </div>

                                <div class="deepBtnsRow" id="deepBtnsRow" style="display:none;">
                                    <div class="deepBtnsLabel">どれを聞く？</div>
                                    <div class="deepBtns">
                                        <button type="button" class="deepBtn" onclick="deepDive(1)">結論</button>
                                        <button type="button" class="deepBtn" onclick="deepDive(2)">問題</button>
                                        <button type="button" class="deepBtn" onclick="deepDive(3)">すぐ改善</button>
                                        <button type="button" class="deepBtn" onclick="deepDive(4)">従業員</button>
                                        <button type="button" class="deepBtn" onclick="deepDive(5)">明日</button>
                                        <button type="button" class="deepBtn" onclick="deepDive(6)">経費</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

            </div>

            <!-- ===========================
                 右：用語（見ながらチャット）
                 =========================== -->
            <div class="rightCol">
                <aside class="card" aria-label="今の改善ポイント">
                    <div class="cardHead">
                        <div class="cardHeadTitle">💡 今の改善ポイント</div>
                    </div>
                    <div class="actionCardBody">
                        <div>人件費率が高い日を確認し、売上不足か人員過多かを見直しましょう。</div>
                        <a class="actionBtn" id="highLaborBtn" href="#dailyTable">この日の内訳を見る →</a>
                    </div>
                </aside>

                <aside class="card helpBox" aria-label="用語・見方">
                    <div class="cardHead">
                        <div class="cardHeadTitle">📘 用語（見方）</div>
                        <!-- <span class="pill">見ながら質問OK</span> -->
                    </div>
                    <div class="helpBody">

                    <details>
                        <summary>📊 KPIの意味 <span class="chev" aria-hidden="true">🔽</span></summary>
                        <div class="helpList">
                            <div>
                                <div class="helpItemTitle">売上（営業日のみ）</div>
                                <div class="helpItemText">売上が入っている日だけを集計対象にします（定休日や未入力日でブレないように）。</div>
                            </div>
                            <div>
                                <div class="helpItemTitle">人件費（営業日のみ）</div>
                                <div class="helpItemText">勤怠から日別人件費を集計し、営業日のみ合算します（打刻調整ON/OFFは人件費だけに影響）。</div>
                            </div>
                            <div>
                                <div class="helpItemTitle">来客数 / 客単価</div>
                                <div class="helpItemText">来客数（visitors）がある場合のみ表示します。客単価＝売上÷来客数。</div>
                            </div>
                            <div>
                                <div class="helpItemTitle">人件費率（定休日除外）</div>
                                <div class="helpItemText">人件費÷売上×100。定休日（売上0＆人件費0）と、売上未確定の疑い（売上0＆人件費>0）は除外して判定します。</div>
                            </div>
                        </div>
                    </details>

                    <details>
                        <summary>🟡 判定（適正/注意/要改善）<span class="chev" aria-hidden="true">🔽</span></summary>
                        <div class="helpList">
                            <div>
                                <div class="helpItemTitle">適正 / 注意 / 要改善</div>
                                <div class="helpItemText">
                                    店舗設定の基準（適正≤<?= number_format($greenMax, 2) ?>%、注意≤<?= number_format($yellowMax, 2) ?>%）で色分けします。
                                </div>
                            </div>
                            <div>
                                <div class="helpItemTitle">売上未入力?</div>
                                <div class="helpItemText">売上0なのに人件費がある日で、「売上確定」が未チェックの日を示します。</div>
                            </div>
                            <div>
                                <div class="helpItemTitle">出勤なし</div>
                                <div class="helpItemText">勤怠ログ上、その日に打刻が無い（または無効）場合です。</div>
                            </div>
                        </div>
                    </details>

                    <details>
                        <summary>🤖 AIの使い方（おすすめ）<span class="chev" aria-hidden="true">🔽</span></summary>
                        <div class="helpList">
                            <div>
                                <div class="helpItemTitle">まず押す</div>
                                <div class="helpItemText">「AIに相談する（改善案）」→ 全体の結論が出ます。</div>
                            </div>
                            <div>
                                <div class="helpItemTitle">次にボタンで深掘り</div>
                                <div class="helpItemText">「結論/問題/すぐ改善/従業員/明日/経費」を押すと質問が自動入力されます。</div>
                            </div>
                            <div>
                                <div class="helpItemTitle">見ながら質問</div>
                                <div class="helpItemText">この用語パネルを見ながら、「この項目の意味は？」「なぜ除外？」など、そのまま聞けます。</div>
                            </div>
                        </div>
                    </details>

                    <!-- <div class="helpTip">
                        💡 タブ切替：上の「📊/📅/🤖」を押すだけ。<br>
                        タブの状態はこの端末に保存されます（UIのみ）。
                    </div> -->

                    </div>
                </aside>
            </div>

        </div>

        <script>
        (function setupHomeDashboardLayout() {
            const rightCol = document.querySelector('.rightCol');
            const aiPanel = document.querySelector('.tabPanel[data-panel="ai"]');
            if (rightCol && aiPanel && !rightCol.querySelector('.aiSidePanel')) {
                const oldInsight = Array.from(rightCol.querySelectorAll('aside.card')).find((el) => !el.classList.contains('helpBox'));
                const oldInsightBody = oldInsight ? oldInsight.querySelector('.actionCardBody > div') : null;
                const oldInsightText = oldInsightBody ? oldInsightBody.textContent.trim() : '';
                const sidePanel = document.createElement('aside');
                sidePanel.className = 'aiSidePanel';
                sidePanel.setAttribute('aria-label', 'AIインサイト');
                sidePanel.innerHTML = `
                    <div class="card">
                        <div class="cardHead">
                            <div class="cardHeadTitle">AIインサイト</div>
                        </div>
                        <div class="aiCta">
                            <div class="aiSideIntro"></div>
                        </div>
                    </div>
                `;
                const intro = sidePanel.querySelector('.aiSideIntro');
                if (intro) {
                    intro.textContent = oldInsightText || '人件費率が高い状況です。売上予測のAI予測から、人員配置の見直しをおすすめします。';

                    const link = document.createElement('a');
                    link.className = 'aiSideLink';
                    link.id = 'highLaborBtn';
                    link.href = '#dailyTable';
                    link.textContent = 'この日の内訳を見る';
                    intro.appendChild(link);
                }
                rightCol.prepend(sidePanel);
                if (oldInsight) oldInsight.remove();
            }

            const helpBox = document.querySelector('.helpBox');
            if (helpBox) {
                const title = helpBox.querySelector('.cardHeadTitle');
                if (title) title.textContent = 'クイックメニュー';
                helpBox.setAttribute('aria-label', 'クイックメニュー');
                const summaries = helpBox.querySelectorAll('summary');
                const labels = ['KPIの定義', '売上 / 人件費', 'AIの使い方'];
                summaries.forEach((summary, index) => {
                    summary.textContent = labels[index] || summary.textContent;
                    const arrow = document.createElement('span');
                    arrow.className = 'chev';
                    arrow.setAttribute('aria-hidden', 'true');
                    arrow.textContent = '›';
                    summary.appendChild(arrow);
                });
            }
        })();

        (function() {
            const btns = document.querySelectorAll('.chartBtn');
            const panels = document.querySelectorAll('[data-chart-panel]');
            if (!btns.length || !panels.length) return;

            const setActive = (key) => {
                btns.forEach((b) => b.classList.toggle('active', b.dataset.chart === key));
                panels.forEach((p) => p.classList.toggle('is-hidden', p.dataset.chartPanel !== key));
            };

            btns.forEach((btn) => {
                btn.addEventListener('click', () => setActive(btn.dataset.chart || 'rate'));
            });

            setActive('rate');
        })();

        // ✅ AI用は「直近30日固定」
        const EMPLOYEE_SUMMARY_30 =
            <?= json_encode($employeeSummary30, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const EMPLOYEE_SUMMARY_PERIOD = {
            from: "<?= h($aiFrom) ?>",
            to: "<?= h($aiTo) ?>"
        };

        const AI_STATE_KEY = `aiBoxState:v2:store<?= (int)$storeId ?>:round<?= $roundOn ? 1 : 0 ?>`;

        function safeJsonParse(s) {
            try {
                return JSON.parse(String(s || ''));
            } catch (e) {
                return null;
            }
        }

        function saveAiBoxState() {
            if (!document.getElementById('aiBox')) return;
            const state = {
                open: (document.getElementById('aiBox').style.display !== 'none'),
                statusText: (document.getElementById('aiStatusText')?.textContent || ''),
                aiTextHtml: (document.getElementById('aiText')?.innerHTML || ''),
                chatHtml: (document.getElementById('aiChatLog')?.innerHTML || ''),
                askWrapVisible: (document.getElementById('aiAskWrap')?.style.display === 'block'),
                chatLogVisible: (document.getElementById('aiChatLog')?.style.display !== 'none'),
                askInput: (document.getElementById('aiAskInput')?.value || ''),
                ctx: aiCtx
            };
            try {
                sessionStorage.setItem(AI_STATE_KEY, JSON.stringify(state));
            } catch (e) {}
        }

        function clearAiBoxState() {
            try {
                sessionStorage.removeItem(AI_STATE_KEY);
            } catch (e) {}
        }

        function restoreAiBoxStateIfAny() {
            const raw = sessionStorage.getItem(AI_STATE_KEY);
            if (!raw) return;
            const st = safeJsonParse(raw);
            if (!st || !st.open) return;

            document.getElementById('aiBox').style.display = 'block';
            document.getElementById('aiStatusText').textContent = st.statusText || 'AI改善案';
            document.getElementById('aiSpinner').style.display = 'none';
            document.getElementById('aiText').innerHTML = st.aiTextHtml || '';

            document.getElementById('aiChatLog').innerHTML = st.chatHtml || '';
            document.getElementById('aiAskWrap').style.display = st.askWrapVisible ? 'block' : 'none';
            document.getElementById('aiChatLog').style.display = st.chatLogVisible ? 'flex' : 'none';

            if (document.getElementById('aiAskInput')) document.getElementById('aiAskInput').value = st.askInput || '';

            if (st.ctx && typeof st.ctx === 'object') {
                aiCtx = Object.assign({
                    loaded: false,
                    from: null,
                    to: null,
                    rate_avg: null,
                    insights: "",
                    daily: null,
                    sending: false
                }, st.ctx);
            }

            setSendLoading(false);
            aiCtx.sending = false;
            const q = normalizeQuestion(document.getElementById('aiAskInput')?.value || '');
            setAskEnabled(!!(aiCtx.loaded && q.length > 0));
        }

        let aiCtx = {
            loaded: false,
            from: null,
            to: null,
            rate_avg: null,
            insights: "",
            daily: null,
            sending: false
        };
        const el = (id) => document.getElementById(id);

        function normalizeQuestion(s) {
            return String(s || '').trim().replace(/\s+/g, ' ').slice(0, 300);
        }

        function escapeHtml(str) {
            return String(str ?? "")
                .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        function injectReadableBreaks(raw) {
            let t = String(raw ?? "");
            t = t.replace(/\r\n/g, "\n").replace(/\r/g, "\n");
            t = t.replace(/[ \t]+/g, " ");
            t = t.trim();
            t = t.replace(/\s(?=\d\)\s)/g, "\n");
            t = t.replace(/\s-\s/g, "\n- ");
            t = t.replace(/。\s(?!\n|\d\)\s|- )/g, "。\n");
            t = t.replace(/\n{3,}/g, "\n\n");
            return t;
        }

        function formatAiAnswer(rawText) {
            let t = injectReadableBreaks(rawText);
            t = escapeHtml(t);
            t = t.replace(/^(\d{1,2}\)\s.*)$/gm, (m) => {
                const isTodo = /^4\)\s*明日やることToDo/.test(m);
                const cls = isTodo ? "ai-heading todo-highlight" : "ai-heading";
                return `<span class="${cls}">${m}</span>`;
            });
            t = t.replace(/\n/g, "<br>");
            return t;
        }

        function setAskEnabled(on) {
            el('aiAskSend').disabled = !on;
        }

        function showAskWrap(on) {
            el('aiAskWrap').style.display = on ? 'block' : 'none';
        }

        function showChatLog(on) {
            el('aiChatLog').style.display = on ? 'flex' : 'none';
        }

        function spinnerOn(on) {
            el('aiSpinner').style.display = on ? 'inline-block' : 'none';
        }

        function setStatus(text, loading) {
            el('aiStatusText').textContent = text;
            spinnerOn(!!loading);
        }

        function setSendLoading(on) {
            el('btnSpin').style.display = on ? 'inline-block' : 'none';
            el('btnLabel').textContent = on ? '送信中…' : '送る';
        }

        function resetChatUi() {
            setStatus('データ分析中…', false);
            el('aiText').innerHTML = '';
            el('aiAskInput').value = '';
            el('aiChatLog').innerHTML = '';
            showChatLog(false);
            showAskWrap(false);
            aiCtx.loaded = false;
            aiCtx.sending = false;
            setSendLoading(false);
            setAskEnabled(false);
            showDeepBtns(false);
            saveAiBoxState();
        }

        el('aiAskInput')?.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') ev.preventDefault();
        });

        document.addEventListener('input', () => {
            if (!aiCtx.loaded) return;
            if (el('aiAskWrap').style.display === 'none') return;
            const q = normalizeQuestion(el('aiAskInput').value);
            setAskEnabled(q.length > 0 && !aiCtx.sending);
            saveAiBoxState();
        }, true);

        window.addEventListener('beforeunload', () => {
            saveAiBoxState();
        });
        document.addEventListener('DOMContentLoaded', () => {
            restoreAiBoxStateIfAny();
            const highBtn = document.getElementById('highLaborBtn');
            if (highBtn) {
                highBtn.addEventListener('click', () => {
                    document.querySelectorAll('.table tbody tr').forEach((tr) => {
                        tr.classList.remove('row-highlight');
                    });
                    document.querySelectorAll('.table tbody tr.row-danger, .table tbody tr.row-warning').forEach((tr) => {
                        tr.classList.add('row-highlight');
                    });
                });
            }
        });

        async function loadAiInsights() {
            // ✅ AIタブへ移動（UIだけ）
            try {
                if (document.body.classList.contains('adminHomeDashboard')) {
                    document.body.classList.add('aiConsultMode');
                }
                uiTabsOpen('ai');
            } catch (e) {}

            const box = el('aiBox');
            box.style.display = 'block';
            saveAiBoxState();
            resetChatUi();
            setStatus('データから分析中…', true);
            saveAiBoxState();

            try {
                const url = `/admin/ai_insights.php?store_id=<?= (int)$storeId ?>&round15=<?= $roundOn ? 1 : 0 ?>`;
                const res = await fetch(url, {
                    credentials: 'same-origin'
                });

                const ct = (res.headers.get('content-type') || '').toLowerCase();
                if (!ct.includes('application/json')) {
                    const t = await res.text();
                    throw new Error('JSONではない応答です（ai_insights.phpのエラーの可能性）: ' + t.slice(0, 200));
                }

                const json = await res.json();
                if (!json.ok) throw new Error(json.message || json.error || 'unknown error');

                setStatus(`AI改善案（${json.from}〜${json.to} / 平均人件費率 ${json.rate_avg ?? '-'}%）`, false);
                el('aiText').innerHTML = formatAiAnswer(json.insights || '(テキストが空でした)');

                aiCtx.loaded = true;
                aiCtx.from = json.from;
                aiCtx.to = json.to;
                aiCtx.rate_avg = json.rate_avg ?? null;
                aiCtx.insights = json.insights || '';
                aiCtx.daily = json.daily ?? null;

                showAskWrap(true);
                showDeepBtns(true);

                const q = normalizeQuestion(el('aiAskInput').value);
                setAskEnabled(q.length > 0);
                saveAiBoxState();
            } catch (e) {
                setStatus('エラー', false);
                el('aiText').textContent = String(e.message || e);
                aiCtx.loaded = false;
                showAskWrap(false);
                saveAiBoxState();
            } finally {
                spinnerOn(false);
                saveAiBoxState();
            }
        }

        function appendFollowupTurn(question, answer) {
            const log = el('aiChatLog');
            showChatLog(true);

            const wrap = document.createElement('div');
            wrap.className = 'aiTurn';

            const head = document.createElement('div');
            head.className = 'aiTurnHead';
            head.innerHTML =
                `<span class="tag">追加質問</span><span class="muted" style="font-size:11px;font-weight:800;">${new Date().toLocaleString()}</span>`;

            const q = document.createElement('div');
            q.className = 'aiTurnQ';
            q.textContent = 'Q. ' + question;

            const a = document.createElement('div');
            a.className = 'aiTurnA';
            a.innerHTML = formatAiAnswer(answer || '(空でした)');

            wrap.appendChild(head);
            wrap.appendChild(q);
            wrap.appendChild(a);
            log.appendChild(wrap);

            wrap.scrollIntoView({
                block: 'end',
                behavior: 'smooth'
            });
            saveAiBoxState();
        }

        function showDeepBtns(on) {
            const r = document.getElementById('deepBtnsRow');
            if (!r) return;
            r.style.display = on ? 'flex' : 'none';
        }

        function deepDive(n) {
            if (!aiCtx.loaded) {
                alert('先に「AIに相談する（改善案）」を押してください。');
                return;
            }
            const map = {
                1: '1) 結論を深掘りして。数字根拠（売上合計/人件費合計/営業日数）と、最優先の打ち手を3つに絞って。',
                2: '2) 今の問題を深掘りして。人件費率が跳ねた日（上位3日）と原因仮説（売上/人件費/出勤ログの観点）を出して。',
                3: '3) すぐやる改善を深掘りして。明日から実行できるチェックリスト（誰が/何を/いつまで）で書いて。',
                4: '4) 従業員評価を深掘りして。打刻データが取れている範囲で「打刻漏れ疑い」「残業過多疑い」「出勤日数」を示し、取れていないなら必要なデータ項目を列挙して。',
                5: '5) 明日やることを深掘りして。30分のミーティング台本（議題/確認する数値/決めること/次回までの宿題）を作って。',
                6: <?= json_encode(
                    '6) 経費も含めて分析して。期間=' . $periodTitle .
                    '、売上=' . number_format((int)$salesMonth) . '円' .
                    '、人件費=' . number_format((int)$laborMonth) . '円' .
                    '、経費日割=' . ($expenseHasSettings ? number_format((int)$expenseMonth) . '円' : '未設定') .
                    '、固定=' . number_format((int)$fixedExpenseMonth) . '円' .
                    '、月別=' . number_format((int)$monthlyExpenseMonth) . '円' .
                    '、推定利益=' . ($expenseHasSettings ? number_format((int)$estimatedProfit) . '円' : '未計算') .
                    '。売上・人件費・経費の兼ね合いと改善優先度を3つで。',
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) ?>
            };
            const q = map[n] || `${n}) を深掘りして。`;
            el('aiAskInput').value = q;
            setAskEnabled(true);
            saveAiBoxState();
        }

        async function sendAiQuestion() {
            if (!aiCtx.loaded) {
                alert('先に「AIに相談する（改善案）」を押してください。');
                return;
            }
            if (aiCtx.sending) return;

            const q = normalizeQuestion(el('aiAskInput').value);
            if (!q) {
                alert('質問を入力してください');
                return;
            }

            aiCtx.sending = true;
            setAskEnabled(false);
            setSendLoading(true);
            setStatus('送信中…', true);
            saveAiBoxState();

            try {
                const form = new FormData();
                form.append('store_id', String(<?= (int)$storeId ?>));
                form.append('round15', String(<?= $roundOn ? 1 : 0 ?>));
                form.append('question', q);
                form.append('from', String(aiCtx.from || ''));
                form.append('to', String(aiCtx.to || ''));
                form.append('rate_avg', String(aiCtx.rate_avg ?? ''));
                form.append('insights', String(aiCtx.insights || ''));

                form.append('employee_summary_period_from', String(EMPLOYEE_SUMMARY_PERIOD.from));
                form.append('employee_summary_period_to', String(EMPLOYEE_SUMMARY_PERIOD.to));
                form.append('employee_summary_json', JSON.stringify(EMPLOYEE_SUMMARY_30 || []));
                form.append('__debug', '1');

                const res = await fetch('/admin/ai_followup.php', {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin'
                });

                const ct = (res.headers.get('content-type') || '').toLowerCase();
                if (!ct.includes('application/json')) {
                    const t = await res.text();
                    throw new Error('JSONではない応答です（ai_followup.phpのエラーの可能性）: ' + t.slice(0, 200));
                }

                const json = await res.json();
                if (!json.ok) throw new Error(json.message || json.error || 'unknown error');

                appendFollowupTurn(q, json.answer || '');
                el('aiAskInput').value = '';
                setStatus(`AI改善案（${aiCtx.from}〜${aiCtx.to}）`, false);
                saveAiBoxState();
            } catch (e) {
                setStatus('エラー', false);
                appendFollowupTurn(q, '[追質問エラー] ' + String(e.message || e));
                el('aiAskInput').value = '';
                saveAiBoxState();
            } finally {
                aiCtx.sending = false;
                spinnerOn(false);
                setSendLoading(false);
                const q2 = normalizeQuestion(el('aiAskInput').value);
                setAskEnabled(aiCtx.loaded && q2.length > 0);
                saveAiBoxState();
            }
        }

        function closeAiChat() {
            el('aiBox').style.display = 'none';
            resetChatUi();
            clearAiBoxState();
        }

        (function persistChartAccordion() {
            const acc = document.getElementById('chartAccordion');
            if (!acc) return;
            acc.open = true;
        })();

        /* =========================================================
           ✅ タブUI（UIのみ / 既存機能に影響なし）
           ========================================================= */
        const TAB_KEY = `adminIndexTab:v1:store<?= (int)$storeId ?>`;

        function uiTabsOpen(tabId) {
            const tabs = Array.from(document.querySelectorAll('.tabBtn[data-tab]'));
            const panels = Array.from(document.querySelectorAll('.tabPanel[data-panel]'));
            const periodForm = document.getElementById('periodFilterForm');

            tabs.forEach(btn => {
                const on = (btn.getAttribute('data-tab') === tabId);
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            panels.forEach(p => {
                const on = (p.getAttribute('data-panel') === tabId);
                p.classList.toggle('isActive', on);
            });

            try {
                localStorage.setItem(TAB_KEY, tabId);
            } catch (e) {}

            if (periodForm) {
                periodForm.style.display = (tabId === 'ai') ? 'none' : '';
            }
        }

        window.openAiConsultation = function openAiConsultation() {
            document.body.classList.add('aiConsultMode');
            uiTabsOpen('ai');
            const aiPanel = document.querySelector('.tabPanel[data-panel="ai"]');
            if (aiPanel) {
                aiPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        (function initTabs() {
            if (document.body.classList.contains('adminHomeDashboard')) {
                const tabs = Array.from(document.querySelectorAll('.tabBtn[data-tab]'));
                tabs.forEach(btn => {
                    btn.addEventListener('click', () => {
                        const id = btn.getAttribute('data-tab');
                        if (!id) return;
                        document.body.classList.toggle('aiConsultMode', id === 'ai');
                        uiTabsOpen(id);
                        window.scrollTo({
                            top: 0,
                            behavior: 'smooth'
                        });
                    });
                });
                uiTabsOpen('dash');
                return;
            }

            const tabs = Array.from(document.querySelectorAll('.tabBtn[data-tab]'));
            if (!tabs.length) return;

            tabs.forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-tab');
                    if (!id) return;
                    uiTabsOpen(id);
                    // “見ながらチャット”用途で、AIタブは開いたら上に来ると分かりやすい
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                });
            });

            // 初期タブ（保存があれば復元）
            let saved = null;
            try {
                saved = localStorage.getItem(TAB_KEY);
            } catch (e) {}
            if (saved && document.querySelector(`.tabBtn[data-tab="${saved}"]`)) {
                uiTabsOpen(saved);
            } else {
                uiTabsOpen('dash');
        }
    })();

    (function openAiFromQuery() {
        if (!document.body.classList.contains('adminHomeDashboard')) return;
        const params = new URLSearchParams(window.location.search);
        if (params.get('open_ai') !== '1') return;
        window.setTimeout(() => {
            if (typeof window.openAiConsultation === 'function') {
                window.openAiConsultation();
            }
        }, 0);
    })();

    (function() {
        const table = document.querySelector('.tableWrap .table');
        const tableWrap = document.querySelector('.tableWrap');
        const tabsBar = document.querySelector('.tabsBar');
        if (!table || !tableWrap) return;

        const wrap = document.createElement('div');
        wrap.className = 'tableStickyWrap';
        const cloneTable = document.createElement('table');
        cloneTable.className = 'table tableSticky';
        wrap.appendChild(cloneTable);
        tableWrap.insertBefore(wrap, table);

        const buildClone = () => {
            const thead = table.querySelector('thead');
            if (!thead) return;
            cloneTable.innerHTML = '';
            cloneTable.appendChild(thead.cloneNode(true));
        };

        const syncWidths = () => {
            const head = table.querySelector('thead');
            const cloneHead = cloneTable.querySelector('thead');
            if (!head || !cloneHead) return;

            const rect = table.getBoundingClientRect();
            const topOffset = tabsBar ? tabsBar.getBoundingClientRect().height : 0;
            wrap.style.top = Math.max(0, topOffset) + 'px';
            wrap.style.left = rect.left + 'px';
            wrap.style.width = rect.width + 'px';

            const ths = head.querySelectorAll('th');
            const cloneThs = cloneHead.querySelectorAll('th');
            cloneTable.style.width = rect.width + 'px';
            ths.forEach((th, i) => {
                const c = cloneThs[i];
                if (!c) return;
                c.style.width = th.getBoundingClientRect().width + 'px';
            });
        };

        const shouldEnable = () => window.matchMedia('(min-width: 901px)').matches;

        const onScroll = () => {
            if (!shouldEnable()) {
                wrap.style.display = 'none';
                return;
            }
            const head = table.querySelector('thead');
            if (!head) return;
            const rect = table.getBoundingClientRect();
            const headHeight = head.getBoundingClientRect().height;
            const topOffset = tabsBar ? tabsBar.getBoundingClientRect().height : 0;
            const isVisible = rect.top < topOffset && rect.bottom - headHeight > topOffset;
            wrap.style.display = isVisible ? 'block' : 'none';
            if (isVisible) syncWidths();
        };

        buildClone();
        syncWidths();
        onScroll();

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', () => {
            buildClone();
            syncWidths();
            onScroll();
        });
    })();
        </script>

    </div>

</body>

</html>
