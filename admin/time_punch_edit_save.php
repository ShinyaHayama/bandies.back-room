<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/time_punch_edit_save.php
 * ✅ 書き込み場所: このファイルを「新規作成」または「丸ごと置き換え」
 *
 * 役割:
 * - time_punch_edit.php の保存先
 * - 出勤/退勤の punched_at を更新（既存IDを使う）
 * - 休憩(break_punches)を「差し替え更新」（DELETE -> INSERT）
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
if (!$dbFile) {
    http_response_code(500);
    echo "db.php not found";
    exit;
}
require_once $dbFile;
require_once __DIR__ . '/../lib/punch_source.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
punch_source_ensure_column($pdo);

function isHm(string $s): bool
{
    return (bool)preg_match('/^\d{2}:\d{2}$/', $s);
}
function isYmd(string $s): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
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

function redirectToEdit(array $params): void
{
    $url = '/admin/time_punch_edit.php?' . http_build_query($params);
    header('Location: ' . $url);
    exit;
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

function tableColumnMeta(PDO $pdo, string $table): array
{
    $out = [];
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}`");
    $st->execute();
    foreach ($st->fetchAll() as $col) {
        $name = (string)($col['Field'] ?? '');
        if ($name !== '') $out[$name] = $col;
    }
    return $out;
}

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
    throw new RuntimeException('devices が見つかりません（device_id必須のため保存できません）');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// CSRF
$csrf = (string)($_SESSION['csrf_token'] ?? '');
$postCsrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals($csrf, $postCsrf)) {
    http_response_code(400);
    echo 'CSRF invalid';
    exit;
}

$storeId = (int)($_POST['store_id'] ?? 0);
$employeeId = (int)($_POST['employee_id'] ?? 0);
$day = (string)($_POST['day'] ?? '');

$clockInId = (int)($_POST['clock_in_id'] ?? 0);
$clockOutId = (int)($_POST['clock_out_id'] ?? 0);

$clockInHm = trim((string)($_POST['clock_in'] ?? ''));
$clockOutHm = trim((string)($_POST['clock_out'] ?? ''));
$nextDay = ((string)($_POST['clock_out_next_day'] ?? '') === '1');
$backUrl = normalizeBackUrl((string)($_POST['back_url'] ?? ''));
$origClockIn = trim((string)($_POST['orig_clock_in'] ?? ''));
$origClockOut = trim((string)($_POST['orig_clock_out'] ?? ''));

$breakStarts = $_POST['break_s'] ?? [];
$breakEnds   = $_POST['break_e'] ?? [];
if (!is_array($breakStarts)) $breakStarts = [];
if (!is_array($breakEnds))   $breakEnds = [];
$breakPairs = normalizeBreakInputs($breakStarts, $breakEnds, 20);

// 戻り用（入力保持）
$backParams = [
    'store_id' => $storeId,
    'employee_id' => $employeeId,
    'day' => $day,
    'clock_in_id' => $clockInId,
    'clock_out_id' => $clockOutId,
    'break_s' => array_map(fn($x) => (string)$x['start'], $breakPairs),
    'break_e' => array_map(fn($x) => (string)$x['end'], $breakPairs),
];
if ($backUrl !== '') {
    $backParams['back_url'] = $backUrl;
}

if ($storeId <= 0 || $employeeId <= 0 || !isYmd($day)) {
    redirectToEdit($backParams + ['err' => 'パラメータが不正です']);
}
if ($clockInId <= 0 && $clockOutId <= 0) {
    redirectToEdit($backParams + ['err' => '編集対象IDが不正です（clock_in_id/clock_out_id）']);
}
if ($clockInHm !== '' && !isHm($clockInHm)) {
    redirectToEdit($backParams + ['err' => '出勤時刻が不正です（HH:MM）']);
}
if ($clockOutHm !== '' && !isHm($clockOutHm)) {
    redirectToEdit($backParams + ['err' => '退勤時刻が不正です（HH:MM）']);
}
if ($clockInHm !== '' && isHm($clockInHm) && (int)substr($clockInHm, 3, 2) % 5 !== 0 && $clockInHm !== $origClockIn) {
    redirectToEdit($backParams + ['err' => '出勤時刻は5分刻みで選択してください。']);
}
if ($clockOutHm !== '' && isHm($clockOutHm) && (int)substr($clockOutHm, 3, 2) % 5 !== 0 && $clockOutHm !== $origClockOut) {
    redirectToEdit($backParams + ['err' => '退勤時刻は5分刻みで選択してください。']);
}
if ($clockInHm === '' || $clockOutHm === '') {
    redirectToEdit($backParams + ['err' => '出勤/退勤は必須です']);
}
if ($clockInHm !== '' && $clockOutHm !== '') {
    [$inH, $inM] = array_map('intval', explode(':', $clockInHm));
    [$outH, $outM] = array_map('intval', explode(':', $clockOutHm));
    $inMin = $inH * 60 + $inM;
    $outMin = $outH * 60 + $outM;
    if ($outMin < $inMin && !$nextDay) {
        redirectToEdit($backParams + ['err' => '退勤は翌日（深夜退勤）にチェックを入れてください。']);
    }
}

// tenant/store/employee チェック
$st = $pdo->prepare("SELECT id FROM stores WHERE tenant_id=:t AND id=:s LIMIT 1");
$st->execute([':t' => $tenantId, ':s' => $storeId]);
if (!$st->fetch()) redirectToEdit($backParams + ['err' => 'storeが不正です']);

$em = $pdo->prepare("SELECT id FROM employees WHERE tenant_id=:t AND store_id=:s AND id=:e LIMIT 1");
$em->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
if (!$em->fetch()) redirectToEdit($backParams + ['err' => 'employeeが不正です']);

// 対象打刻が tenant/store/employee に紐づくか
$clockInRow = null;
if ($clockInId > 0) {
    $pi = $pdo->prepare("SELECT id, punched_at FROM time_punches WHERE id=:id AND tenant_id=:t AND store_id=:s AND employee_id=:e AND punch_type='clock_in' LIMIT 1");
    $pi->execute([':id' => $clockInId, ':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
    $clockInRow = $pi->fetch();
    if (!$clockInRow) redirectToEdit($backParams + ['err' => 'clock_in が見つかりません']);
}

$clockOutRow = null;
if ($clockOutId > 0) {
    $po = $pdo->prepare("SELECT id, punched_at FROM time_punches WHERE id=:id AND tenant_id=:t AND store_id=:s AND employee_id=:e AND punch_type='clock_out' LIMIT 1");
    $po->execute([':id' => $clockOutId, ':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
    $clockOutRow = $po->fetch();
    if (!$clockOutRow) redirectToEdit($backParams + ['err' => 'clock_out が見つかりません']);
}

// 新しい punched_at を作る
$inAt  = $day . ' ' . $clockInHm . ':00';
$outDay = $nextDay ? date('Y-m-d', strtotime($day . ' +1 day')) : $day;
$outAt = $outDay . ' ' . $clockOutHm . ':00';

$inTs = strtotime($inAt);
$outTs = strtotime($outAt);
if ($inTs === false || $outTs === false) redirectToEdit($backParams + ['err' => '日時の解釈に失敗しました']);
if ($outTs <= $inTs) redirectToEdit($backParams + ['err' => '退勤は出勤より後にしてください（深夜退勤はチェック）']);

// ✅ 未来日チェック（今日の未来時刻はOK）
$todayYmd = date('Y-m-d');
if ($day > $todayYmd) {
    redirectToEdit($backParams + ['err' => '未来日には保存できません']);
}

// 休憩のバリデーション（複数）
$breakRanges = [];
$errors = [];

foreach ($breakPairs as $i => $b) {
    $sHm = (string)$b['start'];
    $eHm = (string)$b['end'];

    if (!isHm($sHm) || !isHm($eHm)) {
        $errors[] = '休憩' . ($i + 1) . '：時刻が不正です（HH:MM）';
        continue;
    }

    $sAt = $day . ' ' . $sHm . ':00';
    $eAt0 = $day . ' ' . $eHm . ':00';
    $eAt1 = date('Y-m-d', strtotime($day . ' +1 day')) . ' ' . $eHm . ':00';

    $sTs = strtotime($sAt);
    $eTs0 = strtotime($eAt0);
    $eTs1 = strtotime($eAt1);

    if ($sTs === false || $eTs0 === false || $eTs1 === false) {
        $errors[] = '休憩' . ($i + 1) . '：日時の解釈に失敗しました';
        continue;
    }

    // 終了は開始より後で、かつ退勤までに収まるほうを採用
    $eTs = 0;
    if ($eTs0 > $sTs && $eTs0 <= $outTs) $eTs = $eTs0;
    if ($eTs === 0 && $nextDay && $eTs1 > $sTs && $eTs1 <= $outTs) $eTs = $eTs1;

    if ($eTs === 0) {
        $errors[] = '休憩' . ($i + 1) . '：休憩終了は開始より後、かつ退勤までの範囲にしてください';
        continue;
    }

    if ($sTs < $inTs || $eTs > $outTs) {
        $errors[] = '休憩' . ($i + 1) . '：休憩は出勤〜退勤の範囲内にしてください';
        continue;
    }

    $breakRanges[] = [
        'startTs' => $sTs,
        'endTs' => $eTs,
        'startAt' => date('Y-m-d H:i:s', $sTs),
        'endAt' => date('Y-m-d H:i:s', $eTs),
    ];
}

// 重複チェック
usort($breakRanges, fn($a, $b) => $a['startTs'] <=> $b['startTs']);
for ($i = 1; $i < count($breakRanges); $i++) {
    if ($breakRanges[$i]['startTs'] < $breakRanges[$i - 1]['endTs']) {
        $errors[] = '休憩が重複しています（休憩' . $i . ' と 休憩' . ($i + 1) . '）';
        break;
    }
}
if (!empty($errors)) {
    redirectToEdit($backParams + ['err' => implode(' / ', $errors)]);
}

// device_id（break用）
try {
    $deviceId = resolveDeviceId($pdo, $tenantId, $storeId);
} catch (Throwable $e) {
    redirectToEdit($backParams + ['err' => $e->getMessage()]);
}

// time_punches device_id
$tpHasDeviceId = false;
$tpDeviceNullable = true;
$tpDeviceId = null;
try {
    $tpMeta = tableColumnMeta($pdo, 'time_punches');
    $tpHasDeviceId = isset($tpMeta['device_id']);
    $tpHasPunchSource = isset($tpMeta['punch_source']);
    if ($tpHasDeviceId) {
        $nullFlag = (string)($tpMeta['device_id']['Null'] ?? '');
        $tpDeviceNullable = ($nullFlag === 'YES');
        if (!$tpDeviceNullable) {
            $tpDeviceId = resolveDeviceId($pdo, $tenantId, $storeId);
        }
    }
} catch (Throwable $e) {
    $tpHasDeviceId = false;
    $tpHasPunchSource = false;
    $tpDeviceNullable = true;
    $tpDeviceId = null;
}

$pdo->beginTransaction();
try {
    // 出勤/退勤の更新
    if ($clockInId > 0) {
        $u1 = $pdo->prepare("UPDATE time_punches SET punched_at=:pa, updated_at=NOW() WHERE id=:id AND tenant_id=:t AND store_id=:s AND employee_id=:e AND punch_type='clock_in'");
        $u1->execute([':pa' => $inAt, ':id' => $clockInId, ':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
    } else {
        $data = [
            ':t' => $tenantId,
            ':s' => $storeId,
            ':e' => $employeeId,
            ':pa' => $inAt,
            ':now' => date('Y-m-d H:i:s'),
        ];
        $fields = 'tenant_id, store_id, employee_id, punch_type, punched_at, created_at, updated_at';
        $values = ':t,:s,:e,\'clock_in\',:pa,:now,:now';
        if ($tpHasDeviceId) {
            $fields .= ', device_id';
            $values .= ', :device_id';
            $data[':device_id'] = $tpDeviceNullable ? null : $tpDeviceId;
        }
        if ($tpHasPunchSource) {
            $fields .= ', punch_source';
            $values .= ', :punch_source';
            $data[':punch_source'] = 'admin';
        }
        $ins = $pdo->prepare("INSERT INTO time_punches ($fields) VALUES ($values)");
        $ins->execute($data);
    }

    if ($clockOutId > 0) {
        $u2 = $pdo->prepare("UPDATE time_punches SET punched_at=:pa, updated_at=NOW() WHERE id=:id AND tenant_id=:t AND store_id=:s AND employee_id=:e AND punch_type='clock_out'");
        $u2->execute([':pa' => $outAt, ':id' => $clockOutId, ':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
    } else {
        $data = [
            ':t' => $tenantId,
            ':s' => $storeId,
            ':e' => $employeeId,
            ':pa' => $outAt,
            ':now' => date('Y-m-d H:i:s'),
        ];
        $fields = 'tenant_id, store_id, employee_id, punch_type, punched_at, created_at, updated_at';
        $values = ':t,:s,:e,\'clock_out\',:pa,:now,:now';
        if ($tpHasDeviceId) {
            $fields .= ', device_id';
            $values .= ', :device_id';
            $data[':device_id'] = $tpDeviceNullable ? null : $tpDeviceId;
        }
        if ($tpHasPunchSource) {
            $fields .= ', punch_source';
            $values .= ', :punch_source';
            $data[':punch_source'] = 'admin';
        }
        $ins = $pdo->prepare("INSERT INTO time_punches ($fields) VALUES ($values)");
        $ins->execute($data);
    }

    // ✅ 休憩の差し替え（この勤務範囲に収まるものを対象に削除）
    $del = $pdo->prepare("
        DELETE FROM break_punches
        WHERE tenant_id=:t AND store_id=:s AND employee_id=:e
          AND break_start_at >= :bs AND break_end_at <= :be
    ");
    $del->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
        ':bs' => $inAt,
        ':be' => $outAt,
    ]);

    if (!empty($breakRanges)) {
        $ins = $pdo->prepare("
            INSERT INTO break_punches
              (tenant_id, store_id, employee_id, device_id, break_start_at, break_end_at, created_at, updated_at)
            VALUES
              (:t,:s,:e,:d,:bs,:be,NOW(),NOW())
        ");
        foreach ($breakRanges as $r) {
            $ins->execute([
                ':t' => $tenantId,
                ':s' => $storeId,
                ':e' => $employeeId,
                ':d' => $deviceId,
                ':bs' => $r['startAt'],
                ':be' => $r['endAt'],
            ]);
        }
    }

    $pdo->commit();

    // 戻る（一覧へ）
    if ($backUrl !== '') {
        $sep = (strpos($backUrl, '?') === false) ? '?' : '&';
        header('Location: ' . $backUrl . $sep . http_build_query(['msg' => '勤怠（休憩含む）を更新しました']));
    } else {
        $to = $day;
        if ($nextDay) $to = date('Y-m-d', strtotime($day . ' +1 day'));
        header('Location: /admin/time_punch_daily.php?' . http_build_query([
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'from' => $day,
            'to' => $to,
            'msg' => '勤怠（休憩含む）を更新しました',
        ]));
    }
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    redirectToEdit($backParams + ['err' => '保存エラー: ' . $e->getMessage()]);
}
