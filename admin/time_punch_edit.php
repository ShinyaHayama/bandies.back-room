<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/time_punch_edit.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 変更内容（UIだけ）
 * - 出勤/退勤/休憩（複数）の時刻入力を「自由入力」→「選択式（プルダウン）」へ変更
 * - 5分刻み（00:00〜23:55）＋ 空欄（未設定）を用意
 * - 既存の出勤/退勤の編集ロジックは維持（DB更新は save 側）
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n\n";
    echo $e;
    exit;
});
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

date_default_timezone_set('Asia/Tokyo');

// DB
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
if ($dbFile === null) throw new RuntimeException("db.php not found");
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function isHm(string $s): bool
{
    return (bool)preg_match('/^\d{2}:\d{2}$/', $s);
}
function normalizeBreakInputs(array $starts, array $ends, int $max = 20): array
{
    $out = [];
    $n = max(count($starts), count($ends));
    for ($i = 0; $i < $n; $i++) {
        $s = trim((string)($starts[$i] ?? ''));
        $e = trim((string)($ends[$i] ?? ''));
        if ($s === '' && $e === '') continue;
        $out[] = ['start' => $s, 'end' => $e];
        if (count($out) >= $max) break;
    }
    return $out;
}

/**
 * ✅ UI用：時刻プルダウン生成（5分刻み）
 * - 自由入力を防ぎ、選択でのみ登録できるようにするため
 */
function buildTimeOptions(int $stepMinutes = 5): array
{
    if ($stepMinutes <= 0) $stepMinutes = 5;
    if (60 % $stepMinutes !== 0) $stepMinutes = 5;

    $opts = [];
    for ($h = 0; $h <= 23; $h++) {
        for ($m = 0; $m < 60; $m += $stepMinutes) {
            $opts[] = sprintf('%02d:%02d', $h, $m);
        }
    }
    return $opts;
}
function renderTimeSelect(string $name, string $current, array $timeOptions, string $class = 'ctrl'): string
{
    // ✅ current は "HH:MM" 以外は空扱い（壊さない）
    $cur = isHm($current) ? $current : '';
    $defaultTime = '12:00';
    if ($cur === '' && in_array($defaultTime, $timeOptions, true)) {
        $cur = $defaultTime;
    }

    $html = '<select class="' . h($class) . '" name="' . h($name) . '">';
    $html .= '<option value="">未設定</option>';
    if ($cur !== '' && !in_array($cur, $timeOptions, true)) {
        $html .= '<option value="' . h($cur) . '" selected>' . h($cur) . '</option>';
    }
    foreach ($timeOptions as $t) {
        $sel = ($t === $cur) ? ' selected' : '';
        $html .= '<option value="' . h($t) . '"' . $sel . '>' . h($t) . '</option>';
    }
    $html .= '</select>';
    return $html;
}
function normalizeBackUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^/admin/time_punch_daily\\.php(\\?.*)?$#', $url)) {
        return $url;
    }
    return '';
}

