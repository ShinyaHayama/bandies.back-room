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

function is_valid_csrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function normalize_header(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/\s+/', '', $s);
    $s = preg_replace('/[\x{3000}\s]+/u', '', $s);
    $s = preg_replace('/[^a-z0-9\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]+/u', '', $s);
    return $s ?? '';
}

function detect_encoding(string $sample): string
{
    $enc = mb_detect_encoding($sample, ['UTF-8', 'SJIS-win', 'SJIS', 'CP932', 'EUC-JP'], true);
    return $enc ?: 'UTF-8';
}

function detect_delimiter(array $lines, string $encoding): string
{
    $candidates = [',', "\t", ';'];
    $scores = [];
    foreach ($candidates as $delim) {
        $counts = [];
        foreach ($lines as $line) {
            $lineUtf8 = ($encoding === 'UTF-8') ? $line : mb_convert_encoding($line, 'UTF-8', $encoding);
            $cols = str_getcsv($lineUtf8, $delim);
            $counts[] = count($cols);
        }
        $scores[$delim] = array_sum($counts) / max(count($counts), 1);
    }
    arsort($scores);
    $best = array_key_first($scores);
    return $best ?: ',';
}

function read_preview(string $path, string $encoding, string $delimiter, int $limit = 20): array
{
    $rows = [];
    $header = [];
    $fh = fopen($path, 'rb');
    if ($fh === false) return [$header, $rows];

    $lineIndex = 0;
    while (($cols = fgetcsv($fh, 0, $delimiter)) !== false) {
        if ($lineIndex === 0 && isset($cols[0])) {
            $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cols[0]);
        }
        $cols = array_map(function ($v) use ($encoding) {
            $s = (string)$v;
            return ($encoding === 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', $encoding);
        }, $cols);

        if ($lineIndex === 0) {
            $header = $cols;
        } else {
            $rows[] = $cols;
            if (count($rows) >= $limit) break;
        }
        $lineIndex++;
    }
    fclose($fh);
    return [$header, $rows];
}

$errors = [];
$warnings = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('method not allowed');
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!is_valid_csrf($csrf)) {
    http_response_code(400);
    exit('CSRF invalid');
}

$storeId = (int)($_POST['store_id'] ?? 0);
$source = (string)($_POST['source'] ?? 'generic');
$mode = (string)($_POST['mode'] ?? 'overwrite');

$allowedSources = ['airregi', 'square', 'smaregi', 'stores', 'freee', 'yayoi', 'generic'];
if (!in_array($source, $allowedSources, true)) $source = 'generic';
$allowedModes = ['overwrite', 'add', 'skip'];
if (!in_array($mode, $allowedModes, true)) $mode = 'overwrite';

// store validation
$stores = [];
try {
    $st = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:t ORDER BY id ASC");
    $st->execute([':t' => $tenantId]);
    $stores = $st->fetchAll();
} catch (Throwable $e) {
    $stores = [];
}
$storeName = '';
$validStoreIds = [];
foreach ($stores as $s) {
    $validStoreIds[] = (int)$s['id'];
    if ((int)$s['id'] === $storeId) $storeName = (string)$s['name'];
}
if ($storeId <= 0 || !in_array($storeId, $validStoreIds, true)) {
    $errors[] = '店舗が不正です。';
}

// file
if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'] ?? '')) {
    $errors[] = 'CSVファイルが選択されていません。';
}

$uploadKey = '';
$uploadPath = '';
$origName = '';
$encoding = 'UTF-8';
$delimiter = ',';
$header = [];
$rows = [];

