<?php
declare(strict_types=1);

$paths = [
    __DIR__ . '/api/lib/db.php',
    __DIR__ . '/lib/db.php',
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

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $stmt->execute([':col' => $column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_public_calendar_month_schema(PDO $pdo): bool
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS store_public_calendar_months (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                store_id INT NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                confirmed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_store_month (tenant_id, store_id, `year_month`),
                KEY idx_store_month_status (tenant_id, store_id, `year_month`, is_confirmed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function build_calendar_days(DateTimeImmutable $monthFirst): array
{
    $start = $monthFirst->modify('-' . (int)$monthFirst->format('w') . ' day');
    $last = $monthFirst->modify('last day of this month');
    $end = $last->modify('+' . (6 - (int)$last->format('w')) . ' day');
    $days = [];
    $cur = $start;
    while ($cur <= $end) {
        $days[] = $cur;
        $cur = $cur->modify('+1 day');
    }
    return $days;
}

function shift_minutes(string $time): ?int
{
    $time = substr($time, 0, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) return null;
    [$h, $m] = array_map('intval', explode(':', $time));
    return $h * 60 + $m;
}

function merge_shift_rows(array $current, array $rows): array
{
    foreach ($rows as $row) {
        $day = (string)$row['shift_date'];
        $start = substr((string)($row['start_time'] ?? ''), 0, 5);
        $end = substr((string)($row['end_time'] ?? ''), 0, 5);
        $startMin = shift_minutes($start);
        $endMin = shift_minutes($end);
        if ($startMin === null || $endMin === null) continue;

        $endNext = (int)($row['end_next_day'] ?? 0) === 1 || $endMin < $startMin;
        $orderEndMin = $endMin + ($endNext ? 1440 : 0);
        $updatedAt = (string)($row['updated_at'] ?? $row['created_at'] ?? '');
        $updatedTs = $updatedAt !== '' ? strtotime($updatedAt) : 0;

        if (!isset($current[$day])) {
            $current[$day] = [
                'count' => 0,
                'first_start' => $start,
                'first_start_min' => $startMin,
                'last_end' => $end,
                'last_end_order_min' => $orderEndMin,
                'updated_ts' => $updatedTs,
            ];
        }

        $bucket =& $current[$day];
        $bucket['count']++;
        if ($startMin < (int)$bucket['first_start_min']) {
            $bucket['first_start'] = $start;
            $bucket['first_start_min'] = $startMin;
        }
        if ($orderEndMin > (int)$bucket['last_end_order_min']) {
            $bucket['last_end'] = $end;
            $bucket['last_end_order_min'] = $orderEndMin;
        }
        if ($updatedTs > (int)$bucket['updated_ts']) {
            $bucket['updated_ts'] = $updatedTs;
        }
        unset($bucket);
    }
    return $current;
}

$token = trim((string)($_GET['token'] ?? ''));
$code = trim((string)($_GET['code'] ?? ''));
if ($token !== '' && !preg_match('/^[A-Za-z0-9]{24,64}$/', $token)) {
    http_response_code(404);
    exit('Not found');
}
if ($code !== '' && !preg_match('/^[A-Za-z0-9]{8,16}$/', $code)) {
    http_response_code(404);
    exit('Not found');
}
if ($token === '' && $code === '') {
    http_response_code(404);
    exit('Not found');
}

$hasPublicCalendarTitle = has_column($pdo, 'stores', 'public_calendar_title');
$hasPublicCalendarCode = has_column($pdo, 'stores', 'public_calendar_code');
if (!has_column($pdo, 'stores', 'public_calendar_enabled') || !has_column($pdo, 'stores', 'public_calendar_token')) {
    http_response_code(404);
    exit('Not found');
}

$titleSelect = $hasPublicCalendarTitle ? ", public_calendar_title" : "";
$codeSelect = $hasPublicCalendarCode ? ", public_calendar_code" : "";
$whereSql = ($code !== '' && $hasPublicCalendarCode)
    ? "public_calendar_code = :code"
    : "public_calendar_token = :token";
$storeStmt = $pdo->prepare("
    SELECT id, tenant_id, name{$titleSelect}{$codeSelect}
    FROM stores
    WHERE public_calendar_enabled = 1
      AND {$whereSql}
    LIMIT 1
");
$storeParams = ($code !== '' && $hasPublicCalendarCode) ? [':code' => $code] : [':token' => $token];
$storeStmt->execute($storeParams);
$store = $storeStmt->fetch();
if (!$store) {
    http_response_code(404);
    exit('Not found');
}

$tenantId = (int)$store['tenant_id'];
$storeId = (int)$store['id'];
$storeName = (string)$store['name'];
$publicCalendarTitle = trim((string)($store['public_calendar_title'] ?? ''));
$hasCustomTitle = ($publicCalendarTitle !== '');
if ($publicCalendarTitle === '') {
    $publicCalendarTitle = $storeName;
}
$publicCalendarSubtitle = $hasCustomTitle ? ($storeName . ' / 営業日カレンダー') : '営業日カレンダー';

$dateStr = (string)($_GET['date'] ?? date('Y-m-d'));
$base = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr) ?: new DateTimeImmutable('today');
$monthFirst = $base->modify('first day of this month');
$rangeStart = $monthFirst->format('Y-m-01');
$rangeEnd = $monthFirst->modify('last day of this month')->format('Y-m-d');
$monthYm = $monthFirst->format('Y-m');
$prevDate = $monthFirst->modify('-1 month')->format('Y-m-d');
$nextDate = $monthFirst->modify('+1 month')->format('Y-m-d');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$days = build_calendar_days($monthFirst);
$wdayJa = ['日', '月', '火', '水', '木', '金', '土'];
$monthFeatureAvailable = ensure_public_calendar_month_schema($pdo);
$monthStatus = [];
if ($monthFeatureAvailable) {
    $monthStatusStmt = $pdo->prepare("
        SELECT is_confirmed
        FROM store_public_calendar_months
        WHERE tenant_id = :t AND store_id = :s AND `year_month` = :ym
        LIMIT 1
    ");
    $monthStatusStmt->execute([':t' => $tenantId, ':s' => $storeId, ':ym' => $monthYm]);
    $monthStatus = $monthStatusStmt->fetch() ?: [];
}
$monthConfirmed = (int)($monthStatus['is_confirmed'] ?? 0) === 1;

$openDays = [];
$deletedSql = has_column($pdo, 'shifts', 'deleted_at') ? " AND sh.deleted_at IS NULL" : "";
$endNextSelect = has_column($pdo, 'shifts', 'end_next_day') ? "sh.end_next_day" : "0 AS end_next_day";
$updatedSelect = has_column($pdo, 'shifts', 'updated_at') ? "sh.updated_at" : "NULL AS updated_at";
$createdSelect = has_column($pdo, 'shifts', 'created_at') ? "sh.created_at" : "NULL AS created_at";
$shiftStmt = $pdo->prepare("
    SELECT sh.shift_date, sh.start_time, sh.end_time, {$endNextSelect}, {$updatedSelect}, {$createdSelect},
           sh.employee_id, e.display_name
    FROM shifts sh
    INNER JOIN employees e
        ON e.tenant_id = sh.tenant_id
       AND e.store_id = sh.store_id
       AND e.id = sh.employee_id
       AND e.employment_status = 'active'
    WHERE sh.tenant_id = :t
      AND sh.store_id = :s
      {$deletedSql}
      AND sh.shift_date BETWEEN :a AND :b
    ORDER BY sh.shift_date ASC, sh.start_time ASC, e.sort_order ASC, e.id ASC
");
$shiftStmt->execute([
    ':t' => $tenantId,
    ':s' => $storeId,
    ':a' => $rangeStart,
    ':b' => $rangeEnd,
]);
$shiftRows = $shiftStmt->fetchAll();
$shiftRowsByDay = merge_shift_rows([], $shiftRows);
foreach ($shiftRowsByDay as $day => $chosen) {
    $openDays[$day] = [
        'open' => ((int)$chosen['count'] > 0),
        'start' => (string)$chosen['first_start'],
        'end' => (string)$chosen['last_end'],
    ];
}

$staffByDay = [];
foreach ($shiftRows as $row) {
    $day = (string)$row['shift_date'];
    $name = trim((string)($row['display_name'] ?? ''));
    if ($day === '' || $name === '') continue;
    $start = substr((string)($row['start_time'] ?? ''), 0, 5);
    $end = substr((string)($row['end_time'] ?? ''), 0, 5);
    $staffByDay[$day][] = [
        'name' => $name,
        'time' => ($start !== '' && $end !== '') ? ($start . '-' . $end) : '',
    ];
}

$publicCalendarCode = (string)($store['public_calendar_code'] ?? '');
$baseUrl = $publicCalendarCode !== ''
    ? ('/c/' . rawurlencode($publicCalendarCode))
    : ('/calendar.php?token=' . rawurlencode($token));
$baseUrlDateSep = str_contains($baseUrl, '?') ? '&' : '?';
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($publicCalendarTitle) ?> 営業日カレンダー</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --card: #fff;
            --text: #111827;
            --muted: #6b7280;
            --soft: #f9fafb;
            --line: #e9edf3;
            --open: #2f6b48;
            --open-bg: #edf8f1;
            --closed: #8f4a4a;
            --closed-bg: #fbeeee;
            --pending: #8a6a2e;
            --pending-bg: #fbf5e6;
            --time: #6b7280;
            --sun-bg: #fffafa;
            --sun-text: #9b3a3f;
            --sat-bg: #f8fbff;
            --sat-text: #3d5f9f;
            --accent: #1f2937;
            --shadow: 0 18px 44px rgba(17, 24, 39, .08);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Noto Sans JP", sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap {
            max-width: 920px;
            margin: 0 auto;
            padding: 24px 16px 44px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 14px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        h1 {
            margin: 0;
            font-size: 24px;
            line-height: 1.3;
            letter-spacing: 0;
        }
        .sub {
            margin-top: 4px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }
        .nav {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 8px 12px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 1px 2px rgba(17, 24, 39, .05);
        }
        .month {
            min-width: 116px;
            text-align: center;
            font-weight: 900;
        }
        .calendar {
            background: var(--line);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 0;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .week,
        .grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 1px;
            background: var(--line);
        }
        .week {
            margin-bottom: 1px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 900;
            text-align: center;
        }
        .week > div {
            background: #fbfcfd;
            padding: 9px 0;
        }
        .week > div.sun {
            background: var(--sun-bg);
            color: var(--sun-text);
        }
        .week > div.sat {
            background: var(--sat-bg);
            color: var(--sat-text);
        }
        .day {
            min-height: 102px;
            border: 0;
            border-radius: 0;
            padding: 8px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 7px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .75);
        }
        .day[data-date] {
            cursor: pointer;
        }
        .day[data-date]:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: -2px;
            position: relative;
            z-index: 2;
        }
        .day.outside {
            background: var(--soft);
            color: #9ca3af;
        }
        .day.sun {
            background: var(--sun-bg);
        }
        .day.sat {
            background: var(--sat-bg);
        }
        .day.sun .date {
            color: var(--sun-text);
        }
        .day.sat .date {
            color: var(--sat-text);
        }
        .day.outside.sun,
        .day.outside.sat {
            background: var(--soft);
            border-color: var(--line);
        }
        .day.outside.sun .date,
        .day.outside.sat .date {
            color: #9ca3af;
        }
        .day.today {
            border-color: var(--accent);
            box-shadow: inset 0 0 0 2px var(--accent);
            position: relative;
            z-index: 1;
        }
        .day.is-open {
            background: #fbfffc;
            box-shadow: inset 0 0 0 1px rgba(47, 107, 72, .08);
        }
        .day.is-closed {
            background: #fffafa;
        }
        .date {
            font-weight: 900;
            font-size: 15px;
            line-height: 1;
        }
        .status {
            margin-top: auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 26px;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
            box-shadow: inset 0 0 0 1px rgba(17, 24, 39, .04);
        }
        .status.closed {
            margin: auto;
            min-height: 0;
            padding: 0;
            background: transparent;
            color: var(--closed);
            font-size: 24px;
            line-height: 1;
            box-shadow: none;
        }
        .status.openText {
            min-height: 0;
            padding: 0;
            background: transparent;
            color: var(--open);
            font-size: 12px;
            line-height: 1;
            box-shadow: none;
        }
        .status.pending {
            background: var(--pending-bg);
            color: var(--pending);
        }
        .hours {
            text-align: center;
            color: var(--time);
            font-size: 13px;
            font-weight: 900;
            line-height: 1.2;
            white-space: nowrap;
        }
        .legend {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }
        .staffModalBack {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(17, 24, 39, .42);
        }
        .staffModalBack.is-open {
            display: flex;
        }
        .staffModal {
            width: min(420px, 100%);
            max-height: min(78vh, 560px);
            overflow: hidden;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 22px 60px rgba(17, 24, 39, .22);
        }
        .staffModalHeader {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 18px 12px;
            border-bottom: 1px solid var(--line);
        }
        .staffModalDate {
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }
        .staffModal h2 {
            margin: 0;
            font-size: 18px;
            line-height: 1.35;
        }
        .modalClose {
            flex: 0 0 auto;
            width: 34px;
            height: 34px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: var(--text);
            font-size: 20px;
            font-weight: 800;
            line-height: 1;
            cursor: pointer;
        }
        .staffList {
            max-height: calc(min(78vh, 560px) - 75px);
            overflow: auto;
            padding: 8px 18px 18px;
        }
        .staffRow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--line);
        }
        .staffRow:last-child {
            border-bottom: 0;
        }
        .staffName {
            min-width: 0;
            color: var(--text);
            font-size: 15px;
            font-weight: 900;
            overflow-wrap: anywhere;
        }
        .staffTime {
            flex: 0 0 auto;
            color: var(--time);
            font-size: 13px;
            font-weight: 800;
            white-space: nowrap;
        }
        .staffEmpty {
            padding: 24px 0 18px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 800;
            text-align: center;
        }
        @page {
            size: A4 landscape;
            margin: 8mm;
        }
        @media print {
            :root {
                --bg: #fff;
                --line: #d1d5db;
            }
            html,
            body {
                width: 297mm;
                background: #fff;
            }
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .wrap {
                max-width: none;
                width: 100%;
                padding: 0;
            }
            .header {
                align-items: flex-start;
                margin-bottom: 6mm;
            }
            h1 {
                font-size: 18pt;
            }
            .sub {
                font-size: 9pt;
            }
            .nav {
                display: none;
            }
            .calendar {
                border-radius: 0;
                padding: 0;
                box-shadow: none;
                border-color: #9ca3af;
            }
            .week,
            .grid {
                gap: 0;
            }
            .week > div,
            .day {
                border-radius: 0;
            }
            .week {
                margin-bottom: 0;
                font-size: 8pt;
            }
            .week > div {
                padding: 2mm 0;
                border-right: 1px solid var(--line);
                border-bottom: 1px solid var(--line);
            }
            .week > div:last-child {
                border-right: 0;
            }
            .day {
                min-height: 23mm;
                padding: 2mm;
                gap: 1.5mm;
                border-left: 0;
                border-top: 0;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .date {
                font-size: 8pt;
            }
            .status {
                min-height: 0;
                padding: 1.2mm 1.6mm;
                font-size: 7pt;
            }
            .hours {
                font-size: 7pt;
                line-height: 1.2;
            }
            .legend {
                margin-top: 4mm;
                font-size: 8pt;
            }
            .staffModalBack {
                display: none !important;
            }
        }
        @media (max-width: 560px) {
            body { background: #f8fafc; }
            .wrap { padding: 12px 8px 28px; }
            .header { gap: 8px; margin-bottom: 10px; }
            h1 { font-size: 18px; }
            .sub { font-size: 12px; }
            .nav { gap: 5px; }
            .btn { min-height: 32px; padding: 6px 10px; font-size: 12px; }
            .month { min-width: 92px; font-size: 14px; }
            .calendar { padding: 0; border-radius: 14px; box-shadow: 0 10px 30px rgba(17, 24, 39, .08); }
            .week, .grid { gap: 1px; }
            .week { margin-bottom: 1px; font-size: 11px; }
            .week > div { padding: 7px 0; }
            .day {
                min-height: 76px;
                padding: 5px 4px 4px;
                border-radius: 0;
                gap: 3px;
                justify-content: space-between;
            }
            .day.today { box-shadow: inset 0 0 0 2px var(--accent); }
            .day.outside { background: #f8fafc; }
            .day.is-open { background: #fbfffc; }
            .day.is-closed { background: #fffafa; }
            .date { font-size: 13px; line-height: 1; }
            .status {
                align-self: center;
                min-height: 18px;
                padding: 3px 5px;
                font-size: 9px;
                line-height: 1;
            }
            .status.closed {
                margin: auto;
                padding: 0;
                font-size: 18px;
            }
            .status.openText {
                padding: 0;
                font-size: 9px;
            }
            .hours {
                font-size: 9px;
                line-height: 1.08;
                letter-spacing: 0;
                white-space: normal;
            }
            .hours .timeSep { display: none; }
            .hours .timeEnd { display: block; }
            .legend { font-size: 11px; line-height: 1.5; }
            .staffModalBack { align-items: flex-end; padding: 10px; }
            .staffModal { border-radius: 16px; max-height: 76vh; }
            .staffModalHeader { padding: 16px 16px 10px; }
            .staffModal h2 { font-size: 17px; }
            .staffList { padding: 6px 16px 16px; }
            .staffName { font-size: 14px; }
            .staffTime { font-size: 12px; }
        }
    </style>
</head>

<body>
    <main class="wrap">
        <div class="header">
            <div>
                <h1><?= h($publicCalendarTitle) ?></h1>
                <div class="sub"><?= h($publicCalendarSubtitle) ?></div>
            </div>
            <div class="nav">
                <a class="btn" href="<?= h($baseUrl . $baseUrlDateSep . 'date=' . rawurlencode($prevDate)) ?>">前月</a>
                <div class="month"><?= h($monthFirst->format('Y年n月')) ?></div>
                <a class="btn" href="<?= h($baseUrl . $baseUrlDateSep . 'date=' . rawurlencode($nextDate)) ?>">翌月</a>
                <button class="btn" type="button" onclick="window.print()">印刷</button>
            </div>
        </div>

        <section class="calendar" aria-label="営業日カレンダー">
            <div class="week">
                <?php foreach ($wdayJa as $i => $w): ?>
                    <div class="<?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><?= h($w) ?></div>
                <?php endforeach; ?>
            </div>
            <div class="grid">
                <?php foreach ($days as $d): ?>
                    <?php
                    $ymd = $d->format('Y-m-d');
                    $outside = $d->format('Y-m') !== $monthFirst->format('Y-m');
                    $w = (int)$d->format('w');
                    $dayInfo = $openDays[$ymd] ?? null;
                    $isOpen = !$outside && is_array($dayInfo) && !empty($dayInfo['open']);
                    $statusClass = $isOpen ? 'openText' : ($monthConfirmed ? 'closed' : 'pending');
                    $statusText = $isOpen ? '営業' : ($monthConfirmed ? '×' : '未定');
                    $hoursList = [];
                    if ($isOpen) {
                        $shiftStart = (string)($dayInfo['start'] ?? '');
                        $shiftEnd = (string)($dayInfo['end'] ?? '');
                        if ($shiftStart !== '' && $shiftEnd !== '') {
                            $hoursList[] = $shiftStart . '-' . $shiftEnd;
                        }
                    }
                    $note = '';
                    $classes = 'day'
                        . ($outside ? ' outside' : '')
                        . ($w === 0 ? ' sun' : ($w === 6 ? ' sat' : ''))
                        . (!$outside ? ($isOpen ? ' is-open' : ($monthConfirmed ? ' is-closed' : ' is-pending')) : '')
                        . ($ymd === $today ? ' today' : '');
                    ?>
                    <div class="<?= h($classes) ?>"<?= !$outside ? ' data-date="' . h($ymd) . '" role="button" tabindex="0" aria-label="' . h($d->format('Y年n月j日') . 'の出勤スタッフを見る') . '"' : '' ?>>
                        <div class="date"><?= h($d->format('j')) ?></div>
                        <?php if (!$outside): ?>
                            <?php if ($isOpen): ?>
                                <div class="status <?= h($statusClass) ?>"><?= h($statusText) ?></div>
                            <?php endif; ?>
                            <?php foreach ($hoursList as $hours): ?>
                                <?php if (preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $hours, $hm)): ?>
                                    <div class="hours"><span><?= h($hm[1]) ?></span><span class="timeSep">-</span><span class="timeEnd"><?= h($hm[2]) ?></span></div>
                                <?php else: ?>
                                    <div class="hours"><?= h($hours) ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!$isOpen): ?>
                                <div class="status <?= h($statusClass) ?>"><?= h($statusText) ?></div>
                            <?php endif; ?>
                            <?php if ($note !== ''): ?>
                                <div class="hours"><?= h($note) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <div class="legend">営業予定は変更になる場合があります。最新情報は店舗へご確認ください。</div>
    </main>
    <div class="staffModalBack" id="staffModalBack" aria-hidden="true">
        <div class="staffModal" role="dialog" aria-modal="true" aria-labelledby="staffModalTitle">
            <div class="staffModalHeader">
                <div>
                    <div class="staffModalDate" id="staffModalDate"></div>
                    <h2 id="staffModalTitle">出勤スタッフ</h2>
                </div>
                <button class="modalClose" type="button" id="staffModalClose" aria-label="閉じる">×</button>
            </div>
            <div class="staffList" id="staffList"></div>
        </div>
    </div>
    <script type="application/json" id="staffByDayJson"><?= json_encode($staffByDay, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script>
        (() => {
            const source = document.getElementById('staffByDayJson');
            const modalBack = document.getElementById('staffModalBack');
            const modalDate = document.getElementById('staffModalDate');
            const staffList = document.getElementById('staffList');
            const closeButton = document.getElementById('staffModalClose');
            let staffByDay = {};
            let lastFocus = null;

            try {
                staffByDay = JSON.parse(source?.textContent || '{}') || {};
            } catch (e) {
                staffByDay = {};
            }

            const formatDate = (ymd) => {
                const parts = ymd.split('-').map(Number);
                if (parts.length !== 3 || parts.some(Number.isNaN)) return ymd;
                const date = new Date(parts[0], parts[1] - 1, parts[2]);
                const week = ['日', '月', '火', '水', '木', '金', '土'][date.getDay()];
                return `${parts[0]}年${parts[1]}月${parts[2]}日（${week}）`;
            };

            const escapeText = (value) => {
                const div = document.createElement('div');
                div.textContent = value == null ? '' : String(value);
                return div.innerHTML;
            };

            const openModal = (ymd, trigger) => {
                const rows = Array.isArray(staffByDay[ymd]) ? staffByDay[ymd] : [];
                lastFocus = trigger || document.activeElement;
                modalDate.textContent = formatDate(ymd);
                staffList.innerHTML = rows.length
                    ? rows.map((row) => `
                        <div class="staffRow">
                            <div class="staffName">${escapeText(row.name)}</div>
                            <div class="staffTime">${escapeText(row.time)}</div>
                        </div>
                    `).join('')
                    : '<div class="staffEmpty">出勤スタッフはありません</div>';
                modalBack.classList.add('is-open');
                modalBack.setAttribute('aria-hidden', 'false');
                closeButton.focus();
            };

            const closeModal = () => {
                modalBack.classList.remove('is-open');
                modalBack.setAttribute('aria-hidden', 'true');
                if (lastFocus && typeof lastFocus.focus === 'function') {
                    lastFocus.focus();
                }
            };

            document.querySelectorAll('.day[data-date]').forEach((day) => {
                day.addEventListener('click', () => openModal(day.dataset.date, day));
                day.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        openModal(day.dataset.date, day);
                    }
                });
            });

            closeButton.addEventListener('click', closeModal);
            modalBack.addEventListener('click', (event) => {
                if (event.target === modalBack) closeModal();
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modalBack.classList.contains('is-open')) {
                    closeModal();
                }
            });
        })();
    </script>
</body>

</html>
