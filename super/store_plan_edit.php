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
        // no-op
    }
}

function ensureBackEnabledColumn(PDO $pdo): void
{
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'stores'
          AND COLUMN_NAME = 'back_enabled'
        LIMIT 1
    ");
    $st->execute();
    $has = (bool)$st->fetchColumn();
    if ($has) return;
    $pdo->exec("ALTER TABLE stores ADD COLUMN back_enabled TINYINT(1) NOT NULL DEFAULT 1");
}

$err = null;
try {
    ensureBackEnabledColumn($pdo);
} catch (Throwable $e) {
    $err = 'store 設定列の作成に失敗しました: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'toggle_back') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        $enabled = (int)($_POST['back_enabled'] ?? 0) === 1 ? 1 : 0;
        if ($storeId <= 0) {
            $err = 'store_id が不正です';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE stores
                       SET back_enabled = :enabled
                     WHERE id = :store_id
                     LIMIT 1
                ");
                $stmt->execute([
                    ':enabled' => $enabled,
                    ':store_id' => $storeId,
                ]);
                audit($pdo, $meId, 'store.back_enabled', [
                    'store_id' => $storeId,
                    'back_enabled' => $enabled,
                ]);
                header('Location: /super/store_plan_edit.php?' . http_build_query($_GET));
                exit;
            } catch (Throwable $e) {
                $err = '更新に失敗しました: ' . $e->getMessage();
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
    $where = "WHERE CAST(s.tenant_id AS CHAR) LIKE :q
              OR t.name LIKE :q
              OR CAST(s.id AS CHAR) LIKE :q
              OR s.name LIKE :q";
    $params[':q'] = '%' . $q . '%';
}

$cntStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM stores s
    JOIN tenants t ON t.id = s.tenant_id
    {$where}
");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$listStmt = $pdo->prepare("
    SELECT s.id, s.name, s.tenant_id, t.name AS tenant_name,
           COALESCE(s.back_enabled, 1) AS back_enabled,
           (
               SELECT COUNT(*)
               FROM employees e
               WHERE e.tenant_id = s.tenant_id
                 AND e.store_id = s.id
                 AND e.employment_status = 'active'
           ) AS employee_count
    FROM stores s
    JOIN tenants t ON t.id = s.tenant_id
    {$where}
    ORDER BY s.tenant_id ASC, s.id ASC
    LIMIT :lim OFFSET :ofs
");
foreach ($params as $k => $v) $listStmt->bindValue($k, $v, PDO::PARAM_STR);
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':ofs', $offset, PDO::PARAM_INT);
$listStmt->execute();
$stores = $listStmt->fetchAll();
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>店舗別：バック機能</title>
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
            max-width: 1200px;
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
        <h2 style="margin:0 0 6px;">店舗別：バック機能</h2>
        <div style="font-size:12px;color:#666;">店舗単位でバック機能の表示/非表示を切り替えます。</div>
        <div style="font-size:12px;color:#666;margin-top:4px;">料金目安: 月額4000円〜（税別）</div>

        <?php if ($err): ?>
            <div class="err"><?= h($err) ?></div>
        <?php endif; ?>

        <form method="get" class="row" style="margin-top:10px;">
            <input name="q" placeholder="検索: tenant_id / tenant_name / store_id / store_name" value="<?= h($q) ?>"
                style="min-width:320px;">
            <button type="submit">検索</button>
            <?php if ($q !== ''): ?>
                <a href="/super/store_plan_edit.php">クリア</a>
            <?php endif; ?>
        </form>

        <table>
            <thead>
                <tr>
                    <th style="width:90px;">店舗ID</th>
                    <th style="width:140px;">テナントID</th>
                    <th>テナント名</th>
                    <th>店舗名</th>
                    <th style="width:120px;">従業員数</th>
                    <th style="width:140px;">バック機能</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stores as $s): ?>
                    <tr>
                        <td><?= (int)$s['id'] ?></td>
                        <td><?= (int)$s['tenant_id'] ?></td>
                        <td><?= h((string)$s['tenant_name']) ?></td>
                        <td><?= h((string)$s['name']) ?></td>
                        <td><?= number_format((int)$s['employee_count']) ?></td>
                        <td>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="toggle_back">
                                <input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>">
                                <?php if ((int)$s['back_enabled'] === 1): ?>
                                    <input type="hidden" name="back_enabled" value="0">
                                    <button type="submit">ON → OFF</button>
                                <?php else: ?>
                                    <input type="hidden" name="back_enabled" value="1">
                                    <button type="submit">OFF → ON</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="row" style="margin-top:10px;">
            <?php if ($page > 1): ?>
                <a href="/super/store_plan_edit.php?page=<?= $page - 1 ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>">←
                    前</a>
            <?php endif; ?>
            <span>全 <?= (int)$total ?> 件 / <?= (int)$page ?> / <?= (int)$totalPages ?> ページ</span>
            <?php if ($page < $totalPages): ?>
                <a href="/super/store_plan_edit.php?page=<?= $page + 1 ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>">次
                    →</a>
            <?php endif; ?>
        </div>
    </div>

</body>

</html>