function cutoffToSeconds(string $cutoff): int
{
    $cutoff = trim($cutoff);
    if ($cutoff === '') return 0;
    if (!preg_match('/^\\d{1,2}:\\d{2}(:\\d{2})?$/', $cutoff)) return 0;
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
    if ($dayStart === false) {
        return date('Y-m-d', $ts);
    }
    $cutoffTs = (int)$dayStart + $cutoffSeconds;
    if ($cutoffSeconds <= 0) return date('Y-m-d', $ts);
    if ($ts < $cutoffTs) {
        return date('Y-m-d', strtotime('-1 day', (int)$dayStart));
    }
    return date('Y-m-d', $ts);
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

// 入力
$storeId    = (int)($_GET['store_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);
$day        = (string)($_GET['day'] ?? '');
$backUrl = normalizeBackUrl((string)($_GET['back_url'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));

// ★一覧から渡される「確定ID」（あれば優先）
$forcedClockInId  = (int)($_GET['clock_in_id'] ?? 0);
$forcedClockOutId = (int)($_GET['clock_out_id'] ?? 0);

if ($storeId <= 0 || $employeeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    throw new RuntimeException('不正なパラメータ');
}

// tenant内のstore/employee検証
$st = $pdo->prepare("SELECT id, business_day_cutoff_time FROM stores WHERE tenant_id=:tid AND id=:sid LIMIT 1");
$st->execute([':tid' => $tenantId, ':sid' => $storeId]);
$storeRow = $st->fetch();
if (!$storeRow) throw new RuntimeException('storeが不正');
$storeCutoffStr = (string)($storeRow['business_day_cutoff_time'] ?? '00:00:00');
$cutoffSeconds = cutoffToSeconds($storeCutoffStr);

$em = $pdo->prepare("SELECT display_name FROM employees WHERE tenant_id=:tid AND store_id=:sid AND id=:eid LIMIT 1");
$em->execute([':tid' => $tenantId, ':sid' => $storeId, ':eid' => $employeeId]);
$empName = (string)($em->fetchColumn() ?: "ID:{$employeeId}");

// 重要：深夜退勤を拾うため day の前日〜翌々日まで見る
$scanStart = date('Y-m-d', strtotime($day . ' -1 day')) . ' 00:00:00';
$scanEnd   = date('Y-m-d', strtotime($day . ' +2 day')) . ' 00:00:00';

// 全打刻（編集画面の従来ロジック用）
$rows = $pdo->prepare("
    SELECT id, punch_type, punched_at
    FROM time_punches
    WHERE tenant_id=:tid AND store_id=:sid AND employee_id=:eid
      AND punch_type IN ('clock_in','clock_out')
      AND punched_at >= :st AND punched_at < :ed
    ORDER BY punched_at ASC, id ASC
");
$rows->execute([':tid' => $tenantId, ':sid' => $storeId, ':eid' => $employeeId, ':st' => $scanStart, ':ed' => $scanEnd]);
$punches = $rows->fetchAll();

function fetchPunchById(PDO $pdo, int $id, int $tid, int $sid, int $eid): ?array
{
    if ($id <= 0) return null;
    $q = $pdo->prepare("
        SELECT id, punch_type, punched_at
        FROM time_punches
        WHERE id=:id AND tenant_id=:tid AND store_id=:sid AND employee_id=:eid
        LIMIT 1
    ");
    $q->execute([':id' => $id, ':tid' => $tid, ':sid' => $sid, ':eid' => $eid]);
    $r = $q->fetch();
    return $r ?: null;
}

// 対象dayの勤務を「出勤→その次の退勤」で決める（翌日退勤も拾う）
$dayStartTs = strtotime($day . ' 00:00:00');
$dayEndTs   = strtotime(date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00');
$next2EndTs = strtotime(date('Y-m-d', strtotime($day . ' +2 day')) . ' 00:00:00');

// まず “強制ID” があればそれを採用（= 一覧と編集対象を一致させる）
$clockIn = null;
$clockOut = null;

if ($forcedClockInId > 0) {
    $p = fetchPunchById($pdo, $forcedClockInId, $tenantId, $storeId, $employeeId);
    if ($p && $p['punch_type'] === 'clock_in') $clockIn = $p;
}
if ($forcedClockOutId > 0) {
    $p = fetchPunchById($pdo, $forcedClockOutId, $tenantId, $storeId, $employeeId);
    if ($p && $p['punch_type'] === 'clock_out') $clockOut = $p;
}

// 無ければ従来ロジックで拾う（保険）
if (!$clockIn) {
    foreach ($punches as $p) {
        if ($p['punch_type'] !== 'clock_in') continue;
        $ts = strtotime((string)$p['punched_at']);
        if ($ts >= $dayStartTs && $ts < $dayEndTs) {
            $clockIn = $p;
            break;
        }
    }
}
if (!$clockOut) {
    if ($clockIn) {
        $inTs = strtotime((string)$clockIn['punched_at']);
        foreach ($punches as $p) {
            if ($p['punch_type'] !== 'clock_out') continue;
            $ts = strtotime((string)$p['punched_at']);
            if ($ts > $inTs && $ts < $next2EndTs) {
                $clockOut = $p;
                break;
            }
        }
    } else {
        foreach ($punches as $p) {
            if ($p['punch_type'] !== 'clock_out') continue;
            $ts = strtotime((string)$p['punched_at']);
            if ($ts >= $dayStartTs && $ts < $next2EndTs) $clockOut = $p;
        }
    }
}

// ✅ 追加：営業日（cutoff）基準で拾う（一覧と編集の不一致を防ぐ）
if (!$clockIn) {
    foreach ($punches as $p) {
        if ($p['punch_type'] !== 'clock_in') continue;
        $ts = strtotime((string)$p['punched_at']);
        if ($ts === false) continue;
        if (businessDateFromTs((int)$ts, $cutoffSeconds) === $day) {
            $clockIn = $p;
            break;
        }
    }
}
if (!$clockOut) {
    if ($clockIn) {
        $inTs = strtotime((string)$clockIn['punched_at']);
        foreach ($punches as $p) {
            if ($p['punch_type'] !== 'clock_out') continue;
            $ts = strtotime((string)$p['punched_at']);
            if ($ts === false) continue;
            if ($ts > $inTs) {
                $clockOut = $p;
                break;
            }
        }
    } else {
        foreach ($punches as $p) {
            if ($p['punch_type'] !== 'clock_out') continue;
            $ts = strtotime((string)$p['punched_at']);
            if ($ts === false) continue;
            if (businessDateFromTs((int)$ts, $cutoffSeconds) === $day) {
                $clockOut = $p;
                break;
            }
        }
    }
}

$clockOutNextDayDefault = false;
$shiftStartAt = null;
$shiftEndAt = null;

if ($clockIn) $shiftStartAt = (string)$clockIn['punched_at'];
if ($clockOut) $shiftEndAt = (string)$clockOut['punched_at'];

if ($clockOut) {
    $outDay = date('Y-m-d', strtotime((string)$clockOut['punched_at']));
    $clockOutNextDayDefault = ($outDay !== $day);
}

// ✅ 休憩の取得（この勤務範囲に収まっているものだけ）
$breakRows = [];
if ($shiftStartAt && $shiftEndAt) {
    $qb = $pdo->prepare("
        SELECT id, break_start_at, break_end_at
        FROM break_punches
        WHERE tenant_id=:t AND store_id=:s AND employee_id=:e
          AND break_start_at >= :bs AND break_end_at <= :be
        ORDER BY break_start_at ASC, id ASC
    ");
    $qb->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
        ':bs' => $shiftStartAt,
        ':be' => $shiftEndAt,
    ]);
    $breakRows = $qb->fetchAll();
}

// 休憩入力（GET優先で復元：保存エラー時に消えない）
$breakStarts = $_GET['break_s'] ?? [];
$breakEnds   = $_GET['break_e'] ?? [];
if (!is_array($breakStarts)) $breakStarts = [];
if (!is_array($breakEnds))   $breakEnds = [];

$breaks = [];
$restored = normalizeBreakInputs($breakStarts, $breakEnds, 20);
if (!empty($restored)) {
    $breaks = $restored;
} else {
    foreach ($breakRows as $br) {
        $breaks[] = [
            'start' => date('H:i', strtotime((string)$br['break_start_at'])),
            'end'   => date('H:i', strtotime((string)$br['break_end_at'])),
        ];
    }
}

// 初期は1行
if (count($breaks) < 1) $breaks[] = ['start' => '', 'end' => ''];

// ✅ UI用：時刻オプション（5分刻み）
$timeStepMinutes = 5;
$timeOptions = buildTimeOptions($timeStepMinutes);

// ✅ 表示用（現在値）
$clockInHm = $clockIn ? date('H:i', strtotime((string)$clockIn['punched_at'])) : '';
$clockOutHm = $clockOut ? date('H:i', strtotime((string)$clockOut['punched_at'])) : '';

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>勤怠編集</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
    :root {
        --font: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans JP", sans-serif;
        --bg: #fff;
        --card: #fff;
        --line: #e6e6e6;
        --muted: #666;
        --text: #111;
        --btn: #111;
        --btnText: #fff;
        --btnSub: #f3f3f3;
        --radius: 16px;
        --fs: 14px;
    }

    html,
    body {
        margin: 0;
        padding: 0;
        background: var(--bg);
        color: var(--text);
        font-family: var(--font);
        font-size: var(--fs);
        line-height: 1.6;
    }

    body,
    input,
    select,
    button {
        font-variant-numeric: tabular-nums lining-nums;
        font-feature-settings: "tnum"1, "lnum"1;
    }

    .wrap {
        max-width: 980px;
        margin: 0 auto;
        padding: 18px 18px 40px;
        box-sizing: border-box;
    }

    .card {
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 22px;
        background: var(--card);
    }

    .title {
        font-size: 28px;
        font-weight: 900;
        margin: 0 0 6px;
    }

    .subline {
        color: var(--muted);
        font-weight: 600;
        margin: 0 0 18px;
    }

    .field label {
        display: block;
        font-weight: 900;
        margin: 0 0 8px;
        font-size: 12px;
    }

    /* ✅ input ではなく select でも同じ見た目にする */
    .ctrl {
        width: 100%;
        height: 44px;
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 0 14px;
        box-sizing: border-box;
        font-size: 16px;
        font-weight: 800;
        background: #fff;
        outline: none;
    }

    .gridHalf {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
        margin-top: 10px;
    }

    @media (max-width: 720px) {
        .gridHalf {
            grid-template-columns: 1fr;
        }

        .title {
            font-size: 24px;
        }
    }

    .checkRow {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 10px;
        color: var(--muted);
        font-weight: 700;
        user-select: none;
        font-size: 12px;
    }

    .checkRow input {
        width: 16px;
        height: 16px;
    }

    .btnRow {
        display: flex;
        gap: 14px;
        margin-top: 18px;
        flex-wrap: wrap;
    }

    .btn {
        height: 44px;
        padding: 0 26px;
        border-radius: 16px;
        border: 1px solid var(--line);
        font-size: 15px;
        font-weight: 900;
        cursor: pointer;
        background: var(--btnSub);
        color: var(--text);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
    }

    .btn.primary {
        background: var(--btn);
        color: var(--btnText);
        border-color: var(--btn);
    }

    .muted {
        color: var(--muted);
        font-weight: 600;
        font-size: 12px;
    }

    .sectionTitle {
        margin-top: 18px;
        font-weight: 900;
        font-size: 15px;
    }

    .tinyNote {
        margin-top: 6px;
        color: var(--muted);
        font-weight: 600;
        font-size: 12px;
    }

    .breakTable {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .breakTable th,
    .breakTable td {
        border-bottom: 1px solid var(--line);
        padding: 10px 8px;
        text-align: left;
    }

    .breakTable th {
        color: var(--muted);
        font-size: 12px;
        font-weight: 900;
    }

    /* ✅ breakも select に統一 */
    .breakTime {
        width: 100%;
        height: 36px;
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 0 12px;
        font-size: 14px;
        font-weight: 800;
        box-sizing: border-box;
        background: #fff;
    }

    .breakActions {
        display: flex;
        gap: 10px;
        margin-top: 10px;
        flex-wrap: wrap;
    }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card">
            <h1 class="title">勤怠編集（<?= h($day) ?>）</h1>
            <div class="subline">
                従業員: <b><?= h($empName) ?></b> / store_id: <?= (int)$storeId ?>
            </div>
            <?php if ($err !== ''): ?>
            <div class="notice" style="margin-top:12px;color:#b91c1c;font-weight:800;">
                <?= h($err) ?>
            </div>
            <?php endif; ?>

            <form method="post" action="time_punch_edit_save.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
                <div class="field" style="margin-bottom:12px;">
                    <label>日付</label>
                    <input class="ctrl" type="date" name="day" value="<?= h($day) ?>">
                </div>
                <?php if ($backUrl !== ''): ?>
                <input type="hidden" name="back_url" value="<?= h($backUrl) ?>">
                <?php endif; ?>

                <div class="gridHalf">
                    <div class="field">
                        <label>出勤時刻</label>
                        <!-- ✅ 自由入力を防ぐ：プルダウン選択 -->
                        <?= renderTimeSelect('clock_in', (string)$clockInHm, $timeOptions, 'ctrl') ?>
                        <input type="hidden" name="clock_in_id" value="<?= $clockIn ? (int)$clockIn['id'] : 0 ?>">
                        <input type="hidden" name="orig_clock_in" value="<?= h($clockInHm) ?>">
                    </div>

                    <div class="field">
                        <label>退勤時刻</label>
                        <!-- ✅ 自由入力を防ぐ：プルダウン選択 -->
                        <?= renderTimeSelect('clock_out', (string)$clockOutHm, $timeOptions, 'ctrl') ?>
                        <input type="hidden" name="clock_out_id" value="<?= $clockOut ? (int)$clockOut['id'] : 0 ?>">
                        <input type="hidden" name="orig_clock_out" value="<?= h($clockOutHm) ?>">

                        <div class="checkRow">
                            <input type="checkbox" id="clock_out_next_day" name="clock_out_next_day" value="1"
                                <?= $clockOutNextDayDefault ? 'checked' : '' ?>>
                            <label for="clock_out_next_day" style="margin:0;font-weight:800;">退勤は翌日（深夜退勤）</label>
                        </div>
                    </div>
                </div>

                <div class="sectionTitle">休憩（複数）</div>
                <div class="tinyNote">
                    <?php if ($shiftStartAt && $shiftEndAt): ?>
                    この勤務（<?= h(date('H:i', strtotime($shiftStartAt))) ?> →
                    <?= h(date('H:i', strtotime($shiftEndAt))) ?>）の休憩を編集します。
                    <?php else: ?>
                    ※出勤/退勤が揃っていないため、休憩の自動読込はできません（保存時に範囲チェックします）。
                    <?php endif; ?>
                </div>

                <table class="breakTable" id="breakTable">
                    <thead>
                        <tr>
                            <th style="width:42%;">休憩開始</th>
                            <th style="width:42%;">休憩終了</th>
                            <th style="width:16%;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($breaks as $b): ?>
                        <?php
                            $bs = isHm((string)$b['start']) ? (string)$b['start'] : '';
                            $be = isHm((string)$b['end']) ? (string)$b['end'] : '';
                            ?>
                        <tr>
                            <td>
                                <!-- ✅ 自由入力を防ぐ：プルダウン選択 -->
                                <select class="breakTime" name="break_s[]">
                                    <option value="" <?= ($bs === '' ? 'selected' : '') ?>>未設定</option>
                                    <?php foreach ($timeOptions as $t): ?>
                                    <option value="<?= h($t) ?>" <?= ($t === $bs ? 'selected' : '') ?>><?= h($t) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <!-- ✅ 自由入力を防ぐ：プルダウン選択 -->
                                <select class="breakTime" name="break_e[]">
                                    <option value="" <?= ($be === '' ? 'selected' : '') ?>>未設定</option>
                                    <?php foreach ($timeOptions as $t): ?>
                                    <option value="<?= h($t) ?>" <?= ($t === $be ? 'selected' : '') ?>><?= h($t) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <button type="button" class="btn" style="height:44px;padding:0 14px;"
                                    onclick="removeBreakRow(this)">削除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="breakActions">
                    <button type="button" class="btn" onclick="addBreakRow()">休憩を追加</button>
                </div>

                <div class="btnRow">
                    <button class="btn primary" type="submit">保存</button>
                    <?php
                    $to = $day;
                    if ($clockOutNextDayDefault) $to = date('Y-m-d', strtotime($day . ' +1 day'));
                    $backUrl = $backUrl !== '' ? $backUrl : ('time_punch_daily.php?' . http_build_query([
                        'store_id' => $storeId,
                        'employee_id' => $employeeId,
                        'from' => $day,
                        'to' => $to,
                    ]));
                    ?>
                    <a class="btn" href="<?= h($backUrl) ?>">戻る</a>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        const form = document.querySelector('form');
        const inSel = document.querySelector('select[name="clock_in"]');
        const outSel = document.querySelector('select[name="clock_out"]');
        const nextDay = document.querySelector('#clock_out_next_day');
        const origIn = document.querySelector('input[name="orig_clock_in"]');
        const origOut = document.querySelector('input[name="orig_clock_out"]');
        if (!form || !inSel || !outSel || !nextDay) return;

        const isStep5 = (hm) => {
            if (!hm || !/^\d{2}:\d{2}$/.test(hm)) return false;
            const min = parseInt(hm.slice(3), 10);
            return (min % 5) === 0;
        };

        form.addEventListener('submit', (e) => {
            const inVal = (inSel.value || '').trim();
            const outVal = (outSel.value || '').trim();
            const origInVal = (origIn && origIn.value) ? origIn.value.trim() : '';
            const origOutVal = (origOut && origOut.value) ? origOut.value.trim() : '';

            if (inVal && !isStep5(inVal) && inVal !== origInVal) {
                e.preventDefault();
                alert('出勤時刻は5分刻みで選択してください。');
                return;
            }
            if (outVal && !isStep5(outVal) && outVal !== origOutVal) {
                e.preventDefault();
                alert('退勤時刻は5分刻みで選択してください。');
                return;
            }
            if (inVal && outVal) {
                const [inH, inM] = inVal.split(':').map(v => parseInt(v, 10));
                const [outH, outM] = outVal.split(':').map(v => parseInt(v, 10));
                const inMin = (inH * 60) + inM;
                const outMin = (outH * 60) + outM;
                if (outMin < inMin && !nextDay.checked) {
                    e.preventDefault();
                    alert('退勤は翌日（深夜退勤）にチェックを入れてください。');
                }
            }
        });
    })();

    // ✅ PHP側と同じ「5分刻みの選択肢」をJSでも作る（行追加時に必要）
    const TIME_STEP_MINUTES = <?= (int)$timeStepMinutes ?>;
    function buildTimeOptions(stepMinutes) {
        const opts = [];
        const step = (stepMinutes && (60 % stepMinutes === 0)) ? stepMinutes : TIME_STEP_MINUTES;
        for (let h = 0; h <= 23; h++) {
            for (let m = 0; m < 60; m += step) {
                const hh = String(h).padStart(2, '0');
                const mm = String(m).padStart(2, '0');
                opts.push(`${hh}:${mm}`);
            }
        }
        return opts;
    }

    function buildSelectHtml(name) {
        const times = buildTimeOptions(TIME_STEP_MINUTES);
        let html = `<select class="breakTime" name="${name}">`;
        html += `<option value="" selected>未設定</option>`;
        for (const t of times) {
            html += `<option value="${t}">${t}</option>`;
        }
        html += `</select>`;
        return html;
    }

    function addBreakRow() {
        const tbody = document.querySelector('#breakTable tbody');
        const tr = document.createElement('tr');

        // ✅ input type=time を廃止し、select を追加する
        tr.innerHTML = `
            <td>${buildSelectHtml('break_s[]')}</td>
            <td>${buildSelectHtml('break_e[]')}</td>
            <td>
                <button type="button" class="btn" style="height:44px;padding:0 14px;" onclick="removeBreakRow(this)">削除</button>
            </td>
        `;
        tbody.appendChild(tr);
    }

    function removeBreakRow(btn) {
        const tr = btn.closest('tr');
        if (!tr) return;
        const tbody = tr.parentElement;
        tr.remove();

        // ✅ 0行になったら1行だけ補充（増殖バグ防止）
        if (tbody.querySelectorAll('tr').length === 0) {
            addBreakRow();
        }
    }
    </script>

</body>

</html>
