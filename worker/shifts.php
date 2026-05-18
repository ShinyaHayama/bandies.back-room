<?php
// /worker/shifts.php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_worker_login();

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
    exit('db.php not found');
}
require_once $dbFile;
require_once __DIR__ . '/../lib/shift_leave_requests.php';
require_once __DIR__ . '/../admin/_business_day.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
shift_leave_requests_ensure_schema($pdo);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmt_time(?string $t): string
{
    if ($t === null || $t === '') return '—';
    return substr($t, 0, 5);
}

$tenantId = (int)($_SESSION['worker_tenant_id'] ?? 0);
$storeId = (int)($_SESSION['worker_store_id'] ?? 0);
$employeeId = (int)($_SESSION['worker_employee_id'] ?? 0);
$employeeName = (string)($_SESSION['worker_employee_name'] ?? '');

if ($tenantId <= 0 || $storeId <= 0 || $employeeId <= 0) {
    worker_logout();
    header('Location: /worker/login.php');
    exit;
}

$salesAutoOpenAllowed = !empty($_SESSION['worker_sales_prompt_login_pending']);
unset($_SESSION['worker_sales_prompt_login_pending']);

$tab = (string)($_GET['tab'] ?? 'shift');
if (!in_array($tab, ['shift', 'attendance'], true)) $tab = 'shift';

$view = 'month';

$mode = (string)($_GET['mode'] ?? 'all');
if (!in_array($mode, ['all', 'me'], true)) $mode = 'all';

$dateStr = (string)($_GET['date'] ?? date('Y-m-d'));
$base = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr) ?: new DateTimeImmutable('today');

$first = $base->modify('first day of this month');
$last = $base->modify('last day of this month');
$days = [];
$cur = $first;
while ($cur <= $last) {
    $days[] = $cur;
    $cur = $cur->modify('+1 day');
}
$rangeStart = $first->format('Y-m-d');
$rangeEnd = $last->format('Y-m-d');
$prevDate = $base->modify('-1 month')->format('Y-m-d');
$nextDate = $base->modify('+1 month')->format('Y-m-d');
$title = 'シフト（月）';

function jp_holidays(int $year): array
{
    $holidays = [];

    $add = function (int $m, int $d) use (&$holidays, $year): void {
        $key = sprintf('%04d-%02d-%02d', $year, $m, $d);
        $holidays[$key] = true;
    };

    $nthMonday = function (int $m, int $n) use ($year): string {
        $dt = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
        $w = (int)$dt->format('w');
        $delta = (1 - $w + 7) % 7;
        $day = 1 + $delta + 7 * ($n - 1);
        return sprintf('%04d-%02d-%02d', $year, $m, $day);
    };

    // Fixed holidays
    $add(1, 1);   // New Year's Day
    $add(2, 11);  // National Foundation Day
    if ($year >= 2020) {
        $add(2, 23); // Emperor's Birthday
    }
    $add(4, 29);  // Showa Day
    $add(5, 3);   // Constitution Memorial Day
    $add(5, 4);   // Greenery Day
    $add(5, 5);   // Children's Day
    $add(8, 11);  // Mountain Day
    $add(11, 3);  // Culture Day
    $add(11, 23); // Labor Thanksgiving Day

    // Happy Monday system
    $holidays[$nthMonday(1, 2)] = true;  // Coming of Age Day
    $holidays[$nthMonday(7, 3)] = true;  // Marine Day
    $holidays[$nthMonday(9, 3)] = true;  // Respect for the Aged Day
    $holidays[$nthMonday(10, 2)] = true; // Sports Day

    // Equinoxes (1980-2099 formula)
    $vernal = (int)floor(20.8431 + 0.242194 * ($year - 1980) - floor(($year - 1980) / 4));
    $autumnal = (int)floor(23.2488 + 0.242194 * ($year - 1980) - floor(($year - 1980) / 4));
    $add(3, $vernal);
    $add(9, $autumnal);

    // Special cases for 2020/2021 (Olympics)
    if ($year === 2020) {
        $holidays['2020-07-23'] = true; // Marine Day
        $holidays['2020-07-24'] = true; // Sports Day
        $holidays['2020-08-10'] = true; // Mountain Day
        unset($holidays[$nthMonday(7, 3)], $holidays[$nthMonday(10, 2)], $holidays['2020-08-11']);
    } elseif ($year === 2021) {
        $holidays['2021-07-22'] = true; // Marine Day
        $holidays['2021-07-23'] = true; // Sports Day
        $holidays['2021-08-08'] = true; // Mountain Day
        $holidays['2021-08-09'] = true; // Substitute
        unset($holidays[$nthMonday(7, 3)], $holidays[$nthMonday(10, 2)], $holidays['2021-08-11']);
    }

    // Substitute holidays (if holiday falls on Sunday)
    $keys = array_keys($holidays);
    sort($keys);
    foreach ($keys as $day) {
        $dt = new DateTimeImmutable($day);
        if ((int)$dt->format('w') !== 0) continue;
        $sub = $dt;
        do {
            $sub = $sub->modify('+1 day');
            $subKey = $sub->format('Y-m-d');
        } while (isset($holidays[$subKey]));
        $holidays[$subKey] = true;
    }

    // Citizen's holiday (between two holidays)
    $all = array_keys($holidays);
    sort($all);
    $set = array_fill_keys($all, true);
    foreach ($all as $day) {
        $dt = new DateTimeImmutable($day);
        if ((int)$dt->format('w') === 0 || (int)$dt->format('w') === 6) continue;
        $prev = $dt->modify('-1 day')->format('Y-m-d');
        $next = $dt->modify('+1 day')->format('Y-m-d');
        if (isset($set[$prev]) && isset($set[$next])) {
            $set[$day] = true;
        }
    }

    return array_keys($set);
}