if (!$errors) {
    $origName = (string)($_FILES['csv_file']['name'] ?? '');
    $tmpPath = (string)($_FILES['csv_file']['tmp_name'] ?? '');

    $sampleLines = [];
    $fh = fopen($tmpPath, 'rb');
    if ($fh !== false) {
        for ($i = 0; $i < 5; $i++) {
            $line = fgets($fh);
            if ($line === false) break;
            $sampleLines[] = $line;
        }
        fclose($fh);
    }
    $sampleText = implode('', $sampleLines);
    $encoding = detect_encoding($sampleText);
    $delimiter = detect_delimiter($sampleLines, $encoding);

    $uploadKey = bin2hex(random_bytes(8));
    $uploadPath = '/tmp/sales_import_' . $uploadKey . '.csv';
    if (!move_uploaded_file($tmpPath, $uploadPath)) {
        $errors[] = 'アップロードの保存に失敗しました。';
    } else {
        [$header, $rows] = read_preview($uploadPath, $encoding, $delimiter, 20);
    }
}

if (!$errors && !$header) {
    $errors[] = 'CSVのヘッダー行が取得できません。';
}

// suggested mapping
$columnOptions = [];
foreach ($header as $idx => $name) {
    $label = trim((string)$name);
    $columnOptions[] = ['idx' => $idx, 'label' => $label === '' ? '(空欄)' : $label];
}

$mapDate = '';
$mapSales = '';
$mapCustomers = '';
$mapOrderId = '';

if ($header) {
    foreach ($header as $i => $name) {
        $n = normalize_header((string)$name);
        if ($mapDate === '' && preg_match('/(日付|営業日|売上日|取引日|date|日)$/u', $n)) {
            $mapDate = (string)$i;
        }
        if ($mapSales === '' && preg_match('/(売上|sales|金額|合計|総額|小計|税込|純売上|net|gross)/u', $n)) {
            $mapSales = (string)$i;
        }
        if ($mapCustomers === '' && preg_match('/(客数|来客|customers|人数|客)$/u', $n)) {
            $mapCustomers = (string)$i;
        }
        if ($mapOrderId === '' && preg_match('/(会計|取引|伝票|注文|order|id)$/u', $n)) {
            $mapOrderId = (string)$i;
        }
    }
}

if ($mapDate === '') $warnings[] = '日付列が自動検出できませんでした。';
if ($mapSales === '') $warnings[] = '売上列が自動検出できませんでした。';

