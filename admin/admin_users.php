<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/_db.php';

if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;
$adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

function ensure_admin_acl_schema(PDO $pdo): void
{
    $cols = $pdo->query("SHOW COLUMNS FROM tenant_admin_users")->fetchAll(PDO::FETCH_COLUMN, 0);
    $colSet = array_flip($cols);
    if (!isset($colSet['role'])) {
        $pdo->exec("ALTER TABLE tenant_admin_users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'owner'");
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tenant_admin_store_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            tenant_admin_user_id INT NOT NULL,
            store_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_admin_store (tenant_admin_user_id, store_id),
            KEY idx_tenant_admin (tenant_id, tenant_admin_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

ensure_admin_acl_schema($pdo);

$meStmt = $pdo->prepare("SELECT id, email, role FROM tenant_admin_users WHERE id=:id AND tenant_id=:tenant_id LIMIT 1");
$meStmt->execute([':id' => $adminUserId, ':tenant_id' => $tenantId]);
$me = $meStmt->fetch() ?: [];
$myRole = (string)($me['role'] ?? 'owner');
if (!in_array($myRole, ['owner', 'manager'], true)) $myRole = 'owner';
$_SESSION['admin_role'] = $myRole;
$isOwner = ($myRole === 'owner');

$storesStmt = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id = :t ORDER BY id ASC");
$storesStmt->execute([':t' => $tenantId]);
$stores = $storesStmt->fetchAll();
if ($stores) {
    $storeId = (int)($_GET['store_id'] ?? 0);
    $storeIds = array_map(fn($s) => (int)$s['id'], $stores);
    if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) {
        $storeId = (int)$stores[0]['id'];
    }
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $errors[] = 'CSRFトークンが不正です';
    } elseif (!$isOwner) {
        $errors[] = '権限がありません（オーナーのみ変更できます）';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'add_admin') {
                $email = trim((string)($_POST['email'] ?? ''));
                $pw = (string)($_POST['password'] ?? '');
                $role = (string)($_POST['role'] ?? 'manager');
                $storeIds = array_map('intval', (array)($_POST['store_ids'] ?? []));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'メールアドレスを正しく入力してください';
                }
                if ($pw === '' || mb_strlen($pw) < 8) {
                    $errors[] = 'パスワードは8文字以上にしてください';
                }
                if (!in_array($role, ['owner', 'manager'], true)) {
                    $errors[] = '権限が不正です';
                }

                if (!$errors) {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO tenant_admin_users(tenant_id, email, password_hash, status, role)
                            VALUES(:tenant_id, :email, :password_hash, 'active', :role)
                        ");
                        $stmt->execute([
                            ':tenant_id' => $tenantId,
                            ':email' => $email,
                            ':password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                            ':role' => $role,
                        ]);
                        $newId = (int)$pdo->lastInsertId();

                        $pdo->prepare("DELETE FROM tenant_admin_store_permissions WHERE tenant_admin_user_id=:uid")
                            ->execute([':uid' => $newId]);
                        if ($role === 'manager' && $storeIds) {
                            $ins = $pdo->prepare("
                                INSERT INTO tenant_admin_store_permissions(tenant_id, tenant_admin_user_id, store_id, created_at)
                                VALUES(:tenant_id, :uid, :store_id, NOW())
                            ");
                            foreach ($storeIds as $sid) {
                                $ins->execute([':tenant_id' => $tenantId, ':uid' => $newId, ':store_id' => (int)$sid]);
                            }
                        }

                        $pdo->commit();
                        $success = '管理者を追加しました';
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            } elseif ($action === 'update_role') {
                $targetId = (int)($_POST['admin_id'] ?? 0);
                $role = (string)($_POST['role'] ?? '');
                if ($targetId === $adminUserId) {
                    $errors[] = '自分自身の権限は変更できません';
                } elseif (!in_array($role, ['owner', 'manager'], true)) {
                    $errors[] = '権限が不正です';
                } elseif ($targetId <= 0) {
                    $errors[] = '対象が不正です';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("UPDATE tenant_admin_users SET role=:role WHERE id=:id AND tenant_id=:t LIMIT 1");
                        $stmt->execute([':role' => $role, ':id' => $targetId, ':t' => $tenantId]);
                        $pdo->commit();
                        $success = '権限を更新しました';
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            } elseif ($action === 'update_stores') {
                $targetId = (int)($_POST['admin_id'] ?? 0);
                $storeIds = array_map('intval', (array)($_POST['store_ids'] ?? []));
                if ($targetId <= 0) {
                    $errors[] = '対象が不正です';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("DELETE FROM tenant_admin_store_permissions WHERE tenant_admin_user_id=:uid")
                            ->execute([':uid' => $targetId]);
                        if ($storeIds) {
                            $ins = $pdo->prepare("
                                INSERT INTO tenant_admin_store_permissions(tenant_id, tenant_admin_user_id, store_id, created_at)
                                VALUES(:tenant_id, :uid, :store_id, NOW())
                            ");
                            foreach ($storeIds as $sid) {
                                $ins->execute([':tenant_id' => $tenantId, ':uid' => $targetId, ':store_id' => (int)$sid]);
                            }
                        }
                        $pdo->commit();
                        $success = '担当店舗を更新しました';
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            } elseif ($action === 'toggle_status') {
                $targetId = (int)($_POST['admin_id'] ?? 0);
                if ($targetId === $adminUserId) {
                    $errors[] = '自分自身の状態は変更できません';
                } elseif ($targetId <= 0) {
                    $errors[] = '対象が不正です';
                } else {
                    $cur = $pdo->prepare("SELECT status FROM tenant_admin_users WHERE id=:id AND tenant_id=:t LIMIT 1");
                    $cur->execute([':id' => $targetId, ':t' => $tenantId]);
                    $status = (string)($cur->fetch()['status'] ?? 'active');
                    $next = ($status === 'active') ? 'inactive' : 'active';
                    $u = $pdo->prepare("UPDATE tenant_admin_users SET status=:st WHERE id=:id AND tenant_id=:t LIMIT 1");
                    $u->execute([':st' => $next, ':id' => $targetId, ':t' => $tenantId]);
                    $success = 'ステータスを更新しました';
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$adminsStmt = $pdo->prepare("
    SELECT id, email, role, status, created_at
    FROM tenant_admin_users
    WHERE tenant_id = :t
    ORDER BY id ASC
");
$adminsStmt->execute([':t' => $tenantId]);
$admins = $adminsStmt->fetchAll();

$permStmt = $pdo->prepare("
    SELECT tenant_admin_user_id, store_id
    FROM tenant_admin_store_permissions
    WHERE tenant_id = :t
");
$permStmt->execute([':t' => $tenantId]);
$permRows = $permStmt->fetchAll();
$permMap = [];
foreach ($permRows as $r) {
    $uid = (int)$r['tenant_admin_user_id'];
    if (!isset($permMap[$uid])) $permMap[$uid] = [];
    $permMap[$uid][] = (int)$r['store_id'];
}
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>管理者アカウント</title>
    <style>
        body { margin:0; font-family: system-ui, -apple-system, sans-serif; background:#f8fafc; color:#111; }
        .page { padding: 16px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px; }
        h1 { margin:0 0 12px; font-size:18px; }
        .note { font-size:12px; color:#64748b; }
        .err { background:#ffecec; border:1px solid #ffb3b3; padding:10px; border-radius:10px; margin:10px 0; font-size:13px; }
        .ok { background:#ecfdf3; border:1px solid #a7f3d0; padding:10px; border-radius:10px; margin:10px 0; font-size:13px; }
        table { width:100%; border-collapse: collapse; margin-top:12px; }
        th, td { border-bottom:1px solid #eee; padding:8px; font-size:13px; text-align:left; }
        th { background:#fafafa; }
        .btn { height:34px; padding:0 12px; border-radius:10px; border:1px solid #0f172a; background:#0f172a; color:#fff; font-weight:800; cursor:pointer; }
        .btnGhost { height:34px; padding:0 12px; border-radius:10px; border:1px solid #d0d7de; background:#fff; font-weight:700; cursor:pointer; }
        .row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .input { height:36px; border:1px solid #d0d7de; border-radius:10px; padding:0 10px; }
        select.storeSelect { min-width: 200px; height: 40px; }
        .storePickBtn { height:36px; padding:0 12px; border-radius:10px; border:1px solid #d0d7de; background:#fff; font-weight:700; cursor:pointer; }
        .storePickSummary { font-size:12px; color:#64748b; }
        .storeHidden { display:none; }
        .modalMask {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.35);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 200000;
        }
        .modalMask.is-open { display: flex; }
        .modalPanel {
            width: min(520px, 92vw);
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            padding: 14px;
        }
        .modalHead {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .modalTitle { font-size: 14px; font-weight: 800; }
        .modalList { max-height: 320px; overflow: auto; border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px; }
        .modalItem { display: flex; align-items: center; gap: 8px; padding: 6px 4px; }
        .modalActions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
        .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; background:#eef2ff; color:#1f2a6b; }
        .stores { display:flex; gap:8px; flex-wrap:wrap; }
        .storeTag { font-size:11px; padding:2px 8px; border:1px solid #e2e8f0; border-radius:999px; background:#f8fafc; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/_header.php'; ?>
    <div class="page">
        <div class="card">
            <h1>管理者アカウント</h1>
            <?php if (!$isOwner): ?>
                <div class="err">このページはオーナーのみ利用できます。</div>
            <?php else: ?>
                <?php if ($errors): ?><div class="err"><?= h(implode(' / ', $errors)) ?></div><?php endif; ?>
                <?php if ($success !== ''): ?><div class="ok"><?= h($success) ?></div><?php endif; ?>

                <div class="note">オーナーは全店舗にアクセスできます。店長は許可された店舗のみ表示されます。</div>

                <h2 style="font-size:15px;margin:16px 0 8px;">新規追加</h2>
                <form method="post">
                    <input type="hidden" name="action" value="add_admin">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <div class="row">
                        <input class="input" type="email" name="email" placeholder="メールアドレス" required>
                        <input class="input" type="password" name="password" placeholder="パスワード（8文字以上）" required>
                        <select class="input" name="role">
                            <option value="manager">店長</option>
                            <option value="owner">オーナー</option>
                        </select>
                        <button class="btn" type="submit">追加</button>
                    </div>
                    <div class="note" style="margin-top:6px;">店長の場合は下の「担当店舗」で設定してください。</div>
                </form>

                <table>
                    <thead>
                        <tr>
                            <th>メール</th>
                            <th>権限</th>
                            <th>担当店舗</th>
                            <th>状態</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $a): ?>
                            <?php
                                $aid = (int)$a['id'];
                                $role = (string)($a['role'] ?? 'manager');
                                if (!in_array($role, ['owner', 'manager'], true)) $role = 'manager';
                                $assigned = $permMap[$aid] ?? [];
                            ?>
                            <tr>
                                <td><?= h((string)$a['email']) ?></td>
                                <td><span class="badge"><?= $role === 'owner' ? 'オーナー' : '店長' ?></span></td>
                                <td>
                                    <?php if ($role === 'owner'): ?>
                                        <span class="note">全店舗</span>
                                    <?php else: ?>
                                        <div class="stores">
                                            <?php foreach ($stores as $s): ?>
                                                <?php if (in_array((int)$s['id'], $assigned, true)): ?>
                                                    <span class="storeTag"><?= h((string)$s['name']) ?></span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php if (!$assigned): ?><span class="note">未設定</span><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= h((string)$a['status']) ?></td>
                                <td>
                                    <form method="post" class="row" style="margin-bottom:6px;">
                                        <input type="hidden" name="action" value="update_role">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="admin_id" value="<?= (int)$aid ?>">
                                        <select class="input" name="role">
                                            <option value="owner" <?= $role === 'owner' ? 'selected' : '' ?>>オーナー</option>
                                            <option value="manager" <?= $role === 'manager' ? 'selected' : '' ?>>店長</option>
                                        </select>
                                        <button class="btnGhost" type="submit" <?= $aid === $adminUserId ? 'disabled' : '' ?>>権限変更</button>
                                    </form>
                                    <?php if ($role === 'manager'): ?>
                                        <form method="post" class="row" style="margin-bottom:6px;">
                                            <input type="hidden" name="action" value="update_stores">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="admin_id" value="<?= (int)$aid ?>">
                                            <div class="storeHidden">
                                                <?php foreach ($assigned as $sid): ?>
                                                    <input type="hidden" name="store_ids[]" value="<?= (int)$sid ?>">
                                                <?php endforeach; ?>
                                            </div>
                                            <button class="storePickBtn" type="button"
                                                data-store-ids="<?= h(implode(',', $assigned)) ?>"
                                                data-admin-id="<?= (int)$aid ?>">店舗を選ぶ</button>
                                            <span class="storePickSummary">選択: <?= count($assigned) ?> 店舗</span>
                                            <button class="btnGhost" type="submit">店舗更新</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" class="row">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="admin_id" value="<?= (int)$aid ?>">
                                        <button class="btnGhost" type="submit" <?= $aid === $adminUserId ? 'disabled' : '' ?>><?= ((string)$a['status'] === 'active') ? '無効化' : '有効化' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="modalMask" id="storePickerModal" aria-hidden="true">
    <div class="modalPanel" role="dialog" aria-modal="true">
        <div class="modalHead">
            <div class="modalTitle">担当店舗を選択</div>
            <button class="btnGhost" type="button" id="storePickerClose">閉じる</button>
        </div>
        <div class="modalList" id="storePickerList">
            <?php foreach ($stores as $s): ?>
                <label class="modalItem">
                    <input type="checkbox" value="<?= (int)$s['id'] ?>">
                    <span><?= h((string)$s['name']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="modalActions">
            <button class="btnGhost" type="button" id="storePickerCancel">キャンセル</button>
            <button class="btn" type="button" id="storePickerApply">この内容で反映</button>
        </div>
    </div>
    </div>

    <script>
(() => {
    const modal = document.getElementById('storePickerModal');
    const list = document.getElementById('storePickerList');
    const applyBtn = document.getElementById('storePickerApply');
    const closeBtn = document.getElementById('storePickerClose');
    const cancelBtn = document.getElementById('storePickerCancel');
    let currentForm = null;

    const openModal = (form, ids) => {
        currentForm = form;
        const set = new Set(ids);
        list.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
            cb.checked = set.has(cb.value);
        });
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        currentForm = null;
    };

    document.querySelectorAll('.storePickBtn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const form = btn.closest('form');
            const ids = (btn.dataset.storeIds || '').split(',').filter(Boolean);
            openModal(form, ids);
        });
    });

    const applySelection = () => {
        if (!currentForm) return;
        const holder = currentForm.querySelector('.storeHidden');
        if (!holder) return;
        holder.innerHTML = '';
        const selected = [];
        list.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
            if (cb.checked) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'store_ids[]';
                input.value = cb.value;
                holder.appendChild(input);
                selected.push(cb.value);
            }
        });
        const summary = currentForm.querySelector('.storePickSummary');
        if (summary) summary.textContent = '選択: ' + selected.length + ' 店舗';
        const btn = currentForm.querySelector('.storePickBtn');
        if (btn) btn.dataset.storeIds = selected.join(',');
        closeModal();
    };

    applyBtn.addEventListener('click', applySelection);
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
})();
    </script>
</body>
</html>
