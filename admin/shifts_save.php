<?php

/**
 * ✅ ファイル名: /admin/shifts_save.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 目的:
 * - Drag&Drop の移動は INSERT ではなく UPDATE（元のシフトを「日付変更」して移動）
 * - だから移動前にシフトが残らず、欠勤にならない
 * - ついでに通常の保存も shift_id があれば UPDATE、無ければ INSERT
 * - 削除は soft delete（deleted_at）
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../lib/shift_leave_requests.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
shift_leave_requests_ensure_schema($pdo);

date_default_timezone_set('Asia/Tokyo');

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function isValidCsrf(string $t): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $t);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || !isValidCsrf($csrf)) {
    http_response_code(400);
    echo 'CSRF invalid';
    exit;
}

$action   = (string)($_POST['action'] ?? '');
$storeId  = (int)($_POST['store_id'] ?? 0);
$empId    = (int)($_POST['employee_id'] ?? 0);
$shiftDate = (string)($_POST['shift_date'] ?? '');
$returnTo = (string)($_POST['return_to'] ?? '/admin/shifts.php');

$shiftId  = (int)($_POST['shift_id'] ?? 0);

$start = (string)($_POST['start_time'] ?? '');
$end   = (string)($_POST['end_time'] ?? '');
$break = (int)($_POST['break_minutes'] ?? 0);
$note  = (string)($_POST['note'] ?? '');
$endNextDay = (int)($_POST['end_next_day'] ?? 0);

if ($storeId <= 0) redirect_to($returnTo);

if (!in_array($action, ['upsert', 'delete', 'mark_off'], true)) {
    redirect_to($returnTo);
}

if ($action === 'mark_off') {
    if ($empId <= 0 || !shift_leave_requests_valid_date($shiftDate)) {
        redirect_to($returnTo);
    }

    $empCheck = $pdo->prepare("
        SELECT id
        FROM employees
        WHERE id = :e
          AND tenant_id = :t
          AND store_id = :s
          AND employment_status = 'active'
        LIMIT 1
    ");
    $empCheck->execute([':e' => $empId, ':t' => $tenantId, ':s' => $storeId]);
    if (!$empCheck->fetch()) {
        redirect_to($returnTo);
    }

    $adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);
    $reason = '口頭連絡により管理者が休みに変更';

    try {
        $pdo->beginTransaction();

        try {
            $del = $pdo->prepare("
                UPDATE shifts
                SET deleted_at = NOW(), updated_at = NOW()
                WHERE tenant_id = :t
                  AND store_id = :s
                  AND employee_id = :e
                  AND shift_date = :d
                  AND deleted_at IS NULL
            ");
            $del->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $empId, ':d' => $shiftDate]);
        } catch (Throwable $e) {
            $del = $pdo->prepare("
                DELETE FROM shifts
                WHERE tenant_id = :t
                  AND store_id = :s
                  AND employee_id = :e
                  AND shift_date = :d
            ");
            $del->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $empId, ':d' => $shiftDate]);
        }

        $existing = $pdo->prepare("
            SELECT id
            FROM shift_leave_requests
            WHERE tenant_id = :t
              AND store_id = :s
              AND employee_id = :e
              AND request_date = :d
              AND status IN ('pending', 'approved')
            ORDER BY id DESC
            LIMIT 1
        ");
        $existing->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $empId, ':d' => $shiftDate]);
        $existingId = (int)($existing->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $upd = $pdo->prepare("
                UPDATE shift_leave_requests
                SET status = 'approved',
                    reason = COALESCE(NULLIF(reason, ''), :reason),
                    reviewed_at = NOW(),
                    reviewed_by_admin_user_id = :admin_id,
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :t
                LIMIT 1
            ");
            $upd->execute([
                ':reason' => $reason,
                ':admin_id' => $adminUserId > 0 ? $adminUserId : null,
                ':id' => $existingId,
                ':t' => $tenantId,
            ]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO shift_leave_requests
                    (tenant_id, store_id, employee_id, request_date, reason, status, token, requested_at,
                     reviewed_at, reviewed_by_admin_user_id, created_at, updated_at)
                VALUES
                    (:t, :s, :e, :d, :reason, 'approved', :token, NOW(),
                     NOW(), :admin_id, NOW(), NOW())
            ");
            $ins->execute([
                ':t' => $tenantId,
                ':s' => $storeId,
                ':e' => $empId,
                ':d' => $shiftDate,
                ':reason' => $reason,
                ':token' => bin2hex(random_bytes(32)),
                ':admin_id' => $adminUserId > 0 ? $adminUserId : null,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }

    redirect_to($returnTo);
}

if ($action === 'delete') {
    if ($shiftId <= 0) redirect_to($returnTo);

    // 自テナント・自店舗のものだけ削除できる
    $sql = "UPDATE shifts
            SET deleted_at = NOW()
            WHERE id = :id AND tenant_id=:t AND store_id=:s";
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $shiftId, ':t' => $tenantId, ':s' => $storeId]);
    redirect_to($returnTo);
}

// upsert
if ($empId <= 0 || $shiftDate === '' || $start === '' || $end === '') {
    redirect_to($returnTo);
}

// shift_id がある → UPDATE（これが Drag&Drop の要）
if ($shiftId > 0) {
    // まず「end_next_day がある」想定で更新。無い場合は catch して再試行
    try {
        $sql = "UPDATE shifts
                SET employee_id=:e,
                    shift_date=:d,
                    start_time=:st,
                    end_time=:et,
                    break_minutes=:br,
                    note=:note,
                    end_next_day=:en,
                    updated_at=NOW()
                WHERE id=:id AND tenant_id=:t AND store_id=:s AND deleted_at IS NULL";
        $stt = $pdo->prepare($sql);
        $stt->execute([
            ':e' => $empId,
            ':d' => $shiftDate,
            ':st' => $start,
            ':et' => $end,
            ':br' => $break,
            ':note' => $note,
            ':en' => $endNextDay,
            ':id' => $shiftId,
            ':t' => $tenantId,
            ':s' => $storeId,
        ]);
    } catch (Throwable $e) {
        // end_next_day 列が無い環境用
        $sql = "UPDATE shifts
                SET employee_id=:e,
                    shift_date=:d,
                    start_time=:st,
                    end_time=:et,
                    break_minutes=:br,
                    note=:note,
                    updated_at=NOW()
                WHERE id=:id AND tenant_id=:t AND store_id=:s AND deleted_at IS NULL";
        $stt = $pdo->prepare($sql);
        $stt->execute([
            ':e' => $empId,
            ':d' => $shiftDate,
            ':st' => $start,
            ':et' => $end,
            ':br' => $break,
            ':note' => $note,
            ':id' => $shiftId,
            ':t' => $tenantId,
            ':s' => $storeId,
        ]);
    }

    redirect_to($returnTo);
}

// shift_id なし → INSERT
try {
    $sql = "INSERT INTO shifts
            (tenant_id, store_id, employee_id, shift_date, start_time, end_time, break_minutes, note, end_next_day, created_at, updated_at)
            VALUES
            (:t,:s,:e,:d,:st,:et,:br,:note,:en,NOW(),NOW())";
    $stt = $pdo->prepare($sql);
    $stt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $empId,
        ':d' => $shiftDate,
        ':st' => $start,
        ':et' => $end,
        ':br' => $break,
        ':note' => $note,
        ':en' => $endNextDay,
    ]);
} catch (Throwable $e) {
    // end_next_day 列が無い環境用
    $sql = "INSERT INTO shifts
            (tenant_id, store_id, employee_id, shift_date, start_time, end_time, break_minutes, note, created_at, updated_at)
            VALUES
            (:t,:s,:e,:d,:st,:et,:br,:note,NOW(),NOW())";
    $stt = $pdo->prepare($sql);
    $stt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $empId,
        ':d' => $shiftDate,
        ':st' => $start,
        ':et' => $end,
        ':br' => $break,
        ':note' => $note,
    ]);
}

redirect_to($returnTo);
