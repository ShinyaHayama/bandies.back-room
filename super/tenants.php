<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_super_admin_login();
require_once __DIR__ . '/_db.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$meId = (int)($_SESSION['super_admin_user_id'] ?? 0);

// ===== 監査ログ =====
function audit(PDO $pdo, int $meId, string $action, array $payload = []): void
{
    if ($meId <= 0) return;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO super_audit_logs (super_admin_user_id, action, payload)
            VALUES (:uid, :action, :payload)
        ");
        $stmt->execute([
            ':uid' => $meId,
            ':action' => $action,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // 監査ログが死んでも main は継続
    }
}

$err = null;

function ensureBillingStatusColumn(PDO $pdo): void
{
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tenants'
          AND COLUMN_NAME = 'billing_status'
        LIMIT 1
    ");
    $st->execute();
    $has = (bool)$st->fetchColumn();
    if ($has) return;
    $pdo->exec("ALTER TABLE tenants ADD COLUMN billing_status VARCHAR(20) NOT NULL DEFAULT 'active'");
    $pdo->exec("UPDATE tenants SET billing_status = 'active' WHERE billing_status IS NULL OR billing_status = ''");
}

try {
    ensureBillingStatusColumn($pdo);
} catch (Throwable $e) {
    $err = 'billing_status 列の作成に失敗しました: ' . $e->getMessage();
}

function ensureDeletedAtColumn(PDO $pdo): void
{
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tenants'
          AND COLUMN_NAME = 'deleted_at'
        LIMIT 1
    ");
    $st->execute();
    $has = (bool)$st->fetchColumn();
    if ($has) return;
    $pdo->exec("ALTER TABLE tenants ADD COLUMN deleted_at DATETIME NULL");
}

try {
    ensureDeletedAtColumn($pdo);
} catch (Throwable $e) {
    $err = $err ?: ('deleted_at 列の作成に失敗しました: ' . $e->getMessage());
}

function ensureTrialColumns(PDO $pdo): void
{
    $cols = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('trial_started_at', $cols, true)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN trial_started_at DATETIME NULL");
    }
    if (!in_array('trial_ends_at', $cols, true)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN trial_ends_at DATETIME NULL");
    }
}

try {
    ensureTrialColumns($pdo);
} catch (Throwable $e) {
    $err = $err ?: ('trial列の作成に失敗しました: ' . $e->getMessage());
}

