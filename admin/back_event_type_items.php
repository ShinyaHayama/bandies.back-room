<?php

declare(strict_types=1);

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
$paths = [__DIR__ . '/../api/lib/db.php', __DIR__ . '/../lib/db.php'];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if (!$dbFile) {
    http_response_code(500);
    echo 'db.php not found';
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// CSRF
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

function mustPostCsrf(string $csrf): void
{
    $t = (string)($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals($csrf, $t)) {
        http_response_code(400);
        echo 'CSRF invalid';
        exit;
    }
}

function ensureTypeItemsTable(PDO $pdo): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS back_event_type_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            store_id INT NOT NULL,
            type_id INT NOT NULL,
            label VARCHAR(80) NOT NULL,
            amount_yen INT NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tenant_store_type (tenant_id, store_id, type_id),
            INDEX idx_tenant_store (tenant_id, store_id)
        )
    ";
    $pdo->exec($sql);
}

try {
    ensureTypeItemsTable($pdo);
} catch (Throwable $e) {
    // 握りつぶし
}

// =====================
// ✅ store / type
// =====================
$storeId = (int)($_GET['store_id'] ?? 0);
$typeId = (int)($_GET['type_id'] ?? 0);

$stStores = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=? ORDER BY id");
$stStores->execute([$tenantId]);
$stores = $stStores->fetchAll();

if (empty($stores)) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "stores に店舗がありません。先に店舗を作成してください。";
    exit;
}

if ($storeId <= 0) $storeId = (int)$stores[0]['id'];
$storeIds = array_map('intval', array_column($stores, 'id'));
if (!in_array($storeId, $storeIds, true)) $storeId = (int)$stores[0]['id'];

// back_event_types
$stTypes = $pdo->prepare("
    SELECT id, type_key, label
    FROM back_event_types
    WHERE tenant_id=? AND store_id=?
    ORDER BY sort_order ASC, id ASC
");
$stTypes->execute([$tenantId, $storeId]);
$types = $stTypes->fetchAll();

if ($typeId <= 0 && !empty($types)) $typeId = (int)$types[0]['id'];

$typeLabel = '';
foreach ($types as $t) {
    if ((int)$t['id'] === $typeId) {
        $typeLabel = (string)$t['label'];
        break;
    }
}

$err = '';
$ok = '';

// =====================
// ✅ POST
// =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mustPostCsrf($csrf);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $label = trim((string)($_POST['item_label'] ?? ''));
        $amount = (int)($_POST['amount_yen'] ?? 0);
        $sort = (int)($_POST['sort_order'] ?? 0);
        $active = ((string)($_POST['is_active'] ?? '1') === '1') ? 1 : 0;

        if ($typeId <= 0) $err = '項目が不正です';
        elseif ($label === '') $err = '種別名を入力してください';
        elseif (strlen($label) > 80) $err = '種別名が長すぎます';
        elseif ($amount < 0) $err = '金額は0以上で入力してください';
        else {
            $stIns = $pdo->prepare("
                INSERT INTO back_event_type_items
                    (tenant_id, store_id, type_id, label, amount_yen, sort_order, is_active)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?)
            ");
            $stIns->execute([$tenantId, $storeId, $typeId, $label, $amount, $sort, $active]);
            $ok = '種別を追加しました';
        }
    }

    if ($action === 'update') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $label = trim((string)($_POST['item_label'] ?? ''));
        $amount = (int)($_POST['amount_yen'] ?? 0);
        $sort = (int)($_POST['sort_order'] ?? 0);
        $active = ((string)($_POST['is_active'] ?? '1') === '1') ? 1 : 0;

        if ($itemId <= 0) $err = '種別IDが不正です';
        elseif ($typeId <= 0) $err = '項目が不正です';
        elseif ($label === '') $err = '種別名を入力してください';
        elseif (strlen($label) > 80) $err = '種別名が長すぎます';
        elseif ($amount < 0) $err = '金額は0以上で入力してください';
        else {
            $stUpd = $pdo->prepare("
                UPDATE back_event_type_items
                SET label=?, amount_yen=?, sort_order=?, is_active=?
                WHERE id=? AND tenant_id=? AND store_id=? AND type_id=?
                LIMIT 1
            ");
            $stUpd->execute([$label, $amount, $sort, $active, $itemId, $tenantId, $storeId, $typeId]);
            $ok = '種別を更新しました';
        }
    }

    if ($action === 'delete') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId <= 0 || $typeId <= 0) {
            $err = '種別IDが不正です';
        } else {
            $stDel = $pdo->prepare("
                DELETE FROM back_event_type_items
                WHERE id=? AND tenant_id=? AND store_id=? AND type_id=?
                LIMIT 1
            ");
            $stDel->execute([$itemId, $tenantId, $storeId, $typeId]);
            $ok = '種別を削除しました';
        }
    }
}

