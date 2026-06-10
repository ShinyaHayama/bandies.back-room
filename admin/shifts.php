<?php

/**
 * ✅ ファイル名: /admin/shifts.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 今回の追加（既存機能は一切変えない）
 * 1) 日付曜日の上に「その日の出勤人数（=シフト人数）」を表示
 *    - 1日に2回出勤（=複数シフト）しても「1人」としてカウント（COUNT DISTINCT employee_id）
 *    - 表示範囲（月/週）の全従業員分を集計（ページングや検索に影響されない）
 *
 * 2) 日付曜日（ヘッダー）をタップすると、その日のメモを登録/編集できる
 *    - DB変更なし（localStorage に保存）※端末/ブラウザ単位で保持
 *    - メモがある日付は赤点（memoPin）で可視化
 *    - memoPin をホバーするとメモ本文がツールチップ表示
 *    - 既存のセルクリック・DnD・スクロール保存などは変更しない
 *
 * 3) ✅追加：添付のように「本日」の列の上に印（▼）を表示
 *    - 既存機能は一切いじらず、ヘッダーUIに三角マーカーを追加するだけ
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';

require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../lib/shift_leave_requests.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
shift_leave_requests_ensure_schema($pdo);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

date_default_timezone_set('Asia/Tokyo');

$storeId = (int)($_GET['store_id'] ?? 0);

// ✅ 検索（DB）
$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

// ✅ ページング
$limit = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

function url_with(array $override = [], array $drop = []): string
{
    $params = $_GET;
    foreach ($drop as $k) unset($params[$k]);
    foreach ($override as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = (string)$v;
    }
    $qs = http_build_query($params);
    return $_SERVER['PHP_SELF'] . ($qs ? ('?' . $qs) : '');
}

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
$currentStore = [];
foreach ($stores as $st) {
    if ((int)$st['id'] === $storeId) {
        $storeName = (string)$st['name'];
        $currentStore = $st;
    }
}

/**
 * ✅ business_day_cutoff_time（締め時刻）を安全に取得
 * - stores に business_day_cutoff_time 列があればそれを使う
 * - 無ければデフォルト "05:00"
 */
$cutoffHHMM = '05:00';
try {
    $colsStmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'stores'
    ");
    $colsStmt->execute();
    $colNames = array_map(fn($r) => (string)$r['COLUMN_NAME'], $colsStmt->fetchAll());
    $colSet = array_fill_keys($colNames, true);

    if (isset($colSet['business_day_cutoff_time'])) {
        $st = $pdo->prepare("SELECT business_day_cutoff_time AS c FROM stores WHERE tenant_id=:t AND id=:sid LIMIT 1");
        $st->execute([':t' => $tenantId, ':sid' => $storeId]);
        $v = (string)($st->fetch()['c'] ?? '');
        if (preg_match('/^\d{2}:\d{2}$/', $v)) $cutoffHHMM = $v;
    } elseif (isset($colSet['business_day_cutoff_hhmm'])) {
        $st = $pdo->prepare("SELECT business_day_cutoff_hhmm AS c FROM stores WHERE tenant_id=:t AND id=:sid LIMIT 1");
        $st->execute([':t' => $tenantId, ':sid' => $storeId]);
        $v = (string)($st->fetch()['c'] ?? '');
        if (preg_match('/^\d{2}:\d{2}$/', $v)) $cutoffHHMM = $v;
    }
} catch (Throwable $e) {
    // デフォルトのまま
}

/**
 * ✅ cutoff基準の営業日キー（business date）
 * - cutoffより前の時刻は「前日扱い」
 */
function business_date_by_cutoff(DateTimeImmutable $dt, string $cutoffHHMM): string
{
    [$ch, $cm] = array_map('intval', explode(':', $cutoffHHMM));
    $cutoffMin = $ch * 60 + $cm;

    $h = (int)$dt->format('H');
    $m = (int)$dt->format('i');
    $min = $h * 60 + $m;

    if ($min < $cutoffMin) {
        return $dt->modify('-1 day')->format('Y-m-d');
    }
    return $dt->format('Y-m-d');
}

$view = (string)($_GET['view'] ?? 'month');
if (!in_array($view, ['month', 'week', 'timeline'], true)) $view = 'month';

$dateStr = (string)($_GET['date'] ?? date('Y-m-d'));
$base = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr) ?: new DateTimeImmutable('today');
$fromView = (string)($_GET['from_view'] ?? 'month');
if (!in_array($fromView, ['month', 'week'], true)) $fromView = 'month';

$todayYmd = (new DateTimeImmutable('today'))->format('Y-m-d');
$nowHi = (new DateTimeImmutable('now'))->format('H:i');

if ($view === 'timeline') {
    $days = [$base];
    $rangeStart = $base->format('Y-m-d');
    $rangeEnd   = $base->format('Y-m-d');
} elseif ($view === 'week') {
    $dow = (int)$base->format('w');
    $start = $base->modify("-{$dow} day");
    $days = [];
    for ($i = 0; $i < 7; $i++) $days[] = $start->modify("+{$i} day");
    $rangeStart = $days[0]->format('Y-m-d');
    $rangeEnd   = $days[6]->format('Y-m-d');
} else {
    $first = $base->modify('first day of this month');
    $last  = $base->modify('last day of this month');
    $days = [];
    $cur = $first;
    while ($cur <= $last) {
        $days[] = $cur;
        $cur = $cur->modify('+1 day');
    }
    $rangeStart = $first->format('Y-m-d');
    $rangeEnd   = $last->format('Y-m-d');
}

if ($view === 'timeline') {
    $prevDate = $base->modify('-1 day')->format('Y-m-d');
    $nextDate = $base->modify('+1 day')->format('Y-m-d');
    $title = "シフト管理（グラフ）";
} elseif ($view === 'week') {
    $prevDate = $base->modify('-7 day')->format('Y-m-d');
    $nextDate = $base->modify('+7 day')->format('Y-m-d');
    $title = "シフト管理（週）";
} else {
    $prevDate = $base->modify('-1 month')->format('Y-m-d');
    $nextDate = $base->modify('+1 month')->format('Y-m-d');
    $title = "シフト管理（月）";
}

$returnTo = '/admin/shifts.php?store_id=' . $storeId
    . '&view=' . $view
    . '&date=' . $base->format('Y-m-d')
    . '&q=' . rawurlencode($q)
    . '&page=' . $page;

$appliedCount = (int)($_GET['applied'] ?? 0);
$wdayJa = ['日', '月', '火', '水', '木', '金', '土'];

$empColPx = 140;

// ✅ 週が「詰まって見える」問題の対策：週は広めにする
$dayColPx = ($view === 'month') ? 72 : 160;

$bodyClass = ($view === 'month') ? 'is-month' : (($view === 'timeline') ? 'is-timeline' : 'is-week');
$graphToggleView = ($view === 'timeline') ? $fromView : 'timeline';
$graphToggleLabel = ($view === 'timeline') ? (($fromView === 'week') ? '週表示' : '月表示') : 'グラフ表示';

// =========================================================
// ✅ 追加: 日別「出勤人数（=シフト人数）」を集計
// - 1日に複数シフトがあっても 1人としてカウント
// - 表示範囲（rangeStart〜rangeEnd）で店舗全体を集計
// =========================================================
$dayShiftHeadCount = []; // [Y-m-d] => cnt
try {
    $sql = "
      SELECT shift_date, COUNT(DISTINCT employee_id) AS cnt
      FROM shifts
      WHERE tenant_id=:t AND store_id=:s
        AND deleted_at IS NULL
        AND shift_date BETWEEN :a AND :b
      GROUP BY shift_date
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':a' => $rangeStart,
        ':b' => $rangeEnd,
    ]);
    foreach ($st->fetchAll() as $r) {
        $d = (string)$r['shift_date'];
        $c = (int)($r['cnt'] ?? 0);
        $dayShiftHeadCount[$d] = $c;
    }
} catch (Throwable $e) {
    $dayShiftHeadCount = [];
}

