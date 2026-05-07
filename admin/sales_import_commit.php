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

function parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $raw = str_replace(['.', '/'], '-', $raw);
    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $raw)) {
        [$y, $m, $d] = array_map('intval', explode('-', $raw));
        if (checkdate($m, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
    }
    return null;
}

function parse_number(string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $raw = str_replace([',', '円', '¥', '￥', ' '], '', $raw);
    if (!is_numeric($raw)) return null;
    return (float)$raw;
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

function load_csv_rows(string $path, string $encoding, string $delimiter): array
{
    $rows = [];
    $fh = fopen($path, 'rb');
    if ($fh === false) return $rows;
    $lineIndex = 0;
    while (($cols = fgetcsv($fh, 0, $delimiter)) !== false) {
        if ($lineIndex === 0 && isset($cols[0])) {
            $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cols[0]);
            $lineIndex++;
            continue; // header
        }
        $cols = array_map(function ($v) use ($encoding) {
            $s = (string)$v;
            return ($encoding === 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', $encoding);
        }, $cols);
        $rows[] = $cols;
        $lineIndex++;
    }
    fclose($fh);
    return $rows;
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

$uploadKey = (string)($_POST['upload_key'] ?? '');
$mapDate = (string)($_POST['map_date'] ?? '');
$mapSales = (string)($_POST['map_sales'] ?? '');
$mapCustomers = (string)($_POST['map_customers'] ?? '');
$mapOrderId = (string)($_POST['map_order_id'] ?? '');

if ($uploadKey === '' || !isset($_SESSION['sales_import'][$uploadKey])) {
    $errors[] = 'アップロード情報が見つかりません。やり直してください。';
}

if ($mapDate === '' || $mapSales === '') {
    $errors[] = '日付列と売上列は必須です。';
}

$session = $_SESSION['sales_import'][$uploadKey] ?? [];
$uploadPath = (string)($session['path'] ?? '');
$storeId = (int)($session['store_id'] ?? 0);
$source = (string)($session['source'] ?? 'generic');
$mode = (string)($session['mode'] ?? ($session['mode'] ?? 'overwrite'));
$encoding = (string)($session['encoding'] ?? 'UTF-8');
$delimiter = (string)($session['delimiter'] ?? ',');
$origName = (string)($session['orig_name'] ?? '');

if ($uploadPath === '' || !is_file($uploadPath)) {
    $errors[] = 'アップロードファイルが見つかりません。';
}

// store validation
$storeName = '';
try {
    $st = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:t AND id=:s LIMIT 1");
    $st->execute([':t' => $tenantId, ':s' => $storeId]);
    $storeName = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {
    $storeName = '';
}
if ($storeId <= 0 || $storeName === '') {
    $errors[] = '店舗が不正です。';
}

// admin employee id
$adminEmployeeId = (int)($_SESSION['admin_employee_id'] ?? 0);
if ($adminEmployeeId <= 0) {
    try {
        $st = $pdo->prepare("SELECT id FROM employees WHERE tenant_id=:t AND store_id=:s AND employment_status='active' ORDER BY sort_order ASC, id ASC LIMIT 1");
        $st->execute([':t' => $tenantId, ':s' => $storeId]);
        $adminEmployeeId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $adminEmployeeId = 0;
    }
}
if ($adminEmployeeId <= 0) {
    $errors[] = '更新者の従業員IDが取得できません。';
}

// read rows
$rows = [];
if (!$errors) {
    $rows = load_csv_rows($uploadPath, $encoding, $delimiter);
}

$mapDateIdx = (int)$mapDate;
$mapSalesIdx = (int)$mapSales;
$mapCustomersIdx = ($mapCustomers !== '') ? (int)$mapCustomers : -1;
$mapOrderIdIdx = ($mapOrderId !== '') ? (int)$mapOrderId : -1;

$normalized = []; // date => ['sales'=>float, 'customers'=>int]
$rawCount = 0;
$validCount = 0;
$warningCount = 0;
$skippedCount = 0;
$negativeDays = 0;
$sumSales = 0;
$sumVisitors = 0;
$dateFrom = null;
$dateTo = null;
$lineErrors = [];

if (!$errors) {
    foreach ($rows as $i => $cols) {
        $rawCount++;
        $dateRaw = (string)($cols[$mapDateIdx] ?? '');
        $salesRaw = (string)($cols[$mapSalesIdx] ?? '');
        $customersRaw = ($mapCustomersIdx >= 0) ? (string)($cols[$mapCustomersIdx] ?? '') : '';

        $date = parse_date($dateRaw);
        if ($date === null) {
            $lineErrors[] = '行' . ($i + 2) . ': 日付が不正';
            $skippedCount++;
            continue;
        }

        $sales = parse_number($salesRaw);
        if ($sales === null) {
            $lineErrors[] = '行' . ($i + 2) . ': 売上が不正';
            $skippedCount++;
            continue;
        }

        $customers = null;
        if ($mapCustomersIdx >= 0) {
            $customersVal = parse_number($customersRaw);
            if ($customersVal === null) {
                $warningCount++;
                $customers = 0;
            } else {
                $customers = (int)round($customersVal);
            }
        }

        $validCount++;
        if (!isset($normalized[$date])) {
            $normalized[$date] = ['sales' => 0.0, 'customers' => 0];
        }
        $normalized[$date]['sales'] += $sales;
        if ($customers !== null) {
            $normalized[$date]['customers'] += $customers;
        }
    }

    ksort($normalized);
    foreach ($normalized as $d => $pack) {
        $sales = (float)$pack['sales'];
        $customers = (int)$pack['customers'];
        $sumSales += (int)round($sales);
        $sumVisitors += $customers;
        if ($sales < 0) $negativeDays++;
        if ($dateFrom === null || $d < $dateFrom) $dateFrom = $d;
        if ($dateTo === null || $d > $dateTo) $dateTo = $d;
    }
}

// preview errors (limit)
if (count($lineErrors) > 0) {
    $warnings[] = 'エラー行: ' . implode(' / ', array_slice($lineErrors, 0, 3)) . (count($lineErrors) > 3 ? '...' : '');
}

// precheck (date range)
if ($dateFrom !== null && $dateTo !== null) {
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $min = (new DateTimeImmutable($today))->modify('-2 years')->format('Y-m-d');
    $max = (new DateTimeImmutable($today))->modify('+2 years')->format('Y-m-d');
    if ($dateFrom < $min || $dateTo > $max) {
        $warnings[] = '日付範囲が広すぎる可能性があります（±2年超）。';
    }
}

// potential duplicates count
$dupCount = 0;
if (!$errors && $normalized) {
    $dates = array_keys($normalized);
    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $sql = "SELECT business_date FROM daily_store_reports WHERE tenant_id=? AND store_id=? AND business_date IN ({$placeholders})";
    $st = $pdo->prepare($sql);
    $params = array_merge([$tenantId, $storeId], $dates);
    $st->execute($params);
    $dupCount = count($st->fetchAll());
}

// store prepared data in session for commit
if (!$errors) {
    $_SESSION['sales_import'][$uploadKey]['normalized'] = $normalized;
    $_SESSION['sales_import'][$uploadKey]['map'] = [
        'date' => $mapDateIdx,
        'sales' => $mapSalesIdx,
        'customers' => $mapCustomersIdx,
        'order_id' => $mapOrderIdIdx,
    ];
    $_SESSION['sales_import'][$uploadKey]['summary'] = [
        'raw_rows' => $rawCount,
        'valid_rows' => $validCount,
        'skipped_rows' => $skippedCount,
        'warning_rows' => $warningCount,
        'total_sales' => $sumSales,
        'total_visitors' => $sumVisitors,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'dup_count' => $dupCount,
        'negative_days' => $negativeDays,
    ];
}

$confirmRequired = ($negativeDays > 0);
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>売上CSV取り込み - 確認</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif; background: #f7f7f7; color: #111827; }
        .wrap { max-width: 900px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; }
        h1 { margin: 0 0 6px; font-size: 20px; }
        .muted { color: #6b7280; font-size: 12px; }
        .alert { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: 10px 12px; border-radius: 10px; font-size: 12px; margin-top: 10px; }
        .error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .step { margin-top: 16px; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; }
        .stepTitle { font-size: 14px; font-weight: 800; margin-bottom: 8px; }
        .grid2 { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; font-size: 12px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; border: 1px solid #111827; background: #111827; color: #fff; padding: 10px 14px; border-radius: 10px; font-size: 13px; font-weight: 800; text-decoration: none; cursor: pointer; }
        .btnGhost { background: #fff; color: #111827; }
        @media (max-width: 720px) { .grid2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php @include __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card">
            <h1>売上CSV取り込み - 確認</h1>
            <div class="muted">取り込み内容を確認して確定してください。</div>

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
                    <div class="stepTitle">取り込みサマリー</div>
                    <div class="grid2">
                        <div class="pill">店舗: <?= h($storeName !== '' ? $storeName : ('ID:' . $storeId)) ?></div>
                        <div class="pill">ファイル: <?= h($origName) ?></div>
                        <div class="pill">期間: <?= h((string)($dateFrom ?? '-')) ?> 〜 <?= h((string)($dateTo ?? '-')) ?></div>
                        <div class="pill">合計売上: <?= number_format((int)$sumSales) ?> 円</div>
                        <div class="pill">合計客数: <?= number_format((int)$sumVisitors) ?> 人</div>
                        <div class="pill">重複件数: <?= number_format((int)$dupCount) ?> 日</div>
                        <div class="pill">負数日数: <?= number_format((int)$negativeDays) ?> 日</div>
                    </div>
                </div>

                <?php if ($confirmRequired): ?>
                    <div class="alert">
                        負数の売上が含まれています。確定前に内容を確認してください。
                    </div>
                <?php endif; ?>

                <form method="post" action="/admin/sales_import_commit.php">
                    <input type="hidden" name="csrf_token" value="<?= h((string)$_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="upload_key" value="<?= h($uploadKey) ?>">
                    <input type="hidden" name="confirm" value="1">
                    <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn" type="submit">この内容で確定</button>
                        <a class="btn btnGhost" href="/admin/sales_import.php?store_id=<?= (int)$storeId ?>">戻る</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

<?php
// ===== commit processing =====
if ($errors) return;
if (!isset($_POST['confirm'])) return;

$session = $_SESSION['sales_import'][$uploadKey] ?? [];
$normalized = $session['normalized'] ?? [];
$summary = $session['summary'] ?? [];

if (!$normalized) {
    exit;
}

$pdo->beginTransaction();
try {
    // batch insert
    $insBatch = $pdo->prepare("INSERT INTO sales_import_batches
        (tenant_id, store_id, source, mode, file_name, file_hash, encoding, delimiter, date_from, date_to, total_rows, valid_rows, skipped_rows, warning_rows, total_sales, total_visitors, negative_days, created_by_admin_id)
        VALUES
        (:t, :s, :source, :mode, :file, :hash, :enc, :delim, :df, :dt, :total, :valid, :skipped, :warn, :sum_sales, :sum_visitors, :neg, :admin)");
    $fileHash = hash_file('sha256', $uploadPath) ?: '';
    $insBatch->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':source' => $source,
        ':mode' => $mode,
        ':file' => $origName,
        ':hash' => $fileHash,
        ':enc' => $encoding,
        ':delim' => $delimiter,
        ':df' => $summary['date_from'] ?? null,
        ':dt' => $summary['date_to'] ?? null,
        ':total' => $summary['raw_rows'] ?? 0,
        ':valid' => $summary['valid_rows'] ?? 0,
        ':skipped' => $summary['skipped_rows'] ?? 0,
        ':warn' => $summary['warning_rows'] ?? 0,
        ':sum_sales' => $summary['total_sales'] ?? 0,
        ':sum_visitors' => $summary['total_visitors'] ?? 0,
        ':neg' => $summary['negative_days'] ?? 0,
        ':admin' => $adminEmployeeId,
    ]);
    $batchId = (int)$pdo->lastInsertId();

    // backup existing rows
    $dates = array_keys($normalized);
    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $sel = $pdo->prepare("SELECT business_date, sales_yen, visitors, sales_confirmed, updated_by_employee_id, updated_at
        FROM daily_store_reports
        WHERE tenant_id=? AND store_id=? AND business_date IN ({$placeholders})");
    $sel->execute(array_merge([$tenantId, $storeId], $dates));
    $existing = $sel->fetchAll();

    if ($existing) {
        $insBackup = $pdo->prepare("INSERT INTO sales_import_backups
            (import_batch_id, tenant_id, store_id, business_date, sales_yen, visitors, sales_confirmed, updated_by_employee_id, updated_at)
            VALUES
            (:batch, :t, :s, :d, :sales, :visitors, :confirmed, :eid, :updated_at)");
        foreach ($existing as $row) {
            $insBackup->execute([
                ':batch' => $batchId,
                ':t' => $tenantId,
                ':s' => $storeId,
                ':d' => $row['business_date'],
                ':sales' => (int)$row['sales_yen'],
                ':visitors' => (int)$row['visitors'],
                ':confirmed' => (int)$row['sales_confirmed'],
                ':eid' => $row['updated_by_employee_id'],
                ':updated_at' => $row['updated_at'],
            ]);
        }
    }

    // upsert into daily_store_reports
    $up = $pdo->prepare("INSERT INTO daily_store_reports
        (tenant_id, store_id, business_date, sales_yen, visitors, updated_by_employee_id, sales_confirmed)
        VALUES
        (:t, :s, :d, :sales, :visitors, :eid, 1)
        ON DUPLICATE KEY UPDATE
            sales_yen = " . ($mode === 'add' ? 'sales_yen + VALUES(sales_yen)' : 'VALUES(sales_yen)') . ",
            visitors = " . ($mode === 'add' ? 'visitors + VALUES(visitors)' : 'VALUES(visitors)') . ",
            sales_confirmed = VALUES(sales_confirmed),
            updated_by_employee_id = VALUES(updated_by_employee_id),
            updated_at = NOW()");

    foreach ($normalized as $d => $pack) {
        if ($mode === 'skip') {
            $chk = $pdo->prepare("SELECT 1 FROM daily_store_reports WHERE tenant_id=? AND store_id=? AND business_date=? LIMIT 1");
            $chk->execute([$tenantId, $storeId, $d]);
            if ($chk->fetchColumn()) continue;
        }
        $up->execute([
            ':t' => $tenantId,
            ':s' => $storeId,
            ':d' => $d,
            ':sales' => (int)round((float)$pack['sales']),
            ':visitors' => (int)$pack['customers'],
            ':eid' => $adminEmployeeId,
        ]);
    }

    $pdo->commit();

    // cleanup
    unset($_SESSION['sales_import'][$uploadKey]);
    if (is_file($uploadPath)) @unlink($uploadPath);

    header('Location: /admin/index.php?store_id=' . (int)$storeId);
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n\n";
    echo "Message: " . $e->getMessage() . "\n";
    exit;
}
