<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/time_punch_new.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * 変更点:
 * - 休憩（複数回）に対応
 * - 休憩の開始/終了を break_punches に保存
 * - time_punches は従来通り clock_in / clock_out の2件を保存
 * - FK fk_punch_dev 対策のため time_punches.device_id を必ずセット
 * - break_punches.device_id も必ずセット（FK対策）
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

date_default_timezone_set('Asia/Tokyo');

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
    throw new RuntimeException("db.php not found. tried:\n" . implode("\n", $paths));
}
require_once $dbFile;
require_once __DIR__ . '/../lib/punch_source.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
punch_source_ensure_column($pdo);

// ===== helpers =====
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function isYmd(string $s): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}
function isHm(string $s): bool
{
    return (bool)preg_match('/^\d{2}:\d{2}$/', $s);
}
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}
function buildRedirectUrl(string $base, array $params): string
{
    $q = http_build_query($params);
    return $base . (strpos($base, '?') !== false ? '&' : '?') . $q;
}

/**
 * ✅ devices 外部キー対策
 * - tenant/store に紐づく device を1件取得（なければエラー）
 */
function resolveDeviceId(PDO $pdo, int $tenantId, int $storeId): int
{
    $sqlCandidates = [
        "SELECT id FROM devices WHERE tenant_id=:t AND store_id=:s ORDER BY id ASC LIMIT 1",
        "SELECT id FROM devices WHERE store_id=:s ORDER BY id ASC LIMIT 1",
        "SELECT id FROM devices WHERE tenant_id=:t ORDER BY id ASC LIMIT 1",
        "SELECT id FROM devices ORDER BY id ASC LIMIT 1",
    ];

    foreach ($sqlCandidates as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $params = [];
            if (strpos($sql, ':t') !== false) $params[':t'] = $tenantId;
            if (strpos($sql, ':s') !== false) $params[':s'] = $storeId;
            $st->execute($params);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        } catch (Throwable $e) {
            continue;
        }
    }
    throw new RuntimeException('devices が見つかりません（device_id必須のため登録できません）');
}

/**
 * 休憩入力（配列）を正規化
 * - 空行は除外
 * - 最大件数制限
 */
function normalizeBreakInputs(array $starts, array $ends, int $max = 10): array
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
 * 休憩の妥当性チェック
 * - start/end は "HH:MM"
 * - 出勤〜退勤の範囲内
 * - 未来チェックは「日付が未来」のみNG（今日の未来時刻はOK）
 * - 重複NG
 */
function validateBreaks(array $breaks, string $day, bool $overnight, int $inTs, int $outTs): array
{
    $errors = [];
    $ranges = [];

    foreach ($breaks as $idx => $b) {
        $sHm = (string)$b['start'];
        $eHm = (string)$b['end'];

        if (!isHm($sHm) || !isHm($eHm)) {
            $errors[] = '休憩' . ($idx + 1) . '：時刻が不正です（HH:MM）';
            continue;
        }

        $sAt = $day . ' ' . $sHm . ':00';
        // 休憩終了も同日扱いだが、深夜退勤の場合は「退勤日」を超える可能性がある
        // ここでは「退勤日の範囲内」を許可するため、終了日も同日/翌日両方を試す
        $endDay0 = $day;
        $endDay1 = date('Y-m-d', strtotime($day . ' +1 day'));
        $eAt0 = $endDay0 . ' ' . $eHm . ':00';
        $eAt1 = $endDay1 . ' ' . $eHm . ':00';

        $sTs = strtotime($sAt);
        $eTs0 = strtotime($eAt0);
        $eTs1 = strtotime($eAt1);

        if ($sTs === false || $eTs0 === false || $eTs1 === false) {
            $errors[] = '休憩' . ($idx + 1) . '：日時の解釈に失敗しました';
            continue;
        }

        // 終了は「開始より後」で、かつ退勤までに収まる方を採用
        $eTs = 0;
        if ($eTs0 > $sTs && $eTs0 <= $outTs) $eTs = $eTs0;
        if ($eTs === 0 && $overnight && $eTs1 > $sTs && $eTs1 <= $outTs) $eTs = $eTs1;

        if ($eTs === 0) {
            $errors[] = '休憩' . ($idx + 1) . '：休憩終了は休憩開始より後、かつ退勤までの範囲にしてください';
            continue;
        }

        // 出勤〜退勤の範囲
        if ($sTs < $inTs || $eTs > $outTs) {
            $errors[] = '休憩' . ($idx + 1) . '：休憩は出勤〜退勤の範囲内にしてください';
            continue;
        }

        $ranges[] = ['startTs' => $sTs, 'endTs' => $eTs, 'startAt' => date('Y-m-d H:i:s', $sTs), 'endAt' => date('Y-m-d H:i:s', $eTs)];
    }

    // 重複チェック（ソートして隣接比較）
    usort($ranges, fn($a, $b) => $a['startTs'] <=> $b['startTs']);
    for ($i = 1; $i < count($ranges); $i++) {
        $prev = $ranges[$i - 1];
        $cur  = $ranges[$i];
        if ($cur['startTs'] < $prev['endTs']) {
            $errors[] = '休憩が重複しています（休憩' . $i . ' と 休憩' . ($i + 1) . '）';
            break;
        }
    }

    return [$errors, $ranges];
}

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