function url_with(array $override = []): string
{
    $params = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = (string)$v;
    }
    $qs = http_build_query($params);
    return $_SERVER['PHP_SELF'] . ($qs ? ('?' . $qs) : '');
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $st->execute([':col' => $column]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

// store
$storeName = '';
$salesPromptEnabled = false;
$storeTz = 'Asia/Tokyo';
$businessDayCutoff = '05:00:00';
$storeSelect = ['name'];
if (table_has_column($pdo, 'stores', 'clock_qr_sales_prompt')) $storeSelect[] = 'clock_qr_sales_prompt';
if (table_has_column($pdo, 'stores', 'payroll_tz')) $storeSelect[] = 'payroll_tz';
if (table_has_column($pdo, 'stores', 'business_day_cutoff_time')) $storeSelect[] = 'business_day_cutoff_time';
$st = $pdo->prepare("SELECT " . implode(', ', $storeSelect) . " FROM stores WHERE tenant_id = :t AND id = :s LIMIT 1");
$st->execute([':t' => $tenantId, ':s' => $storeId]);
$storeRow = $st->fetch() ?: [];
$storeName = (string)($storeRow['name'] ?? '');
$salesPromptEnabled = ((int)($storeRow['clock_qr_sales_prompt'] ?? 0) === 1);
$storeTz = (string)($storeRow['payroll_tz'] ?? 'Asia/Tokyo');
$businessDayCutoff = normalize_cutoff_time((string)($storeRow['business_day_cutoff_time'] ?? '05:00:00'));

// employees
$empStmt = $pdo->prepare("
    SELECT id, display_name
    FROM employees
    WHERE tenant_id = :t
      AND store_id  = :s
      AND employment_status = 'active'
    ORDER BY id ASC
");
$empStmt->execute([':t' => $tenantId, ':s' => $storeId]);
$employees = $empStmt->fetchAll();
$empNameMap = [];
foreach ($employees as $e) {
    $empNameMap[(int)$e['id']] = (string)$e['display_name'];
}

// shifts（deleted_at がある場合は除外）
$hasDeletedAt = false;
try {
    $colStmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shifts'
    ");
    $colStmt->execute();
    $cols = array_map(fn($r) => (string)$r['COLUMN_NAME'], $colStmt->fetchAll());
    $hasDeletedAt = in_array('deleted_at', $cols, true);
} catch (Throwable $e) {
    $hasDeletedAt = false;
}

$deletedSql = $hasDeletedAt ? " AND deleted_at IS NULL" : "";
$shiftStmt = $pdo->prepare("
    SELECT id, employee_id, shift_date, start_time, end_time, break_minutes, note
    FROM shifts
    WHERE tenant_id = :t
      AND store_id  = :s
      {$deletedSql}
      AND shift_date BETWEEN :a AND :b
    ORDER BY shift_date ASC, start_time ASC, id ASC
");
$shiftStmt->execute([
    ':t' => $tenantId,
    ':s' => $storeId,
    ':a' => $rangeStart,
    ':b' => $rangeEnd,
]);
$rows = $shiftStmt->fetchAll();

$shiftsByDay = [];
foreach ($rows as $r) {
    $day = (string)$r['shift_date'];
    $eid = (int)$r['employee_id'];
    $shiftsByDay[$day][] = [
        'employee_id' => $eid,
        'name' => $empNameMap[$eid] ?? ('ID:' . $eid),
        'start' => (string)($r['start_time'] ?? ''),
        'end' => (string)($r['end_time'] ?? ''),
        'break' => (int)($r['break_minutes'] ?? 0),
        'note' => (string)($r['note'] ?? ''),
    ];
}
$shiftsByDayFiltered = $shiftsByDay;
if ($mode === 'me') {
    $shiftsByDayFiltered = [];
    foreach ($shiftsByDay as $dayKey => $list) {
        $filtered = array_values(array_filter(
            $list,
            fn($r) => (int)$r['employee_id'] === $employeeId
        ));
        if ($filtered) $shiftsByDayFiltered[$dayKey] = $filtered;
    }
}
$shiftCalendarShiftsByDay = ($mode === 'all') ? $shiftsByDay : $shiftsByDayFiltered;

$leaveRequestsByDay = [];
try {
    $leaveStmt = $pdo->prepare("
        SELECT request_date, status, reason
        FROM shift_leave_requests
        WHERE tenant_id = :t
          AND store_id = :s
          AND employee_id = :e
          AND request_date BETWEEN :a AND :b
          AND status IN ('pending', 'approved')
        ORDER BY id DESC
    ");
    $leaveStmt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
        ':a' => $rangeStart,
        ':b' => $rangeEnd,
    ]);
    foreach ($leaveStmt->fetchAll() as $row) {
        $day = (string)$row['request_date'];
        if (!isset($leaveRequestsByDay[$day])) {
            $leaveRequestsByDay[$day] = [
                'status' => (string)$row['status'],
                'reason' => (string)($row['reason'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    $leaveRequestsByDay = [];
}

$wdayJa = ['日', '月', '火', '水', '木', '金', '土'];
$monthLabel = $base->format('Y年m月');

// ===== 最終打刻の状態 =====
$lastPunchType = '';
$lastPunchAt = '';
try {
    $lp = $pdo->prepare("
        SELECT punch_type, punched_at
        FROM time_punches
        WHERE tenant_id = :t
          AND store_id  = :s
          AND employee_id = :e
          AND punch_type IN ('clock_in','clock_out')
        ORDER BY punched_at DESC, id DESC
        LIMIT 1
    ");
    $lp->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
    ]);
    $row = $lp->fetch();
    if ($row) {
        $lastPunchType = (string)($row['punch_type'] ?? '');
        $lastPunchAt = (string)($row['punched_at'] ?? '');
    }
} catch (Throwable $e) {
    $lastPunchType = '';
    $lastPunchAt = '';
}

$statusLabel = '未打刻';
$statusClass = 'none';
if ($lastPunchType === 'clock_in') {
    $statusLabel = '出勤中';
    $statusClass = 'in';
} elseif ($lastPunchType === 'clock_out') {
    $statusLabel = '退勤済';
    $statusClass = 'out';
}
$statusTime = ($lastPunchAt !== '') ? date('H:i', strtotime($lastPunchAt)) : '—';
$qrButtonLabel = '顔写真で出退勤';

// ===== 出勤履歴（過去12ヶ月） =====
$historyMonths = [];
$historyMonthKey = (string)($_GET['month'] ?? $base->format('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $historyMonthKey)) $historyMonthKey = $base->format('Y-m');
$monthCursor = (new DateTimeImmutable('first day of this month'))->modify('+1 month');
for ($i = 0; $i < 12; $i++) {
    $monthCursor = $monthCursor->modify('-1 month');
    $key = $monthCursor->format('Y-m');
    $historyMonths[] = [
        'key' => $key,
        'label' => $monthCursor->format('Y年m月'),
    ];
}
$validMonthKeys = array_column($historyMonths, 'key');
if (!in_array($historyMonthKey, $validMonthKeys, true)) {
    $historyMonthKey = $historyMonths[0]['key'];
}
$histStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $historyMonthKey . '-01 00:00:00')
    ?: new DateTimeImmutable('first day of this month 00:00:00');
$histEnd = $histStart->modify('+1 month');
$attendanceByDay = [];
if ($tab === 'attendance') {
    $fetchStart = $histStart->modify('-7 days');
    $fetchEnd = $histEnd->modify('+7 days');
    $attStmt = $pdo->prepare("
        SELECT punch_type, punched_at
        FROM time_punches
        WHERE tenant_id = :t
          AND store_id  = :s
          AND employee_id = :e
          AND punch_type IN ('clock_in','clock_out')
          AND punched_at >= :a
          AND punched_at < :b
        ORDER BY punched_at ASC
    ");
    $attStmt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
        ':a' => $fetchStart->format('Y-m-d H:i:s'),
        ':b' => $fetchEnd->format('Y-m-d H:i:s'),
    ]);
    $attRows = $attStmt->fetchAll();

    $addAttendancePunch = function (DateTimeImmutable $inDt, ?DateTimeImmutable $outDt) use (&$attendanceByDay, $histStart, $histEnd): void {
        $dayStart = $inDt->setTime(0, 0, 0);
        if ($dayStart < $histStart || $dayStart >= $histEnd) return;

        $dayKey = $inDt->format('Y-m-d');
        if (!isset($attendanceByDay[$dayKey])) {
            $attendanceByDay[$dayKey] = [
                'ins' => [],
                'outs' => [],
            ];
        }
        $inKey = $inDt->format('Y-m-d H:i:s');
        $existingIns = array_map(fn($dt) => $dt instanceof DateTimeImmutable ? $dt->format('Y-m-d H:i:s') : '', $attendanceByDay[$dayKey]['ins']);
        if (!in_array($inKey, $existingIns, true)) {
            $attendanceByDay[$dayKey]['ins'][] = $inDt;
        }
        if ($outDt !== null) {
            $outKey = $outDt->format('Y-m-d H:i:s');
            $existingOuts = array_map(fn($dt) => $dt instanceof DateTimeImmutable ? $dt->format('Y-m-d H:i:s') : '', $attendanceByDay[$dayKey]['outs']);
            if (!in_array($outKey, $existingOuts, true)) {
                $attendanceByDay[$dayKey]['outs'][] = $outDt;
            }
        }
    };

    $openIn = null;
    foreach ($attRows as $r) {
        $dt = new DateTimeImmutable((string)$r['punched_at']);
        if ((string)$r['punch_type'] === 'clock_in') {
            if ($openIn instanceof DateTimeImmutable) {
                $addAttendancePunch($openIn, null);
            }
            $openIn = $dt;
        } else {
            if ($openIn instanceof DateTimeImmutable && $dt > $openIn) {
                $addAttendancePunch($openIn, $dt);
                $openIn = null;
            }
        }
    }
    if ($openIn instanceof DateTimeImmutable) {
        $addAttendancePunch($openIn, null);
    }
    ksort($attendanceByDay);
}
$todayKey = (new DateTimeImmutable('today'))->format('Y-m-d');

function build_calendar_days(DateTimeImmutable $monthFirst): array
{
    $startDow = (int)$monthFirst->format('w');
    $start = $monthFirst->modify("-{$startDow} day");
    $days = [];
    for ($i = 0; $i < 42; $i++) {
        $days[] = $start->modify("+{$i} day");
    }
    return $days;
}

function month_includes(DateTimeImmutable $monthFirst, string $ymd): bool
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    if (!$dt) return false;
    return $dt->format('Y-m') === $monthFirst->format('Y-m');
}

function holiday_set(array $days): array
{
    $years = [];
    foreach ($days as $d) {
        $years[(int)$d->format('Y')] = true;
    }
    $set = [];
    foreach (array_keys($years) as $y) {
        foreach (jp_holidays((int)$y) as $k) {
            $set[$k] = true;
        }
    }
    return $set;
}

$shiftMonthFirst = $first;
$attendanceMonthFirst = DateTimeImmutable::createFromFormat('Y-m-d', $historyMonthKey . '-01') ?: $first;
$shiftCalendarDays = build_calendar_days($shiftMonthFirst);
$attendanceCalendarDays = build_calendar_days($attendanceMonthFirst);

$shiftHolidays = holiday_set($shiftCalendarDays);
$attendanceHolidays = holiday_set($attendanceCalendarDays);

$salesMissingByDay = [];
$salesEntryDataByDay = [];
$salesNoticeMissingDays = [];
$salesAutoOpenDate = '';
$todayBusinessDate = '';
if ($salesPromptEnabled) {
    try {
        $tzObj = new DateTimeZone($storeTz);
    } catch (Throwable $e) {
        $tzObj = new DateTimeZone('Asia/Tokyo');
    }
    $todayBusinessDate = business_date_from_datetime(new DateTimeImmutable('now', $tzObj), $businessDayCutoff);
    $hasSalesConfirmed = table_has_column($pdo, 'daily_store_reports', 'sales_confirmed');
    $hasSalesReportedAt = table_has_column($pdo, 'daily_store_reports', 'reported_at');
    $hasSalesCreatedAt = table_has_column($pdo, 'daily_store_reports', 'created_at');
    $salesEmployeeFilterId = ($tab === 'attendance' || $mode === 'me') ? $employeeId : 0;

    $loadSalesState = function (DateTimeImmutable $start, DateTimeImmutable $end) use (
        $pdo,
        $tenantId,
        $storeId,
        $salesEmployeeFilterId,
        $tzObj,
        $businessDayCutoff,
        $todayBusinessDate,
        $hasSalesConfirmed,
        $hasSalesReportedAt,
        $hasSalesCreatedAt
    ): array {
        $workDays = [];
        $fetchStart = $start->modify('-1 day');
        $fetchEnd = $end->modify('+1 day');

        try {
            $employeeSql = $salesEmployeeFilterId > 0 ? " AND employee_id = :e" : "";
            $punchStmt = $pdo->prepare("
                SELECT punched_at
                FROM time_punches
                WHERE tenant_id = :t
                  AND store_id = :s
                  {$employeeSql}
                  AND punch_type = 'clock_in'
                  AND punched_at >= :a
                  AND punched_at < :b
                ORDER BY punched_at ASC
            ");
            $punchParams = [
                ':t' => $tenantId,
                ':s' => $storeId,
                ':a' => $fetchStart->format('Y-m-d H:i:s'),
                ':b' => $fetchEnd->format('Y-m-d H:i:s'),
            ];
            if ($salesEmployeeFilterId > 0) {
                $punchParams[':e'] = $salesEmployeeFilterId;
            }
            $punchStmt->execute($punchParams);
            foreach ($punchStmt->fetchAll() as $row) {
                $dt = new DateTimeImmutable((string)$row['punched_at'], $tzObj);
                $bizDate = business_date_from_datetime($dt, $businessDayCutoff);
                if ($bizDate >= $start->format('Y-m-d') && $bizDate < $end->format('Y-m-d') && $bizDate <= $todayBusinessDate) {
                    $workDays[$bizDate] = true;
                }
            }
        } catch (Throwable $e) {
            $workDays = [];
        }

        $salesByDay = [];
        try {
            $confirmedSelect = $hasSalesConfirmed ? ", COALESCE(sales_confirmed, 0) AS sales_confirmed" : ", 1 AS sales_confirmed";
            $reportedAtSelect = $hasSalesReportedAt ? ", reported_at" : ", NULL AS reported_at";
            $createdAtSelect = $hasSalesCreatedAt ? ", created_at" : ", NULL AS created_at";
            $salesStmt = $pdo->prepare("
                SELECT
                    business_date,
                    COALESCE(sales_yen, 0) AS sales_yen,
                    COALESCE(visitors, 0) AS visitors
                    {$confirmedSelect}
                    {$reportedAtSelect}
                    {$createdAtSelect}
                FROM daily_store_reports
                WHERE tenant_id = :t
                  AND store_id = :s
                  AND business_date >= DATE_SUB(:a, INTERVAL 1 DAY)
                  AND business_date < DATE_ADD(:b, INTERVAL 1 DAY)
            ");
            $salesStmt->execute([
                ':t' => $tenantId,
                ':s' => $storeId,
                ':a' => $start->format('Y-m-d'),
                ':b' => $end->format('Y-m-d'),
            ]);
            foreach ($salesStmt->fetchAll() as $row) {
                $baseDate = (string)$row['business_date'];
                $dates = [$baseDate];
                $reportedAt = (string)($row['reported_at'] ?? '');
                $createdAt = (string)($row['created_at'] ?? '');
                if ($reportedAt !== '') {
                    $dates[] = business_date_from_datetime(new DateTimeImmutable($reportedAt, $tzObj), $businessDayCutoff);
                } elseif ($createdAt !== '') {
                    $corrected = business_date_from_datetime(new DateTimeImmutable($createdAt, $tzObj), $businessDayCutoff);
                    $createdCalendarDay = substr($createdAt, 0, 10);
                    if ($corrected !== $createdCalendarDay && $baseDate === $createdCalendarDay) {
                        $dates[] = $corrected;
                    }
                }

                foreach (array_unique($dates) as $d) {
                    if ($d < $start->format('Y-m-d') || $d >= $end->format('Y-m-d')) continue;

                    if (!isset($salesByDay[$d])) {
                        $salesByDay[$d] = [
                            'business_date' => $d,
                            'sales_yen' => 0,
                            'visitors' => 0,
                            'confirmed' => false,
                        ];
                    }
                    $salesByDay[$d]['sales_yen'] += (int)($row['sales_yen'] ?? 0);
                    $salesByDay[$d]['visitors'] += (int)($row['visitors'] ?? 0);
                    $salesByDay[$d]['confirmed'] = $salesByDay[$d]['confirmed'] || ((int)($row['sales_confirmed'] ?? 1) === 1);
                }
            }
        } catch (Throwable $e) {
            $salesByDay = [];
        }

        $missing = [];
        foreach (array_keys($workDays) as $d) {
            $row = $salesByDay[$d] ?? null;
            $salesEntered = $row !== null && (int)($row['sales_yen'] ?? 0) > 0;
            $zeroSalesConfirmed = $row !== null && (int)($row['sales_yen'] ?? 0) === 0 && !empty($row['confirmed']);
            if (!$salesEntered && !$zeroSalesConfirmed) {
                $missing[$d] = true;
                if ($row === null) {
                    $salesByDay[$d] = [
                        'business_date' => $d,
                        'sales_yen' => 0,
                        'visitors' => 0,
                        'confirmed' => false,
                    ];
                }
            }
        }
        ksort($missing);
        ksort($salesByDay);

        return [$missing, $salesByDay];
    };

    $visibleStart = ($tab === 'attendance') ? $attendanceMonthFirst : $shiftMonthFirst;
    $visibleEnd = $visibleStart->modify('+1 month');
    [$salesMissingByDay, $salesEntryDataByDay] = $loadSalesState($visibleStart, $visibleEnd);

    $noticeEnd = (new DateTimeImmutable($todayBusinessDate . ' 00:00:00'))->modify('+1 day');
    $noticeStart = $noticeEnd->modify('-31 days');
    [$noticeMissingMap, $noticeSalesMap] = $loadSalesState($noticeStart, $noticeEnd);
    $salesNoticeMissingDays = array_keys($noticeMissingMap);
    $salesEntryDataByDay = array_replace($salesEntryDataByDay, $noticeSalesMap);
    $salesAutoOpenDate = $salesAutoOpenAllowed ? ($salesNoticeMissingDays[0] ?? '') : '';
}

$shiftSelected = month_includes($shiftMonthFirst, $todayKey) ? $todayKey : $shiftMonthFirst->format('Y-m-01');
$attendanceSelected = month_includes($attendanceMonthFirst, $todayKey) ? $todayKey : $attendanceMonthFirst->format('Y-m-01');
$shiftDetailData = $shiftsByDayFiltered;
$leaveRequestData = $leaveRequestsByDay;
$salesNoticeScopeLabel = ($tab === 'attendance' || $mode === 'me') ? '自分' : '全員';
$attendanceDetailData = [];
foreach ($attendanceByDay as $dayKey => $pack) {
    $ins = array_map(fn($dt) => $dt instanceof DateTimeImmutable ? $dt->format('H:i') : '', $pack['ins']);
    $outs = array_map(fn($dt) => $dt instanceof DateTimeImmutable ? $dt->format('H:i') : '', $pack['outs']);
    $attendanceDetailData[$dayKey] = [
        'ins' => array_values(array_filter($ins)),
        'outs' => array_values(array_filter($outs)),
    ];
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>作業員シフト</title>
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #fff;
            --text: #111827;
            --muted: rgba(17, 24, 39, .6);
            --line: rgba(17, 24, 39, .12);
            --accent: #2563eb;
            --accent-soft: rgba(37, 99, 235, .12);
            --radius: 16px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
        }

        .page {
            padding: 16px 14px 80px;
            max-width: 100%;
        }

        .top {
            display: grid;
            gap: 12px;
            min-width: 0;
        }

        .topCard {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 14px 16px;
            box-shadow: 0 14px 40px rgba(15, 23, 42, .08);
            max-width: 100%;
            width: 100%;
            overflow: hidden;
        }

        .logoRow {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .logoRow img {
            height: 22px;
            width: auto;
            display: block;
        }

        .title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .sub {
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        .toolbar {
            display: grid;
            gap: 10px;
            width: 100%;
            max-width: 100%;
            min-width: 0;
        }

        .row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            width: 100%;
        }

        .toolbarRow {
            width: 100%;
            min-width: 0;
            overflow: hidden;
        }

        .row > * {
            min-width: 0;
        }

        .pill {
            padding: 6px 10px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid var(--line);
            font-size: 12px;
            font-weight: 600;
        }

        .btnGroup {
            display: inline-flex;
            gap: 6px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 4px;
        }

        .btn {
            border: none;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            background: transparent;
            text-decoration: none;
        }

        .btn.active {
            background: var(--accent);
            color: #fff;
        }

        .btn.actionSoft {
            background: #eef2ff;
            border: 1px solid rgba(37, 99, 235, .2);
            color: #1d4ed8;
            font-weight: 800;
        }

        .btn.actionSoft.warn {
            background: #fff7ed;
            border-color: rgba(220, 38, 38, .22);
            color: #b91c1c;
        }

        .btnGroup.full {
            width: 100%;
            justify-content: stretch;
        }

        .btnGroup.full .btn {
            flex: 1;
            text-align: center;
        }

        .switchGroup {
            display: inline-flex;
            gap: 0;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            overflow: hidden;
            height: 30px;
        }

        .switchBtn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text);
            text-decoration: none;
            min-width: 58px;
        }

        .switchBtn.active {
            background: var(--accent);
            color: #fff;
        }

        .tabGroup {
            display: flex;
            gap: 4px;
            background: #eef2ff;
            border-radius: 999px;
            padding: 4px;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            overflow: hidden;
        }

        .tabBtn {
            border: none;
            border-radius: 999px;
            padding: 8px 10px;
            font-size: 12px;
            font-weight: 700;
            background: transparent;
            color: var(--text);
            text-decoration: none;
            text-align: center;
            flex: 1 1 0;
            min-width: 0;
            white-space: normal;
            line-height: 1.2;
        }

        .tabBtn.active {
            background: #fff;
            box-shadow: 0 4px 12px rgba(15, 23, 42, .08);
        }

        .navBtn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--line);
            text-decoration: none;
            color: var(--text);
            font-weight: 600;
            font-size: 13px;
        }

        .logout {
            color: #b91c1c;
            padding: 6px 10px;
            font-size: 11px;
            border-radius: 999px;
        }

        .list {
            margin-top: 14px;
            display: grid;
            gap: 12px;
        }

        .dayCard {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 12px 14px;
        }

        .dayHead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .dayTitle {
            font-size: 14px;
            font-weight: 700;
        }

        .dayTitle.sun {
            color: #dc2626;
        }

        .dayTitle.sat {
            color: #2563eb;
        }

        .dayWday {
            font-size: 12px;
            color: var(--muted);
        }

        .dayWday.sun {
            color: #dc2626;
            font-weight: 700;
        }

        .dayWday.sat {
            color: #2563eb;
            font-weight: 700;
        }

        .calendar {
            margin-top: 12px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px;
            display: grid;
            gap: 6px;
        }

        .calendarGrid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 6px;
        }

        .calHead {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 6px;
            font-size: 11px;
            color: var(--muted);
            text-align: center;
            font-weight: 700;
        }

        .calCell {
            position: relative;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            min-height: 52px;
            padding: 6px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: flex-start;
            justify-content: flex-start;
            font-size: 12px;
            color: var(--text);
        }

        .calCell.is-outside {
            background: #f8fafc;
            color: var(--muted);
        }

        .calCell.is-selected {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, .12);
        }

        .calCell.sales-missing {
            border-color: rgba(220, 38, 38, .55);
            background: #fff7ed;
        }

        .calCell.is-sun,
        .calLabel.sun {
            color: #dc2626;
            font-weight: 700;
        }

        .calCell.is-sat,
        .calLabel.sat {
            color: #2563eb;
            font-weight: 700;
        }

        .calCell.is-holiday {
            color: #dc2626;
            font-weight: 700;
        }

        .calDate {
            font-weight: 700;
        }

        .calRest {
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            line-height: 1;
        }

        .calDot {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: #ef4444;
        }

        .calDot.gray {
            background: #9ca3af;
        }

        .calDot.sales {
            background: #dc2626;
        }

        .calSalesMiss {
            font-size: 10px;
            font-weight: 800;
            color: #b91c1c;
            line-height: 1;
        }

        .calDots {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .calButton {
            background: transparent;
            border: none;
            padding: 0;
            text-align: left;
            width: 100%;
        }

        .detailPanel {
            margin-top: 12px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 12px 14px;
            display: grid;
            gap: 10px;
        }

        .detailTitle {
            font-size: 13px;
            font-weight: 700;
        }

        .shiftRow {
            display: grid;
            gap: 4px;
            padding: 10px 10px;
            border-radius: 12px;
            border: 1px solid transparent;
            background: #fff;
            margin-bottom: 8px;
        }

        .shiftRow.me {
            background: var(--accent-soft);
            border-color: rgba(37, 99, 235, .22);
        }

        .shiftRow:last-child {
            margin-bottom: 0;
        }

        .shiftName {
            font-size: 13px;
            font-weight: 700;
        }

        .shiftTime {
            font-size: 13px;
            font-weight: 600;
        }

        .shiftMeta {
            font-size: 12px;
            color: var(--muted);
        }

        .logoutTop {
            margin-left: auto;
        }

        .srOnly {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .empty {
            font-size: 12px;
            color: var(--muted);
            text-align: center;
            padding: 8px 0 2px;
        }

        .salesNotice {
            margin-top: 12px;
            border: 1px solid rgba(220, 38, 38, .28);
            background: #fff7ed;
            color: #7f1d1d;
            border-radius: 12px;
            padding: 10px 12px;
            display: grid;
            gap: 8px;
            font-size: 12px;
        }

        .salesNoticeTitle {
            font-size: 13px;
            font-weight: 800;
        }

        .salesNoticeDays {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .salesDayBtn {
            appearance: none;
            border: 1px solid rgba(220, 38, 38, .35);
            background: #fff;
            color: #991b1b;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 12px;
            font-weight: 800;
        }

        .attSalesRow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--line);
            font-size: 12px;
            color: var(--muted);
        }

        .monthTabs {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 4px;
            width: 100%;
            min-width: 0;
            max-width: 100%;
        }

        .monthTabs::-webkit-scrollbar {
            height: 4px;
        }

        .monthTab {
            flex: 0 0 auto;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            color: var(--text);
        }

        .monthTab.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        .calRowActions {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
            margin-top: 12px;
        }

        .attRow {
            display: grid;
            gap: 6px;
            font-size: 13px;
        }

        .attTime {
            font-weight: 700;
        }

        .attMeta {
            font-size: 12px;
            color: var(--muted);
        }

        .qrAction {
            margin-top: 6px;
            display: grid;
            gap: 6px;
        }

        .qrButton {
            width: 100%;
            border: none;
            border-radius: 14px;
            background: var(--accent);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            padding: 18px 14px;
        }

        .qrStatus {
            font-size: 12px;
            color: var(--muted);
        }

        .toast {
            position: fixed;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%) scale(.98);
            background: #fff;
            color: #0f172a;
            padding: 18px 22px;
            border-radius: 16px;
            font-size: 18px;
            font-weight: 800;
            box-shadow: 0 20px 60px rgba(15, 23, 42, .25);
            border: 1px solid rgba(15, 23, 42, .12);
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s ease, transform .2s ease;
            z-index: 10000;
            min-width: 220px;
            text-align: center;
        }

        .toast.show {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }

        .punchStatus {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px 14px;
            display: grid;
            gap: 6px;
            background: #fff;
        }

        .punchStatus.in {
            border-color: rgba(34, 197, 94, .35);
            background: rgba(34, 197, 94, .08);
        }

        .punchStatus.out {
            border-color: rgba(59, 130, 246, .35);
            background: rgba(59, 130, 246, .08);
        }

        .punchStatus.none {
            border-color: rgba(148, 163, 184, .6);
            background: rgba(148, 163, 184, .08);
        }

        .punchLabel {
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
        }

        .punchValue {
            font-size: 20px;
            font-weight: 800;
        }

        .punchTime {
            font-size: 12px;
            color: var(--muted);
        }

        .salesModal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .4);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 10001;
        }

        .salesModal.active {
            display: flex;
        }

        .salesPanel {
            width: min(92vw, 420px);
            background: #fff;
            border-radius: 16px;
            padding: 16px;
            border: 1px solid var(--line);
            box-shadow: 0 20px 50px rgba(15, 23, 42, .2);
            display: grid;
            gap: 12px;
        }

        .salesTitle {
            font-size: 16px;
            font-weight: 800;
        }

        .salesGrid {
            display: grid;
            gap: 10px;
        }

        .salesField label {
            display: block;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .salesField input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
        }

        .salesActions {
            display: flex;
            gap: 8px;
        }

        .salesActions .btn {
            flex: 1;
            text-align: center;
        }

        .leaveModal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .4);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 10002;
        }

        .leaveModal.active {
            display: flex;
        }

        .leavePanel {
            width: min(92vw, 420px);
            background: #fff;
            border-radius: 16px;
            padding: 16px;
            border: 1px solid var(--line);
            box-shadow: 0 20px 50px rgba(15, 23, 42, .2);
            display: grid;
            gap: 12px;
        }

        .leaveTitle {
            font-size: 16px;
            font-weight: 800;
        }

        .leaveDate {
            font-size: 13px;
            color: var(--muted);
            font-weight: 700;
        }

        .leaveReason {
            width: 100%;
            min-height: 92px;
            resize: vertical;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            font: inherit;
        }

        .leaveActions,
        .leaveRequestArea {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .leaveRequestArea {
            justify-content: flex-end;
        }

        .leaveActions .btn {
            flex: 1;
            text-align: center;
        }

        .leaveBadge {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            background: #fef3c7;
            color: #92400e;
        }

        .leaveBadge.approved {
            background: #dcfce7;
            color: #166534;
        }

        .salesModal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .4);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 10001;
        }

        .salesModal.active {
            display: flex;
        }

        .salesPanel {
            width: min(92vw, 420px);
            background: #fff;
            border-radius: 16px;
            padding: 16px;
            border: 1px solid var(--line);
            box-shadow: 0 20px 50px rgba(15, 23, 42, .2);
            display: grid;
            gap: 12px;
        }

        .salesTitle {
            font-size: 16px;
            font-weight: 800;
        }

        .salesGrid {
            display: grid;
            gap: 10px;
        }

        .salesField label {
            display: block;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .salesField input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
        }

        .salesActions {
            display: flex;
            gap: 8px;
        }

        .salesActions .btn {
            flex: 1;
            text-align: center;
        }

        .qrModal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 9999;
        }

        .qrModal.active {
            display: flex;
        }

        .qrPanel {
            width: min(92vw, 420px);
            background: #fff;
            border-radius: 16px;
            padding: 14px;
            border: 1px solid var(--line);
            box-shadow: 0 20px 50px rgba(15, 23, 42, .2);
            display: grid;
            gap: 10px;
        }

        .qrHead {
            font-size: 14px;
            font-weight: 700;
        }

        .qrVideo {
            width: 100%;
            aspect-ratio: 3 / 4;
            background: #0f172a;
            border-radius: 12px;
        }

        .qrHint {
            font-size: 12px;
            color: var(--muted);
        }
    </style>