// =========================
// ✅ 従業員（DB検索 + ページング）
// =========================
$countSql = "SELECT COUNT(*) AS cnt
             FROM employees
             WHERE tenant_id=:t AND store_id=:s
             AND employment_status='active'
             AND (:q = '' OR display_name LIKE :qlike)";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute([
    ':t' => $tenantId,
    ':s' => $storeId,
    ':q' => $q,
    ':qlike' => $qLike,
]);
$totalEmployees = (int)($countStmt->fetch()['cnt'] ?? 0);
$totalPages = max(1, (int)ceil($totalEmployees / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$empSql = "SELECT id, display_name, employment_status
           FROM employees
           WHERE tenant_id=:t AND store_id=:s
           AND employment_status='active'
           AND (:q = '' OR display_name LIKE :qlike)
           ORDER BY sort_order ASC, id ASC
           LIMIT :lim OFFSET :ofs";
$empStmt = $pdo->prepare($empSql);
$empStmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
$empStmt->bindValue(':s', $storeId, PDO::PARAM_INT);
$empStmt->bindValue(':q', $q, PDO::PARAM_STR);
$empStmt->bindValue(':qlike', $qLike, PDO::PARAM_STR);
$empStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$empStmt->bindValue(':ofs', $offset, PDO::PARAM_INT);
$empStmt->execute();
$employees = $empStmt->fetchAll();

// =========================
// ✅ シフト取得（表示中の従業員だけ）
// =========================
$shiftRows = [];
$shiftMap = [];
$leaveRequestMap = [];

$empIds = array_map(fn($e) => (int)$e['id'], $employees);
if (!empty($empIds)) {
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $shiftSql = "
      SELECT id, employee_id, shift_date, start_time, end_time, break_minutes, note
      FROM shifts
      WHERE tenant_id=? AND store_id=?
        AND deleted_at IS NULL
        AND employee_id IN ($placeholders)
        AND shift_date BETWEEN ? AND ?
      ORDER BY shift_date ASC, start_time ASC, id ASC
    ";
    $shiftStmt = $pdo->prepare($shiftSql);
    $bind = array_merge([$tenantId, $storeId], $empIds, [$rangeStart, $rangeEnd]);
    $shiftStmt->execute($bind);
    $shiftRows = $shiftStmt->fetchAll();

    foreach ($shiftRows as $r) {
        $eid = (int)$r['employee_id'];
        $d   = (string)$r['shift_date'];
        $shiftMap[$eid][$d][] = $r;
    }

    $leaveSql = "
      SELECT id, employee_id, request_date, status, token
      FROM shift_leave_requests
      WHERE tenant_id=? AND store_id=?
        AND employee_id IN ($placeholders)
        AND request_date BETWEEN ? AND ?
        AND status IN ('pending', 'approved')
      ORDER BY id DESC
    ";
    $leaveStmt = $pdo->prepare($leaveSql);
    $leaveStmt->execute($bind);
    foreach ($leaveStmt->fetchAll() as $r) {
        $eid = (int)$r['employee_id'];
        $d = (string)$r['request_date'];
        if (!isset($leaveRequestMap[$eid][$d])) {
            $leaveRequestMap[$eid][$d] = $r;
        }
    }
}

// =========================
// ✅ 打刻状態（出勤/退勤）を取る（列構成に合わせて自動判定）
// - パターンA: punched_at + punch_type（in/out）← 正式運用
// - パターンB: clock_in + clock_out（旧）
// ✅ 締め時刻(cutoffHHMM)を用いて dkey を確定する
// =========================
$punchInSet = [];
$punchOutSet = [];

if (!empty($empIds)) {
    try {
        // --- 列の存在を調べる（tenant_id/store_id も含めて）
        $colsStmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'time_punches'
        ");
        $colsStmt->execute();
        $colNames = array_map(fn($r) => (string)$r['COLUMN_NAME'], $colsStmt->fetchAll());
        $colSet = array_fill_keys($colNames, true);

        $hasTenantCol = isset($colSet['tenant_id']);
        $hasStoreCol  = isset($colSet['store_id']);

        $hasPunchedAt = isset($colSet['punched_at']);
        $hasPunchType = isset($colSet['punch_type']);

        $hasClockIn   = isset($colSet['clock_in']);
        $hasClockOut  = isset($colSet['clock_out']);

        // --- 表示範囲 + cutoff分だけ翌日に延ばす（深夜打刻を拾う）
        $startDT = $rangeStart . " 00:00:00";

        [$ch, $cm] = array_map('intval', explode(':', $cutoffHHMM));
        $endDT = (new DateTimeImmutable($rangeEnd . " 00:00:00"))
            ->modify('+1 day')
            ->modify('+' . $ch . ' hours')
            ->modify('+' . $cm . ' minutes')
            ->format('Y-m-d H:i:s');

        $placeholders = implode(',', array_fill(0, count($empIds), '?'));
        $tz = new DateTimeZone('Asia/Tokyo');

        // ---- WHERE を環境に応じて組み立て
        $where = [];
        $bind = [];

        if ($hasTenantCol) {
            $where[] = "tenant_id = ?";
            $bind[] = $tenantId;
        }
        if ($hasStoreCol) {
            $where[] = "store_id = ?";
            $bind[] = $storeId;
        }

        $where[] = "employee_id IN ($placeholders)";
        $bind = array_merge($bind, $empIds);

        // =====================
        // ✅ パターンA（正式）
        // =====================
        if ($hasPunchedAt && $hasPunchType) {
            $whereA = $where;
            $whereA[] = "punched_at >= ? AND punched_at < ?";
            $bindA = array_merge($bind, [$startDT, $endDT]);

            $sql = "
                SELECT employee_id, punch_type, punched_at
                FROM time_punches
                WHERE " . implode(' AND ', $whereA) . "
            ";

            $st = $pdo->prepare($sql);
            $st->execute($bindA);
            $rows = $st->fetchAll();

            foreach ($rows as $r) {
                $eid = (int)$r['employee_id'];

                $type = strtolower(trim((string)$r['punch_type']));
                $ts   = (string)$r['punched_at'];
                if ($ts === '') continue;

                try {
                    $dt = new DateTimeImmutable($ts, $tz);
                } catch (Throwable $e) {
                    continue;
                }

                // ✅ dkey は cutoff 基準で確定
                $dkey = business_date_by_cutoff($dt, $cutoffHHMM);

                // ✅ 出勤
                if ($type === 'in' || $type === 'clock_in' || $type === 'start') {
                    $punchInSet[$eid][$dkey] = true;
                    continue;
                }

                // ✅ 退勤
                if ($type === 'out' || $type === 'clock_out' || $type === 'end') {
                    $punchOutSet[$eid][$dkey] = true;
                    continue;
                }
            }

            // =====================
            // ✅ パターンB（旧：clock_in/clock_out）
            // =====================
        } elseif ($hasClockIn || $hasClockOut) {
            $whereB = $where;

            // clock_in/out のどちらかが範囲内なら取る
            $cond = [];
            if ($hasClockIn)  $cond[] = "(clock_in  IS NOT NULL AND clock_in  >= ? AND clock_in  < ?)";
            if ($hasClockOut) $cond[] = "(clock_out IS NOT NULL AND clock_out >= ? AND clock_out < ?)";
            if (!$cond) $cond[] = "1=0";
            $whereB[] = "(" . implode(" OR ", $cond) . ")";

            // bind: cond の数だけ範囲を積む
            $bindB = $bind;
            $needPairs = count($cond);
            for ($i = 0; $i < $needPairs; $i++) {
                $bindB[] = $startDT;
                $bindB[] = $endDT;
            }

            $selCols = "employee_id";
            if ($hasClockIn)  $selCols .= ", clock_in";
            if ($hasClockOut) $selCols .= ", clock_out";

            $sql = "
                SELECT {$selCols}
                FROM time_punches
                WHERE " . implode(' AND ', $whereB) . "
            ";

            $st = $pdo->prepare($sql);
            $st->execute($bindB);
            $rows = $st->fetchAll();

            foreach ($rows as $r) {
                $eid = (int)$r['employee_id'];

                if ($hasClockIn && !empty($r['clock_in'])) {
                    try {
                        $dt = new DateTimeImmutable((string)$r['clock_in'], $tz);
                        $dkey = business_date_by_cutoff($dt, $cutoffHHMM);
                        $punchInSet[$eid][$dkey] = true;
                    } catch (Throwable $e) {
                    }
                }

                if ($hasClockOut && !empty($r['clock_out'])) {
                    try {
                        $dt = new DateTimeImmutable((string)$r['clock_out'], $tz);
                        $dkey = business_date_by_cutoff($dt, $cutoffHHMM);
                        $punchOutSet[$eid][$dkey] = true;
                    } catch (Throwable $e) {
                    }
                }
            }
        } else {
            $punchInSet = [];
            $punchOutSet = [];
        }
    } catch (Throwable $e) {
        $punchInSet = [];
        $punchOutSet = [];
    }
}

// =========================
// ✅ 実休憩（break_punches）を日別合計で取得
// =========================
$breakMinSet = []; // [employee_id][Y-m-d] = minutes

if (!empty($empIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($empIds), '?'));

        $sql = "
          SELECT employee_id,
                 DATE(break_start_at) AS d,
                 SUM(TIMESTAMPDIFF(MINUTE, break_start_at, break_end_at)) AS mins
          FROM break_punches
          WHERE tenant_id=? AND store_id=?
            AND employee_id IN ($placeholders)
            AND break_start_at >= ? AND break_start_at < ?
            AND break_end_at IS NOT NULL
          GROUP BY employee_id, DATE(break_start_at)
        ";
        $st = $pdo->prepare($sql);

        $startDT = $rangeStart . " 00:00:00";
        $endDT   = (new DateTimeImmutable($rangeEnd . " 00:00:00"))->modify('+1 day')->format('Y-m-d H:i:s');

        $st->execute(array_merge([$tenantId, $storeId], $empIds, [$startDT, $endDT]));
        $rows = $st->fetchAll();

        foreach ($rows as $r) {
            $eid = (int)$r['employee_id'];
            $d   = (string)$r['d'];
            $mins = (int)($r['mins'] ?? 0);
            if ($mins > 0) $breakMinSet[$eid][$d] = $mins;
        }
    } catch (Throwable $e) {
        $breakMinSet = [];
    }
}

/**
 * ✅ 表示ラベル判定
 */
function attendance_label(
    string $cellYmd,
    bool $hasShift,
    bool $hasIn,
    bool $hasOut,
    string $todayYmd,
    ?string $earliestStartHHMM,
    string $nowHi
): array {
    if ($cellYmd > $todayYmd) {
        return ['text' => '', 'class' => ''];
    }

    if (
        $cellYmd === $todayYmd &&
        $hasShift &&
        !$hasIn &&
        !$hasOut &&
        $earliestStartHHMM !== null &&
        $earliestStartHHMM !== '' &&
        $nowHi < $earliestStartHHMM
    ) {
        return ['text' => '', 'class' => ''];
    }

    if ($hasOut) return ['text' => '退勤済', 'class' => 'out'];
    if ($hasIn)  return ['text' => '出勤中', 'class' => 'in'];
    if ($hasShift) return ['text' => '欠勤', 'class' => 'absent'];
    return ['text' => '', 'class' => ''];
}

function shift_hhmm_to_minutes(string $hhmm): ?int
{
    $hhmm = substr($hhmm, 0, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $hhmm)) return null;
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

function shift_timeline_label(int $minutes): string
{
    $minutes %= 1440;
    if ($minutes < 0) $minutes += 1440;
    $h = intdiv($minutes, 60);
    return str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00';
}

