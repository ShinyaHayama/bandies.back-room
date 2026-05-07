<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/time_punch_delete_pair.php
 * ✅ 目的：1件（clock_in/clock_out ペア）だけ削除する
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
$clockInId = (int)($_POST['clock_in_id'] ?? 0);
$clockOutId = (int)($_POST['clock_out_id'] ?? 0);
$backUrl = (string)($_POST['back_url'] ?? '/admin/time_punch_daily.php');

if ($storeId <= 0 || $employeeId <= 0 || $day === null || $clockOutId <= 0) {
    header('Location: ' . $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'msg=' . rawurlencode('削除条件が不正です'));
    exit;
}

// ===== 対象打刻の確認 =====
$punchIn = null;
$punchOut = null;
if ($clockInId > 0) {
    $st = $pdo->prepare("
        SELECT id, punched_at, punch_type
        FROM time_punches
        WHERE id = :id AND tenant_id = :t AND store_id = :s AND employee_id = :e
        LIMIT 1
    ");
    $st->execute([':id' => $clockInId, ':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
    $punchIn = $st->fetch();
    if (!$punchIn || (string)$punchIn['punch_type'] !== 'clock_in') {
        $punchIn = null;
    }
}
$st = $pdo->prepare("
    SELECT id, punched_at, punch_type
    FROM time_punches
    WHERE id = :id AND tenant_id = :t AND store_id = :s AND employee_id = :e
    LIMIT 1
");
$st->execute([':id' => $clockOutId, ':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
$punchOut = $st->fetch();
if (!$punchOut || (string)$punchOut['punch_type'] !== 'clock_out') {
    header('Location: ' . $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'msg=' . rawurlencode('退勤データが見つかりません'));
    exit;
}

$inAt = $punchIn ? (string)$punchIn['punched_at'] : null;
$outAt = (string)$punchOut['punched_at'];

$pdo->beginTransaction();
try {
    // break_punches がある場合は該当区間を削除
    try {
        $pdo->query("SELECT 1 FROM break_punches LIMIT 1");
        if ($inAt !== null) {
            $delBp = $pdo->prepare("
                DELETE FROM break_punches
                WHERE tenant_id = :t
                  AND store_id  = :s
                  AND employee_id = :e
                  AND break_start_at < :out_at
                  AND (break_end_at IS NULL OR break_end_at > :in_at)
            ");
            $delBp->execute([
                ':t' => $tenantId,
                ':s' => $storeId,
                ':e' => $employeeId,
                ':in_at' => $inAt,
                ':out_at' => $outAt,
            ]);
        }
    } catch (Throwable $e) {
        // break_punches 無い環境は無視
    }

    // clock_in / clock_out を削除
    if ($punchIn) {
        $delIn = $pdo->prepare("
            DELETE FROM time_punches
            WHERE id = :id AND tenant_id = :t AND store_id = :s AND employee_id = :e
            LIMIT 1
        ");
        $delIn->execute([':id' => (int)$punchIn['id'], ':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
    }

    $delOut = $pdo->prepare("
        DELETE FROM time_punches
        WHERE id = :id AND tenant_id = :t AND store_id = :s AND employee_id = :e
        LIMIT 1
    ");
    $delOut->execute([':id' => (int)$punchOut['id'], ':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: ' . $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'msg=' . rawurlencode('削除失敗: ' . $e->getMessage()));
    exit;
}

header('Location: ' . $backUrl . (str_contains($backUrl, '?') ? '&' : '?') . 'msg=' . rawurlencode('1件削除しました'));
exit;