// ===== 作成処理 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $tz   = trim((string)($_POST['timezone'] ?? 'Asia/Tokyo'));
        if ($tz === '') $tz = 'Asia/Tokyo';

        if ($name === '') {
            $err = 'テナント名は必須です';
        } else {
            try {
                $pdo->beginTransaction();

                $now = date('Y-m-d H:i:s');
                $trialEnd = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);
                $cols = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN, 0);
                $hasTrial = in_array('trial_started_at', $cols, true) && in_array('trial_ends_at', $cols, true);

                // 1) tenant 作成
                if ($hasTrial) {
                    $stmt = $pdo->prepare("
                        INSERT INTO tenants(name, timezone, trial_started_at, trial_ends_at)
                        VALUES(:name, :tz, :trial_started_at, :trial_ends_at)
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':tz' => $tz,
                        ':trial_started_at' => $now,
                        ':trial_ends_at' => $trialEnd,
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO tenants(name, timezone) VALUES(:name, :tz)");
                    $stmt->execute([':name' => $name, ':tz' => $tz]);
                }
                $newId = (int)$pdo->lastInsertId();

                audit($pdo, $meId, 'tenant.create', [
                    'tenant_id' => $newId,
                    'name' => $name,
                    'timezone' => $tz,
                ]);

                // 2) ✅ デフォルト store を 1件作成（ここが今回の本題）
                // stores の必須列が tenant_id / name である前提の最小INSERT
                $defaultStoreName = '本店';
                $s = $pdo->prepare("INSERT INTO stores(tenant_id, name) VALUES(:tid, :name)");
                $s->execute([':tid' => $newId, ':name' => $defaultStoreName]);

                audit($pdo, $meId, 'store.create.default', [
                    'tenant_id' => $newId,
                    'name' => $defaultStoreName
                ]);

                $pdo->commit();

                header('Location: /super/tenants.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = '作成に失敗しました: ' . $e->getMessage();
            }
        }
    }

    if ($act === 'toggle_billing') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $err = 'id が不正です';
        } else {
            try {
                $cur = $pdo->prepare("SELECT billing_status FROM tenants WHERE id=:id");
                $cur->execute([':id' => $id]);
                $st = (string)($cur->fetch()['billing_status'] ?? 'active');
                $next = ($st === 'active') ? 'inactive' : 'active';

                $u = $pdo->prepare("UPDATE tenants SET billing_status=:st WHERE id=:id");
                $u->execute([':st' => $next, ':id' => $id]);

                audit($pdo, $meId, 'tenant.billing_status', ['id' => $id, 'to' => $next]);

                header('Location: /super/tenants.php');
                exit;
            } catch (Throwable $e) {
                $err = 'ステータス更新に失敗しました: ' . $e->getMessage();
            }
        }
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $err = 'id が不正です';
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare("UPDATE tenants SET billing_status='deleted', deleted_at=NOW() WHERE id=:id")
                    ->execute([':id' => $id]);

                // 管理者ユーザーと関連付けを削除
                $pdo->prepare("DELETE FROM tenant_admin_store_permissions WHERE tenant_id=:tid")
                    ->execute([':tid' => $id]);
                $pdo->prepare("DELETE FROM tenant_admin_users WHERE tenant_id=:tid")
                    ->execute([':tid' => $id]);

                audit($pdo, $meId, 'tenant.delete', ['id' => $id]);

                $pdo->commit();

                header('Location: /super/tenants.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = '削除に失敗しました: ' . $e->getMessage();
            }
        }
    }
}

// ===== 一覧取得（検索 + ページング）=====
$q = trim((string)($_GET['q'] ?? ''));
$limit = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where = "";
$params = [];
if ($q !== '') {
    $where = "WHERE CAST(id AS CHAR) LIKE :q OR name LIKE :q";
    $params[':q'] = '%' . $q . '%';
}

