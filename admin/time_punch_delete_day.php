<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/time_punch_delete_day.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 目的（削除しても消えない問題の修正）
 * - 「営業日(day)」で削除する（暦日DATE(punched_at)=day ではない）
 * - cutoff(例 05:00:00) を使い、営業日Dの範囲を [D cutoff, (D+1) cutoff) として削除する
 *   → 退勤が翌日 04:00 のような打刻も同じ営業日に含まれるので確実に消える
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
    echo "db.php not found.\n";
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ===== helpers =====
function mustYmd(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

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
 * ✅ 営業日Dの削除範囲を作る
 * - start = D 00:00 + cutoff
 * - end   = (D+1) 00:00 + cutoff
 */
function businessDayRange(string $dayYmd, int $cutoffSeconds): array
{
    $tz = new DateTimeZone('Asia/Tokyo');

    $dayStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dayYmd . ' 00:00:00', $tz);
    if (!$dayStart) {
        // 保険：暦日で削除（ただし基本ここには来ない想定）
        $start = $dayYmd . ' 00:00:00';
        $end   = $dayYmd . ' 23:59:59';
        return [$start, $end];
    }

    $startDt = $dayStart->modify('+' . $cutoffSeconds . ' seconds');
    $endDt   = $dayStart->modify('+1 day')->modify('+' . $cutoffSeconds . ' seconds');

    return [$startDt->format('Y-m-d H:i:s'), $endDt->format('Y-m-d H:i:s')];
}

// ===== input =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('不正なアクセスです'));
    exit;
}

session_start();
$csrf = (string)($_SESSION['csrf_token'] ?? '');
$csrfPost = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals($csrf, $csrfPost)) {
    header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('CSRFエラー'));
    exit;
}

$storeId = (int)($_POST['store_id'] ?? 0);
$employeeId = (int)($_POST['employee_id'] ?? 0);
$day = mustYmd((string)($_POST['day'] ?? ''));
$backUrl = (string)($_POST['back_url'] ?? '/admin/time_punch_daily.php');

if ($storeId <= 0 || $employeeId <= 0 || $day === null) {
    header('Location: ' . $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'msg=' . rawurlencode('削除条件が不正です'));
    exit;
}

// ===== store cutoff =====
$cutoffStr = '00:00:00';
try {
    $st = $pdo->prepare("
        SELECT COALESCE(business_day_cutoff_time, '00:00:00') AS business_day_cutoff_time
        FROM stores
        WHERE tenant_id = :t AND id = :sid
        LIMIT 1
    ");
    $st->execute([':t' => $tenantId, ':sid' => $storeId]);
    $cutoffStr = (string)($st->fetch()['business_day_cutoff_time'] ?? '00:00:00');
} catch (Throwable $e) {
    $cutoffStr = '00:00:00';
}
$cutoffSeconds = cutoffToSeconds($cutoffStr);

// ✅ 営業日範囲
[$rangeStart, $rangeEnd] = businessDayRange($day, $cutoffSeconds);

// ✅ 出勤中（最後がclock_in）の場合は削除不可
try {
    $lastStmt = $pdo->prepare("
        SELECT punch_type, punched_at
        FROM time_punches
        WHERE tenant_id = :t
          AND store_id  = :s
          AND employee_id = :e
          AND punch_type IN ('clock_in','clock_out')
        ORDER BY punched_at DESC, id DESC
        LIMIT 1
    ");
    $lastStmt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
    ]);
    $last = $lastStmt->fetch();
    if ($last && (string)($last['punch_type'] ?? '') === 'clock_in') {
        header('Location: ' . $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'msg=' . rawurlencode('出勤中のため削除できません（退勤を入力してください）'));
        exit;
    }
} catch (Throwable $e) {
    // チェックに失敗した場合は既存動作を優先
}

$pdo->beginTransaction();
try {
    // ===== time_punches 削除（営業日範囲で削除）=====
    $delTp = $pdo->prepare("
        DELETE FROM time_punches
        WHERE tenant_id = :t
          AND store_id  = :s
          AND employee_id = :e
          AND punched_at >= :start_dt
          AND punched_at <  :end_dt
    ");
    $delTp->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
        ':start_dt' => $rangeStart,
        ':end_dt'   => $rangeEnd,
    ]);

    // ===== break_punches 削除（営業日範囲に重なる休憩を削除）=====
    // break_start_at < end かつ (break_end_at is null or break_end_at >= start)
    try {
        $pdo->query("SELECT 1 FROM break_punches LIMIT 1");
        $delBp = $pdo->prepare("
            DELETE FROM break_punches
            WHERE tenant_id = :t
              AND store_id  = :s
              AND employee_id = :e
              AND break_start_at < :end_dt
              AND (break_end_at IS NULL OR break_end_at >= :start_dt)
        ");
        $delBp->execute([
            ':t' => $tenantId,
            ':s' => $storeId,
            ':e' => $employeeId,
            ':start_dt' => $rangeStart,
            ':end_dt'   => $rangeEnd,
        ]);
    } catch (Throwable $e) {
        // break_punches 無い環境は無視
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: ' . $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'msg=' . rawurlencode('削除失敗: ' . $e->getMessage()));
    exit;
}

// ✅ 完了
header('Location: ' . $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'msg=' . rawurlencode("削除しました（営業日={$day} / 範囲={$rangeStart}〜{$rangeEnd} / cutoff={$cutoffStr}）"));
exit;