// stash upload in session
if (!$errors) {
    $_SESSION['sales_import'] = $_SESSION['sales_import'] ?? [];
    $_SESSION['sales_import'][$uploadKey] = [
        'path' => $uploadPath,
        'orig_name' => $origName,
        'encoding' => $encoding,
        'delimiter' => $delimiter,
        'store_id' => $storeId,
        'source' => $source,
        'mode' => $mode,
        'tenant_id' => $tenantId,
        'uploaded_at' => time(),
    ];
}
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>売上CSV取り込み - プレビュー</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif;
            background: #f7f7f7;
            color: #111827;
        }
        .wrap { max-width: 980px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; }
        h1 { margin: 0 0 6px; font-size: 20px; }
        .muted { color: #6b7280; font-size: 12px; }
        .alert { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: 10px 12px; border-radius: 10px; font-size: 12px; margin-top: 10px; }
        .error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .step { margin-top: 16px; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; }
        .stepTitle { font-size: 14px; font-weight: 800; margin-bottom: 8px; }
        .grid2 { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        label { font-size: 12px; font-weight: 700; color: #374151; display: block; margin-bottom: 6px; }
        select, input[type="text"] {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
        }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; }
        th { background: #f9fafb; position: sticky; top: 0; }
        .tableWrap { max-height: 360px; overflow: auto; border: 1px solid #e5e7eb; border-radius: 10px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; border: 1px solid #111827; background: #111827; color: #fff; padding: 10px 14px; border-radius: 10px; font-size: 13px; font-weight: 800; text-decoration: none; cursor: pointer; }
        .btnGhost { background: #fff; color: #111827; }
        .pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; font-size: 12px; }
        @media (max-width: 720px) { .grid2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php @include __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card">
            <h1>売上CSV取り込み - プレビュー</h1>
            <div class="muted">先頭20行のプレビューと列マッピングを確認してください。</div>

            <?php if ($errors): ?>
                <div class="alert error">
                    <?php foreach ($errors as $e): ?>
                        <div><?= h($e) ?></div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:12px;">
                    <a class="btn btnGhost" href="/admin/sales_import.php">戻る</a>
                </div>
            <?php else: ?>
                <?php if ($warnings): ?>
                    <div class="alert">
                        <?php foreach ($warnings as $w): ?>
                            <div><?= h($w) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="step">
                    <div class="stepTitle">ファイル情報</div>
                    <div class="grid2">
                        <div class="pill">店舗: <?= h($storeName !== '' ? $storeName : ('ID:' . $storeId)) ?></div>
                        <div class="pill">ファイル: <?= h($origName) ?></div>
                        <div class="pill">文字コード: <?= h($encoding) ?></div>
                        <div class="pill">区切り: <?= h($delimiter === "\t" ? 'タブ' : $delimiter) ?></div>
                        <div class="pill">テンプレ: <?= h($source) ?></div>
                        <div class="pill">方針: <?= h($mode) ?></div>
                    </div>
                </div>

                <form method="post" action="/admin/sales_import_commit.php">
                    <input type="hidden" name="csrf_token" value="<?= h((string)$_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="upload_key" value="<?= h($uploadKey) ?>">

                    <div class="step">
                        <div class="stepTitle">列マッピング</div>
                        <div class="grid2">
                            <div>
                                <label>日付列（必須）</label>
                                <select name="map_date" required>
                                    <option value="">-- 選択 --</option>
                                    <?php foreach ($columnOptions as $opt): ?>
                                        <option value="<?= (int)$opt['idx'] ?>" <?= ((string)$opt['idx'] === $mapDate) ? 'selected' : '' ?>>
                                            <?= h($opt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>売上列（必須）</label>
                                <select name="map_sales" required>
                                    <option value="">-- 選択 --</option>
                                    <?php foreach ($columnOptions as $opt): ?>
                                        <option value="<?= (int)$opt['idx'] ?>" <?= ((string)$opt['idx'] === $mapSales) ? 'selected' : '' ?>>
                                            <?= h($opt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>客数列（任意）</label>
                                <select name="map_customers">
                                    <option value="">-- なし --</option>
                                    <?php foreach ($columnOptions as $opt): ?>
                                        <option value="<?= (int)$opt['idx'] ?>" <?= ((string)$opt['idx'] === $mapCustomers) ? 'selected' : '' ?>>
                                            <?= h($opt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>会計ID列（任意）</label>
                                <select name="map_order_id">
                                    <option value="">-- なし --</option>
                                    <?php foreach ($columnOptions as $opt): ?>
                                        <option value="<?= (int)$opt['idx'] ?>" <?= ((string)$opt['idx'] === $mapOrderId) ? 'selected' : '' ?>>
                                            <?= h($opt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="muted" style="margin-top:8px;">日付列は「YYYY-MM-DD」または「YYYY/MM/DD」想定です。</div>
                    </div>

                    <div class="step">
                        <div class="stepTitle">プレビュー（先頭20行）</div>
                        <div class="tableWrap">
                            <table>
                                <thead>
                                    <tr>
                                        <?php foreach ($header as $hcol): ?>
                                            <th><?= h((string)$hcol) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <?php foreach ($header as $idx => $_): ?>
                                                <td><?= h((string)($r[$idx] ?? '')) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$rows): ?>
                                        <tr><td colspan="<?= count($header) ?>" class="muted">データ行がありません。</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="step">
                        <div class="stepTitle">次へ</div>
                        <div class="muted">次の画面で件数・期間・警告を確認し、確定します。</div>
                        <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                            <button class="btn" type="submit">取り込み内容を確認</button>
                            <a class="btn btnGhost" href="/admin/sales_import.php?store_id=<?= (int)$storeId ?>">戻る</a>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