$timelineDate = $base->format('Y-m-d');
$timelineMin = null;
$timelineMax = null;
if ($view === 'timeline') {
    foreach ($shiftRows as $r) {
        $startMin = shift_hhmm_to_minutes((string)($r['start_time'] ?? ''));
        $endMin = shift_hhmm_to_minutes((string)($r['end_time'] ?? ''));
        if ($startMin === null || $endMin === null) continue;
        if ($endMin <= $startMin) $endMin += 1440;
        $timelineMin = ($timelineMin === null) ? $startMin : min($timelineMin, $startMin);
        $timelineMax = ($timelineMax === null) ? $endMin : max($timelineMax, $endMin);
    }
}
$timelineStartMin = $timelineMin === null ? 18 * 60 : max(0, intdiv($timelineMin, 60) * 60);
$timelineEndMin = $timelineMax === null ? 26 * 60 : min(30 * 60, (int)(ceil($timelineMax / 60) * 60));
if ($timelineEndMin <= $timelineStartMin) $timelineEndMin = $timelineStartMin + 8 * 60;
$timelineTotalMin = max(60, $timelineEndMin - $timelineStartMin);
$timelineHourWidth = 96;
$timelineWidth = (int)ceil($timelineTotalMin / 60) * $timelineHourWidth;
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?></title>
    <style>
    /* ===== 置換用CSS（重複削除・整理済み） ===== */
    :root {
        --bg: #fff;
        --card: #fff;
        --border: #e9e9e9;
        --text: #222;
        --muted: #777;
        --selectedBlue: #dff3ff;
        --selectedBlueBorder: #9ed6ff;
        --sunBg: #fff1f1;
        --sunBorder: #ffd0d0;
        --sunText: #b00020;
        --satBg: #eef5ff;
        --satBorder: #cfe2ff;
        --satText: #0b57d0;
        --dropOk: #e9fff1;
        --dropOkBorder: #80e0a7;
        --dropNg: #fff1f1;
        --dropNgBorder: #ffb3b3;
        --radius: 14px;
        --radius-sm: 12px;
        --radius-lg: 16px;
    }

    * {
        box-sizing: border-box
    }

    body {
        margin: 0;
        font-family: system-ui, -apple-system, sans-serif;
        background: var(--bg);
        color: var(--text);
        font-size: 14px
    }

    html,
    body.shiftPage {
        min-height: 100%;
    }

    .shiftPage,
    .shiftPage * {
        font-size: 14px;
    }

    .page {
        min-height: calc(100vh - 72px);
        min-height: calc(100dvh - 72px);
        padding: 14px 24px 64px;
        display: flex;
        flex-direction: column;
    }

    .card {
        background: var(--card);
        border: 1px solid #eee;
        border-radius: var(--radius);
        padding: 14px
    }

    .top {
        display: flex;
        gap: 10px;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 34px;
        padding: 0 12px;
        border-radius: var(--radius);
        border: 1px solid #ddd;
        background: #fff;
        color: #555;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap
    }

    .btn.primary {
        border-color: #111;
        background: #111;
        color: #fff;
        font-weight: 800
    }

    .btn.white {
        border-color: #ddd;
        background: #fff;
        color: #111;
        font-weight: 800
    }

    .muted {
        color: var(--muted);
        font-size: 12px
    }

    .subCard {
        margin-top: 12px;
        background: #fbfbfb;
        border: 1px solid #eee;
        border-radius: var(--radius);
        padding: 10px 12px
    }

    .subCard summary {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        list-style: none;
        cursor: pointer;
        user-select: none;
        color: #555;
        font-weight: 800;
        font-size: 13px
    }

    .subCard summary::-webkit-details-marker {
        display: none
    }

    .badgeOk {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 999px;
        border: 1px solid #bde5bd;
        background: #ecffec;
        color: #1a7f1a;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap
    }

    input,
    select,
    textarea {
        width: 100%;
        padding: 8px;
        margin-top: 6px;
        border: 1px solid #ddd;
        border-radius: var(--radius-sm);
        background: #fff;
        color: #333;
        font-weight: 500
    }

    textarea {
        min-height: 60px
    }

    .subGrid {
        margin-top: 10px;
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 8px;
        align-items: end
    }

    @media (max-width:980px) {
        .subGrid {
            grid-template-columns: 1fr
        }
    }

    .subActions {
        margin-top: 10px;
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: center
    }

    .miniBtn {
        height: 30px;
        padding: 0 10px;
        border-radius: var(--radius);
        border: 1px solid #ddd;
        background: #fff;
        color: #555;
        font-weight: 700;
        cursor: pointer
    }

    .applyBtn {
        height: 30px;
        padding: 0 10px;
        border-radius: var(--radius);
        border: 1px solid #111;
        background: #111;
        color: #fff;
        font-weight: 800;
        cursor: pointer
    }

    .searchBar {
        margin-top: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: space-between
    }

    .searchLeft {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        min-width: 0
    }

    .searchLeft input[type="text"] {
        width: min(320px, 70vw);
        margin-top: 0;
        height: 30px;
        padding: 0 10px;
        font-weight: 700;
        font-size: 12px
    }

    .searchLeft .btn {
        height: 30px;
        font-size: 12px;
    }

    .pager {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap
    }

    .pager .pill {
        height: 28px;
        display: inline-flex;
        align-items: center;
        padding: 0 10px;
        border: 1px solid #eee;
        background: #fafafa;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        color: #444;
        white-space: nowrap
    }

    /* スクロールバー非表示（スクロール可能） */
    .tableWrap {
        overflow: auto;
        -ms-overflow-style: none;
        scrollbar-width: none;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: #fff;
        flex: 1 1 auto;
        min-height: 360px;
        height: calc(100vh - 250px);
        height: calc(100dvh - 250px);
    }

    .tableWrap::-webkit-scrollbar {
        display: none
    }

    table.shiftTable {
        border-collapse: collapse;
        width: max-content;
        min-width: 100%;
    }

    .timelinePanel {
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: #fff;
        overflow: hidden;
        flex: 1 1 auto;
        min-height: 360px;
        height: calc(100vh - 250px);
        height: calc(100dvh - 250px);
        display: flex;
        flex-direction: column;
    }

    .timelineHead {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 12px 14px;
        border-bottom: 1px solid var(--border);
        background: #fafafa;
        flex-wrap: wrap;
    }

    .timelineTitle {
        font-size: 16px;
        font-weight: 900;
        color: #222;
    }

    .timelineMeta {
        color: var(--muted);
        font-size: 12px;
        font-weight: 800;
    }

    .timelineScroll {
        overflow: auto;
        -ms-overflow-style: none;
        scrollbar-width: none;
        flex: 1 1 auto;
        min-height: 0;
    }

    .timelineScroll::-webkit-scrollbar {
        display: none;
    }

    .timelineGrid {
        width: max-content;
        min-width: 100%;
    }

    .timelineScale,
    .timelineRow {
        display: grid;
        grid-template-columns: <?= (int)$empColPx ?>px <?= (int)$timelineWidth ?>px;
        min-width: <?= (int)($empColPx + $timelineWidth) ?>px;
    }

    .timelineScale {
        position: sticky;
        top: 0;
        z-index: 4;
        background: #fff;
        border-bottom: 1px solid var(--border);
    }

    .timelineNameHead,
    .timelineName {
        position: sticky;
        left: 0;
        z-index: 3;
        background: #fff;
        border-right: 1px solid var(--border);
    }

    .timelineNameHead {
        z-index: 5;
        padding: 10px 12px;
        font-size: 12px;
        font-weight: 900;
        color: #555;
    }

    .timelineTicks {
        position: relative;
        height: 38px;
        background:
            repeating-linear-gradient(to right, #e5e7eb 0, #e5e7eb 1px, transparent 1px, transparent <?= (int)$timelineHourWidth ?>px),
            #fff;
    }

    .timelineTick {
        position: absolute;
        top: 10px;
        transform: translateX(6px);
        color: #777;
        font-size: 11px;
        font-weight: 800;
        white-space: nowrap;
    }

    .timelineRow {
        border-bottom: 1px solid var(--border);
    }

    .timelineRow:last-child {
        border-bottom: 0;
    }

    .timelineName {
        padding: 10px 12px;
        min-height: 54px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 3px;
    }

    .timelineLane {
        position: relative;
        min-height: 54px;
        background:
            repeating-linear-gradient(to right, #eef0f3 0, #eef0f3 1px, transparent 1px, transparent <?= (int)$timelineHourWidth ?>px),
            linear-gradient(to bottom, #fff, #fff);
    }

    .timelineOpenSurface {
        cursor: pointer;
    }

    .timelineBar {
        position: absolute;
        top: 9px;
        height: 36px;
        min-width: 28px;
        border: 1px solid #b7d8c4;
        border-radius: 999px;
        background: #eaf8ef;
        color: #27613f;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 10px;
        font-size: 12px;
        font-weight: 900;
        line-height: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        cursor: pointer;
    }

    .timelineBar:hover {
        filter: brightness(.98);
        box-shadow: 0 4px 14px rgba(39, 97, 63, .16);
    }

    .timelineLeave {
        display: inline-flex;
        width: fit-content;
        align-items: center;
        justify-content: center;
        min-height: 20px;
        padding: 3px 7px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 900;
        line-height: 1;
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .timelineEmpty {
        padding: 28px 14px;
        color: var(--muted);
        font-size: 13px;
        font-weight: 800;
        text-align: center;
    }

    table.shiftTable th,
    table.shiftTable td {
        border: 1px solid var(--border);
        vertical-align: top
    }

    table.shiftTable th:first-child,
    table.shiftTable td:first-child {
        position: sticky;
        left: 0;
        z-index: 5;
        background: #fff;
        cursor: default;
        border-right: 1px solid var(--border)
    }

    table.shiftTable thead th:first-child {
        z-index: 8
    }

    @media (max-width:650px) {
        table.shiftTable col:first-child {
            width: 64px !important;
        }

        table.shiftTable th:first-child,
        table.shiftTable td:first-child {
            width: 64px;
            min-width: 64px;
            max-width: 64px;
            padding: 6px;
        }

        .empName {
            font-size: 12px;
            line-height: 1.2;
            word-break: break-word;
        }

        .empMeta {
            font-size: 11px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    }

    .shiftTable thead th {
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 6;
        text-align: center;
        padding: 10px 6px;
    }

    .shiftTable th.sunCol {
        background: var(--sunBg);
        color: var(--sunText);
        border-bottom-color: var(--sunBorder)
    }

    .shiftTable td.sunCol {
        background: var(--sunBg)
    }

    .shiftTable th.satCol {
        background: var(--satBg);
        color: var(--satText);
        border-bottom-color: var(--satBorder)
    }

    .shiftTable td.satCol {
        background: var(--satBg)
    }

    td.shiftTd {
        cursor: pointer
    }

    td.shiftTd:hover {
        filter: brightness(.99)
    }

    .cell {
        min-height: 64px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        gap: 6px
    }

    .timeBox {
        width: 100%;
        border: 1px solid #eee;
        background: rgba(255, 255, 255, .65);
        border-radius: var(--radius);
        padding: 6px;
        text-align: center;
        overflow: hidden
    }

    .timeBox.is-draggable {
        cursor: grab;
        user-select: none
    }

    .timeBox.is-draggable:active {
        cursor: grabbing
    }

    .timeBox.dragging {
        opacity: .55
    }

    .timeRow {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 2px
    }

    .timeRow .t,
    .timeRow .m {
        font-size: 11px;
        line-height: 1.05;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%
    }

    .timeRow .t {
        font-weight: 900;
        color: #333
    }

    .timeRow .m {
        font-weight: 800;
        color: var(--muted)
    }

    .moreBadge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-top: 4px;
        padding: 2px 6px;
        border-radius: 999px;
        border: 1px solid #eee;
        background: #fff;
        color: #666;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap;
        pointer-events: none
    }

    td.shiftTd.drop-ok {
        outline: 2px solid var(--dropOkBorder);
        outline-offset: -2px;
        background: var(--dropOk) !important
    }

    td.shiftTd.drop-ng {
        outline: 2px solid var(--dropNgBorder);
        outline-offset: -2px;
        background: var(--dropNg) !important
    }

    .attLabel {
        margin-top: 2px;
        font-size: 11px;
        font-weight: 900;
        line-height: 1;
        padding: 3px 8px;
        border: 1px solid #eee;
        background: #fff;
        color: #666;
        border-radius: 999px;
        white-space: nowrap
    }

    .attLabel.in {
        border-color: #bde5bd;
        background: #ecffec;
        color: #1a7f1a
    }

    .attLabel.out {
        border-color: #cfe2ff;
        background: #eef5ff;
        color: #0b57d0
    }

    .attLabel.absent {
        border-color: #ffb3b3;
        background: #ffecec;
        color: #b00020
    }

    .modalBack {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .35);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        z-index: 9999
    }

    .modalBack.is-open {
        display: flex
    }

    .modal {
        width: min(520px, 100%);
        background: #fff;
        border-radius: var(--radius-lg);
        border: 1px solid #eee;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
        overflow: hidden
    }

    .modalHead {
        padding: 12px 14px;
        border-bottom: 1px solid #eee;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px
    }

    .modalTitle {
        font-weight: 900;
        color: #222;
        font-size: 14px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .modalBody {
        padding: 12px 14px
    }

    .grid2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px
    }

    @media (max-width:520px) {
        .grid2 {
            grid-template-columns: 1fr
        }
    }

    .modalActions {
        padding: 12px 14px;
        border-top: 1px solid #eee;
        display: flex;
        gap: 8px;
        justify-content: flex-end
    }

    .btn2 {
        height: 34px;
        padding: 0 12px;
        border-radius: var(--radius);
        border: 1px solid #ddd;
        background: #fff;
        color: #555;
        font-weight: 800;
        cursor: pointer
    }

    .btn2.primary {
        border-color: #111;
        background: #111;
        color: #fff
    }

    .btn2.danger {
        border-color: #ffb3b3;
        background: #ffecec;
        color: #b00020
    }

    .btn2.markOffAction {
        margin-right: auto
    }

    .toggleRow {
        margin-top: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        justify-content: flex-start;
        flex-wrap: wrap
    }

    .toggleRow input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin: 0
    }

    .toggleRow .label {
        font-weight: 800;
        color: #333;
        font-size: 13px
    }

    .toggleRow .hint {
        font-size: 12px;
        color: var(--muted);
        font-weight: 600
    }

    .dayListBox {
        border: 1px solid #eee;
        background: #fafafa;
        border-radius: var(--radius);
        padding: 10px;
        margin-bottom: 10px
    }

    .dayListHead {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        list-style: none;
        cursor: pointer;
        user-select: none
    }

    .dayListBox summary::-webkit-details-marker {
        display: none
    }

    .dayListTitle {
        font-weight: 900;
        color: #333;
        font-size: 13px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .dayListBtns {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap
    }

    .miniAction {
        height: 28px;
        padding: 0 10px;
        border-radius: var(--radius);
        border: 1px solid #ddd;
        background: #fff;
        color: #555;
        font-weight: 800;
        cursor: pointer;
        font-size: 12px;
        white-space: nowrap
    }

    .miniAction.primary {
        border-color: #111;
        background: #111;
        color: #fff
    }

    .miniAction.danger {
        border-color: #ffb3b3;
        background: #ffecec;
        color: #b00020
    }

    .dayRow {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px;
        border-top: 1px solid #eee;
        border-radius: var(--radius);
        transition: background .12s ease, border-color .12s ease
    }

    .dayRow:first-of-type {
        border-top: 0
    }

    .dayRow.is-selected {
        background: var(--selectedBlue);
        border: 1px solid var(--selectedBlueBorder)
    }

    .dayRowLeft {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 8px
    }

    .editingBadge {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 999px;
        background: #111;
        color: #fff;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap
    }

    .dayRowText {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px
    }

    .dayRowMain {
        font-weight: 900;
        color: #333;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .dayRowSub {
        font-size: 11px;
        color: var(--muted);
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .dayRowRight {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-shrink: 0
    }

    /* =========================================================
       ✅ ヘッダー見た目（出勤人数 + メモ赤点 + 本日マーカー）
       ========================================================= */
    .shiftTable thead th .headTop {
        position: relative;
        height: 20px;
        margin-bottom: 6px;
    }

    .shiftTable thead th .headCount {
        position: absolute;
        left: 6px;
        top: 0;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 6px;
        border-radius: 999px;
        border: 1px solid #ddd;
        background: #fafafa;
        color: #555;
        font-size: 10px;
        font-weight: 900;
        line-height: 1;
        white-space: nowrap;
    }

    .shiftTable thead th.sunCol .headCount {
        border-color: var(--sunBorder);
        background: #fff6f6;
        color: #a00018
    }

    .shiftTable thead th.satCol .headCount {
        border-color: var(--satBorder);
        background: #f4f8ff;
        color: var(--satText)
    }

    .shiftTable thead th .d1 {
        font-size: 13px;
        font-weight: 900;
        line-height: 1.1;
        color: #444;
        white-space: nowrap
    }

    .shiftTable thead th .d2 {
        margin-top: 3px;
        font-size: 11px;
        font-weight: 800;
        opacity: .85;
        color: var(--muted);
        white-space: nowrap
    }

    /* ✅ メモ赤点：JSで is-on を付けた日だけ表示 */
    .shiftTable thead th .memoPin {
        position: absolute;
        right: -2px;
        top: -6px;
        width: 12px;
        height: 12px;
        border-radius: 999px;
        background: #ff3b30;
        border: 2px solid #fff;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .22);
        z-index: 50;
        display: none;
    }

    .shiftTable thead th .memoPin.is-on {
        display: inline-block;
    }

    .shiftTable thead th .memoPin[data-memo]::after {
        content: attr(data-memo);
        position: absolute;
        right: 0;
        top: 18px;
        min-width: 180px;
        max-width: 260px;
        padding: 8px 10px;
        border-radius: 10px;
        background: rgba(17, 17, 17, .92);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.35;
        white-space: pre-wrap;
        word-break: break-word;
        opacity: 0;
        transform: translateY(-4px);
        pointer-events: none;
        transition: opacity .12s ease, transform .12s ease;
        z-index: 9999;
        box-shadow: 0 14px 40px rgba(0, 0, 0, .25);
    }

    .shiftTable thead th .memoPin[data-memo]::before {
        content: "";
        position: absolute;
        right: 8px;
        top: 12px;
        width: 0;
        height: 0;
        border-left: 6px solid transparent;
        border-right: 6px solid transparent;
        border-bottom: 6px solid rgba(17, 17, 17, .92);
        opacity: 0;
        transform: translateY(-4px);
        pointer-events: none;
        transition: opacity .12s ease, transform .12s ease;
        z-index: 9999;
    }

    .shiftTable thead th .memoPin[data-memo]:hover::after,
    .shiftTable thead th .memoPin[data-memo]:hover::before {
        opacity: 1;
        transform: translateY(0);
    }

    .shiftTable thead th[data-date] {
        cursor: pointer
    }

    .shiftTable thead th[data-date]:hover {
        filter: brightness(.99)
    }

    /* ✅ 本日マーク：バッジと重ならない「専用1行」 */
    .shiftTable thead th .todayMarkRow {
        height: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 2px;
    }

    /* ▼自体 */
    .shiftTable thead th .todayArrow {
        display: inline-block;
        color: #0b57d0;
        font-weight: 900;
        font-size: 16px;
        line-height: 1;
        text-shadow: 0 2px 6px rgba(0, 0, 0, .18);
        pointer-events: none;
    }

    /* 今日じゃない列は「高さ維持のために非表示」 */
    .shiftTable thead th .todayArrow.is-off {
        visibility: hidden;
    }

    /* =========================================================
       ✅ 追加UI（シフト表の見やすさ改善）
       ========================================================= */
    .shiftPage .shiftTable thead th .headCount {
        font-size: 8px;
        padding: 1px 4px;
        border-radius: 999px;
        max-width: calc(100% - 12px);
        height: 18px;
        box-sizing: border-box;
        justify-content: center;
        gap: 3px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .shiftPage .shiftTable thead th .headCount .num {
        font-size: 10px;
        font-weight: 900;
    }

    .shiftPage .shiftTable thead th .headCount .label,
    .shiftPage .shiftTable thead th .headCount .unit {
        font-size: 7px;
    }

    .shiftPage .shiftTable thead th .d1 {
        font-size: 13px;
    }

    .shiftPage .shiftTable thead th .d2 {
        font-size: 11px;
        color: #888;
    }

    .shiftPage .shiftTable thead th.sunCol .d2 {
        color: var(--sunText);
    }

    .shiftPage .shiftTable thead th.satCol .d2 {
        color: var(--satText);
    }

    .shiftPage .shiftTable thead th.todayCol,
    .shiftPage .shiftTable td.todayCol {
        box-shadow: inset 0 0 0 2px #cfe2ff, 0 0 0 1px #dbeafe;
    }

    .shiftPage .shiftTable thead th.todayCol .d1::after {
        content: "";
    }

    .shiftPage .shiftTable th:first-child,
    .shiftPage .shiftTable td:first-child {
        box-shadow: 2px 0 0 rgba(0, 0, 0, .06);
        padding-left: 0;
        background: #fff;
        z-index: 7;
    }

    .shiftPage .empHeadLabel {
        display: inline-block;
        padding-left: 6px;
    }

    .shiftPage .top {
        display: flex;
        flex-direction: row;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }

    .shiftPage .topMeta .muted {
        display: block;
        margin-top: 4px;
    }

    .shiftPage .topActions {
        margin-left: auto;
        padding-top: 0;
        border-top: 0;
        align-items: center;
        justify-content: space-between;
        width: 100%;
    }

    .shiftPage .topBtns {
        display: inline-flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }

    .shiftPage .topBand {
        background: #F8F8FC;
        margin: -24px -24px 14px;
        padding: 12px 24px 14px;
    }

    .shiftPage .metaPill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        background: #f3f3f3;
        border: 1px solid #e5e5e5;
        border-radius: 999px;
        font-weight: 700;
        white-space: nowrap;
        color: #9ca3af;
    }

    .shiftPage .empMeta {
        color: #888;
        font-size: 11px;
    }

    .shiftPage .cell {
        align-items: stretch;
        min-height: 72px;
    }

    .shiftPage .cellTime,
    .shiftPage .cellStatus {
        width: 100%;
        display: flex;
        justify-content: center;
    }

    .shiftPage .cellStatus {
        margin-top: auto;
        min-height: 18px;
        gap: 4px;
        flex-wrap: wrap;
    }

    .shiftPage .leaveReqBadge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 20px;
        padding: 3px 7px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 900;
        line-height: 1;
        text-decoration: none;
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }

    .shiftPage .leaveReqBadge.approved {
        background: #dcfce7;
        color: #166534;
        border-color: #bbf7d0;
    }

    .shiftPage td.shiftTd.cell-warn {
        background: #ffecec;
    }

    .shiftPage td.shiftTd.cell-normal {
        background: transparent;
    }

    .shiftPage .btn.primaryAction {
        background: #111;
        color: #fff;
        border-color: rgba(0, 0, 0, .14);
        border-radius: 999px;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
    }

    .shiftPage .btn.subAction {
        background: #fff;
        color: #555;
        border-color: #e5e7eb;
        border-radius: 999px;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
    }

    .shiftPage footer {
        background: var(--bg) !important;
        border-top-color: var(--border) !important;
        color: var(--muted) !important;
    }

    body.adminTheme.adminHomeDark.shiftPage footer {
        background: #080b12 !important;
        border-top-color: rgba(148, 163, 184, .18) !important;
        color: #64748b !important;
    }

    input::placeholder {
        color: #aaa;
        /* 薄いグレー */
        opacity: 1;
        /* Safari対策（必須） */
    }
    </style>
</head>

<body class="<?= h(trim('shiftPage ' . $bodyClass)) ?>">
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="shiftNavTabsHost">
        <?php require_once __DIR__ . '/_shift_nav_tabs.php'; ?>
    </div>
    <div class="page">

        <div class="topBand">
        <div class="top">
                <div class="topMeta">
                </div>

                <div class="topActions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <div class="topBtns">
                        <a class="btn"
                            href="<?= h(url_with(['view' => 'month', 'date' => $base->format('Y-m-d'), 'page' => 1])) ?>">月</a>
                        <a class="btn"
                            href="<?= h(url_with(['view' => 'week',  'date' => $base->format('Y-m-d'), 'page' => 1])) ?>">週</a>
                        <a class="btn"
                            href="<?= h(url_with([
                                'view' => $graphToggleView,
                                'from_view' => ($view === 'timeline' ? null : $view),
                                'date' => $base->format('Y-m-d'),
                                'page' => 1,
                            ])) ?>"><?= h($graphToggleLabel) ?></a>
                        <a class="btn" href="<?= h(url_with(['date' => $prevDate, 'page' => 1])) ?>">←前</a>
                        <a class="btn" href="<?= h(url_with(['date' => $nextDate, 'page' => 1])) ?>">次→</a>
                    </div>
                    <span class="metaPill">
                        範囲：<?= h($rangeStart) ?>〜<?= h($rangeEnd) ?> /
                        従業員：<?= (int)$totalEmployees ?>人（表示 <?= (int)count($employees) ?>人）
                        / 締め：<?= h($cutoffHHMM) ?>
                    </span>
                </div>
        </div>

        <div class="searchBar">
            <form class="searchLeft" method="get" action="/admin/shifts.php">
                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                <input type="hidden" name="view" value="<?= h($view) ?>">
                <input type="hidden" name="date" value="<?= h($base->format('Y-m-d')) ?>">
                <input type="hidden" name="page" value="1">

                <input type="text" name="q" value="<?= h($q) ?>" placeholder="従業員名で検索（例：田中）">
                <button class="btn" type="submit">検索</button>

                <?php if ($q !== ''): ?>
                <a class="btn" href="<?= h(url_with(['q' => '', 'page' => 1])) ?>">クリア</a>
                <?php endif; ?>
            </form>

            <div class="pager">
                <span class="pill">ページ <?= (int)$page ?> / <?= (int)$totalPages ?>（1ページ<?= (int)$limit ?>人）</span>

                <?php if ($page > 1): ?>
                <a class="btn" href="<?= h(url_with(['page' => $page - 1])) ?>">←前の50人</a>
                <?php else: ?>
                <span class="btn" style="opacity:.4; pointer-events:none;">←前の50人</span>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                <a class="btn" href="<?= h(url_with(['page' => $page + 1])) ?>">次の50人→</a>
                <?php else: ?>
                <span class="btn" style="opacity:.4; pointer-events:none;">次の50人→</span>
                <?php endif; ?>
            </div>
        </div>

        </div>

        <?php if ($view === 'timeline'): ?>
        <div class="timelinePanel">
            <div class="timelineHead">
                <div>
                    <div class="timelineTitle"><?= h($base->format('Y年n月j日') . '（' . $wdayJa[(int)$base->format('w')] . '）') ?></div>
                    <div class="timelineMeta">
                        表示時間：<?= h(shift_timeline_label($timelineStartMin)) ?>〜<?= h(shift_timeline_label($timelineEndMin)) ?> /
                        出勤予定：<?= (int)($dayShiftHeadCount[$timelineDate] ?? 0) ?>人
                    </div>
                </div>
                <div class="topBtns">
                    <a class="btn" href="<?= h(url_with(['date' => $prevDate, 'page' => 1])) ?>">前日</a>
                    <a class="btn" href="<?= h(url_with(['date' => $todayYmd, 'page' => 1])) ?>">今日</a>
                    <a class="btn" href="<?= h(url_with(['date' => $nextDate, 'page' => 1])) ?>">翌日</a>
                </div>
            </div>
            <div class="timelineScroll" id="tableWrap">
                <div class="timelineGrid">
                    <div class="timelineScale">
                        <div class="timelineNameHead">従業員</div>
                        <div class="timelineTicks">
                            <?php for ($m = $timelineStartMin; $m <= $timelineEndMin; $m += 60): ?>
                            <?php $left = (($m - $timelineStartMin) / $timelineTotalMin) * $timelineWidth; ?>
                            <div class="timelineTick" style="left:<?= h(number_format($left, 4, '.', '')) ?>px;">
                                <?= h(shift_timeline_label($m)) ?>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <?php if (!$employees): ?>
                    <div class="timelineEmpty">該当する従業員がいません（検索条件を変えてください）</div>
                    <?php endif; ?>

                    <?php foreach ($employees as $e): ?>
                    <?php
                        $eid = (int)$e['id'];
                        $ename = (string)$e['display_name'];
                        $list = $shiftMap[$eid][$timelineDate] ?? [];
                        $leaveReq = $leaveRequestMap[$eid][$timelineDate] ?? null;
                        $mini = [];
                        foreach ($list as $r) {
                            $mini[] = [
                                'id'    => (int)$r['id'],
                                'start' => substr((string)$r['start_time'], 0, 5),
                                'end'   => substr((string)$r['end_time'], 0, 5),
                                'break' => (int)$r['break_minutes'],
                                'note'  => (string)($r['note'] ?? ''),
                            ];
                        }
                        $shiftsB64 = base64_encode(json_encode($mini, JSON_UNESCAPED_UNICODE));
                    ?>
                    <div class="timelineRow">
                        <div class="timelineName">
                            <div class="empName"><?= h($ename) ?></div>
                            <div class="empMeta">ID:<?= (int)$eid ?></div>
                            <?php if ($leaveReq): ?>
                            <?php $leaveStatus = (string)($leaveReq['status'] ?? ''); ?>
                            <div class="timelineLeave"><?= h($leaveStatus === 'approved' ? '休承認' : '休申請') ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="timelineLane timelineOpenSurface"
                            data-store-id="<?= (int)$storeId ?>"
                            data-employee-id="<?= (int)$eid ?>"
                            data-employee-name="<?= h($ename) ?>"
                            data-shift-date="<?= h($timelineDate) ?>"
                            data-shifts="<?= h($shiftsB64) ?>">
                            <?php foreach ($list as $r): ?>
                            <?php
                                $st = substr((string)$r['start_time'], 0, 5);
                                $et = substr((string)$r['end_time'], 0, 5);
                                $startMin = shift_hhmm_to_minutes($st);
                                $endMin = shift_hhmm_to_minutes($et);
                                if ($startMin === null || $endMin === null) continue;
                                if ($endMin <= $startMin) $endMin += 1440;
                                $left = max(0, (($startMin - $timelineStartMin) / $timelineTotalMin) * $timelineWidth);
                                $width = max(28, (($endMin - $startMin) / $timelineTotalMin) * $timelineWidth);
                            ?>
                            <div class="timelineBar timelineOpen"
                                style="left:<?= h(number_format($left, 4, '.', '')) ?>px;width:<?= h(number_format($width, 4, '.', '')) ?>px;"
                                title="<?= h($ename . ' ' . $st . '-' . $et) ?>"
                                data-store-id="<?= (int)$storeId ?>"
                                data-employee-id="<?= (int)$eid ?>"
                                data-employee-name="<?= h($ename) ?>"
                                data-shift-date="<?= h($timelineDate) ?>"
                                data-shifts="<?= h($shiftsB64) ?>">
                                <?= h($st . '-' . $et) ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="tableWrap" id="tableWrap">
                <table class="shiftTable">
                    <colgroup>
                        <col style="width:<?= (int)$empColPx ?>px;">
                        <?php foreach ($days as $_): ?>
                        <col style="width:<?= (int)$dayColPx ?>px;">
                        <?php endforeach; ?>
                    </colgroup>

                    <thead>
                        <tr>
                            <th><span class="empHeadLabel">従業員</span></th>

                            <?php foreach ($days as $d): ?>
                            <?php
                                $w    = (int)$d->format('w');
                                $dYmd = $d->format('Y-m-d');

                                // ✅ 日別人数（DB集計）
                                $cnt = (int)($dayShiftHeadCount[$dYmd] ?? 0);

                                // ✅ 本日判定
                                $isToday = ($dYmd === $todayYmd);
                                ?>
                            <th class="<?= $w === 0 ? 'sunCol' : ($w === 6 ? 'satCol' : '') ?> <?= $isToday ? 'todayCol' : '' ?>"
                                data-date="<?= h($dYmd) ?>">
                                <div class="headTop">
                                    <div class="headCount" title="この日の出勤予定人数">
                                        <span class="label">出勤</span>
                                        <span class="num"><?= (int)$cnt ?></span>
                                        <span class="unit">人</span>
                                    </div>

                                    <span class="memoPin" data-memo-pin="1" aria-label="メモ"></span>
                                </div>


                                <div class="d1"><?= h($d->format('m/d')) ?></div>
                                <div class="d2"><?= h($wdayJa[$w]) ?></div>
                            </th>
                            <?php endforeach; ?>

                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$employees): ?>
                        <tr>
                            <td colspan="<?= 1 + count($days) ?>">
                                <div class="muted" style="padding:12px 8px;">
                                    該当する従業員がいません（検索条件を変えてください）
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($employees as $e): ?>
                        <?php
                            $eid = (int)$e['id'];
                            $ename = (string)$e['display_name'];
                            $status = (string)$e['employment_status'];
                            $statusNorm = strtolower(trim($status));
                            if ($statusNorm === 'active') $status = '有効';
                            ?>
                        <tr>
                            <td>
                                <div class="empName"><?= h($ename) ?></div>
                                <div class="empMeta">ID:<?= (int)$eid ?> / <?= h($status) ?></div>
                            </td>

                            <?php foreach ($days as $d): ?>
                            <?php
                                    $dkey = $d->format('Y-m-d');
                                    $w = (int)$d->format('w');
                                    $colClass = ($w === 0) ? 'sunCol' : (($w === 6) ? 'satCol' : '');
                                    $isTodayCol = ($dkey === $todayYmd);

                                    $list = $shiftMap[$eid][$dkey] ?? [];
                                    $count = count($list);
                                    $firstShift = $count > 0 ? $list[0] : null;

                                    $st = '';
                                    $et = '';
                                    $br = 0;
                                    $note = '';
                                    $firstShiftId = 0;
                                    if ($firstShift) {
                                        $firstShiftId = (int)$firstShift['id'];
                                        $st = substr((string)$firstShift['start_time'], 0, 5);
                                        $et = substr((string)$firstShift['end_time'], 0, 5);
                                        $br = (int)$firstShift['break_minutes'];
                                        $note = (string)($firstShift['note'] ?? '');
                                    }

                                    $mini = [];
                                    foreach ($list as $r) {
                                        $mini[] = [
                                            'id'    => (int)$r['id'],
                                            'start' => substr((string)$r['start_time'], 0, 5),
                                            'end'   => substr((string)$r['end_time'], 0, 5),
                                            'break' => (int)$r['break_minutes'],
                                            'note'  => (string)($r['note'] ?? ''),
                                        ];
                                    }
                                    $shiftsB64 = base64_encode(json_encode($mini, JSON_UNESCAPED_UNICODE));

                                    $earliestStart = null;
                                    if (!empty($list)) {
                                        $earliestStart = '99:99';
                                        foreach ($list as $r) {
                                            $st0 = substr((string)$r['start_time'], 0, 5);
                                            if ($st0 !== '' && $st0 < $earliestStart) $earliestStart = $st0;
                                        }
                                        if ($earliestStart === '99:99') $earliestStart = null;
                                    }

                                    $hasShift = $count > 0;
                                    $hasIn  = !empty($punchInSet[$eid][$dkey]);
                                    $hasOut = !empty($punchOutSet[$eid][$dkey]);
                                    $leaveReq = $leaveRequestMap[$eid][$dkey] ?? null;

                                    $att = attendance_label($dkey, $hasShift, $hasIn, $hasOut, $todayYmd, $earliestStart, $nowHi);
                                    $attClass = (string)($att['class'] ?? '');
                                    $cellStateClass = ($attClass === 'absent')
                                        ? 'cell-warn'
                                        : (($attClass === 'in' || $attClass === 'out') ? 'cell-normal' : '');

                                    $realBreakMin = (int)($breakMinSet[$eid][$dkey] ?? 0);
                                    $realBreakHM  = ($realBreakMin > 0)
                                        ? (intdiv($realBreakMin, 60) . ':' . str_pad((string)($realBreakMin % 60), 2, '0', STR_PAD_LEFT))
                                        : '';
                                    ?>
                            <td class="shiftTd <?= h(trim($colClass . ' ' . ($isTodayCol ? 'todayCol' : '') . ' ' . $cellStateClass)) ?>"
                                data-employee-id="<?= (int)$eid ?>"
                                data-employee-name="<?= h($ename) ?>" data-shift-date="<?= h($dkey) ?>"
                                data-store-id="<?= (int)$storeId ?>" data-shifts="<?= h($shiftsB64) ?>"
                                data-start="<?= h($st) ?>" data-end="<?= h($et) ?>" data-break="<?= h((string)$br) ?>"
                                data-note="<?= h($note) ?>">
                                <div class="cell">
                                    <div class="cellTime">
                                        <?php if ($firstShift): ?>
                                        <div class="timeBox is-draggable" draggable="true"
                                            title="<?= h($st . '-' . $et . ' / 休' . $br . 'm') ?>" data-dnd="shiftbox"
                                            data-shift-id="<?= (int)$firstShiftId ?>" data-employee-id="<?= (int)$eid ?>"
                                            data-from-date="<?= h($dkey) ?>" data-start="<?= h($st) ?>"
                                            data-end="<?= h($et) ?>" data-break="<?= (int)$br ?>"
                                            data-note="<?= h($note) ?>">
                                            <div class="timeRow">
                                                <div class="t"><?= h($st) ?></div>
                                                <div class="t"><?= h($et) ?></div>
                                                <?php if ($realBreakHM !== ''): ?>
                                                <div class="muted" style="font-size:11px; font-weight:800;">実休
                                                    <?= h($realBreakHM) ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($count > 1): ?>
                                            <div class="moreBadge">+<?= (int)($count - 1) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php else: ?>
                                        <div style="height:0;"></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="cellStatus">
                                        <?php if ($leaveReq): ?>
                                        <?php
                                            $leaveStatus = (string)($leaveReq['status'] ?? '');
                                            $leaveLabel = $leaveStatus === 'approved' ? '休承認' : '休申請';
                                            $leaveUrl = '/admin/leave_request_confirm.php?token=' . rawurlencode((string)($leaveReq['token'] ?? ''));
                                        ?>
                                        <a class="leaveReqBadge <?= h($leaveStatus === 'approved' ? 'approved' : '') ?>"
                                            href="<?= h($leaveUrl) ?>"><?= h($leaveLabel) ?></a>
                                        <?php endif; ?>
                                        <?php if (($att['text'] ?? '') !== ''): ?>
                                        <div class="attLabel <?= h($att['class'] ?? '') ?>">
                                            <?= h($att['text'] ?? '') ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

        </div>
        <?php endif; ?>
        <div class="searchBar" style="margin-top:12px;">
            <div class="pager">
                <span class="pill">ページ <?= (int)$page ?> / <?= (int)$totalPages ?></span>
                <?php if ($page > 1): ?>
                <a class="btn" href="<?= h(url_with(['page' => $page - 1])) ?>">←前の50人</a>
                <?php else: ?>
                <span class="btn" style="opacity:.4; pointer-events:none;">←前の50人</span>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                <a class="btn" href="<?= h(url_with(['page' => $page + 1])) ?>">次の50人→</a>
                <?php else: ?>
                <span class="btn" style="opacity:.4; pointer-events:none;">次の50人→</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ✅ DnD 移動用 -->
    <form method="post" action="/admin/shifts_save.php" id="moveShiftForm" style="display:none;"
        data-preserve-scroll="1">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
        <input type="hidden" name="employee_id" id="mv_employee_id" value="">
        <input type="hidden" name="shift_date" id="mv_shift_date" value="">
        <input type="hidden" name="shift_id" id="mv_shift_id" value="">
        <input type="hidden" name="end_next_day" id="mv_end_next_day" value="0">
        <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
        <input type="hidden" name="action" value="upsert">
        <input type="hidden" name="start_time" id="mv_start_time" value="">
        <input type="hidden" name="end_time" id="mv_end_time" value="">
        <input type="hidden" name="break_minutes" id="mv_break_minutes" value="0">
        <input type="hidden" name="note" id="mv_note" value="">
    </form>

    <!-- ✅ モーダル -->
    <div class="modalBack" id="shiftModalBack" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="shiftModalTitle">
            <div class="modalHead">
                <div class="modalTitle" id="shiftModalTitle">シフト編集</div>
                <button class="btn2" type="button" id="shiftModalClose">閉じる</button>
            </div>

            <form method="post" action="/admin/shifts_save.php" id="shiftModalForm" data-preserve-scroll="1">
                <div class="modalBody">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="store_id" id="m_store_id" value="<?= (int)$storeId ?>">
                    <input type="hidden" name="employee_id" id="m_employee_id" value="">
                    <input type="hidden" name="shift_date" id="m_shift_date" value="">
                    <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                    <input type="hidden" name="shift_id" id="m_shift_id" value="">
                    <input type="hidden" name="end_next_day" id="m_end_next_day" value="0">

                    <details class="dayListBox" open>
                        <summary class="dayListHead">
                            <div class="dayListTitle" id="m_day_list_title">この日のシフト</div>
                            <div class="dayListBtns">
                                <button type="button" class="miniAction primary" id="m_add_new">＋新規</button>
                            </div>
                        </summary>
                        <div id="m_day_list" style="margin-top:8px;"></div>
                    </details>

                    <div class="grid2">
                        <div>
                            <label class="muted">開始</label>
                            <input type="time" name="start_time" id="m_start_time" required>
                        </div>
                        <div>
                            <label class="muted">終了</label>
                            <input type="time" name="end_time" id="m_end_time" required>
                        </div>
                    </div>

                    <div class="toggleRow">
                        <input type="checkbox" id="m_end_next_day_check">
                        <div class="label">終了が翌日</div>
                        <div class="hint">（例：20:00 → 01:00）</div>
                    </div>

                    <label class="muted">休憩（分）</label>
                    <input type="number" name="break_minutes" id="m_break_minutes" min="0" step="1" value="0">

                    <label class="muted">メモ</label>
                    <textarea name="note" id="m_note" maxlength="255"></textarea>
                </div>

                <div class="modalActions">
                    <button class="btn2 danger markOffAction" type="button" id="shiftMarkOff">休みに変更</button>
                    <button class="btn2" type="button" id="shiftModalCancel">キャンセル</button>
                    <button class="btn2 primary" type="submit" name="action" value="upsert">保存</button>
                </div>
            </form>

            <form method="post" action="/admin/shifts_save.php" id="shiftDeleteForm" style="display:none;"
                data-preserve-scroll="1">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="store_id" id="d_store_id" value="<?= (int)$storeId ?>">
                <input type="hidden" name="employee_id" id="d_employee_id" value="">
                <input type="hidden" name="shift_date" id="d_shift_date" value="">
                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                <input type="hidden" name="shift_id" id="d_shift_id" value="">
                <input type="hidden" name="action" value="delete">
            </form>

            <form method="post" action="/admin/shifts_save.php" id="shiftMarkOffForm" style="display:none;"
                data-preserve-scroll="1">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="store_id" id="off_store_id" value="<?= (int)$storeId ?>">
                <input type="hidden" name="employee_id" id="off_employee_id" value="">
                <input type="hidden" name="shift_date" id="off_shift_date" value="">
                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                <input type="hidden" name="action" value="mark_off">
            </form>
        </div>
    </div>

    <footer
        style="position:fixed;left:0;right:0;bottom:0;text-align:center;padding:10px 0;font-size:12px;color:#777;background:rgba(255,255,255,.85);border-top:1px solid #eee;backdrop-filter:blur(6px)">
        &copy; AzureSystems by Fader
    </footer>

    <script>
    (function() {
        // =========================
        // ✅ スクロール保存・復元（縦 + 横）
        // =========================
        const tableWrap = document.getElementById('tableWrap');

        try {
            if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        } catch (e) {}

        function scrollKey() {
            const p = new URLSearchParams(location.search);
            const store = p.get('store_id') || '';
            const view = p.get('view') || '';
            const date = p.get('date') || '';
            const q = p.get('q') || '';
            const page = p.get('page') || '';
            return `shifts_scroll:${store}:${view}:${date}:${q}:${page}`;
        }

        function saveScroll() {
            try {
                const key = scrollKey();
                const y = window.scrollY || 0;
                const x = (tableWrap ? tableWrap.scrollLeft : 0) || 0;
                const meaningful = (y > 0 || x > 0);
                sessionStorage.setItem(key, JSON.stringify({
                    y,
                    x,
                    meaningful
                }));
            } catch (e) {}
        }

        function restoreScroll() {
            try {
                const key = scrollKey();
                const raw = sessionStorage.getItem(key);

                const centerToday = () => {
                    const TODAY = "<?= h($todayYmd) ?>";
                    if (!tableWrap) return false;

                    const th = document.querySelector(`table.shiftTable thead th[data-date="${TODAY}"]`);
                    if (!th) return false;

                    if (tableWrap.scrollWidth <= tableWrap.clientWidth) return false;

                    const thCenter = th.offsetLeft + (th.offsetWidth / 2);
                    let desiredLeft = thCenter - (tableWrap.clientWidth / 2);

                    const maxLeft = Math.max(0, tableWrap.scrollWidth - tableWrap.clientWidth);
                    if (desiredLeft < 0) desiredLeft = 0;
                    if (desiredLeft > maxLeft) desiredLeft = maxLeft;

                    tableWrap.scrollLeft = desiredLeft;
                    return true;
                };

                const centerTodayStable = () => {
                    let tries = 0;
                    const tick = () => {
                        tries++;
                        const ok = centerToday();
                        if (ok) {
                            setTimeout(centerToday, 120);
                            setTimeout(centerToday, 400);
                            return;
                        }
                        if (tries < 25) requestAnimationFrame(tick);
                    };
                    requestAnimationFrame(tick);

                    try {
                        if (document.fonts && document.fonts.ready) {
                            document.fonts.ready.then(() => {
                                setTimeout(centerToday, 0);
                                setTimeout(centerToday, 120);
                            });
                        }
                    } catch (e) {}
                };

                if (raw) {
                    let payload = null;
                    try {
                        payload = JSON.parse(raw);
                    } catch (e) {}

                    const px = payload && typeof payload.x === 'number' ? payload.x : null;
                    const py = payload && typeof payload.y === 'number' ? payload.y : null;
                    const meaningful = !!(payload && payload.meaningful);

                    if (meaningful) {
                        if (tableWrap && px !== null) tableWrap.scrollLeft = px;
                        if (py !== null) window.scrollTo(0, py);
                        return;
                    }
                }
                centerTodayStable();
            } catch (e) {}
        }

        window.addEventListener('load', restoreScroll);
        window.addEventListener('pageshow', restoreScroll);

        document.querySelectorAll('form[data-preserve-scroll="1"]').forEach(f => {
            f.addEventListener('submit', () => saveScroll());
        });

        // =========================================================
        // ✅ 日付ヘッダーの「日別メモ」（DB変更なし / localStorage）
        // - memoPin は HTMLで常設 → JSが is-on / data-memo を更新して表示
        // =========================================================
        const TENANT_ID = "<?= (int)$tenantId ?>";
        const STORE_ID = "<?= (int)$storeId ?>";

        function memoKey(dateYmd) {
            return `shiftDayMemo:${TENANT_ID}:${STORE_ID}:${dateYmd}`;
        }

        function getMemo(dateYmd) {
            try {
                return (localStorage.getItem(memoKey(dateYmd)) || "").trim();
            } catch (e) {
                return "";
            }
        }

        function setMemo(dateYmd, text) {
            try {
                const v = (text || "").trim();
                if (v === "") localStorage.removeItem(memoKey(dateYmd));
                else localStorage.setItem(memoKey(dateYmd), v);
            } catch (e) {}
        }

        function refreshMemoPins() {
            document.querySelectorAll('table.shiftTable thead th[data-date]').forEach(th => {
                const date = th.dataset.date || "";
                if (!date) return;

                const memo = getMemo(date);
                const pin = th.querySelector('.memoPin[data-memo-pin="1"]');
                if (!pin) return;

                if (memo) {
                    pin.classList.add('is-on');
                    pin.setAttribute('data-memo', memo);
                    th.title = `メモ: ${memo}`;
                } else {
                    pin.classList.remove('is-on');
                    pin.removeAttribute('data-memo');
                    th.title = "";
                }
            });
        }

        function openMemoEditor(dateYmd) {
            const current = getMemo(dateYmd);
            const next = prompt(`${dateYmd} のメモ（空にすると削除）`, current);
            if (next === null) return;
            setMemo(dateYmd, next);
            refreshMemoPins();
        }

        document.querySelectorAll('table.shiftTable thead th[data-date]').forEach(th => {
            th.addEventListener('click', (e) => {
                const date = th.dataset.date || "";
                if (!date) return;
                openMemoEditor(date);
                e.preventDefault();
                e.stopPropagation();
            });
        });

        window.addEventListener('load', refreshMemoPins);
        window.addEventListener('pageshow', refreshMemoPins);

        // =========================
        // 既存モーダル処理（あなたのコードそのまま）
        // =========================
        const back = document.getElementById('shiftModalBack');
        const closeBtn = document.getElementById('shiftModalClose');
        const cancelBtn = document.getElementById('shiftModalCancel');
        const markOffBtn = document.getElementById('shiftMarkOff');
        const title = document.getElementById('shiftModalTitle');

        const mStore = document.getElementById('m_store_id');
        const mEid = document.getElementById('m_employee_id');
        const mDate = document.getElementById('m_shift_date');
        const mSid = document.getElementById('m_shift_id');
        const mSt = document.getElementById('m_start_time');
        const mEt = document.getElementById('m_end_time');
        const mBr = document.getElementById('m_break_minutes');
        const mNote = document.getElementById('m_note');

        const mEndNextHidden = document.getElementById('m_end_next_day');
        const mEndNextCheck = document.getElementById('m_end_next_day_check');
        const form = document.getElementById('shiftModalForm');

        const dayListTitle = document.getElementById('m_day_list_title');
        const dayList = document.getElementById('m_day_list');
        const addNewBtn = document.getElementById('m_add_new');

        const deleteForm = document.getElementById('shiftDeleteForm');
        const dStore = document.getElementById('d_store_id');
        const dEid = document.getElementById('d_employee_id');
        const dDate = document.getElementById('d_shift_date');
        const dSid = document.getElementById('d_shift_id');
        const markOffForm = document.getElementById('shiftMarkOffForm');
        const offStore = document.getElementById('off_store_id');
        const offEid = document.getElementById('off_employee_id');
        const offDate = document.getElementById('off_shift_date');

        let currentShifts = [];
        let selectedShiftId = null;

        function decodeB64Json(b64) {
            if (!b64) return [];
            try {
                const json = decodeURIComponent(escape(atob(b64)));
                const arr = JSON.parse(json);
                return Array.isArray(arr) ? arr : [];
            } catch (e) {
                try {
                    const arr = JSON.parse(atob(b64));
                    return Array.isArray(arr) ? arr : [];
                } catch (_) {
                    return [];
                }
            }
        }

        function isOvernight(startHHMM, endHHMM) {
            if (!startHHMM || !endHHMM) return false;
            const [sh, sm] = startHHMM.split(':').map(Number);
            const [eh, em] = endHHMM.split(':').map(Number);
            if ([sh, sm, eh, em].some(n => Number.isNaN(n))) return false;
            return (eh * 60 + em) < (sh * 60 + sm);
        }

        function syncOvernightFlag() {
            const auto = isOvernight(mSt.value, mEt.value);
            if (auto) mEndNextCheck.checked = true;
            mEndNextHidden.value = (mEndNextCheck.checked || auto) ? "1" : "0";
        }

        function doDelete(shiftId) {
            if (!shiftId) return;
            if (!confirm("このシフトを削除しますか？")) return;

            saveScroll();

            dStore.value = mStore.value;
            dEid.value = mEid.value;
            dDate.value = mDate.value;
            dSid.value = String(shiftId);
            deleteForm.submit();
        }

        function renderDayList(shifts) {
            dayList.innerHTML = "";
            if (!shifts.length) {
                const empty = document.createElement('div');
                empty.className = "muted";
                empty.textContent = "この日はシフトがありません（＋新規で追加できます）";
                dayList.appendChild(empty);
                return;
            }

            shifts.forEach((s) => {
                const row = document.createElement('div');
                row.className = "dayRow";
                row.dataset.shiftId = String(s.id);

                const selected = (selectedShiftId !== null && Number(s.id) === Number(selectedShiftId));
                if (selected) row.classList.add('is-selected');

                const left = document.createElement('div');
                left.className = "dayRowLeft";

                if (selected) {
                    const b = document.createElement('span');
                    b.className = "editingBadge";
                    b.textContent = "編集中";
                    left.appendChild(b);
                }

                const textWrap = document.createElement('div');
                textWrap.className = "dayRowText";

                const main = document.createElement('div');
                main.className = "dayRowMain";
                main.textContent = `${s.start || ""} - ${s.end || ""}（休${s.break ?? 0}m）`;

                const sub = document.createElement('div');
                sub.className = "dayRowSub";
                const note = (s.note || "").trim();
                sub.textContent = note ? note : `ID:${s.id}`;

                textWrap.appendChild(main);
                textWrap.appendChild(sub);
                left.appendChild(textWrap);

                const right = document.createElement('div');
                right.className = "dayRowRight";

                const editBtn = document.createElement('button');
                editBtn.type = "button";
                editBtn.className = "miniAction";
                editBtn.textContent = "編集";
                editBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    fillFormFromShift(s, mStore.value, mEid.value, mDate.value);
                });

                const delBtn = document.createElement('button');
                delBtn.type = "button";
                delBtn.className = "miniAction danger";
                delBtn.textContent = "削除";
                delBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    doDelete(s.id);
                });

                right.appendChild(editBtn);
                right.appendChild(delBtn);

                row.appendChild(left);
                row.appendChild(right);

                row.addEventListener('click', () => fillFormFromShift(s, mStore.value, mEid.value, mDate
                    .value));
                dayList.appendChild(row);
            });
        }

        function fillFormFromShift(shift, storeId, employeeId, shiftDate) {
            mStore.value = storeId;
            mEid.value = employeeId;
            mDate.value = shiftDate;

            mSid.value = (shift && shift.id) ? String(shift.id) : "";
            mSt.value = (shift && shift.start) ? shift.start : "";
            mEt.value = (shift && shift.end) ? shift.end : "";
            mBr.value = (shift && typeof shift.break !== "undefined") ? String(shift.break) : "0";
            mNote.value = (shift && shift.note) ? shift.note : "";

            mEndNextCheck.checked = isOvernight(mSt.value, mEt.value);
            syncOvernightFlag();

            selectedShiftId = (shift && shift.id) ? Number(shift.id) : null;
            renderDayList(currentShifts);

            setTimeout(() => mSt.focus(), 0);
        }

        function openModal(payload) {
            title.textContent = payload.employeeName + " / " + payload.shiftDate;
            dayListTitle.textContent = "この日のシフト（" + payload.employeeName + " / " + payload.shiftDate + "）";

            currentShifts = Array.isArray(payload.shifts) ? payload.shifts : [];

            if (currentShifts.length > 0) fillFormFromShift(currentShifts[0], payload.storeId, payload.employeeId,
                payload.shiftDate);
            else fillFormFromShift(null, payload.storeId, payload.employeeId, payload.shiftDate);

            back.classList.add('is-open');
            back.setAttribute('aria-hidden', 'false');
        }

        function closeModal() {
            back.classList.remove('is-open');
            back.setAttribute('aria-hidden', 'true');
        }

        function markCurrentDayOff() {
            if (!mStore.value || !mEid.value || !mDate.value) return;
            const hasShift = currentShifts.length > 0;
            const message = hasShift ?
                "この日のシフトを全て削除し、休みに変更しますか？" :
                "この日を休みに変更しますか？";
            if (!confirm(message)) return;

            saveScroll();
            offStore.value = mStore.value;
            offEid.value = mEid.value;
            offDate.value = mDate.value;
            markOffForm.submit();
        }

        let isDragging = false;

        document.querySelectorAll('td.shiftTd').forEach(td => {
            td.addEventListener('click', () => {
                if (isDragging) return;
                const shifts = decodeB64Json(td.dataset.shifts || "");
                openModal({
                    storeId: td.dataset.storeId || "",
                    employeeId: td.dataset.employeeId || "",
                    employeeName: td.dataset.employeeName || "",
                    shiftDate: td.dataset.shiftDate || "",
                    shifts: shifts
                });
            });
        });

        function openModalFromDataset(el) {
            const shifts = decodeB64Json(el.dataset.shifts || "");
            openModal({
                storeId: el.dataset.storeId || "",
                employeeId: el.dataset.employeeId || "",
                employeeName: el.dataset.employeeName || "",
                shiftDate: el.dataset.shiftDate || "",
                shifts: shifts
            });
        }

        document.querySelectorAll('.timelineOpen').forEach(el => {
            el.addEventListener('click', (event) => {
                event.stopPropagation();
                openModalFromDataset(el);
            });
        });

        document.querySelectorAll('.timelineOpenSurface').forEach(el => {
            el.addEventListener('click', () => {
                const shifts = decodeB64Json(el.dataset.shifts || "");
                if (shifts.length > 0) return;
                openModalFromDataset(el);
            });
        });

        addNewBtn.addEventListener('click', (e) => {
            e.preventDefault();
            selectedShiftId = null;
            mSid.value = "";
            mSt.value = "";
            mEt.value = "";
            mBr.value = "0";
            mNote.value = "";
            mEndNextCheck.checked = false;
            mEndNextHidden.value = "0";
            renderDayList(currentShifts);
            setTimeout(() => mSt.focus(), 0);
        });

        mSt.addEventListener('change', syncOvernightFlag);
        mEt.addEventListener('change', syncOvernightFlag);
        mEndNextCheck.addEventListener('change', syncOvernightFlag);

        form.addEventListener('submit', () => {
            syncOvernightFlag();
            saveScroll();
        });

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        markOffBtn.addEventListener('click', markCurrentDayOff);

        back.addEventListener('click', (e) => {
            if (e.target === back) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && back.classList.contains('is-open')) closeModal();
        });

        // =========================
        // ✅ Drag & Drop（同一従業員行の左右移動）
        // =========================
        const moveForm = document.getElementById('moveShiftForm');
        const mvEmp = document.getElementById('mv_employee_id');
        const mvDate = document.getElementById('mv_shift_date');
        const mvSid = document.getElementById('mv_shift_id');
        const mvStart = document.getElementById('mv_start_time');
        const mvEnd = document.getElementById('mv_end_time');
        const mvBreak = document.getElementById('mv_break_minutes');
        const mvNote = document.getElementById('mv_note');
        const mvEndNext = document.getElementById('mv_end_next_day');

        let dragPayload = null;

        function clearDropMarks() {
            document.querySelectorAll('td.shiftTd.drop-ok, td.shiftTd.drop-ng').forEach(el => {
                el.classList.remove('drop-ok', 'drop-ng');
            });
        }

        function canDropToCell(td, payload) {
            if (!td || !payload) return false;
            const toEmp = String(td.dataset.employeeId || '');
            if (toEmp === '' || toEmp !== String(payload.employeeId)) return false;

            const toDate = String(td.dataset.shiftDate || '');
            if (!toDate) return false;

            if (toDate === String(payload.fromDate)) return false;
            return true;
        }

        function submitMove(payload, toTd) {
            const toDate = String(toTd.dataset.shiftDate);

            mvEmp.value = String(payload.employeeId);
            mvDate.value = toDate;

            mvSid.value = String(payload.shiftId);

            mvStart.value = payload.start || "";
            mvEnd.value = payload.end || "";
            mvBreak.value = String(payload.breakMinutes ?? 0);
            mvNote.value = payload.note || "";
            mvEndNext.value = (isOvernight(payload.start, payload.end) ? "1" : "0");

            saveScroll();
            moveForm.submit();
        }

        document.querySelectorAll('.timeBox.is-draggable[draggable="true"]').forEach(box => {
            box.addEventListener('dragstart', (e) => {
                const shiftId = box.dataset.shiftId;
                const employeeId = box.dataset.employeeId;
                const fromDate = box.dataset.fromDate;

                isDragging = true;

                if (!shiftId || !employeeId || !fromDate) {
                    e.preventDefault();
                    return;
                }

                dragPayload = {
                    shiftId: Number(shiftId),
                    employeeId: String(employeeId),
                    fromDate: String(fromDate),
                    start: String(box.dataset.start || ""),
                    end: String(box.dataset.end || ""),
                    breakMinutes: Number(box.dataset.break || 0),
                    note: String(box.dataset.note || "")
                };

                box.classList.add('dragging');

                try {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', 'shift:' + shiftId);
                } catch (_) {}

                clearDropMarks();
            });

            box.addEventListener('dragend', () => {
                box.classList.remove('dragging');
                dragPayload = null;
                clearDropMarks();
                setTimeout(() => {
                    isDragging = false;
                }, 80);
            });
        });

        document.querySelectorAll('td.shiftTd').forEach(td => {
            td.addEventListener('dragenter', (e) => {
                if (!dragPayload) return;
                e.preventDefault();
                clearDropMarks();
                td.classList.add(canDropToCell(td, dragPayload) ? 'drop-ok' : 'drop-ng');
            });

            td.addEventListener('dragover', (e) => {
                if (!dragPayload) return;
                e.preventDefault();
                const ok = canDropToCell(td, dragPayload);
                try {
                    e.dataTransfer.dropEffect = ok ? 'move' : 'none';
                } catch (_) {}
            });

            td.addEventListener('dragleave', () => {
                td.classList.remove('drop-ok', 'drop-ng');
            });

            td.addEventListener('drop', (e) => {
                if (!dragPayload) return;
                e.preventDefault();

                const ok = canDropToCell(td, dragPayload);
                clearDropMarks();

                if (!ok) {
                    alert('同じ従業員の行の中で、別の日にだけ移動できます。');
                    return;
                }
                isDragging = true;
                submitMove(dragPayload, td);
            });
        });

    })();
    </script>
</body>

</html>