</head>

<body>
    <div class="page">
        <section class="top">
            <div class="topCard">
                <div class="logoRow">
                    <img src="/images/logo_main.png" alt="SHIMENABI">
                    <a class="navBtn logout logoutTop" href="/worker/login.php?logout=1">ログアウト</a>
                </div>
                <div class="row" style="justify-content:space-between;">
                    <div>
                        <h1 class="title srOnly"><?= h($tab === 'attendance' ? '出勤履歴' : $title) ?></h1>
                    </div>
                </div>

                <div class="punchStatus <?= h($statusClass) ?>" id="punchState" data-state="<?= h($statusClass) ?>">
                    <div class="punchLabel">現在の状態</div>
                    <div class="punchValue" id="punchStateValue"><?= h($statusLabel) ?></div>
                    <div class="punchTime" id="punchStateTime">最終打刻 <?= h($statusTime) ?></div>
                </div>

                <div class="qrAction">
                    <button class="qrButton" type="button" id="openQrScan"><?= h($qrButtonLabel) ?></button>
                    <div class="qrStatus" id="qrStatus">出勤時は顔写真と位置情報を取得して記録します。</div>
                </div>

                <?php if ($salesPromptEnabled && $salesNoticeMissingDays): ?>
                    <div class="salesNotice" id="salesNotice">
                        <div class="salesNoticeTitle">(<?= h($salesNoticeScopeLabel) ?>) 売上未入力の日があります。</div>
                        <div class="salesNoticeDays">
                            <?php foreach (array_slice($salesNoticeMissingDays, 0, 8) as $missingDay): ?>
                                <button type="button" class="salesDayBtn" data-sales-date="<?= h($missingDay) ?>">
                                    <?= h(substr($missingDay, 5)) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="toolbar" style="margin-top:12px;">
                    <div class="row toolbarRow">
                        <div class="tabGroup">
                            <a class="tabBtn <?= $tab === 'shift' ? 'active' : '' ?>" href="<?= h(url_with(['tab' => 'shift'])) ?>">シフト</a>
                            <a class="tabBtn <?= $tab === 'attendance' ? 'active' : '' ?>" href="<?= h(url_with(['tab' => 'attendance'])) ?>">出勤履歴</a>
                        </div>
                    </div>

                    <?php if ($tab === 'shift'): ?>
                        <div class="row toolbarRow">
                            <div class="switchGroup" aria-label="表示切り替え">
                                <a class="switchBtn <?= $mode === 'me' ? 'active' : '' ?>" href="<?= h(url_with(['mode' => 'me'])) ?>">自分だけ</a>
                                <a class="switchBtn <?= $mode === 'all' ? 'active' : '' ?>" href="<?= h(url_with(['mode' => 'all'])) ?>">全員</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row toolbarRow">
                            <div class="monthTabs">
                                <?php foreach ($historyMonths as $hm): ?>
                                    <a class="monthTab <?= $hm['key'] === $historyMonthKey ? 'active' : '' ?>"
                                        href="<?= h(url_with(['tab' => 'attendance', 'month' => $hm['key']])) ?>">
                                        <?= h($hm['label']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </section>

        <?php if ($tab === 'shift'): ?>
            <div class="calRowActions">
                <a class="navBtn" href="<?= h(url_with(['date' => $prevDate])) ?>">← 前</a>
                <div class="pill"><?= h($monthLabel) ?></div>
                <a class="navBtn" href="<?= h(url_with(['date' => $nextDate])) ?>">次 →</a>
            </div>
            <div class="calendar" id="shiftCalendar">
                <div class="calHead">
                    <div class="calLabel sun">日</div>
                    <div class="calLabel">月</div>
                    <div class="calLabel">火</div>
                    <div class="calLabel">水</div>
                    <div class="calLabel">木</div>
                    <div class="calLabel">金</div>
                    <div class="calLabel sat">土</div>
                </div>
                <div class="calendarGrid">
                    <?php foreach ($shiftCalendarDays as $d): ?>
                        <?php
                        $ymd = $d->format('Y-m-d');
                        $w = (int)$d->format('w');
                        $isOutside = $d->format('Y-m') !== $shiftMonthFirst->format('Y-m');
                        $isHoliday = isset($shiftHolidays[$ymd]);
                        $hasCalendarShift = isset($shiftCalendarShiftsByDay[$ymd]);
                        $hasMissingSales = $salesPromptEnabled && isset($salesMissingByDay[$ymd]);
                        $isRest = !$isOutside && !$hasCalendarShift;
                        $cellClass = 'calCell';
                        if ($isOutside) $cellClass .= ' is-outside';
                        if ($w === 0) $cellClass .= ' is-sun';
                        if ($w === 6) $cellClass .= ' is-sat';
                        if ($isHoliday) $cellClass .= ' is-holiday';
                        if ($hasMissingSales) $cellClass .= ' sales-missing';
                        if ($ymd === $shiftSelected) $cellClass .= ' is-selected';
                        ?>
                        <button class="<?= h($cellClass) ?> calButton" type="button" data-date="<?= h($ymd) ?>" data-cal="shift">
                            <div class="calDate"><?= h((string)$d->format('j')) ?></div>
                            <?php if ($hasCalendarShift || $hasMissingSales): ?>
                                <div class="calDots">
                                    <?php if ($hasMissingSales): ?>
                                        <span class="calDot sales"></span>
                                    <?php elseif ($hasCalendarShift): ?>
                                        <span class="calDot"></span>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($isRest): ?>
                                <div class="calRest">休</div>
                            <?php endif; ?>
                            <?php if ($hasMissingSales): ?>
                                <div class="calSalesMiss">売上未</div>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="detailPanel" id="shiftDetail">
                <div class="detailTitle" id="shiftDetailTitle"></div>
                <div id="shiftDetailBody"></div>
            </div>
        <?php else: ?>
            <div class="calendar" id="attendanceCalendar">
                <div class="calHead">
                    <div class="calLabel sun">日</div>
                    <div class="calLabel">月</div>
                    <div class="calLabel">火</div>
                    <div class="calLabel">水</div>
                    <div class="calLabel">木</div>
                    <div class="calLabel">金</div>
                    <div class="calLabel sat">土</div>
                </div>
                <div class="calendarGrid">
                    <?php foreach ($attendanceCalendarDays as $d): ?>
                        <?php
                        $ymd = $d->format('Y-m-d');
                        $w = (int)$d->format('w');
                        $isOutside = $d->format('Y-m') !== $attendanceMonthFirst->format('Y-m');
                        $isHoliday = isset($attendanceHolidays[$ymd]);
                        $hasDot = isset($attendanceByDay[$ymd]);
                        $hasMissingSales = $salesPromptEnabled && isset($salesMissingByDay[$ymd]);
                        $cellClass = 'calCell';
                        if ($isOutside) $cellClass .= ' is-outside';
                        if ($w === 0) $cellClass .= ' is-sun';
                        if ($w === 6) $cellClass .= ' is-sat';
                        if ($isHoliday) $cellClass .= ' is-holiday';
                        if ($hasMissingSales) $cellClass .= ' sales-missing';
                        if ($ymd === $attendanceSelected) $cellClass .= ' is-selected';
                        ?>
                        <button class="<?= h($cellClass) ?> calButton" type="button" data-date="<?= h($ymd) ?>" data-cal="attendance">
                            <div class="calDate"><?= h((string)$d->format('j')) ?></div>
                            <?php if ($hasDot || $hasMissingSales): ?>
                                <div class="calDots">
                                    <?php if ($hasMissingSales): ?>
                                        <span class="calDot sales"></span>
                                    <?php elseif ($hasDot): ?>
                                        <span class="calDot gray"></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($hasMissingSales): ?>
                                <div class="calSalesMiss">売上未</div>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="detailPanel" id="attendanceDetail">
                <div class="detailTitle" id="attendanceDetailTitle"></div>
                <div id="attendanceDetailBody"></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="toast" id="toast"></div>

    <div class="salesModal" id="salesModal" aria-hidden="true">
        <div class="salesPanel">
            <div class="salesTitle" id="salesModalTitle">売上入力</div>
            <div class="qrStatus" id="salesDateLabel"></div>
            <div class="salesGrid">
                <div class="salesField">
                    <label for="salesYen">売上（円）</label>
                    <input id="salesYen" type="number" min="0" step="1" inputmode="numeric" placeholder="例: 50000">
                </div>
                <div class="salesField">
                    <label for="salesVisitors">来客人数</label>
                    <input id="salesVisitors" type="number" min="0" step="1" inputmode="numeric" placeholder="例: 25">
                </div>
            </div>
            <div class="salesActions">
                <button type="button" class="btn" id="salesSkipBtn">入力しないで終了</button>
                <button type="button" class="btn active" id="salesSubmitBtn">送信</button>
            </div>
            <div class="qrStatus" id="salesStatus"></div>
        </div>
    </div>

    <div class="leaveModal" id="leaveModal" aria-hidden="true">
        <div class="leavePanel">
            <div class="leaveTitle">休み申請</div>
            <div class="leaveDate" id="leaveDateLabel"></div>
            <textarea class="leaveReason" id="leaveReason" placeholder="理由（任意）"></textarea>
            <div class="leaveActions">
                <button type="button" class="btn" id="leaveCancelBtn">キャンセル</button>
                <button type="button" class="btn active" id="leaveSubmitBtn">申請する</button>
            </div>
            <div class="qrStatus" id="leaveStatus"></div>
        </div>
    </div>

    <div class="qrModal" id="qrModal" aria-hidden="true">
        <div class="qrPanel">
            <div class="qrHead">顔写真を撮影してください</div>
            <video class="qrVideo" id="qrVideo" playsinline></video>
            <div class="qrHint" id="qrHint">顔が画面内に入るようにしてください。</div>
            <button type="button" class="qrButton" id="captureFacePunch">撮影して出勤</button>
            <button type="button" class="navBtn" id="closeQrScan">閉じる</button>
        </div>
    </div>

    <script>
        (() => {
            const shiftData = <?= json_encode($shiftDetailData, JSON_UNESCAPED_UNICODE) ?>;
            const leaveRequestData = <?= json_encode($leaveRequestData, JSON_UNESCAPED_UNICODE) ?>;
            const attendanceData = <?= json_encode($attendanceDetailData, JSON_UNESCAPED_UNICODE) ?>;
            const salesMissingData = <?= json_encode(array_fill_keys(array_keys($salesMissingByDay), true), JSON_UNESCAPED_UNICODE) ?>;
            const salesEntryData = <?= json_encode($salesEntryDataByDay, JSON_UNESCAPED_UNICODE) ?>;
            const salesAutoOpenDate = "<?= h($salesAutoOpenDate) ?>";
            window.salesMissingData = salesMissingData;
            window.salesEntryData = salesEntryData;
            window.salesAutoOpenDate = salesAutoOpenDate;
            const shiftSelected = "<?= h($shiftSelected) ?>";
            const attendanceSelected = "<?= h($attendanceSelected) ?>";
            const currentEmployeeId = <?= (int)$employeeId ?>;
            const wdayJa = ['日', '月', '火', '水', '木', '金', '土'];
            const leaveModal = document.getElementById('leaveModal');
            const leaveDateLabel = document.getElementById('leaveDateLabel');
            const leaveReason = document.getElementById('leaveReason');
            const leaveStatus = document.getElementById('leaveStatus');
            const leaveCancelBtn = document.getElementById('leaveCancelBtn');
            const leaveSubmitBtn = document.getElementById('leaveSubmitBtn');
            let leaveRequestDate = '';

            function formatLabel(ymd) {
                const parts = ymd.split('-').map((v) => parseInt(v, 10));
                if (parts.length !== 3 || !parts[0] || !parts[1] || !parts[2]) return ymd;
                const dt = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
                const w = dt.getUTCDay();
                return `${parts[1]}月${parts[2]}日（${wdayJa[w]}）`;
            }
            window.formatSalesDateLabel = formatLabel;

            function setSelected(cal, date) {
                const buttons = document.querySelectorAll(`.calButton[data-cal="${cal}"]`);
                buttons.forEach((btn) => {
                    btn.classList.toggle('is-selected', btn.dataset.date === date);
                });
            }

            function renderShiftDetail(date) {
                const title = document.getElementById('shiftDetailTitle');
                const body = document.getElementById('shiftDetailBody');
                if (!title || !body) return;
                title.textContent = formatLabel(date);
                body.innerHTML = '';
                const list = shiftData[date] || [];
                if (!list.length) {
                    const empty = document.createElement('div');
                    empty.className = 'empty';
                    empty.textContent = 'シフトなし';
                    body.appendChild(empty);
                    appendSalesInputArea(body, date);
                    appendLeaveRequestArea(body, date);
                    return;
                }
                list.forEach((r) => {
                    const row = document.createElement('div');
                    row.className = 'shiftRow' + (Number(r.employee_id) === currentEmployeeId ? ' me' : '');

                    const name = document.createElement('div');
                    name.className = 'shiftName';
                    name.textContent = String(r.name || '');

                    const time = document.createElement('div');
                    time.className = 'shiftTime';
                    const start = r.start ? String(r.start).slice(0, 5) : '—';
                    const end = r.end ? String(r.end).slice(0, 5) : '—';
                    time.textContent = `${start} 〜 ${end} / 休憩${Number(r.break || 0)}分`;

                    row.appendChild(name);
                    row.appendChild(time);

                    if (r.note && String(r.note).trim() !== '') {
                        const note = document.createElement('div');
                        note.className = 'shiftMeta';
                        note.textContent = `メモ: ${String(r.note)}`;
                        row.appendChild(note);
                    }

                    body.appendChild(row);
                });
                appendSalesInputArea(body, date);
                appendLeaveRequestArea(body, date);
            }

            function appendSalesInputArea(body, date) {
                if (!salesMissingData[date]) return;
                const area = document.createElement('div');
                area.className = 'attSalesRow';

                const label = document.createElement('div');
                label.textContent = '売上未入力';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn actionSoft warn';
                btn.textContent = '売上入力';
                btn.addEventListener('click', () => {
                    if (typeof window.openSalesModal === 'function') {
                        window.openSalesModal({ business_date: date });
                    }
                });

                area.appendChild(label);
                area.appendChild(btn);
                body.appendChild(area);
            }

            function appendLeaveRequestArea(body, date) {
                const area = document.createElement('div');
                area.className = 'leaveRequestArea';
                const request = leaveRequestData[date] || null;
                if (request && request.status) {
                    const badge = document.createElement('span');
                    badge.className = 'leaveBadge' + (request.status === 'approved' ? ' approved' : '');
                    badge.textContent = request.status === 'approved' ? '休み承認済み' : '休み申請中';
                    area.appendChild(badge);
                } else {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn actionSoft';
                    btn.textContent = '休み申請';
                    btn.addEventListener('click', () => {
                        openLeaveModal(date);
                    });
                    area.appendChild(btn);
                }
                body.appendChild(area);
            }

            window.updateLeaveRequestState = function (date, status, reason) {
                leaveRequestData[date] = {
                    status: status || 'pending',
                    reason: reason || ''
                };
                renderShiftDetail(date);
            };

            function showLocalToast(msg) {
                const el = document.getElementById('toast');
                if (!el) return;
                el.textContent = msg;
                el.classList.add('show');
                setTimeout(() => el.classList.remove('show'), 2000);
            }

            function openLeaveModal(date) {
                if (!leaveModal) return;
                leaveRequestDate = date;
                if (leaveDateLabel) leaveDateLabel.textContent = formatLabel(date);
                if (leaveReason) leaveReason.value = '';
                if (leaveStatus) leaveStatus.textContent = '';
                leaveModal.classList.add('active');
                leaveModal.setAttribute('aria-hidden', 'false');
            }

            function closeLeaveModal() {
                if (!leaveModal) return;
                leaveModal.classList.remove('active');
                leaveModal.setAttribute('aria-hidden', 'true');
            }

            function scrollToDetail(cal) {
                const panel = cal === 'attendance'
                    ? document.getElementById('attendanceDetail')
                    : document.getElementById('shiftDetail');
                if (!panel) return;
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            function renderAttendanceDetail(date) {
                const title = document.getElementById('attendanceDetailTitle');
                const body = document.getElementById('attendanceDetailBody');
                if (!title || !body) return;
                title.textContent = formatLabel(date);
                body.innerHTML = '';
                const pack = attendanceData[date];
                if (!pack) {
                    const empty = document.createElement('div');
                    empty.className = 'empty';
                    empty.textContent = '出勤履歴がありません';
                    body.appendChild(empty);
                    appendSalesInputArea(body, date);
                    return;
                }
                const ins = Array.isArray(pack.ins) ? pack.ins : [];
                const outs = Array.isArray(pack.outs) ? pack.outs : [];
                const inTime = ins.length ? ins[0] : '—';
                const outTime = outs.length ? outs[outs.length - 1] : '—';

                const row = document.createElement('div');
                row.className = 'attRow';

                const time = document.createElement('div');
                time.className = 'attTime';
                time.textContent = `出勤 ${inTime} / 退勤 ${outTime}`;

                const meta = document.createElement('div');
                meta.className = 'attMeta';
                meta.textContent = `打刻数: 出勤 ${ins.length} / 退勤 ${outs.length}`;

                row.appendChild(time);
                row.appendChild(meta);
                body.appendChild(row);
                appendSalesInputArea(body, date);
            }
            window.renderShiftDetailForSales = renderShiftDetail;
            window.renderAttendanceDetailForSales = renderAttendanceDetail;

            document.querySelectorAll('.calButton').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const date = btn.dataset.date;
                    const cal = btn.dataset.cal;
                    if (!date || !cal) return;
                    setSelected(cal, date);
                    if (cal === 'shift') renderShiftDetail(date);
                    if (cal === 'attendance') renderAttendanceDetail(date);
                    scrollToDetail(cal);
                });
            });

            if (document.getElementById('shiftDetail')) {
                setSelected('shift', shiftSelected);
                renderShiftDetail(shiftSelected);
            }
            if (document.getElementById('attendanceDetail')) {
                setSelected('attendance', attendanceSelected);
                renderAttendanceDetail(attendanceSelected);
            }

            if (leaveCancelBtn) {
                leaveCancelBtn.addEventListener('click', () => {
                    closeLeaveModal();
                });
            }

            if (leaveModal) {
                leaveModal.addEventListener('click', (e) => {
                    if (e.target === leaveModal) closeLeaveModal();
                });
            }

            if (leaveSubmitBtn) {
                leaveSubmitBtn.addEventListener('click', async () => {
                    if (!leaveRequestDate) {
                        if (leaveStatus) leaveStatus.textContent = '日付が取得できませんでした。';
                        return;
                    }
                    try {
                        leaveSubmitBtn.disabled = true;
                        if (leaveStatus) leaveStatus.textContent = '送信中...';
                        const res = await fetch('/worker/leave_request_submit.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                request_date: leaveRequestDate,
                                reason: leaveReason ? leaveReason.value : ''
                            })
                        });
                        const data = await res.json();
                        if (!data.ok) {
                            if (leaveStatus) leaveStatus.textContent = data.error || '申請に失敗しました。';
                            return;
                        }
                        closeLeaveModal();
                        window.updateLeaveRequestState(
                            leaveRequestDate,
                            data.status || 'pending',
                            leaveReason ? leaveReason.value : ''
                        );
                        showLocalToast(data.message || '休み申請を送信しました');
                    } catch (e) {
                        if (leaveStatus) leaveStatus.textContent = '申請に失敗しました。';
                    } finally {
                        leaveSubmitBtn.disabled = false;
                    }
                });
            }
        })();
    </script>

    <script>
        (() => {
            const openBtn = document.getElementById('openQrScan');
            const modal = document.getElementById('qrModal');
            const video = document.getElementById('qrVideo');
            const hint = document.getElementById('qrHint');
            const closeBtn = document.getElementById('closeQrScan');
            const captureBtn = document.getElementById('captureFacePunch');
            const statusEl = document.getElementById('qrStatus');
            const salesModal = document.getElementById('salesModal');
            const salesModalTitle = document.getElementById('salesModalTitle');
            const salesDateLabel = document.getElementById('salesDateLabel');
            const salesStatus = document.getElementById('salesStatus');
            const salesYen = document.getElementById('salesYen');
            const salesVisitors = document.getElementById('salesVisitors');
            const salesSkipBtn = document.getElementById('salesSkipBtn');
            const salesSubmitBtn = document.getElementById('salesSubmitBtn');
            const salesMissingData = window.salesMissingData || {};
            const salesEntryData = window.salesEntryData || {};
            const salesAutoOpenDate = window.salesAutoOpenDate || '';
            const formatLabel = window.formatSalesDateLabel || ((date) => date);
            let salesBusinessDate = '';
            let stream = null;
            let pendingLocation = null;
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d', { willReadFrequently: true });

            function setStatus(msg, isError = false) {
                if (!statusEl) return;
                statusEl.textContent = msg;
                statusEl.style.color = isError ? '#dc2626' : 'var(--muted)';
            }

            function playBeep() {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = 880;
                    gain.gain.value = 0.2;
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start();
                    setTimeout(() => {
                        osc.stop();
                        ctx.close();
                    }, 160);
                } catch (e) {
                    // ignore
                }
            }

            function vibrateOnce() {
                if (navigator.vibrate) {
                    navigator.vibrate(60);
                }
            }

            function updatePunchState(type, timeStr) {
                const box = document.getElementById('punchState');
                const val = document.getElementById('punchStateValue');
                const time = document.getElementById('punchStateTime');
                if (!box || !val || !time) return;
                const state = type === 'clock_out' ? 'out' : 'in';
                box.classList.remove('in', 'out', 'none');
                box.classList.add(state);
                box.dataset.state = state;
                val.textContent = type === 'clock_out' ? '退勤済' : '出勤中';
                time.textContent = `最終打刻 ${timeStr || '—'}`;
            }

            function openSalesModal(payload) {
                if (!salesModal) return;
                salesBusinessDate = payload.business_date || '';
                const saved = salesEntryData[salesBusinessDate] || {};
                const salesValue = Object.prototype.hasOwnProperty.call(payload, 'sales_yen') ? payload.sales_yen : saved.sales_yen;
                const visitorsValue = Object.prototype.hasOwnProperty.call(payload, 'visitors') ? payload.visitors : saved.visitors;
                if (salesModalTitle) salesModalTitle.textContent = salesBusinessDate ? '売上入力' : '本日の売上入力';
                if (salesDateLabel) salesDateLabel.textContent = salesBusinessDate ? `${formatLabel(salesBusinessDate)} の売上を入力してください。` : '';
                if (salesYen) salesYen.value = String(Number(salesValue || 0));
                if (salesVisitors) salesVisitors.value = String(Number(visitorsValue || 0));
                if (salesStatus) salesStatus.textContent = '';
                salesModal.classList.add('active');
                salesModal.setAttribute('aria-hidden', 'false');
            }
            window.openSalesModal = openSalesModal;

            function closeSalesModal() {
                if (!salesModal) return;
                salesModal.classList.remove('active');
                salesModal.setAttribute('aria-hidden', 'true');
            }

            function markSalesSaved(date, sales, visitors) {
                if (!date) return;
                delete salesMissingData[date];
                salesEntryData[date] = {
                    business_date: date,
                    sales_yen: sales,
                    visitors,
                    confirmed: true
                };
                document.querySelectorAll(`.calButton[data-date="${date}"]`).forEach((btn) => {
                    btn.classList.remove('sales-missing');
                    btn.querySelectorAll('.calDot.sales, .calSalesMiss').forEach((el) => el.remove());
                });
                document.querySelectorAll(`.salesDayBtn[data-sales-date="${date}"]`).forEach((btn) => btn.remove());
                const notice = document.getElementById('salesNotice');
                if (notice && !notice.querySelector('.salesDayBtn')) notice.remove();
                if (document.getElementById('attendanceDetail') && typeof window.renderAttendanceDetailForSales === 'function') {
                    window.renderAttendanceDetailForSales(date);
                }
                if (document.getElementById('shiftDetail') && typeof window.renderShiftDetailForSales === 'function') {
                    window.renderShiftDetailForSales(date);
                }
            }

            let toastTimer = null;
            function showToast(msg) {
                const el = document.getElementById('toast');
                if (!el) return;
                el.textContent = msg;
                el.classList.add('show');
                if (toastTimer) clearTimeout(toastTimer);
                toastTimer = setTimeout(() => {
                    el.classList.remove('show');
                }, 2000);
            }

            function stopScan() {
                if (stream) {
                    stream.getTracks().forEach((t) => t.stop());
                    stream = null;
                }
                pendingLocation = null;
                if (modal) {
                    modal.classList.remove('active');
                    modal.setAttribute('aria-hidden', 'true');
                }
            }

            function currentPunchState() {
                const box = document.getElementById('punchState');
                return box ? String(box.dataset.state || '') : '';
            }

            function getCurrentLocation() {
                return new Promise((resolve, reject) => {
                    if (!navigator.geolocation) {
                        reject(new Error('この端末では位置情報を取得できません。'));
                        return;
                    }
                    navigator.geolocation.getCurrentPosition(
                        (pos) => resolve({
                            latitude: pos.coords.latitude,
                            longitude: pos.coords.longitude,
                            location_accuracy_m: pos.coords.accuracy
                        }),
                        () => reject(new Error('位置情報の取得を許可してください。')),
                        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
                    );
                });
            }

            function captureFacePhoto() {
                if (!video || !ctx) throw new Error('顔写真を撮影できません。');
                const srcW = video.videoWidth || 0;
                const srcH = video.videoHeight || 0;
                if (srcW <= 0 || srcH <= 0) throw new Error('カメラ映像を取得できません。');

                const maxW = 720;
                const scale = Math.min(1, maxW / srcW);
                const w = Math.max(1, Math.round(srcW * scale));
                const h = Math.max(1, Math.round(srcH * scale));
                canvas.width = w;
                canvas.height = h;
                ctx.drawImage(video, 0, 0, w, h);
                return canvas.toDataURL('image/jpeg', 0.82);
            }

            async function sendPunch(payload = {}) {
                try {
                    const res = await fetch('/worker/qr_clock.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (!data.ok) {
                        setStatus(data.error || '打刻に失敗しました。', true);
                        return;
                    }
                    const label = data.punch_type === 'clock_out' ? '退勤' : '出勤';
                    const time = data.punched_at ? data.punched_at.slice(11, 16) : '';
                    setStatus(`${label}しました ${time ? '（' + time + '）' : ''}`);
                    updatePunchState(data.punch_type, time);
                    playBeep();
                    vibrateOnce();
                    showToast(`${label}しました`);
                    const promptSales = (data.prompt_sales === true || data.prompt_sales === 1 || data.prompt_sales === '1');
                    if (String(data.punch_type) === 'clock_out' && promptSales) {
                        openSalesModal(data);
                    }
                } catch (e) {
                    setStatus('通信に失敗しました。', true);
                }
            }

            async function startScan() {
                const nextIsClockIn = currentPunchState() !== 'in';
                if (!nextIsClockIn) {
                    await sendPunch({});
                    return;
                }

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    setStatus('この端末ではカメラを使用できません。', true);
                    return;
                }
                try {
                    setStatus('位置情報を取得しています...');
                    pendingLocation = await getCurrentLocation();
                    setStatus('カメラを起動しています...');
                    stream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: {
                                ideal: 'user'
                            }
                        },
                        audio: false
                    });
                    video.srcObject = stream;
                    video.setAttribute('playsinline', 'true');
                    await video.play();
                    modal.classList.add('active');
                    modal.setAttribute('aria-hidden', 'false');
                    hint.textContent = '顔が画面内に入ったら「撮影して出勤」を押してください。';
                    setStatus('顔写真を撮影してください。');
                } catch (e) {
                    stopScan();
                    setStatus(e && e.message ? e.message : 'カメラまたは位置情報の取得に失敗しました。', true);
                }
            }

            if (openBtn) {
                openBtn.addEventListener('click', () => {
                    startScan();
                });
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    stopScan();
                });
            }
            if (captureBtn) {
                captureBtn.addEventListener('click', async () => {
                    try {
                        captureBtn.disabled = true;
                        const facePhoto = captureFacePhoto();
                        const location = pendingLocation || await getCurrentLocation();
                        stopScan();
                        await sendPunch({
                            face_photo_data: facePhoto,
                            latitude: location.latitude,
                            longitude: location.longitude,
                            location_accuracy_m: location.location_accuracy_m
                        });
                    } catch (e) {
                        setStatus(e && e.message ? e.message : '撮影に失敗しました。', true);
                    } finally {
                        captureBtn.disabled = false;
                    }
                });
            }

            if (salesSkipBtn) {
                salesSkipBtn.addEventListener('click', () => {
                    closeSalesModal();
                });
            }

            if (salesSubmitBtn) {
                salesSubmitBtn.addEventListener('click', async () => {
                    if (!salesBusinessDate) {
                        if (salesStatus) salesStatus.textContent = '営業日が取得できませんでした。';
                        return;
                    }
                    const payload = {
                        business_date: salesBusinessDate,
                        sales_yen: Number(salesYen ? salesYen.value : 0) || 0,
                        visitors: Number(salesVisitors ? salesVisitors.value : 0) || 0,
                    };
                    try {
                        if (salesStatus) salesStatus.textContent = '送信中...';
                        const res = await fetch('/worker/qr_sales_save.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify(payload)
                        });
                        const data = await res.json();
                        if (!data.ok) {
                            if (salesStatus) salesStatus.textContent = data.error || '送信に失敗しました。';
                            return;
                        }
                        markSalesSaved(salesBusinessDate, payload.sales_yen, payload.visitors);
                        closeSalesModal();
                        showToast('売上を保存しました');
                    } catch (e) {
                        if (salesStatus) salesStatus.textContent = '送信に失敗しました。';
                    }
                });
            }

            document.querySelectorAll('.salesDayBtn').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const date = btn.dataset.salesDate || '';
                    if (date) openSalesModal({ business_date: date });
                });
            });

            if (salesAutoOpenDate) {
                window.setTimeout(() => {
                    if (salesMissingData[salesAutoOpenDate]) {
                        openSalesModal({ business_date: salesAutoOpenDate });
                    }
                }, 350);
            }

        })();
    </script>
</body>

</html>