// list
$typeItems = [];
if ($typeId > 0) {
    $stItems = $pdo->prepare("
        SELECT id, label, amount_yen, sort_order, is_active
        FROM back_event_type_items
        WHERE tenant_id=? AND store_id=? AND type_id=?
        ORDER BY is_active DESC, sort_order ASC, id ASC
    ");
    $stItems->execute([$tenantId, $storeId, $typeId]);
    $typeItems = $stItems->fetchAll();
}

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>種別編集</title>
    <style>
    body {
        font-family: system-ui, -apple-system, sans-serif;
        margin: 0;
        background: #f7f7f7;
        color: #111;
    }

    .wrap {
        max-width: 980px;
        margin: 20px auto;
        padding: 16px;
    }

    .card {
        background: #fff;
        border: 1px solid #e5e5e5;
        padding: 14px;
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    select,
    input {
        padding: 10px 12px;
        border: 1px solid #ddd;
        background: #fff;
    }

    .btn {
        padding: 10px 14px;
        border: 1px solid #111;
        background: #fff;
        font-weight: 800;
        cursor: pointer;
        text-decoration: none;
        color: #111;
    }

    .btnPrimary {
        background: #111;
        color: #fff;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        margin-top: 10px;
    }

    th,
    td {
        padding: 10px 8px;
        border-bottom: 1px solid #eee;
        text-align: left;
        vertical-align: top;
    }

    .small {
        font-size: 12px;
        color: #666;
    }

    .err {
        background: #fff0f0;
        border: 1px solid #ffb3b3;
        padding: 10px 12px;
        margin-bottom: 10px;
    }

    .ok {
        background: #f0fff4;
        border: 1px solid #9ae6b4;
        padding: 10px 12px;
        margin-bottom: 10px;
    }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card">
            <div class="row" style="justify-content:space-between;">
                <div style="font-weight:900;">種別編集（<?= h($typeLabel) ?>）</div>
                <a class="btn" href="/admin/back_event_types.php?store_id=<?= (int)$storeId ?>">戻る</a>
            </div>

            <?php if ($err !== ''): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
            <?php if ($ok !== ''): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>

            <div style="font-weight:900;margin:12px 0 6px;">種別（項目＋金額）</div>
            <form method="post" class="row">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <input name="item_label" placeholder="種別名（例：ショット / シャンパン など）" style="min-width:220px;">
                <input name="amount_yen" type="number" min="0" style="width:120px;" placeholder="金額">
                <input name="sort_order" type="number" style="width:90px;" placeholder="並び順">
                <select name="is_active">
                    <option value="1" selected>有効</option>
                    <option value="0">無効</option>
                </select>
                <button class="btn btnPrimary" type="submit">追加</button>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>種別名</th>
                        <th>金額</th>
                        <th>並び順</th>
                        <th>有効</th>
                        <th style="width:180px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($typeItems)): ?>
                    <tr>
                        <td colspan="5" class="small">まだ種別がありません</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($typeItems as $it): ?>
                    <tr>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                            <td>
                                <input name="item_label" value="<?= h((string)$it['label']) ?>" style="width:100%;">
                            </td>
                            <td>
                                <input name="amount_yen" type="number" min="0" value="<?= (int)$it['amount_yen'] ?>"
                                    style="width:120px;">
                            </td>
                            <td>
                                <input name="sort_order" type="number" value="<?= (int)$it['sort_order'] ?>"
                                    style="width:90px;">
                            </td>
                            <td>
                                <select name="is_active">
                                    <option value="1" <?= ((int)$it['is_active'] === 1 ? 'selected' : '') ?>>有効</option>
                                    <option value="0" <?= ((int)$it['is_active'] === 0 ? 'selected' : '') ?>>無効</option>
                                </select>
                            </td>
                            <td>
                                <button class="btn btnPrimary" type="submit">更新</button>
                                <button class="btn" type="submit" name="action" value="delete"
                                    onclick="return confirm('この種別を削除しますか？');">削除</button>
                            </td>
                        </form>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </div>
    </div>
</body>

</html>
