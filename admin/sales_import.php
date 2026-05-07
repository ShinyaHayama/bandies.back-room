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
    exit('db.php not found');
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

// ===== stores =====
$stores = [];
try {
    $st = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:t ORDER BY id ASC");
    $st->execute([':t' => $tenantId]);
    $stores = $st->fetchAll();
} catch (Throwable $e) {
    $stores = [];
}
$storeIds = array_map(fn($r) => (int)$r['id'], $stores);

$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) {
    $storeId = (int)($stores[0]['id'] ?? 0);
}

$defaultSource = (string)($_GET['source'] ?? 'generic');
$allowedSources = ['airregi', 'square', 'smaregi', 'stores', 'freee', 'yayoi', 'generic'];
if (!in_array($defaultSource, $allowedSources, true)) $defaultSource = 'generic';

$defaultMode = (string)($_GET['mode'] ?? 'overwrite');
$allowedModes = ['overwrite', 'add', 'skip'];
if (!in_array($defaultMode, $allowedModes, true)) $defaultMode = 'overwrite';
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>売上CSV取り込み</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif;
            background: #f7f7f7;
            color: #111827;
        }
        .wrap { max-width: 900px; margin: 0 auto; padding: 16px; }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
        }
        h1 { margin: 0 0 6px; font-size: 20px; }
        .muted { color: #6b7280; font-size: 12px; }
        .step {
            margin-top: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
        }
        .stepTitle { font-size: 14px; font-weight: 800; margin-bottom: 8px; }
        .row { display: grid; gap: 10px; }
        .grid2 { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        label { font-size: 12px; font-weight: 700; color: #374151; display: block; margin-bottom: 6px; }
        select, input[type="text"], input[type="file"] {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #111827;
            background: #111827;
            color: #fff;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }
        .btnGhost {
            background: #fff;
            color: #111827;
        }
        .noteBox {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 12px;
            color: #374151;
        }
        @media (max-width: 720px) {
            .grid2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php @include __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card">
            <h1>売上CSV取り込み</h1>
            <div class="muted">外部POS/会計/ECのCSVを取り込み、KPI（売上・客数・人件費率）に反映します。</div>

            <form method="post" action="/admin/sales_import_parse.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                <div class="step">
                    <div class="stepTitle">Step 1：ファイルを選択</div>
                    <div class="row">
                        <div>
                            <label>店舗</label>
                            <select name="store_id" required>
                                <?php foreach ($stores as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $storeId) ? 'selected' : '' ?>>
                                        <?= h((string)$s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>CSVファイル</label>
                            <input type="file" name="csv_file" accept=".csv,text/csv" required>
                        </div>
                        <div class="noteBox">
                            文字コード・区切り文字は自動判定します（UTF-8 / Shift_JIS、カンマ/タブ等）。
                        </div>
                    </div>
                </div>

                <div class="step">
                    <div class="stepTitle">Step 2：取り込みタイプ</div>
                    <div class="grid2">
                        <div>
                            <label>サービステンプレ</label>
                            <select name="source" required>
                                <option value="airregi" <?= $defaultSource === 'airregi' ? 'selected' : '' ?>>Airレジ</option>
                                <option value="square" <?= $defaultSource === 'square' ? 'selected' : '' ?>>Square</option>
                                <option value="smaregi" <?= $defaultSource === 'smaregi' ? 'selected' : '' ?>>スマレジ</option>
                                <option value="stores" <?= $defaultSource === 'stores' ? 'selected' : '' ?>>STORES</option>
                                <option value="freee" <?= $defaultSource === 'freee' ? 'selected' : '' ?>>freee会計</option>
                                <option value="yayoi" <?= $defaultSource === 'yayoi' ? 'selected' : '' ?>>弥生会計</option>
                                <option value="generic" <?= $defaultSource === 'generic' ? 'selected' : '' ?>>汎用CSV</option>
                            </select>
                        </div>
                        <div>
                            <label>取り込み方針</label>
                            <select name="mode" required>
                                <option value="overwrite" <?= $defaultMode === 'overwrite' ? 'selected' : '' ?>>上書き（同日付を置換）</option>
                                <option value="add" <?= $defaultMode === 'add' ? 'selected' : '' ?>>加算（同日付に足し込む）</option>
                                <option value="skip" <?= $defaultMode === 'skip' ? 'selected' : '' ?>>スキップ（既存を残す）</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="step">
                    <div class="stepTitle">Step 3：プレビュー & マッピング</div>
                    <div class="noteBox">
                        次の画面で先頭20行のプレビューを表示し、必須列（日時・売上・客数など）をマッピングします。
                    </div>
                </div>

                <div class="step">
                    <div class="stepTitle">Step 4：確定</div>
                    <div class="noteBox">
                        取り込み件数・日付範囲・重複件数・警告を確認後、確定します。
                    </div>
                </div>

                <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button class="btn" type="submit">プレビューへ進む</button>
                    <a class="btn btnGhost" href="/admin/index.php?store_id=<?= (int)$storeId ?>">戻る</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