// ===== inputs (GET defaults) =====
$storeId = (int)($_GET['store_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);

$backUrl = (string)($_GET['back_url'] ?? '');
if ($backUrl === '' || $backUrl[0] !== '/' || strpos($backUrl, '/admin/') !== 0) {
    $backUrl = '/admin/time_punch_daily.php';
}

$today = date('Y-m-d');
$day = (string)($_GET['day'] ?? $today);
if (!isYmd($day)) $day = $today;

$inTime  = (string)($_GET['in']  ?? '18:00');
$outTime = (string)($_GET['out'] ?? '23:00');
if (!isHm($inTime))  $inTime  = '18:00';
if (!isHm($outTime)) $outTime = '23:00';

$overnight = ((string)($_GET['overnight'] ?? '') === '1');

$flash = (string)($_GET['msg'] ?? '');
$err   = (string)($_GET['err'] ?? '');

// ✅ 休憩（GETから復元）
$breakStarts = $_GET['break_s'] ?? [];
$breakEnds   = $_GET['break_e'] ?? [];
if (!is_array($breakStarts)) $breakStarts = [];
if (!is_array($breakEnds))   $breakEnds = [];
$breaks = normalizeBreakInputs($breakStarts, $breakEnds, 10);

// 画面初期表示は2行用意（空でもOK）
while (count($breaks) < 1) $breaks[] = ['start' => '', 'end' => ''];

// ===== stores =====
$storesStmt = $pdo->prepare("
    SELECT id, name
    FROM stores
    WHERE tenant_id = :tenant_id
    ORDER BY id ASC
");
$storesStmt->execute([':tenant_id' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (empty($stores)) {
    http_response_code(400);
    echo "stores がありません。tenant_id={$tenantId}";
    exit;
}
$validStoreIds = array_map(fn($s) => (int)$s['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $validStoreIds, true)) {
    $storeId = (int)$stores[0]['id'];
}

// ===== employees (selected store) =====
$empStmt = $pdo->prepare("
  SELECT id, display_name, sort_order
  FROM employees
  WHERE tenant_id = :tenant_id AND store_id = :store_id
  ORDER BY sort_order ASC, id ASC
");
$empStmt->execute([':tenant_id' => $tenantId, ':store_id' => $storeId]);
$employees = $empStmt->fetchAll();

$validEmpIds = array_map(fn($e) => (int)$e['id'], $employees);
if ($employeeId > 0 && !in_array($employeeId, $validEmpIds, true)) $employeeId = 0;
if ($employeeId === 0 && !empty($employees)) $employeeId = (int)$employees[0]['id'];

// ===== POST save =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $postCsrf)) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', ['err' => 'CSRFが不正です']));
    }

    $storeId = (int)($_POST['store_id'] ?? 0);
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $day = (string)($_POST['day'] ?? '');
    $inTime  = (string)($_POST['in_time'] ?? '');
    $outTime = (string)($_POST['out_time'] ?? '');
    $overnight = ((string)($_POST['overnight'] ?? '') === '1');
    $backUrl = (string)($_POST['back_url'] ?? '/admin/time_punch_daily.php');
    if ($backUrl === '' || $backUrl[0] !== '/' || strpos($backUrl, '/admin/') !== 0) $backUrl = '/admin/time_punch_daily.php';

    $breakStarts = $_POST['break_s'] ?? [];
    $breakEnds   = $_POST['break_e'] ?? [];
    if (!is_array($breakStarts)) $breakStarts = [];
    if (!is_array($breakEnds))   $breakEnds = [];
    $breakPairs = normalizeBreakInputs($breakStarts, $breakEnds, 10);

    // validate store
    if ($storeId <= 0) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', ['err' => '店舗を選択してください']));
    }
    $stCheck = $pdo->prepare("SELECT id FROM stores WHERE tenant_id=:t AND id=:id");
    $stCheck->execute([':t' => $tenantId, ':id' => $storeId]);
    if (!$stCheck->fetchColumn()) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', ['err' => '店舗が不正です']));
    }

    // validate employee belongs to store
    if ($employeeId <= 0) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', ['err' => '従業員を選択してください', 'store_id' => $storeId]));
    }
    $empCheck = $pdo->prepare("SELECT id FROM employees WHERE tenant_id=:t AND store_id=:s AND id=:id");
    $empCheck->execute([':t' => $tenantId, ':s' => $storeId, ':id' => $employeeId]);
    if (!$empCheck->fetch()) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', ['err' => '従業員が不正です', 'store_id' => $storeId]));
    }

    // validate day
    if (!isYmd($day)) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', ['err' => '日付が不正です', 'store_id' => $storeId, 'employee_id' => $employeeId]));
    }
    $today = date('Y-m-d');
    if ($day > $today) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', ['err' => '未来日は登録できません', 'store_id' => $storeId, 'employee_id' => $employeeId]));
    }

    // validate times
    if (!isHm($inTime) || !isHm($outTime)) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', [
            'err' => '時刻が不正です（HH:MM）',
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'day' => $day
        ]));
    }

    // build timestamps
    $inAt = $day . ' ' . $inTime . ':00';
    $outDay = $overnight ? date('Y-m-d', strtotime($day . ' +1 day')) : $day;
    $outAt = $outDay . ' ' . $outTime . ':00';

    $inTs = strtotime($inAt);
    $outTs = strtotime($outAt);
    if ($inTs === false || $outTs === false) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', [
            'err' => '日時の解釈に失敗しました',
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'day' => $day
        ]));
    }

    if ($outTs <= $inTs) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', [
            'err' => '退勤は出勤より後にしてください（深夜退勤はチェック）',
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'day' => $day
        ]));
    }

    // ✅ 未来日チェック（今日の未来時刻はOK）
    $todayYmd = date('Y-m-d');
    if ($day > $todayYmd) {
        redirect(buildRedirectUrl('/admin/time_punch_new.php', [
            'err' => '未来日には登録できません',
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'day' => $day
        ]));
    }

    // ✅ 休憩バリデーション（複数）
    [$breakErrors, $breakRanges] = validateBreaks($breakPairs, $day, $overnight, (int)$inTs, (int)$outTs);
    if (!empty($breakErrors)) {
        // 入力をGETに戻して復元
        $params = [
            'err' => implode(' / ', $breakErrors),
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'day' => $day,
            'in' => $inTime,
            'out' => $outTime,
            'overnight' => $overnight ? '1' : '0',
            'back_url' => $backUrl,
            'break_s' => array_map(fn($x) => (string)$x['start'], $breakPairs),
            'break_e' => array_map(fn($x) => (string)$x['end'], $breakPairs),
        ];
        redirect(buildRedirectUrl('/admin/time_punch_new.php', $params));
    }

    // ✅ device_id を解決（FK対策）
    $deviceId = resolveDeviceId($pdo, $tenantId, $storeId);

    $pdo->beginTransaction();
    try {
        // clock_in / clock_out 重複チェック
        $existsStmt = $pdo->prepare("
            SELECT COUNT(*) FROM time_punches
            WHERE tenant_id=:t AND store_id=:s AND employee_id=:e AND punch_type=:pt AND punched_at=:pa
        ");

        $tpCols = [];
        try {
            $tpCols = array_map(
                static fn(array $r): string => (string)($r['Field'] ?? ''),
                $pdo->query("SHOW COLUMNS FROM `time_punches`")->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable $e) {
            $tpCols = [];
        }
        $hasPunchSource = in_array('punch_source', $tpCols, true);

        $insertSql = "INSERT INTO time_punches (tenant_id, store_id, employee_id, device_id, punch_type, punched_at";
        $insertVals = " VALUES (:t, :s, :e, :d, :pt, :pa";
        if ($hasPunchSource) {
            $insertSql .= ", punch_source";
            $insertVals .= ", :src";
        }
        $insertPunchStmt = $pdo->prepare($insertSql . ", created_at, updated_at)" . $insertVals . ", NOW(), NOW())");

        // clock_in
        $existsStmt->execute([
            ':t' => $tenantId,
            ':s' => $storeId,
            ':e' => $employeeId,
            ':pt' => 'clock_in',
            ':pa' => $inAt,
        ]);
        if ((int)$existsStmt->fetchColumn() > 0) {
            throw new RuntimeException('同じ出勤時刻の打刻が既に存在します');
        }
        $insertPunchStmt->execute([
            ':t' => $tenantId,
            ':s' => $storeId,
            ':e' => $employeeId,
            ':d' => $deviceId,
            ':pt' => 'clock_in',
            ':pa' => $inAt,
            ...( $hasPunchSource ? [':src' => 'admin'] : [] ),
        ]);

        // clock_out
        $existsStmt->execute([
            ':t' => $tenantId,
            ':s' => $storeId,
            ':e' => $employeeId,
            ':pt' => 'clock_out',
            ':pa' => $outAt,
        ]);
        if ((int)$existsStmt->fetchColumn() > 0) {
            throw new RuntimeException('同じ退勤時刻の打刻が既に存在します');
        }
        $insertPunchStmt->execute([
            ':t' => $tenantId,
            ':s' => $storeId,
            ':e' => $employeeId,
            ':d' => $deviceId,
            ':pt' => 'clock_out',
            ':pa' => $outAt,
            ...( $hasPunchSource ? [':src' => 'admin'] : [] ),
        ]);

        // ✅ 休憩 INSERT（break_punches）
        if (!empty($breakRanges)) {
            $insBreak = $pdo->prepare("
                INSERT INTO break_punches
                    (tenant_id, store_id, employee_id, device_id, break_start_at, break_end_at, created_at, updated_at)
                VALUES
                    (:t,:s,:e,:d,:bs,:be,NOW(),NOW())
            ");

            foreach ($breakRanges as $r) {
                $insBreak->execute([
                    ':t'  => $tenantId,
                    ':s'  => $storeId,
                    ':e'  => $employeeId,
                    ':d'  => $deviceId,
                    ':bs' => $r['startAt'],
                    ':be' => $r['endAt'],
                ]);
            }
        }

        $pdo->commit();

        $join = (strpos($backUrl, '?') !== false) ? '&' : '?';
        redirect($backUrl . $join . 'msg=' . rawurlencode('勤怠（休憩含む）を登録しました'));
    } catch (Throwable $ex) {
        $pdo->rollBack();

        $params = [
            'err' => $ex->getMessage(),
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'day' => $day,
            'in' => $inTime,
            'out' => $outTime,
            'overnight' => $overnight ? '1' : '0',
            'back_url' => $backUrl,
            'break_s' => array_map(fn($x) => (string)$x['start'], $breakPairs),
            'break_e' => array_map(fn($x) => (string)$x['end'], $breakPairs),
        ];
        redirect(buildRedirectUrl('/admin/time_punch_new.php', $params));
    }
}

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>勤怠登録</title>
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
            --radius: 0px;
            --fs: 16px;
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
            font-feature-settings: "tnum" 1, "lnum" 1;
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
            font-size: 34px;
            font-weight: 900;
            margin: 0 0 6px;
        }

        .subline {
            color: var(--muted);
            font-weight: 600;
            margin: 0 0 18px;
        }

        .flash {
            margin: 0 0 14px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 0px;
            background: #f8f8f8;
            font-weight: 700;
        }

        .flash.err {
            background: #fff5f5;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .gridTop {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 18px;
            align-items: end;
        }

        @media (max-width: 720px) {
            .gridTop {
                grid-template-columns: 1fr;
            }

            .title {
                font-size: 28px;
            }
        }

        .field label {
            display: block;
            font-weight: 900;
            margin: 0 0 8px;
        }

        .ctrl {
            width: 100%;
            height: 56px;
            border: 1px solid var(--line);
            border-radius: 0px;
            padding: 0 14px;
            box-sizing: border-box;
            font-size: 20px;
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
        }

        .checkRow {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 10px;
            color: var(--muted);
            font-weight: 700;
            user-select: none;
        }

        .checkRow input {
            width: 20px;
            height: 20px;
        }

        .btnRow {
            display: flex;
            gap: 14px;
            margin-top: 18px;
            flex-wrap: wrap;
        }

        .btn {
            height: 56px;
            padding: 0 26px;
            border-radius: 0px;
            border: 1px solid var(--line);
            font-size: 18px;
            font-weight: 900;
            cursor: pointer;
            background: var(--btnSub);
            color: var(--text);
        }

        .btn.primary {
            background: var(--btn);
            color: var(--btnText);
            border-color: var(--btn);
        }

        .tinyNote {
            margin-top: 10px;
            color: var(--muted);
            font-weight: 600;
            font-size: 14px;
        }

        .sectionTitle {
            margin-top: 18px;
            font-weight: 900;
            font-size: 18px;
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

        .breakTime {
            width: 100%;
            height: 44px;
            border: 1px solid var(--line);
            padding: 0 12px;
            font-size: 18px;
            font-weight: 800;
            box-sizing: border-box;
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
            <h1 class="title">勤怠登録</h1>
            <p class="subline">店舗ID: <?= (int)$storeId ?> / テナントID: <?= (int)$tenantId ?></p>

            <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
            <?php if ($err !== ''): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>

            <form method="post" action="/admin/time_punch_new.php" id="punchForm">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="back_url" value="<?= h($backUrl) ?>">

                <div class="gridTop">
                    <div class="field">
                        <label>店舗</label>
                        <select class="ctrl" name="store_id" onchange="onStoreChange(this.value)">
                            <?php foreach ($stores as $st): ?>
                                <option value="<?= (int)$st['id'] ?>"
                                    <?= ((int)$st['id'] === $storeId) ? 'selected' : '' ?>>
                                    <?= h((string)$st['name']) ?> (<?= (int)$st['id'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>従業員</label>
                        <select class="ctrl" name="employee_id">
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= (int)$e['id'] ?>"
                                    <?= ((int)$e['id'] === $employeeId) ? 'selected' : '' ?>>
                                    <?= h((string)$e['display_name']) ?> (<?= (int)$e['id'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>日付</label>
                    <input class="ctrl" type="date" name="day" value="<?= h($day) ?>" max="<?= h(date('Y-m-d')) ?>">
                    <div class="tinyNote">※未来日は選択できません（max=今日）</div>
                </div>

                <div class="gridHalf">
                    <div class="field">
                        <label>出勤時刻</label>
                        <input class="ctrl" type="time" name="in_time" value="<?= h($inTime) ?>">
                    </div>

                    <div class="field">
                        <label>退勤時刻</label>
                        <input class="ctrl" type="time" name="out_time" value="<?= h($outTime) ?>">
                        <div class="checkRow">
                            <input type="checkbox" id="overnight" name="overnight" value="1"
                                <?= $overnight ? 'checked' : '' ?>>
                            <label for="overnight" style="margin:0;font-weight:800;">退勤は翌日（深夜退勤）</label>
                        </div>
                    </div>
                </div>

                <div class="sectionTitle">休憩（複数可）</div>
                <div class="tinyNote">・休憩は「開始→終了」のペアで記録されます（空欄行は無視）／重複はNG</div>

                <table class="breakTable" id="breakTable">
                    <thead>
                        <tr>
                            <th style="width:42%;">休憩開始</th>
                            <th style="width:42%;">休憩終了</th>
                            <th style="width:16%;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($breaks as $i => $b): ?>
                            <tr>
                                <td><input class="breakTime" type="time" name="break_s[]"
                                        value="<?= h((string)$b['start']) ?>"></td>
                                <td><input class="breakTime" type="time" name="break_e[]"
                                        value="<?= h((string)$b['end']) ?>"></td>
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
                    <a class="btn" href="<?= h($backUrl) ?>"
                        style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">戻る</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function onStoreChange(storeId) {
            const url = new URL(location.href);
            url.searchParams.set('store_id', String(storeId));
            url.searchParams.delete('employee_id');
            location.href = url.toString();
        }

        function addBreakRow() {
            const tbody = document.querySelector('#breakTable tbody');
            const tr = document.createElement('tr');
            tr.innerHTML = `
    <td><input class="breakTime" type="time" name="break_s[]" value=""></td>
    <td><input class="breakTime" type="time" name="break_e[]" value=""></td>
    <td><button type="button" class="btn" style="height:44px;padding:0 14px;" onclick="removeBreakRow(this)">削除</button></td>
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
