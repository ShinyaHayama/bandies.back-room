<?php
// /admin/leave_request_confirm.php

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

function shift_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $stmt->execute([':col' => $column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];
$adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$request = $token !== '' ? shift_leave_requests_fetch_by_token($pdo, $token) : [];
$message = '';
$error = '';

if (!$request || (int)($request['tenant_id'] ?? 0) !== $tenantId) {
    http_response_code(404);
    $error = '休み申請が見つかりません。';
} elseif ((string)($_SESSION['admin_role'] ?? 'owner') === 'manager'
    && !in_array((int)($request['store_id'] ?? 0), array_map('intval', (array)($_SESSION['allowed_store_ids'] ?? [])), true)) {
    http_response_code(403);
    $error = 'この店舗の申請を処理する権限がありません。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        $error = 'CSRFトークンが不正です。';
    } elseif (!in_array($action, ['approve', 'reject'], true)) {
        $error = '操作が不正です。';
    } elseif ((string)$request['status'] !== 'pending') {
        $error = 'この申請はすでに処理済みです。';
    } else {
        try {
            $pdo->beginTransaction();

            if ($action === 'approve') {
                $hasDeletedAt = shift_has_column($pdo, 'shifts', 'deleted_at');
                if ($hasDeletedAt) {
                    $del = $pdo->prepare("
                        UPDATE shifts
                        SET deleted_at = NOW(), updated_at = NOW()
                        WHERE tenant_id = :t
                          AND store_id = :s
                          AND employee_id = :e
                          AND shift_date = :d
                          AND deleted_at IS NULL
                    ");
                    $del->execute([
                        ':t' => (int)$request['tenant_id'],
                        ':s' => (int)$request['store_id'],
                        ':e' => (int)$request['employee_id'],
                        ':d' => (string)$request['request_date'],
                    ]);
                } else {
                    $del = $pdo->prepare("
                        DELETE FROM shifts
                        WHERE tenant_id = :t
                          AND store_id = :s
                          AND employee_id = :e
                          AND shift_date = :d
                    ");
                    $del->execute([
                        ':t' => (int)$request['tenant_id'],
                        ':s' => (int)$request['store_id'],
                        ':e' => (int)$request['employee_id'],
                        ':d' => (string)$request['request_date'],
                    ]);
                }
            }

            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $upd = $pdo->prepare("
                UPDATE shift_leave_requests
                SET status = :status,
                    reviewed_at = NOW(),
                    reviewed_by_admin_user_id = :admin_id,
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :t
                  AND status = 'pending'
                LIMIT 1
            ");
            $upd->execute([
                ':status' => $newStatus,
                ':admin_id' => $adminUserId > 0 ? $adminUserId : null,
                ':id' => (int)$request['id'],
                ':t' => $tenantId,
            ]);

            $pdo->commit();
            $message = $action === 'approve'
                ? '休み申請を認証し、対象日のシフトを削除しました。'
                : '休み申請を非認証にしました。';
            $request = shift_leave_requests_fetch_by_token($pdo, $token);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = '処理に失敗しました。';
        }
    }
}

$storeId = (int)($request['store_id'] ?? 0);
$backUrl = '/admin/shifts.php' . ($storeId > 0 ? ('?store_id=' . $storeId . '&date=' . rawurlencode((string)($request['request_date'] ?? ''))) : '');
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>休み申請確認</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Noto Sans JP", sans-serif; background: #f6f7fb; color: #111827; }
        .wrap { max-width: 720px; margin: 32px auto; padding: 0 16px; }
        .panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; box-shadow: 0 12px 30px rgba(15, 23, 42, .08); }
        h1 { font-size: 20px; margin: 0 0 16px; }
        .row { display: grid; grid-template-columns: 120px 1fr; gap: 10px; padding: 10px 0; border-top: 1px solid #eef2f7; }
        .label { color: #6b7280; font-size: 13px; font-weight: 700; }
        .value { font-size: 14px; font-weight: 700; white-space: pre-wrap; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        .btn { appearance: none; border: 1px solid #d1d5db; background: #fff; color: #111827; border-radius: 10px; padding: 10px 14px; font-weight: 800; text-decoration: none; cursor: pointer; }
        .btn.primary { background: #111827; color: #fff; border-color: #111827; }
        .btn.danger { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
        .msg { margin: 0 0 14px; padding: 10px 12px; border-radius: 10px; font-weight: 700; background: #ecfdf5; color: #047857; }
        .err { margin: 0 0 14px; padding: 10px 12px; border-radius: 10px; font-weight: 700; background: #fff1f2; color: #be123c; }
        .status { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 12px; background: #fef3c7; color: #92400e; }
        .status.approved { background: #dcfce7; color: #166534; }
        .status.rejected { background: #fee2e2; color: #991b1b; }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="panel">
            <h1>休み申請確認</h1>
            <?php if ($message !== ''): ?><div class="msg"><?= h($message) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

            <?php if ($request): ?>
                <?php $status = (string)$request['status']; ?>
                <div class="row"><div class="label">状態</div><div class="value"><span class="status <?= h($status) ?>"><?= h($status) ?></span></div></div>
                <div class="row"><div class="label">店舗</div><div class="value"><?= h((string)($request['store_name'] ?? '')) ?></div></div>
                <div class="row"><div class="label">従業員</div><div class="value"><?= h((string)($request['employee_name'] ?? '')) ?></div></div>
                <div class="row"><div class="label">申請日</div><div class="value"><?= h((string)$request['request_date']) ?></div></div>
                <div class="row"><div class="label">理由</div><div class="value"><?= h((string)($request['reason'] ?? '')) ?></div></div>
                <div class="row"><div class="label">申請日時</div><div class="value"><?= h((string)$request['requested_at']) ?></div></div>

                <div class="actions">
                    <?php if ($status === 'pending' && $error === ''): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="token" value="<?= h($token) ?>">
                            <input type="hidden" name="action" value="approve">
                            <button class="btn primary" type="submit">認証してシフトを削除</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="token" value="<?= h($token) ?>">
                            <input type="hidden" name="action" value="reject">
                            <button class="btn danger" type="submit">非認証</button>
                        </form>
                    <?php endif; ?>
                    <a class="btn" href="<?= h($backUrl) ?>">シフト表へ戻る</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
