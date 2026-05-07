<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/attendance_exceptions.php
 * ✅ 書き込み場所: 新規作成（または既存を丸ごと置き換え）
 *
 * 目的:
 * - shifts（予定）× punch（実績）から
 *   遅刻 / 早退 / 欠勤 を自動算出して一覧表示する
 *
 * GET:
 * - store_id
 * - date=YYYY-MM-DD（省略: 今日）
 * - view=month|week（省略: month）
 *
 * ✅ 今回の修正
 * - 未来日（今日より後）は「行自体出さない」（＝欠勤判定も表示も一切しない）
 * - 例外一覧は「日付が新しいものが上」（降順）
 * - カード角を四角（border-radius: 0）
 * - カード枠線は薄いグレー（#e6e6e6）
 *
 * ⚠️ punchテーブル名が環境で違う可能性があるので自動探索する
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';

require_once __DIR__ . '/../api/lib/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/** ✅ 500の原因を可視化（本番で消してOK） */
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

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

// CSRF（将来の絞り込みPOST用）
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

$storeId = (int)($_GET['store_id'] ?? 0);

// stores（header用）
$storesStmt = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:t ORDER BY id ASC");
$storesStmt->execute([':t' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (!$stores) {
    http_response_code(400);
    echo "storesなし";
    exit;
}
$validStoreIds = array_map(fn($s) => (int)$s['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $validStoreIds, true)) $storeId = (int)$stores[0]['id'];

$storeName = '';
foreach ($stores as $st) if ((int)$st['id'] === $storeId) $storeName = (string)$st['name'];

// view/date
$view = (string)($_GET['view'] ?? 'month');
if (!in_array($view, ['month', 'week'], true)) $view = 'month';

$dateStr = (string)($_GET['date'] ?? date('Y-m-d'));
$base = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr) ?: new DateTimeImmutable('today');

// 対象レンジ
if ($view === 'week') {
    $dow = (int)$base->format('w'); // 0=日
    $start = $base->modify("-{$dow} day");
    $rangeStart = $start->format('Y-m-d');
    $rangeEnd   = $start->modify('+6 day')->format('Y-m-d');
    $title = '勤怠注意（週）';
    $prevDate = $base->modify('-7 day')->format('Y-m-d');
    $nextDate = $base->modify('+7 day')->format('Y-m-d');
} else {
    $first = $base->modify('first day of this month');
    $last  = $base->modify('last day of this month');
    $rangeStart = $first->format('Y-m-d');
    $rangeEnd   = $last->format('Y-m-d');
    $title = '勤怠注意（月）';
    $prevDate = $base->modify('-1 month')->format('Y-m-d');
    $nextDate = $base->modify('+1 month')->format('Y-m-d');
}

// ✅ 未来は行自体出さないので、表示対象の上限日を「今日」に打刻調整る
$today = new DateTimeImmutable('today');
$rangeEndObj = new DateTimeImmutable($rangeEnd);
if ($rangeEndObj > $today) {
    $rangeEndObj = $today;
}
$rangeEndForLoop = $rangeEndObj->format('Y-m-d');

// 従業員
$empStmt = $pdo->prepare("
  SELECT id, display_name, employment_status
  FROM employees
  WHERE tenant_id=:t AND store_id=:s
    AND employment_status='active'
  ORDER BY sort_order ASC, id ASC
");
$empStmt->execute([':t' => $tenantId, ':s' => $storeId]);
$employees = $empStmt->fetchAll();

// シフト（複数シフト同日: その日の最初start〜最後end を代表にする）
$shiftStmt = $pdo->prepare("
  SELECT employee_id, shift_date,
         MIN(start_time) AS start_time,
         MAX(end_time)   AS end_time
  FROM shifts
  WHERE tenant_id=:t AND store_id=:s
    AND deleted_at IS NULL
    AND shift_date BETWEEN :d1 AND :d2
  GROUP BY employee_id, shift_date
");
$shiftStmt->execute([
    ':t'  => $tenantId,
    ':s'  => $storeId,
    ':d1' => $rangeStart,
    ':d2' => $rangeEndForLoop, // ✅ 未来を含めない
]);
$shiftRows = $shiftStmt->fetchAll();

// shiftMap[eid][date] => [start,end]
$shiftMap = [];
foreach ($shiftRows as $r) {
    $eid = (int)$r['employee_id'];
    $d   = (string)$r['shift_date'];
    $shiftMap[$eid][$d] = [
        'start_time' => (string)$r['start_time'],
        'end_time'   => (string)$r['end_time'],
    ];
}

/**
 * ✅ punchテーブル名の自動探索（環境差吸収）
 * よくある候補を順番に試して、SELECTできたものを採用
 */
function detectPunchTable(PDO $pdo, array $candidates): string
{
    foreach ($candidates as $tbl) {
        try {
            $pdo->query("SELECT 1 FROM {$tbl} LIMIT 1");
            return $tbl;
        } catch (Throwable $e) {
            // 次へ
        }
    }
    throw new RuntimeException("punchテーブルが見つかりません。候補: " . implode(', ', $candidates));
}

$punchTable = detectPunchTable($pdo, [
    'time_punches',
    'punches',
    'attendance_punches',
]);

/**
 * ✅ 対象期間の punch をまとめ取り（employee_id + 日付で集計）
 * - clock_in: 最初
 * - clock_out: 最後
 *
 * ※ shift の日付基準で集計するため、ここは「日付(0:00〜翌日0:00)」の範囲で取る
 * ✅ 未来は含めない（rangeEndForLoop + 1日まで）
 */
$p1 = $rangeStart . ' 00:00:00';
$p2 = (new DateTimeImmutable($rangeEndForLoop . ' 00:00:00'))->modify('+1 day')->format('Y-m-d 00:00:00');

$punchStmt = $pdo->prepare("
  SELECT employee_id,
         DATE(punched_at) AS pdate,
         MIN(CASE WHEN punch_type='clock_in'  THEN punched_at END) AS first_in,
         MAX(CASE WHEN punch_type='clock_out' THEN punched_at END) AS last_out
  FROM {$punchTable}
  WHERE tenant_id=:t AND store_id=:s
    AND deleted_at IS NULL
    AND punched_at >= :p1 AND punched_at < :p2
  GROUP BY employee_id, DATE(punched_at)
");
$punchStmt->execute([':t' => $tenantId, ':s' => $storeId, ':p1' => $p1, ':p2' => $p2]);
$punchRows = $punchStmt->fetchAll();

// punchMap[eid][date] => [first_in,last_out]
$punchMap = [];
foreach ($punchRows as $r) {
    $eid = (int)$r['employee_id'];
    $d   = (string)$r['pdate'];
    $punchMap[$eid][$d] = [
        'first_in' => $r['first_in'] ? (string)$r['first_in'] : null,
        'last_out' => $r['last_out'] ? (string)$r['last_out'] : null,
    ];
}

function toTs(?string $dt): ?int
{
    if ($dt === null || $dt === '') return null;
    $ts = strtotime($dt);
    return $ts === false ? null : $ts;
}

function minutesDiff(int $a, int $b): int
{
    return (int)floor(($a - $b) / 60);
}

$wdayJa = ['日', '月', '火', '水', '木', '金', '土'];

// レポート作成
$items = [];
$sumByEmp = []; // eid => counts

$cur = new DateTimeImmutable($rangeStart);
$end = new DateTimeImmutable($rangeEndForLoop);

while ($cur <= $end) {
    $dkey = $cur->format('Y-m-d');

    // ✅ 念のため：未来は行自体出さない（rangeEndを打刻調整ても保険）
    if ($cur > $today) {
        $cur = $cur->modify('+1 day');
        continue;
    }

    foreach ($employees as $e) {
        $eid = (int)$e['id'];
        $ename = (string)$e['display_name'];

        $shift = $shiftMap[$eid][$dkey] ?? null;
        if (!$shift) continue; // 予定がない日は対象外（＝欠勤扱いにしない）

        $scheduledStart = $dkey . ' ' . substr($shift['start_time'], 0, 8);
        $scheduledEnd   = $dkey . ' ' . substr($shift['end_time'], 0, 8);

        $p = $punchMap[$eid][$dkey] ?? ['first_in' => null, 'last_out' => null];
        $actualInTs  = toTs($p['first_in']);
        $actualOutTs = toTs($p['last_out']);

        $schInTs  = toTs($scheduledStart);
        $schOutTs = toTs($scheduledEnd);

        // 欠勤: 予定あり & in/out 両方なし（未来はここに来ない）
        $isAbsence = ($actualInTs === null && $actualOutTs === null);

        $lateMin = 0;
        if (!$isAbsence && $actualInTs !== null && $schInTs !== null) {
            $lateMin = max(0, minutesDiff($actualInTs, $schInTs));
        }

        $earlyMin = 0;
        if (!$isAbsence && $actualOutTs !== null && $schOutTs !== null) {
            $earlyMin = max(0, minutesDiff($schOutTs, $actualOutTs));
        }

        // 例外として出す条件
        if ($isAbsence || $lateMin > 0 || $earlyMin > 0) {
            $items[] = [
                'date' => $dkey,
                'wday' => $wdayJa[(int)$cur->format('w')],
                'employee_id' => $eid,
                'name' => $ename,
                'scheduled' => substr($shift['start_time'], 0, 5) . ' - ' . substr($shift['end_time'], 0, 5),
                'actual_in' => $p['first_in'] ? substr((string)$p['first_in'], 11, 5) : '',
                'actual_out' => $p['last_out'] ? substr((string)$p['last_out'], 11, 5) : '',
                'late_min' => $lateMin,
                'early_min' => $earlyMin,
                'absence' => $isAbsence ? 1 : 0,
            ];

            if (!isset($sumByEmp[$eid])) {
                $sumByEmp[$eid] = ['name' => $ename, 'late' => 0, 'early' => 0, 'absence' => 0];
            }
            if ($lateMin > 0) $sumByEmp[$eid]['late']++;
            if ($earlyMin > 0) $sumByEmp[$eid]['early']++;
            if ($isAbsence) $sumByEmp[$eid]['absence']++;
        }
    }

    $cur = $cur->modify('+1 day');
}

// ✅ ソート：日付が新しいものが上（降順）
// 同日内は 欠勤→遅刻→早退 が目に入りやすい順に
usort($items, function ($a, $b) {
    if ($a['date'] !== $b['date']) return strcmp($b['date'], $a['date']); // date DESC

    $sa = ($a['absence'] ? 100000 : 0) + ($a['late_min'] * 10) + $a['early_min'];
    $sb = ($b['absence'] ? 100000 : 0) + ($b['late_min'] * 10) + $b['early_min'];
    if ($sa !== $sb) return ($sa < $sb) ? 1 : -1;

    return strcmp($a['name'], $b['name']);
});

// 集計を並び替え（件数の多い順）
$sumList = array_values($sumByEmp);
usort($sumList, function ($a, $b) {
    $ka = $a['absence'] * 100 + $a['late'] * 10 + $a['early'];
    $kb = $b['absence'] * 100 + $b['late'] * 10 + $b['early'];
    if ($ka === $kb) return strcmp($a['name'], $b['name']);
    return ($ka < $kb) ? 1 : -1;
});

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?></title>
    <style>
        :root {
            --bg: #ffffff;
            --card: #fff;
            --text: #444;
            --muted: #777;
            --border: #e9e9e9;
            --cardBorder: #e6e6e6;
            /* ✅ 薄いグレー */
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            font-weight: 500;
        }

        .page {
            padding: 24px;
            padding-bottom: 64px;
        }

        .wrap {
            width: 100%;
            max-width: none;
            /* 制限を外す */
            margin: 0;
            padding: 0 20px;
            /* 左右20pxの余白 */
        }


        /* ✅ カード：角四角 / 白背景 / 薄いグレー枠 */
        .card {
            background: var(--card);
            border: 1px solid var(--cardBorder);
            border-radius: 12px;
            padding: 16px;
            width: 100%;
        }

        .top {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 34px;
            padding: 0 12px;
            border-radius: 10px;
            /* ✅ ボタンも四角寄り */
            border: 1px solid var(--cardBorder);
            background: #fff;
            text-decoration: none;
            color: #555;
            font-weight: 650;
        }

        .btn.primary {
            border-color: #111;
            background: #111;
            color: #fff;
            font-weight: 800;
        }

        .muted {
            color: var(--muted);
            font-size: 12px;
            font-weight: 500;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #fff;
        }

        th,
        td {
            border-bottom: 1px solid var(--border);
            padding: 10px;
            vertical-align: top;
            font-size: 12px;
        }

        th {
            color: #555;
            font-weight: 700;
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #eee;
            border-radius: 999px;
            padding: 3px 10px;
            background: #fafafa;
            font-size: 12px;
            color: #555;
            font-weight: 600;
            white-space: nowrap;
        }

        .pill.bad {
            background: #fff5f5;
            border-color: #ffd0d0;
            color: #a30000;
        }

        .pill.warn {
            background: #fff8e7;
            border-color: #ffe2a8;
            color: #7a5200;
        }

        .grid2 {
            display: grid;
            grid-template-columns: 420px 1fr;
            gap: 14px;
        }

        @media(max-width:980px) {
            .grid2 {
                grid-template-columns: 1fr;
            }
        }

        .nowrap {
            white-space: nowrap;
        }

        .title {
            font-weight: 800;
            font-size: 16px;
            color: #222;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="shiftNavTabsHost">
        <?php require_once __DIR__ . '/_shift_nav_tabs.php'; ?>
    </div>
    <div class="page">
        <div class="wrap">

            <div class="card">
                <div class="top">
                    <div>
                        <div class="title"><?= h($title) ?></div>
                        <div class="muted">
                            店舗：<?= h($storeName) ?> /
                            範囲：<?= h($rangeStart) ?>〜<?= h($rangeEndForLoop) ?>
                            <!-- punch_table: <?= h($punchTable) ?> -->
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a class="btn"
                            href="/admin/shifts.php?store_id=<?= (int)$storeId ?>&view=<?= h($view) ?>&date=<?= h($base->format('Y-m-d')) ?>">シフトへ</a>
                        <a class="btn"
                            href="/admin/attendance_exceptions.php?store_id=<?= (int)$storeId ?>&view=month&date=<?= h($base->format('Y-m-d')) ?>">月</a>
                        <a class="btn"
                            href="/admin/attendance_exceptions.php?store_id=<?= (int)$storeId ?>&view=week&date=<?= h($base->format('Y-m-d')) ?>">週</a>
                        <a class="btn"
                            href="/admin/attendance_exceptions.php?store_id=<?= (int)$storeId ?>&view=<?= h($view) ?>&date=<?= h($prevDate) ?>">←前</a>
                        <a class="btn"
                            href="/admin/attendance_exceptions.php?store_id=<?= (int)$storeId ?>&view=<?= h($view) ?>&date=<?= h($nextDate) ?>">次→</a>
                    </div>
                </div>
            </div>

            <div class="grid2" style="margin-top:14px;">
                <div class="card" style="overflow:auto;">
                    <div class="title" style="margin-bottom:10px;">従業員別サマリ（件数）</div>
                    <?php if (!$sumList): ?>
                        <div class="muted">勤怠注意はありません</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>従業員</th>
                                    <th class="nowrap">欠勤</th>
                                    <th class="nowrap">遅刻</th>
                                    <th class="nowrap">早退</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sumList as $s): ?>
                                    <tr>
                                        <td><?= h($s['name']) ?></td>
                                        <td class="nowrap"><?= (int)$s['absence'] ?></td>
                                        <td class="nowrap"><?= (int)$s['late'] ?></td>
                                        <td class="nowrap"><?= (int)$s['early'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="card" style="overflow:auto;">
                    <div class="title" style="margin-bottom:10px;">例外一覧（予定×打刻差分）</div>
                    <?php if (!$items): ?>
                        <div class="muted">勤怠注意はありません</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th class="nowrap">日付</th>
                                    <th>従業員</th>
                                    <th class="nowrap">予定</th>
                                    <th class="nowrap">実績IN</th>
                                    <th class="nowrap">実績OUT</th>
                                    <th class="nowrap">判定</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $it): ?>
                                    <?php
                                    $badges = [];
                                    if ($it['absence']) $badges[] = '<span class="pill bad">欠勤</span>';
                                    if ($it['late_min'] > 0) $badges[] = '<span class="pill warn">遅刻 ' . (int)$it['late_min'] . '分</span>';
                                    if ($it['early_min'] > 0) $badges[] = '<span class="pill warn">早退 ' . (int)$it['early_min'] . '分</span>';
                                    ?>
                                    <tr>
                                        <td class="nowrap"><?= h($it['date']) ?>（<?= h($it['wday']) ?>）</td>
                                        <td><?= h($it['name']) ?> <span class="muted">(#<?= (int)$it['employee_id'] ?>)</span>
                                        </td>
                                        <td class="nowrap"><?= h($it['scheduled']) ?></td>
                                        <td class="nowrap"><?= h($it['actual_in']) ?></td>
                                        <td class="nowrap"><?= h($it['actual_out']) ?></td>
                                        <td class="nowrap"><?= implode(' ', $badges) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <footer
        style="position:fixed;left:0;right:0;bottom:0;text-align:center;padding:10px 0;font-size:12px;color:#777;background:rgba(255,255,255,.85);border-top:1px solid #eee;backdrop-filter:blur(6px)">
        &copy; AzureSystems by Fader
    </footer>
</body>

</html>
