<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/back_event_types.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 修正点（500対策）
 * - back_event_types の表示名カラムが「label」でも「type_label」でも動くように吸収
 * - type_key(varchar32)に合わせてキーは必ず32文字以内に収める
 * - 初回の3種は「固定」ではなく、初回投入のみ（以後は自由に追加/無効化/表示名変更OK）
 *
 * 目的:
 * - back_events の「項目」を店舗ごとにDB保持で管理（最大8）
 * - 既存データは壊さない（type_keyは基本変更させない）
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

function tableColumns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        foreach ($rows as $r) {
            if (isset($r['Field'])) $cols[(string)$r['Field']] = true;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $cols;
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

$MAX_ACTIVE = 8;
$err = '';
$ok = '';

// =====================
// ✅ 種別（項目＋金額）テーブルを用意
// =====================
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
    // テーブル作成失敗時は後続でエラー表示
}

// =====================
// ✅ back_event_types の表示名カラムを吸収
// - あなたのDBは label
// - 別環境は type_label の可能性
// =====================
$betCols = tableColumns($pdo, 'back_event_types');
if (empty($betCols)) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "back_event_types が見つからないか、参照できません。";
    exit;
}

$labelCol = null;
if (isset($betCols['label'])) $labelCol = 'label';
elseif (isset($betCols['type_label'])) $labelCol = 'type_label';

if ($labelCol === null) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "back_event_types に 表示名カラム（label / type_label）がありません。";
    exit;
}

/**
 * ✅ 初回だけ：3種を自動投入（固定ではない。DB管理対象）
 */
