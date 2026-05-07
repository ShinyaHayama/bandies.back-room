<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_db.php';

/**
 * HTML escape
 * （_db.php 側に h() がある/ないが混在しても死なないようガード）
 */
if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$meId = (int)($_SESSION['super_admin_user_id'] ?? 0);

function audit(PDO $pdo, int $meId, string $action, array $payload = []): void
{
    // 監査ログテーブルが無い/カラム違い等でも本体を落とさないようにするなら try/catch 推奨だが、
    // ここでは「失敗したら落ちる」より「監査失敗でも画面は動く」に寄せる
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO super_audit_logs(super_admin_user_id, action, payload)
             VALUES(:uid,:action,:payload)"
        );
        $stmt->execute([
            ':uid'    => $meId,
            ':action' => $action,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // 監査ログが死んでも main 処理は継続
    }
}

$err = null;

function ensure_admin_acl_schema(PDO $pdo): void
{
    $cols = $pdo->query("SHOW COLUMNS FROM tenant_admin_users")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('role', $cols, true)) {
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

/**
 * POST処理（必ず action で分岐）
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');

    // ===== create =====
    if ($act === 'create') {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $email = trim((string)($_POST['email'] ?? ''));
        $pw    = (string)($_POST['password'] ?? '');
        $role  = (string)($_POST['role'] ?? 'manager');

        if ($tenantId <= 0) {
            $err = 'tenant を選択してください';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = '正しいメールアドレスを入力してください';
        } elseif ($pw === '') {
            $err = 'tenant / email / password はすべて必須です';
        } elseif (!in_array($role, ['owner', 'manager'], true)) {
            $err = '権限が不正です';
        } else {

            try {
                $hash = password_hash($pw, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    "INSERT INTO tenant_admin_users(tenant_id, email, password_hash, status, role)
                     VALUES(:tid,:email,:hash,'active',:role)"
                );
                $stmt->execute([
                    ':tid'  => $tenantId,
                    ':email' => $email,
                    ':hash' => $hash,
                    ':role' => $role,
                ]);

                audit($pdo, $meId, 'tenant_admin.create', [
                    'tenant_id' => $tenantId,
                    'email'     => $email
                ]);

                header('Location: /super/tenant_admins.php');
                exit;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (strpos($msg, '1062') !== false || strpos($msg, 'Duplicate entry') !== false) {
                    $err = '同じtenant内で email/識別子 が重複しています。別の値にしてください。';
                } else {
                    $err = '作成に失敗しました: ' . $msg;
                }
            }
        }
    }

    // ===== toggle =====
    if ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $err = 'id が不正です';
        } else {
            try {
                $cur = $pdo->prepare("SELECT status FROM tenant_admin_users WHERE id=:id");
                $cur->execute([':id' => $id]);
                $st = (string)($cur->fetch()['status'] ?? 'active');

                $next = ($st === 'active') ? 'inactive' : 'active';

                $u = $pdo->prepare("UPDATE tenant_admin_users SET status=:st WHERE id=:id");
                $u->execute([':st' => $next, ':id' => $id]);

                audit($pdo, $meId, 'tenant_admin.toggle', ['id' => $id, 'to' => $next]);

                header('Location: /super/tenant_admins.php');
                exit;
            } catch (Throwable $e) {
                $err = 'ステータス更新に失敗しました: ' . $e->getMessage();
            }
        }
    }

    // ===== role update (super only) =====
    if ($act === 'role_update') {
        $id = (int)($_POST['id'] ?? 0);
        $role = (string)($_POST['role'] ?? '');
        if ($id <= 0) {
            $err = 'id が不正です';
        } elseif (!in_array($role, ['owner', 'manager'], true)) {
            $err = '権限が不正です';
        } else {
            try {
                $u = $pdo->prepare("UPDATE tenant_admin_users SET role=:role WHERE id=:id");
                $u->execute([':role' => $role, ':id' => $id]);

                audit($pdo, $meId, 'tenant_admin.role_update', ['id' => $id, 'role' => $role]);

                header('Location: /super/tenant_admins.php');
                exit;
            } catch (Throwable $e) {
                $err = '権限更新に失敗しました: ' . $e->getMessage();
            }
        }
    }

    // ===== store assign =====
    if ($act === 'update_stores') {
        $id = (int)($_POST['id'] ?? 0);
        $storeIds = array_map('intval', (array)($_POST['store_ids'] ?? []));
        if ($id <= 0) {
            $err = 'id が不正です';
        } else {
            try {
                $adminRow = $pdo->prepare("SELECT tenant_id FROM tenant_admin_users WHERE id=:id");
                $adminRow->execute([':id' => $id]);
                $tenantId = (int)($adminRow->fetch()['tenant_id'] ?? 0);
                if ($tenantId <= 0) {
                    throw new RuntimeException('tenant が不明です');
                }

                $validStores = [];
                if ($storeIds) {
                    $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
                    $st = $pdo->prepare("SELECT id FROM stores WHERE tenant_id = ? AND id IN ({$placeholders})");
                    $st->execute(array_merge([$tenantId], $storeIds));
                    $validStores = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
                }

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM tenant_admin_store_permissions WHERE tenant_admin_user_id=:uid")
                        ->execute([':uid' => $id]);
                    if ($validStores) {
                        $ins = $pdo->prepare("
                            INSERT INTO tenant_admin_store_permissions(tenant_id, tenant_admin_user_id, store_id, created_at)
                            VALUES(:tenant_id, :uid, :store_id, NOW())
                        ");
                        foreach ($validStores as $sid) {
                            $ins->execute([':tenant_id' => $tenantId, ':uid' => $id, ':store_id' => (int)$sid]);
                        }
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                audit($pdo, $meId, 'tenant_admin.store_update', [
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'store_ids' => $validStores,
                ]);

                header('Location: /super/tenant_admins.php');
                exit;
            } catch (Throwable $e) {
                $err = '担当店舗更新に失敗しました: ' . $e->getMessage();
            }
        }
    }
}

/**
 * 一覧取得
 */
try {
    ensure_admin_acl_schema($pdo);
    $hasDeletedAt = (bool)$pdo->query("
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tenants'
          AND COLUMN_NAME = 'deleted_at'
        LIMIT 1
    ")->fetchColumn();
    $tenantWhere = $hasDeletedAt ? "WHERE deleted_at IS NULL" : "";

    $tenants = $pdo->query("SELECT id,name FROM tenants {$tenantWhere} ORDER BY id DESC")->fetchAll();

    $admins = $pdo->query("
        SELECT u.id,u.tenant_id,t.name AS tenant_name,u.email,u.status,u.created_at,u.role
        FROM tenant_admin_users u
        JOIN tenants t ON t.id=u.tenant_id
        " . ($hasDeletedAt ? "WHERE t.deleted_at IS NULL" : "") . "
        ORDER BY u.id DESC
        LIMIT 200
    ")->fetchAll();

    $tenantIds = array_map(fn($t) => (int)$t['id'], $tenants);
    $storesByTenant = [];
    if ($tenantIds) {
        $ph = implode(',', array_fill(0, count($tenantIds), '?'));
        $st = $pdo->prepare("SELECT id, tenant_id, name FROM stores WHERE tenant_id IN ({$ph}) ORDER BY id ASC");
        $st->execute($tenantIds);
        foreach ($st->fetchAll() as $r) {
            $tid = (int)$r['tenant_id'];
            if (!isset($storesByTenant[$tid])) $storesByTenant[$tid] = [];
            $storesByTenant[$tid][] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
        }
    }

    $permMap = [];
    if ($tenantIds) {
        $ph = implode(',', array_fill(0, count($tenantIds), '?'));
        $st = $pdo->prepare("
            SELECT tenant_admin_user_id, store_id
            FROM tenant_admin_store_permissions
            WHERE tenant_id IN ({$ph})
        ");
        $st->execute($tenantIds);
        foreach ($st->fetchAll() as $r) {
            $uid = (int)$r['tenant_admin_user_id'];
            if (!isset($permMap[$uid])) $permMap[$uid] = [];
            $permMap[$uid][] = (int)$r['store_id'];
        }
    }
} catch (Throwable $e) {
    $tenants = [];
    $admins  = [];
    $storesByTenant = [];
    $permMap = [];
    $err = $err ?: ('一覧取得に失敗しました: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tenant Admins</title>
    <style>
    body {
        font-family: system-ui;
        padding: 18px
    }

    .card {
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 14px;
        max-width: 1600px
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        table-layout: auto
    }

    th,
    td {
        border-bottom: 1px solid #eee;
        padding: 8px;
        font-size: 13px;
        text-align: left;
        white-space: nowrap
    }

    th {
        background: #fafafa
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center
    }

    td.actions {
        min-width: 320px;
    }

    .row.inline {
        flex-wrap: nowrap;
    }

    input,
    select,
    button {
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 10px
    }

    button {
        cursor: pointer
    }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        background: #eef2ff;
        color: #1f2a6b;
        margin-left: 6px;
    }

    .badgeOwner {
        background: #ffe8cc;
        color: #7a3e00;
    }

    .storePickBtn {
        padding: 8px 10px;
        border-radius: 10px;
        border: 1px solid #d0d7de;
        background: #fff;
        font-weight: 700;
    }

    .storePickSummary {
        font-size: 12px;
        color: #64748b;
    }

    .storeHidden {
        display: none;
    }

    .storeTag {
        display: inline-flex;
        align-items: center;
        padding: 2px 6px;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        background: #f8fafc;
        font-size: 11px;
        font-weight: 700;
        margin: 0 4px 4px 0;
    }

    .modalMask {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.35);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 200000;
    }

    .modalMask.is-open {
        display: flex;
    }

    .modalPanel {
        width: min(520px, 92vw);
        background: #fff;
        border-radius: 14px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 18px 60px rgba(15, 23, 42, 0.2);
        padding: 12px;
    }

    .modalHead {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        border-bottom: 1px solid #eee;
        padding: 4px 4px 10px;
    }

    .modalTitle {
        font-weight: 800;
        font-size: 14px;
    }

    .modalList {
        max-height: 320px;
        overflow: auto;
        padding: 10px 4px;
    }

    .modalItem {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 4px;
    }

    .modalActions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding-top: 8px;
    }

    .err {
        background: #ffecec;
        border: 1px solid #ffb3b3;
        padding: 10px;
        border-radius: 10px;
        margin: 10px 0
    }
    </style>
</head>

<body>
    <?php require __DIR__ . '/_top.php'; ?>

    <div class="card">
        <h2>テナント管理者（ログインユーザー）</h2>

        <?php if ($err): ?>
        <div class="err"><?= h($err) ?></div>
        <?php endif; ?>

        <form method="post" class="row">
            <input type="hidden" name="action" value="create">

            <select name="tenant_id" required>
                <option value="">tenant</option>
                <?php foreach ($tenants as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= h((string)$t['name']) ?> (<?= (int)$t['id'] ?>)</option>
                <?php endforeach; ?>
            </select>

            <input name="email" type="email" required placeholder="メールアドレス（ログインID）" style="min-width:260px;">

            <input name="password" type="password" required placeholder="初期パスワード" style="min-width:200px;">

            <select name="role" required>
                <option value="manager">manager</option>
                <option value="owner">owner</option>
            </select>

            <button type="submit">作成</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th style="width:80px;">id</th>
                    <th style="width:90px;">tenant</th>
                    <th>tenant_name</th>
                    <th>email</th>
                    <th style="width:90px;">role</th>
                    <th>stores</th>
                    <th style="width:110px;">status</th>
                    <th style="width:220px;">created</th>
                    <th style="width:340px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $a): ?>
                <?php
                    $aid = (int)$a['id'];
                    $role = (string)($a['role'] ?? 'manager');
                    if (!in_array($role, ['owner', 'manager'], true)) $role = 'manager';
                    $assigned = $permMap[$aid] ?? [];
                    $tenantStores = $storesByTenant[(int)$a['tenant_id']] ?? [];
                ?>
                <tr>
                    <td><?= (int)$a['id'] ?></td>
                    <td><?= (int)$a['tenant_id'] ?></td>
                    <td><?= h((string)$a['tenant_name']) ?></td>
                    <td>
                        <?= h((string)$a['email']) ?>
                        <?php if ((string)($a['role'] ?? '') === 'owner'): ?>
                            <span class="badge badgeOwner">オーナー</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h((string)($a['role'] ?? '')) ?></td>
                    <td>
                        <?php if ($role === 'owner'): ?>
                            <span class="storePickSummary">全店舗</span>
                        <?php else: ?>
                            <div>
                                <?php foreach ($tenantStores as $s): ?>
                                    <?php if (in_array((int)$s['id'], $assigned, true)): ?>
                                        <span class="storeTag"><?= h((string)$s['name']) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (!$assigned): ?>
                                    <span class="storePickSummary">未設定</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?= h((string)$a['status']) ?></td>
                    <td><?= h((string)$a['created_at']) ?></td>
                    <td class="actions">
                        <form method="post" style="margin:0 0 6px 0;" class="row inline">
                            <input type="hidden" name="action" value="role_update">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <select name="role">
                                <option value="owner" <?= ((string)($a['role'] ?? '') === 'owner') ? 'selected' : '' ?>>owner</option>
                                <option value="manager" <?= ((string)($a['role'] ?? '') === 'manager') ? 'selected' : '' ?>>manager</option>
                            </select>
                            <button type="submit">権限変更</button>
                        </form>
                        <?php if ($role === 'manager'): ?>
                        <form method="post" style="margin:0 0 6px 0;" class="row inline">
                            <input type="hidden" name="action" value="update_stores">
                            <input type="hidden" name="id" value="<?= (int)$aid ?>">
                            <div class="storeHidden">
                                <?php foreach ($assigned as $sid): ?>
                                    <input type="hidden" name="store_ids[]" value="<?= (int)$sid ?>">
                                <?php endforeach; ?>
                            </div>
                            <button class="storePickBtn" type="button"
                                data-tenant-id="<?= (int)$a['tenant_id'] ?>"
                                data-store-ids="<?= h(implode(',', $assigned)) ?>"
                                data-admin-id="<?= (int)$aid ?>">店舗を選ぶ</button>
                            <span class="storePickSummary">選択: <?= count($assigned) ?> 店舗</span>
                            <button type="submit">店舗更新</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="margin:0;" class="row inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="submit"><?= ((string)$a['status'] === 'active') ? '無効化' : '有効化' ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="modalMask" id="storePickerModal" aria-hidden="true">
        <div class="modalPanel" role="dialog" aria-modal="true">
            <div class="modalHead">
                <div class="modalTitle">担当店舗を選択</div>
                <button type="button" id="storePickerClose">閉じる</button>
            </div>
            <div class="modalList" id="storePickerList"></div>
            <div class="modalActions">
                <button type="button" id="storePickerCancel">キャンセル</button>
                <button type="button" id="storePickerApply">この内容で反映</button>
            </div>
        </div>
    </div>

    <script>
    (() => {
        const storeMap = <?= json_encode($storesByTenant, JSON_UNESCAPED_UNICODE) ?>;
        const modal = document.getElementById('storePickerModal');
        const list = document.getElementById('storePickerList');
        const applyBtn = document.getElementById('storePickerApply');
        const closeBtn = document.getElementById('storePickerClose');
        const cancelBtn = document.getElementById('storePickerCancel');
        let currentForm = null;
        let currentTenantId = null;

        const openModal = (form, tenantId, ids) => {
            currentForm = form;
            currentTenantId = tenantId;
            list.innerHTML = '';
            const stores = storeMap[tenantId] || [];
            const set = new Set(ids);
            stores.forEach((s) => {
                const label = document.createElement('label');
                label.className = 'modalItem';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = String(s.id);
                cb.checked = set.has(String(s.id));
                const span = document.createElement('span');
                span.textContent = s.name;
                label.appendChild(cb);
                label.appendChild(span);
                list.appendChild(label);
            });
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        };

        const closeModal = () => {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            currentForm = null;
            currentTenantId = null;
        };

        document.querySelectorAll('.storePickBtn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const form = btn.closest('form');
                const tenantId = btn.dataset.tenantId || '';
                const ids = (btn.dataset.storeIds || '').split(',').filter(Boolean);
                openModal(form, tenantId, ids);
            });
        });

        const applySelection = () => {
            if (!currentForm || !currentTenantId) return;
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