$hasDeletedAt = false;
try {
    $st = $pdo->prepare("
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tenants'
          AND COLUMN_NAME = 'deleted_at'
        LIMIT 1
    ");
    $st->execute();
    $hasDeletedAt = (bool)$st->fetchColumn();
} catch (Throwable $e) {
    $hasDeletedAt = false;
}
if ($hasDeletedAt) {
    $where = ($where === '') ? "WHERE deleted_at IS NULL" : ($where . " AND deleted_at IS NULL");
}

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM tenants {$where}");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$listStmt = $pdo->prepare("
    SELECT id, name, timezone, created_at, updated_at,
           COALESCE(billing_status, 'active') AS billing_status,
           (
               SELECT email
               FROM tenant_admin_users u
               WHERE u.tenant_id = tenants.id
               ORDER BY u.id ASC
               LIMIT 1
           ) AS admin_email,
           (
               SELECT COUNT(*)
               FROM employees e
               WHERE e.tenant_id = tenants.id
                 AND e.employment_status = 'active'
           ) AS employee_count
    FROM tenants
    {$where}
    ORDER BY id DESC
    LIMIT :lim OFFSET :ofs
");
foreach ($params as $k => $v) $listStmt->bindValue($k, $v, PDO::PARAM_STR);
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':ofs', $offset, PDO::PARAM_INT);
$listStmt->execute();
$tenants = $listStmt->fetchAll();
$employeeTotal = 0;
foreach ($tenants as $t) {
    $employeeTotal += (int)($t['employee_count'] ?? 0);
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tenants</title>
    <style>
    body {
        font-family: system-ui;
        padding: 18px;
        color: #111;
    }

    .card {
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 14px;
        width: 100%;
        max-width: none;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    th,
    td {
        border-bottom: 1px solid #eee;
        padding: 8px;
        font-size: 13px;
        text-align: left;
    }

    th {
        background: #fafafa;
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    input,
    button {
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 10px;
    }

    button {
        cursor: pointer;
    }

    a {
        color: #111;
    }

    .err {
        background: #ffecec;
        border: 1px solid #ffb3b3;
        padding: 10px;
        border-radius: 10px;
        margin: 10px 0;
    }
    </style>
</head>

<body>
    <?php require __DIR__ . '/_top.php'; ?>

    <div class="card">
        <h2 style="margin:0 0 10px;">テナント一覧</h2>

        <?php if ($err): ?>
        <div class="err"><?= h($err) ?></div>
        <?php endif; ?>

        <form method="post" class="row">
            <input type="hidden" name="action" value="create">
            <input name="name" placeholder="テナント名" required style="min-width:260px;">
            <input name="timezone" placeholder="Asia/Tokyo" value="Asia/Tokyo" style="min-width:200px;">
            <button type="submit">作成</button>
        </form>

        <form method="get" class="row" style="margin-top:10px;">
            <input name="q" placeholder="検索: tenant_id / name" value="<?= h($q) ?>" style="min-width:260px;">
            <button type="submit">検索</button>
            <?php if ($q !== ''): ?>
                <a href="/super/tenants.php">クリア</a>
            <?php endif; ?>
        </form>

        <div class="row" style="margin-top:10px;">
            <strong>全作業員数合計: <?= number_format($employeeTotal) ?></strong>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:90px;">id</th>
                    <th>name</th>
                    <th>email</th>
                    <th style="width:120px;">従業員数</th>
                    <th style="width:130px;">課金状態</th>
                    <th style="width:140px;">timezone</th>
                    <th style="width:220px;">created</th>
                    <th style="width:220px;">updated</th>
                    <th style="width:280px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $t): ?>
                <tr>
                    <td><?= (int)$t['id'] ?></td>
                    <td><?= h((string)$t['name']) ?></td>
                    <td><?= h((string)($t['admin_email'] ?? '')) ?></td>
                    <td><?= number_format((int)$t['employee_count']) ?></td>
                    <td><?= h((string)$t['billing_status']) ?></td>
                    <td><?= h((string)$t['timezone']) ?></td>
                    <td><?= h((string)$t['created_at']) ?></td>
                    <td><?= h((string)$t['updated_at']) ?></td>
                    <td>
                        <a href="/super/tenant_billing.php?tenant_id=<?= (int)$t['id'] ?>">請求詳細</a>
                        <span style="margin:0 6px;">|</span>
                        <a href="/super/stores.php?tenant_id=<?= (int)$t['id'] ?>">店舗一覧</a>
                        <span style="margin:0 6px;">|</span>
                        <a href="/super/impersonate.php?tenant_id=<?= (int)$t['id'] ?>">なりすまし</a>
                        <span style="margin:0 6px;">|</span>
                        <form method="post" style="display:inline;margin:0;">
                            <input type="hidden" name="action" value="toggle_billing">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button type="submit">
                                <?= ((string)$t['billing_status'] === 'active') ? '課金停止' : '課金再開' ?>
                            </button>
                        </form>
                        <span style="margin:0 6px;">|</span>
                        <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('このテナントを削除します。よろしいですか？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button type="submit">削除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="row" style="margin-top:10px;">
            <?php if ($page > 1): ?>
                <a href="/super/tenants.php?page=<?= $page - 1 ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>">← 前</a>
            <?php endif; ?>
            <span>全 <?= (int)$total ?> 件 / <?= (int)$page ?> / <?= (int)$totalPages ?> ページ</span>
            <?php if ($page < $totalPages): ?>
                <a href="/super/tenants.php?page=<?= $page + 1 ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>">次 →</a>
            <?php endif; ?>
        </div>
    </div>

</body>

</html>