function ensureDefaultTypes(PDO $pdo, int $tenantId, int $storeId, string $labelCol): void
{
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM back_event_types WHERE tenant_id=? AND store_id=?");
    $st->execute([$tenantId, $storeId]);
    $c = (int)($st->fetch()['c'] ?? 0);
    if ($c > 0) return;

    $defaults = [
        ['nomination', '指名', 10],
        ['drink_back', 'ドリンクバック', 20],
        ['escort', '同伴・アフター', 30],
    ];

    $sql = "
        INSERT INTO back_event_types(tenant_id, store_id, type_key, `$labelCol`, sort_order, is_active)
        VALUES(?,?,?,?,?,1)
    ";
    $ins = $pdo->prepare($sql);

    foreach ($defaults as [$k, $label, $sort]) {
        $ins->execute([$tenantId, $storeId, $k, $label, $sort]);
    }
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

/**
 * type_key を 32文字以内にする（DBが varchar(32)）
 * label から英数抽出→ custom_xxx 生成
 */
function makeTypeKey32(string $label): string
{
    $s = strtolower($label);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    $s = trim($s ?? '', '_');
    if ($s === '') $s = 'custom';

    $key = 'custom_' . $s;

    // 32文字制限（末尾が切れてもOK / ただし最後が "_" なら整える）
    $key = substr($key, 0, 32);
    $key = rtrim($key, '_');
    if ($key === 'custom') $key = 'custom_x';

    return $key;
}

// 初期投入（失敗しても画面は生かす）
try {
    ensureDefaultTypes($pdo, $tenantId, $storeId, $labelCol);
} catch (Throwable $e) {
    // 握りつぶし
}

// =====================
// ✅ POST
// =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mustPostCsrf($csrf);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $label = trim((string)($_POST['type_label'] ?? ''));
        if ($label === '') {
            $err = '表示名を入力してください';
        } elseif (strlen($label) > 60) {
            $err = '表示名が長すぎます（短くしてください）';
        } else {
            $active = activeCount($pdo, $tenantId, $storeId);
            if ($active >= $MAX_ACTIVE) {
                $err = "項目は最大{$MAX_ACTIVE}個までです";
            } else {
                $baseKey = makeTypeKey32($label);

                // 重複回避（_1,_2,... を付けるが 32以内に収める）
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
                        $err = '内部キー生成に失敗しました';
                        break;
                    }
                }

                if ($err === '') {
                    $sql = "
                        INSERT INTO back_event_types(tenant_id, store_id, type_key, `$labelCol`, sort_order, is_active)
                        VALUES(?,?,?,?,?,1)
                    ";
                    $stIns = $pdo->prepare($sql);
                    $stIns->execute([$tenantId, $storeId, $key, $label, 100 + $active]);
                    $ok = '追加しました';
                }
            }
        }
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $label = trim((string)($_POST['type_label'] ?? ''));
        $sort = (int)($_POST['sort_order'] ?? 0);
        $active = ((string)($_POST['is_active'] ?? '1') === '1') ? 1 : 0;

        if ($id <= 0) $err = 'id invalid';
        elseif ($label === '') $err = '表示名を入力してください';
        elseif (strlen($label) > 60) $err = '表示名が長すぎます（短くしてください）';
        else {
            if ($active === 1) {
                $stCount = $pdo->prepare("
                    SELECT COUNT(*) AS c
                    FROM back_event_types
                    WHERE tenant_id=? AND store_id=? AND is_active=1 AND id<>?
                ");
                $stCount->execute([$tenantId, $storeId, $id]);
                $c = (int)($stCount->fetch()['c'] ?? 0);
                if ($c >= $MAX_ACTIVE) $err = "項目は最大{$MAX_ACTIVE}個までです";
            }

            if ($err === '') {
                $sql = "
                    UPDATE back_event_types
                    SET `$labelCol`=?, sort_order=?, is_active=?
                    WHERE id=? AND tenant_id=? AND store_id=?
                    LIMIT 1
                ";
                $stUpd = $pdo->prepare($sql);
                $stUpd->execute([$label, $sort, $active, $id, $tenantId, $storeId]);
                $ok = '更新しました';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $err = 'id invalid';
        } else {
            $stType = $pdo->prepare("
                SELECT type_key
                FROM back_event_types
                WHERE id=? AND tenant_id=? AND store_id=?
                LIMIT 1
            ");
            $stType->execute([$id, $tenantId, $storeId]);
            $row = $stType->fetch();

            if (!$row) {
                $err = '削除対象が見つかりません';
            } else {
                $typeKey = (string)$row['type_key'];
                $stRef = $pdo->prepare("
                    SELECT COUNT(*) AS c
                    FROM back_events
                    WHERE tenant_id=? AND store_id=? AND event_type=?
                ");
                $stRef->execute([$tenantId, $storeId, $typeKey]);
                $refCount = (int)($stRef->fetch()['c'] ?? 0);

                if ($refCount > 0) {
                    $err = '使用中のため削除できません';
                } else {
                    $stDel = $pdo->prepare("
                        DELETE FROM back_event_types
                        WHERE id=? AND tenant_id=? AND store_id=?
                        LIMIT 1
                    ");
                    $stDel->execute([$id, $tenantId, $storeId]);
                    $ok = '削除しました';
                }
            }
        }
    }

    if ($action === 'create_type_item') {
        $typeId = (int)($_POST['type_id'] ?? 0);
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

    if ($action === 'update_type_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 0);
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

    if ($action === 'delete_type_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 0);
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

// list（label を type_label として揃えて扱う）
$stList = $pdo->prepare("
    SELECT
      id, tenant_id, store_id, type_key,
      `$labelCol` AS type_label,
      sort_order, is_active
    FROM back_event_types
    WHERE tenant_id=? AND store_id=?
    ORDER BY is_active DESC, sort_order ASC, id ASC
");
$stList->execute([$tenantId, $storeId]);
$types = $stList->fetchAll();

$activeNow = count(array_filter($types, fn($t) => (int)$t['is_active'] === 1));

$selectedTypeId = (int)($_GET['type_id'] ?? 0);
if ($selectedTypeId <= 0 && !empty($types)) {
    $selectedTypeId = (int)$types[0]['id'];
}

$typeItems = [];
if ($selectedTypeId > 0) {
    $stItems = $pdo->prepare("
        SELECT id, type_id, label, amount_yen, sort_order, is_active
        FROM back_event_type_items
        WHERE tenant_id=? AND store_id=? AND type_id=?
        ORDER BY is_active DESC, sort_order ASC, id ASC
    ");
    $stItems->execute([$tenantId, $storeId, $selectedTypeId]);
    $typeItems = $stItems->fetchAll();
}

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>キャッシュバック項目管理</title>
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

    .badge {
        display: inline-block;
        border: 1px solid #ddd;
        padding: 4px 8px;
        font-size: 12px;
        font-weight: 800;
        background: #fff;
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

    .k {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 12px;
        color: #333;
    }

    .actionRow {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: nowrap;
        width: 100%;
        justify-content: flex-start;
    }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card">
            <div class="row" style="justify-content:space-between;">
                <div style="font-weight:900;">キャッシュバック項目管理（店舗ごと）</div>
                <a class="btn" href="/admin/back_events.php?store_id=<?= (int)$storeId ?>">戻る</a>
            </div>

            <?php if ($err !== ''): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
            <?php if ($ok !== ''): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>

            <hr style="border:none;border-top:1px solid #eee;margin:12px 0;">

            <div class="row" style="justify-content:flex-end;align-items:center;margin-bottom:6px;">
                <a class="btn" href="/admin/back_event_type_create.php?store_id=<?= (int)$storeId ?>">新規追加</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:22%;">表示名</th>
                        <th style="width:12%;">並び順</th>
                        <th style="width:10%;">有効</th>
                        <th style="width:56%;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $t): ?>
                    <tr>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <td>
                                <input name="type_label" value="<?= h((string)$t['type_label']) ?>" style="width:100%;">
                            </td>
                            <td>
                                <input name="sort_order" type="number" value="<?= (int)$t['sort_order'] ?>"
                                    style="width:90px;">
                            </td>
                            <td>
                                <select name="is_active">
                                    <option value="1" <?= ((int)$t['is_active'] === 1 ? 'selected' : '') ?>>有効</option>
                                    <option value="0" <?= ((int)$t['is_active'] === 0 ? 'selected' : '') ?>>無効</option>
                                </select>
                            </td>
                            <td>
                                <div class="actionRow">
                                    <button class="btn btnPrimary" type="submit">更新</button>
                                    <a class="btn"
                                        href="/admin/back_event_type_items.php?store_id=<?= (int)$storeId ?>&type_id=<?= (int)$t['id'] ?>">項目追加</a>
                                    <button class="btn" type="submit" name="action" value="delete"
                                        onclick="return confirm('この項目を削除しますか？');">削除</button>
                                </div>
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
