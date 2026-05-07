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

function makeTypeKey32(string $label): string
{
    $s = strtolower($label);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    $s = trim($s ?? '', '_');
    if ($s === '') $s = 'custom';

    $key = 'custom_' . $s;
    $key = substr($key, 0, 32);
    $key = rtrim($key, '_');
    if ($key === 'custom') $key = 'custom_x';
    return $key;
}

function activeCount(PDO $pdo, int $tenantId, int $storeId): int
{
    $st = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM back_event_types
        WHERE tenant_id=? AND store_id=? AND is_active=1
    ");
    $st->execute([$tenantId, $storeId]);
    return (int)($st->fetch()['c'] ?? 0);
}

// =====================
// ✅ store
// =====================
$storeId = (int)($_GET['store_id'] ?? 0);
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

$err = '';
$ok = '';

try {
    ensureTypeItemsTable($pdo);
} catch (Throwable $e) {
    // 握りつぶし
}

// =====================
// ✅ POST
// =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mustPostCsrf($csrf);
    $label = trim((string)($_POST['type_label'] ?? ''));
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = ((string)($_POST['is_active'] ?? '1') === '1') ? 1 : 0;

    $itemLabel = trim((string)($_POST['item_label'] ?? ''));
    $itemAmount = (int)($_POST['amount_yen'] ?? 0);
    $itemSort = (int)($_POST['item_sort_order'] ?? 0);
    $itemActive = ((string)($_POST['item_is_active'] ?? '1') === '1') ? 1 : 0;

    if ($label === '') $err = '項目名を入力してください';
    elseif (strlen($label) > 60) $err = '項目名が長すぎます（短くしてください）';
    elseif ($itemLabel === '') $err = '種別名を入力してください';
    elseif (strlen($itemLabel) > 80) $err = '種別名が長すぎます';
    elseif ($itemAmount < 0) $err = '金額は0以上で入力してください';
    else {
        $MAX_ACTIVE = 8;
        if ($active === 1) {
            $activeNow = activeCount($pdo, $tenantId, $storeId);
            if ($activeNow >= $MAX_ACTIVE) $err = "項目は最大{$MAX_ACTIVE}個までです";
        }
    }

    if ($err === '') {
        $pdo->beginTransaction();
        try {
            $baseKey = makeTypeKey32($label);
            $key = $baseKey;
            $try = 0;
            while (true) {
                $candidate = $key;
                if ($try > 0) {
                    $suffix = '_' . $try;
                    $candidate = substr($baseKey, 0, max(1, 32 - strlen($suffix))) . $suffix;
                    $candidate = rtrim($candidate, '_');
                }
                $stDup = $pdo->prepare("
                    SELECT id FROM back_event_types
                    WHERE tenant_id=? AND store_id=? AND type_key=?
                    LIMIT 1
                ");
                $stDup->execute([$tenantId, $storeId, $candidate]);
                if (!$stDup->fetch()) {
                    $key = $candidate;
                    break;
                }
                $try++;
                if ($try > 50) {
                    throw new RuntimeException('内部キー生成に失敗しました');
                }
            }

            $stIns = $pdo->prepare("
                INSERT INTO back_event_types
                    (tenant_id, store_id, type_key, label, sort_order, is_active)
                VALUES
                    (?, ?, ?, ?, ?, ?)
            ");
            $stIns->execute([$tenantId, $storeId, $key, $label, $sort, $active]);
            $typeId = (int)$pdo->lastInsertId();

            $stItem = $pdo->prepare("
                INSERT INTO back_event_type_items
                    (tenant_id, store_id, type_id, label, amount_yen, sort_order, is_active)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?)
            ");
            $stItem->execute([$tenantId, $storeId, $typeId, $itemLabel, $itemAmount, $itemSort, $itemActive]);

            $pdo->commit();
            $ok = '項目と種別を追加しました';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = $e->getMessage();
        }
    }
}

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>項目と種別の新規追加</title>
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
        border: 1px solid #e5e7eb;
        background: #fff;
        font-weight: 800;
        cursor: pointer;
        text-decoration: none;
        color: #111;
        border-radius: 999px;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
    }

    .btnPrimary {
        background: #111;
        color: #fff;
        border-color: rgba(0, 0, 0, .14);
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
                <div style="font-weight:900;">項目と種別の新規追加</div>
                <a class="btn" href="/admin/back_event_types.php?store_id=<?= (int)$storeId ?>">戻る</a>
            </div>

            <?php if ($err !== ''): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
            <?php if ($ok !== ''): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>

            <form method="post" class="row" style="margin-top:12px;">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

                <input name="type_label" placeholder="項目名（例：指名 / ボトル など）" style="min-width:220px;">
                <input name="sort_order" type="number" value="" style="width:90px;" placeholder="並び順">
                <select name="is_active">
                    <option value="1" selected>有効</option>
                    <option value="0">無効</option>
                </select>

                <span style="opacity:.6;">/</span>

                <input name="item_label" placeholder="種別名（例：ショット / シャンパン など）" style="min-width:220px;">
                <input name="amount_yen" type="number" min="0" value="" style="width:120px;" placeholder="金額">
                <input name="item_sort_order" type="number" value="" style="width:90px;" placeholder="並び順">
                <select name="item_is_active">
                    <option value="1" selected>有効</option>
                    <option value="0">無効</option>
                </select>

                <button class="btn btnPrimary" type="submit">追加</button>
            </form>
        </div>
    </div>
</body>

</html>
