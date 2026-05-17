<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';

header('Content-Type: application/json; charset=utf-8');

function out_json(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_page_table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ai_page_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
        $st->execute([':c' => $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ai_page_valid_ymd(string $ymd): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $ymd;
}

function ai_page_request_period(?string $defaultFrom = null, ?string $defaultTo = null, int $maxDays = 62): array
{
    $from = trim((string)($_POST['from'] ?? $_GET['from'] ?? ''));
    $to = trim((string)($_POST['to'] ?? $_GET['to'] ?? ''));

    if (ai_page_valid_ymd($from) && ai_page_valid_ymd($to)) {
        $fromDt = new DateTimeImmutable($from);
        $toDt = new DateTimeImmutable($to);
        if ($fromDt <= $toDt && $fromDt->diff($toDt)->days <= $maxDays) {
            return [$from, $to];
        }
    }

    if ($defaultFrom !== null && $defaultTo !== null) {
        return [$defaultFrom, $defaultTo];
    }

    return [
        (new DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d'),
        (new DateTimeImmutable('today'))->format('Y-m-d'),
    ];
}

function ai_page_store_name(PDO $pdo, int $tenantId, int $storeId): string
{
    try {
        $st = $pdo->prepare("SELECT name FROM stores WHERE tenant_id=:t AND id=:s LIMIT 1");
        $st->execute([':t' => $tenantId, ':s' => $storeId]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function ai_page_employee_names(PDO $pdo, int $tenantId, int $storeId): array
{
    if (!ai_page_table_exists($pdo, 'employees')) {
        return [];
    }

    $nameCol = ai_page_has_column($pdo, 'employees', 'display_name') ? 'display_name' : 'id';
    try {
        $st = $pdo->prepare("
            SELECT id, {$nameCol} AS name
            FROM employees
            WHERE tenant_id=:t AND store_id=:s
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId]);
        $names = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) continue;
            $name = trim((string)($r['name'] ?? ''));
            $names[$id] = $name !== '' ? $name : ('ID:' . $id);
        }
        return $names;
    } catch (Throwable $e) {
        return [];
    }
}

function ai_page_employee_top_lines(array $stats, string $metric, string $label, string $unit = '件', int $limit = 5): string
{
    $rows = [];
    foreach ($stats as $row) {
        $value = (int)($row[$metric] ?? 0);
        if ($value <= 0) continue;
        $rows[] = [
            'name' => (string)($row['name'] ?? '不明'),
            'value' => $value,
            'dates' => $row[$metric . '_dates'] ?? [],
        ];
    }

    usort($rows, fn($a, $b) => ($b['value'] <=> $a['value']) ?: strcmp($a['name'], $b['name']));
    $lines = [];
    foreach (array_slice($rows, 0, $limit) as $row) {
        $dateText = '';
        if (!empty($row['dates']) && is_array($row['dates'])) {
            $dateText = '（' . implode(', ', array_slice(array_unique($row['dates']), 0, 4)) . '）';
        }
        $lines[] = $row['name'] . ': ' . $row['value'] . $unit . $dateText;
    }

    return $label . ': ' . ($lines ? implode(' / ', $lines) : '-');
}

function ai_page_store_summary(PDO $pdo, int $tenantId, int $storeId): array
{
    [$from, $to] = ai_page_request_period();

    $salesMap = [];
    if (
        ai_page_table_exists($pdo, 'daily_store_reports') &&
        ai_page_has_column($pdo, 'daily_store_reports', 'business_date') &&
        ai_page_has_column($pdo, 'daily_store_reports', 'sales_yen')
    ) {
        $st = $pdo->prepare("
            SELECT business_date, COALESCE(sales_yen,0) AS sales_yen
            FROM daily_store_reports
            WHERE tenant_id=:t AND store_id=:s
              AND business_date BETWEEN :from AND :to
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId, ':from' => $from, ':to' => $to]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $salesMap[(string)$r['business_date']] = (int)$r['sales_yen'];
        }
    }

    $laborMap = [];
    $laborFile = __DIR__ . '/../api/lib/labor_mvp.php';
    if (is_file($laborFile)) {
        require_once $laborFile;
        if (function_exists('mvp_daily_labor')) {
            $laborMap = mvp_daily_labor($pdo, $tenantId, $storeId, $from, $to, true);
        }
    }

    $dates = [];
    $cur = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    while ($cur <= $end) {
        $dates[] = $cur->format('Y-m-d');
        $cur = $cur->modify('+1 day');
    }

    $sumSales = 0;
    $sumLabor = 0;
    $openDays = 0;
    $highDays = 0;
    $missingSales = [];
    $highRateDays = [];
    $weekdayCounts = array_fill(0, 7, 0);
    $weekJa = ['日', '月', '火', '水', '木', '金', '土'];

    foreach ($dates as $d) {
        $sales = (int)($salesMap[$d] ?? 0);
        $labor = (int)($laborMap[$d] ?? 0);
        $sumSales += $sales;
        $sumLabor += $labor;

        if ($sales > 0) {
            $openDays++;
            $rate = $labor > 0 ? round(($labor / $sales) * 100, 1) : 0.0;
            if ($rate > 35.0) {
                $highDays++;
                $weekdayCounts[(int)(new DateTimeImmutable($d))->format('w')]++;
                $highRateDays[] = "{$d}: {$rate}%";
            }
        } elseif ($labor > 0) {
            $missingSales[] = $d;
        }
    }

    $avgRate = $sumSales > 0 ? round(($sumLabor / $sumSales) * 100, 1) : null;
    $weekdayLines = [];
    foreach ($weekJa as $idx => $label) {
        if ($weekdayCounts[$idx] > 0) {
            $weekdayLines[] = "{$label}曜{$weekdayCounts[$idx]}日";
        }
    }

    return [
        '対象: 店舗運営',
        "期間: {$from}〜{$to}",
        '売上合計: ' . number_format($sumSales) . '円',
        '人件費合計: ' . number_format($sumLabor) . '円',
        '営業日数: ' . $openDays . '日',
        '平均人件費率: ' . ($avgRate === null ? '不明' : $avgRate . '%'),
        '人件費率35%超の日数: ' . $highDays . '日',
        '高人件費率の曜日傾向: ' . ($weekdayLines ? implode(', ', $weekdayLines) : '-'),
        '高人件費率の日: ' . ($highRateDays ? implode(', ', array_slice($highRateDays, 0, 8)) : '-'),
        '売上0かつ人件費あり: ' . ($missingSales ? implode(', ', array_slice($missingSales, 0, 8)) : '-'),
    ];
}

function ai_page_attendance_summary(PDO $pdo, int $tenantId, int $storeId): array
{
    if (!ai_page_table_exists($pdo, 'time_punches')) {
        return ['勤怠テーブルが見つかりません。'];
    }
    if (
        !ai_page_has_column($pdo, 'time_punches', 'punched_at') ||
        !ai_page_has_column($pdo, 'time_punches', 'punch_type') ||
        !ai_page_has_column($pdo, 'time_punches', 'employee_id')
    ) {
        return ['勤怠テーブルの列が不足しているため、詳細集計はできません。'];
    }

    $deletedSql = ai_page_has_column($pdo, 'time_punches', 'deleted_at') ? " AND deleted_at IS NULL" : "";
    [$fromYmd, $toYmd] = ai_page_request_period();
    $from = $fromYmd . ' 00:00:00';
    $to = (new DateTimeImmutable($toYmd))->modify('+1 day')->format('Y-m-d 00:00:00');
    $employeeNames = ai_page_employee_names($pdo, $tenantId, $storeId);

    $st = $pdo->prepare("
        SELECT
            DATE(punched_at) AS d,
            employee_id,
            SUM(CASE WHEN LOWER(punch_type) IN ('clock_in','in','start') THEN 1 ELSE 0 END) AS ins,
            SUM(CASE WHEN LOWER(punch_type) IN ('clock_out','out','end') THEN 1 ELSE 0 END) AS outs,
            MIN(CASE WHEN LOWER(punch_type) IN ('clock_in','in','start') THEN punched_at ELSE NULL END) AS first_in,
            MAX(CASE WHEN LOWER(punch_type) IN ('clock_out','out','end') THEN punched_at ELSE NULL END) AS last_out,
            COUNT(*) AS punches
        FROM time_punches
        WHERE tenant_id=:t AND store_id=:s
          AND punched_at >= :from AND punched_at < :to
          {$deletedSql}
        GROUP BY DATE(punched_at), employee_id
        ORDER BY d DESC, employee_id ASC
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':from' => $from, ':to' => $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $workDays = [];
    $employees = [];
    $missingOut = 0;
    $missingIn = 0;
    $multiPunch = 0;
    $longWork = 0;
    $employeeStats = [];
    foreach ($rows as $r) {
        $d = (string)($r['d'] ?? '');
        $eid = (int)($r['employee_id'] ?? 0);
        if ($d !== '') $workDays[$d] = true;
        if ($eid > 0) $employees[$eid] = true;
        if ($eid > 0 && !isset($employeeStats[$eid])) {
            $employeeStats[$eid] = [
                'name' => $employeeNames[$eid] ?? ('ID:' . $eid),
                'work_days' => 0,
                'punches' => 0,
                'missing_out' => 0,
                'missing_out_dates' => [],
                'missing_in' => 0,
                'missing_in_dates' => [],
                'multi_punch' => 0,
                'multi_punch_dates' => [],
                'long_work' => 0,
                'long_work_dates' => [],
            ];
        }
        $ins = (int)($r['ins'] ?? 0);
        $outs = (int)($r['outs'] ?? 0);
        $punches = (int)($r['punches'] ?? 0);
        if ($eid > 0) {
            $employeeStats[$eid]['work_days']++;
            $employeeStats[$eid]['punches'] += $punches;
        }
        if ($ins > $outs) {
            $missingOut++;
            if ($eid > 0) {
                $employeeStats[$eid]['missing_out']++;
                $employeeStats[$eid]['missing_out_dates'][] = $d;
            }
        }
        if ($outs > $ins) {
            $missingIn++;
            if ($eid > 0) {
                $employeeStats[$eid]['missing_in']++;
                $employeeStats[$eid]['missing_in_dates'][] = $d;
            }
        }
        if ($punches >= 5) {
            $multiPunch++;
            if ($eid > 0) {
                $employeeStats[$eid]['multi_punch']++;
                $employeeStats[$eid]['multi_punch_dates'][] = $d;
            }
        }

        $firstIn = (string)($r['first_in'] ?? '');
        $lastOut = (string)($r['last_out'] ?? '');
        if ($firstIn !== '' && $lastOut !== '') {
            $startTs = strtotime($firstIn);
            $endTs = strtotime($lastOut);
            if ($startTs !== false && $endTs !== false && $endTs > $startTs) {
                $workMin = (int)floor(($endTs - $startTs) / 60);
                if ($workMin >= 9 * 60) {
                    $longWork++;
                    if ($eid > 0) {
                        $employeeStats[$eid]['long_work']++;
                        $employeeStats[$eid]['long_work_dates'][] = $d . '(' . round($workMin / 60, 1) . 'h)';
                    }
                }
            }
        }
    }

    $daily = [];
    foreach ($rows as $r) {
        $d = (string)($r['d'] ?? '');
        if ($d === '') continue;
        $daily[$d]['employees'] = ($daily[$d]['employees'] ?? 0) + 1;
        $daily[$d]['punches'] = ($daily[$d]['punches'] ?? 0) + (int)($r['punches'] ?? 0);
    }
    $dailyLines = [];
    foreach (array_slice($daily, 0, 10, true) as $d => $v) {
        $dailyLines[] = "{$d}: 出勤者{$v['employees']}人/打刻{$v['punches']}件";
    }

    return [
        '対象: 日別勤怠',
        "期間: {$fromYmd}〜{$toYmd}",
        '勤怠がある日数: ' . count($workDays) . '日',
        '勤怠がある従業員数: ' . count($employees) . '人',
        '退勤漏れ疑い: ' . $missingOut . '件',
        '出勤漏れ疑い: ' . $missingIn . '件',
        '打刻回数が多い日/人: ' . $multiPunch . '件',
        '実働9時間以上疑い: ' . $longWork . '件',
        ai_page_employee_top_lines($employeeStats, 'missing_out', '退勤漏れ疑いが多い人'),
        ai_page_employee_top_lines($employeeStats, 'missing_in', '出勤漏れ疑いが多い人'),
        ai_page_employee_top_lines($employeeStats, 'multi_punch', '打刻回数が多い人'),
        ai_page_employee_top_lines($employeeStats, 'long_work', '実働が長い疑いが多い人'),
        ai_page_employee_top_lines($employeeStats, 'work_days', '出勤日数が多い人', '日'),
        ai_page_employee_top_lines($employeeStats, 'punches', '打刻件数が多い人'),
        '日別サンプル: ' . ($dailyLines ? implode(', ', $dailyLines) : '-'),
    ];
}

function ai_page_shift_summary(PDO $pdo, int $tenantId, int $storeId): array
{
    if (!ai_page_table_exists($pdo, 'shifts')) {
        return ['シフトテーブルが見つかりません。'];
    }
    foreach (['shift_date', 'employee_id', 'start_time', 'end_time'] as $col) {
        if (!ai_page_has_column($pdo, 'shifts', $col)) {
            return ['シフトテーブルの列が不足しているため、詳細集計はできません。'];
        }
    }

    $from = (new DateTimeImmutable('today'))->format('Y-m-d');
    $to = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
    $employeeNames = ai_page_employee_names($pdo, $tenantId, $storeId);

    $breakExpr = ai_page_has_column($pdo, 'shifts', 'break_minutes') ? 'break_minutes' : '0 AS break_minutes';
    $st = $pdo->prepare("
        SELECT shift_date, employee_id, start_time, end_time, {$breakExpr}
        FROM shifts
        WHERE tenant_id=:t AND store_id=:s
          AND shift_date BETWEEN :from AND :to
        ORDER BY shift_date ASC, employee_id ASC, start_time ASC
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':from' => $from, ':to' => $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $days = [];
    $employees = [];
    $dailyCount = [];
    $longShift = 0;
    $employeeStats = [];
    foreach ($rows as $r) {
        $d = (string)($r['shift_date'] ?? '');
        $eid = (int)($r['employee_id'] ?? 0);
        if ($d !== '') {
            $days[$d] = true;
            $dailyCount[$d] = ($dailyCount[$d] ?? 0) + 1;
        }
        if ($eid > 0) $employees[$eid] = true;
        if ($eid > 0 && !isset($employeeStats[$eid])) {
            $employeeStats[$eid] = [
                'name' => $employeeNames[$eid] ?? ('ID:' . $eid),
                'shift_count' => 0,
                'total_hours' => 0,
                'long_shift' => 0,
                'long_shift_dates' => [],
            ];
        }
        if ($eid > 0) {
            $employeeStats[$eid]['shift_count']++;
        }

        $start = substr((string)($r['start_time'] ?? ''), 0, 5);
        $end = substr((string)($r['end_time'] ?? ''), 0, 5);
        if (preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end)) {
            [$sh, $sm] = array_map('intval', explode(':', $start));
            [$eh, $em] = array_map('intval', explode(':', $end));
            $startMin = $sh * 60 + $sm;
            $endMin = $eh * 60 + $em;
            if ($endMin <= $startMin) $endMin += 1440;
            $workMin = max(0, $endMin - $startMin - (int)($r['break_minutes'] ?? 0));
            if ($eid > 0) {
                $employeeStats[$eid]['total_hours'] += (int)round($workMin / 60);
            }
            if ($workMin >= 9 * 60) {
                $longShift++;
                if ($eid > 0) {
                    $employeeStats[$eid]['long_shift']++;
                    $employeeStats[$eid]['long_shift_dates'][] = $d . '(' . round($workMin / 60, 1) . 'h)';
                }
            }
        }
    }

    arsort($dailyCount);
    $heavyDays = [];
    foreach (array_slice($dailyCount, 0, 8, true) as $d => $cnt) {
        $heavyDays[] = "{$d}: {$cnt}枠";
    }

    return [
        '対象: シフト管理',
        "期間: {$from}〜{$to}",
        'シフト枠数: ' . count($rows) . '枠',
        'シフトがある日数: ' . count($days) . '日',
        '予定従業員数: ' . count($employees) . '人',
        '9時間以上の長時間シフト疑い: ' . $longShift . '枠',
        ai_page_employee_top_lines($employeeStats, 'long_shift', '長時間シフトが目立つ人', '枠'),
        ai_page_employee_top_lines($employeeStats, 'shift_count', 'シフト枠数が多い人', '枠'),
        ai_page_employee_top_lines($employeeStats, 'total_hours', '予定時間合計が多い人', '時間'),
        'シフトが多い日: ' . ($heavyDays ? implode(', ', $heavyDays) : '-'),
    ];
}

try {
    $tenantId = (int)($tenantId ?? 0);
    if ($tenantId <= 0) out_json(['ok' => false, 'error' => 'tenant not found']);

    require_once __DIR__ . '/../api/bootstrap.php';
    require_once __DIR__ . '/../api/lib/db.php';
    require_once __DIR__ . '/../api/lib/openai_client.php';
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $storeId = (int)($_POST['store_id'] ?? $_GET['store_id'] ?? 0);
    $scope = (string)($_POST['scope'] ?? $_GET['scope'] ?? '');
    $title = (string)($_POST['title'] ?? $_GET['title'] ?? 'AI改善提案');
    $question = trim((string)($_POST['question'] ?? ''));
    $prior = trim((string)($_POST['prior'] ?? ''));

    if (!in_array($scope, ['store', 'attendance', 'shifts'], true)) {
        out_json(['ok' => false, 'error' => 'unsupported scope']);
    }

    $storeName = ai_page_store_name($pdo, $tenantId, $storeId);
    if ($scope === 'store') {
        $summary = ai_page_store_summary($pdo, $tenantId, $storeId);
    } elseif ($scope === 'attendance') {
        $summary = ai_page_attendance_summary($pdo, $tenantId, $storeId);
    } else {
        $summary = ai_page_shift_summary($pdo, $tenantId, $storeId);
    }

    $scopeName = ($scope === 'store') ? '店舗運営' : (($scope === 'attendance') ? '日別勤怠' : 'シフト管理');
    $prompt = [
        "あなたは店舗管理を支援するAIです。",
        "対象画面: {$scopeName}",
        "表示名: {$title}",
        "店舗: " . ($storeName !== '' ? $storeName : "store_id={$storeId}"),
        "回答は短く、店長が次に動ける改善提案にしてください。",
        "形式: 1)結論 2)気になる点 3)すぐやる改善 4)追加で確認すること。",
        "『誰』『だれ』『どの従業員』と聞かれた場合は、データ要約にある個人名・件数・日付を使って明確に答えてください。",
        "個人名つきランキングがある場合は『不明』で逃げず、上位者から具体的に書いてください。",
        "ただし断定しすぎず、『疑い』『目立つ』という表現で、確認すべき記録も添えてください。",
        "専門用語は避け、現場向けに書いてください。",
        "",
        "データ要約:",
        implode("\n", array_map(fn($line) => "- " . $line, $summary)),
    ];
    if ($prior !== '') {
        $prompt[] = "";
        $prompt[] = "前回のAI改善提案:";
        $prompt[] = $prior;
    }
    if ($question !== '') {
        $prompt[] = "";
        $prompt[] = "追加質問:";
        $prompt[] = $question;
    }

    $resp = openai_responses('gpt-4.1-mini', implode("\n", $prompt), 24);
    $answer = trim((string)openai_extract_text($resp));
    out_json(['ok' => true, 'answer' => $answer, 'scope' => $scope, 'title' => $title]);
} catch (Throwable $e) {
    out_json(['ok' => false, 'error' => $e->getMessage()]);
}
