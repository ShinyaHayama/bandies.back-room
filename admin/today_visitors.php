<?php
// /admin/today_visitors.php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

// ===== DB（他画面と統一）=====
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
    throw new RuntimeException('db.php not found');
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ===== 店舗一覧（tenant固定）=====
$storesStmt = $pdo->prepare("
    SELECT id, name
    FROM stores
    WHERE tenant_id = :tenant_id
    ORDER BY id ASC
");
$storesStmt->execute([':tenant_id' => $tenantId]);
$stores = $storesStmt->fetchAll();

if (!$stores) {
    http_response_code(400);
    echo '店舗がありません';
    exit;
}

// store_id（tenant内のみ許可）
$storeId = (int)($_GET['store_id'] ?? (int)$stores[0]['id']);
$validStoreIds = array_map(fn($r) => (int)$r['id'], $stores);
if (!in_array($storeId, $validStoreIds, true)) {
    $storeId = (int)$stores[0]['id'];
}

// ===== employees（ヘッダー用）=====
$empStmt = $pdo->prepare("
    SELECT id, display_name
    FROM employees
    WHERE tenant_id = :tenant_id
      AND store_id  = :store_id
    ORDER BY sort_order ASC, id ASC
");
$empStmt->execute([':tenant_id' => $tenantId, ':store_id' => $storeId]);
$employees = $empStmt->fetchAll();

// ===== テーブル存在チェック =====
$chk = $pdo->prepare("SHOW TABLES LIKE 'daily_store_reports'");
$chk->execute();
$hasTable = (bool)$chk->fetchColumn();

// tenant timezone（なければAsia/Tokyo）
$tzStmt = $pdo->prepare("SELECT timezone FROM tenants WHERE id = :tenant_id");
$tzStmt->execute([':tenant_id' => $tenantId]);
$tz = (string)($tzStmt->fetchColumn() ?: 'Asia/Tokyo');

$today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');

// ===== フィルタ（期間）=====
$defaultFrom = (new DateTimeImmutable($today, new DateTimeZone($tz)))->modify('-30 days')->format('Y-m-d');
$defaultTo   = $today;

$from = (string)($_GET['from'] ?? $defaultFrom);
$to   = (string)($_GET['to']   ?? $defaultTo);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $defaultTo;
if ($from > $to) {
    $tmp = $from;
    $from = $to;
    $to = $tmp;
}

// ===== paging（25件）=====
$perPage = 25;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

// ===== 今日の最新1件 + 期間一覧 + 合計 =====
$todayRow = null;
$rows = [];
$totalRows = 0;
$sumSales = 0;
$sumVisitors = 0;

if ($hasTable) {
    // 今日
    $stmtToday = $pdo->prepare("
        SELECT
            r.business_date,
            r.visitors,
            r.sales_yen,
            r.updated_at,
            e.display_name AS updated_by
        FROM daily_store_reports r
        LEFT JOIN employees e ON e.id = r.updated_by_employee_id
        WHERE r.tenant_id = :tenant_id
          AND r.store_id  = :store_id
          AND r.business_date = :today
        ORDER BY r.updated_at DESC
        LIMIT 1
    ");
    $stmtToday->execute([
        ':tenant_id' => $tenantId,
        ':store_id'  => $storeId,
        ':today'     => $today,
    ]);
    $todayRow = $stmtToday->fetch() ?: null;

    // 件数（ページング用）
    $stmtCount = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM daily_store_reports r
        WHERE r.tenant_id = :tenant_id
          AND r.store_id  = :store_id
          AND r.business_date >= :from
          AND r.business_date <= :to
    ");
    $stmtCount->execute([
        ':tenant_id' => $tenantId,
        ':store_id'  => $storeId,
        ':from'      => $from,
        ':to'        => $to,
    ]);
    $totalRows = (int)($stmtCount->fetch()['cnt'] ?? 0);

    // 合計（期間内）
    $stmtSum = $pdo->prepare("
        SELECT
            COALESCE(SUM(r.sales_yen),0) AS sum_sales,
            COALESCE(SUM(r.visitors),0)  AS sum_visitors
        FROM daily_store_reports r
        WHERE r.tenant_id = :tenant_id
          AND r.store_id  = :store_id
          AND r.business_date >= :from
          AND r.business_date <= :to
    ");
    $stmtSum->execute([
        ':tenant_id' => $tenantId,
        ':store_id'  => $storeId,
        ':from'      => $from,
        ':to'        => $to,
    ]);
    $sum = $stmtSum->fetch() ?: [];
    $sumSales = (int)($sum['sum_sales'] ?? 0);
    $sumVisitors = (int)($sum['sum_visitors'] ?? 0);

    // 一覧（期間内 + ページ）
    $stmt = $pdo->prepare("
        SELECT
            r.business_date,
            r.visitors,
            r.sales_yen,
            r.updated_at,
            e.display_name AS updated_by
        FROM daily_store_reports r
        LEFT JOIN employees e ON e.id = r.updated_by_employee_id
        WHERE r.tenant_id = :tenant_id
          AND r.store_id  = :store_id
          AND r.business_date >= :from
          AND r.business_date <= :to
        ORDER BY r.business_date DESC, r.updated_at DESC
        LIMIT :lim OFFSET :ofs
    ");
    $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
    $stmt->bindValue(':store_id',  $storeId,  PDO::PARAM_INT);
    $stmt->bindValue(':from',      $from,     PDO::PARAM_STR);
    $stmt->bindValue(':to',        $to,       PDO::PARAM_STR);
    $stmt->bindValue(':lim',       $perPage,  PDO::PARAM_INT);
    $stmt->bindValue(':ofs',       $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

// ===== ページリンク用 =====
$totalPages = ($totalRows > 0) ? (int)ceil($totalRows / $perPage) : 1;
if ($page > $totalPages) $page = $totalPages;

function buildUrl(array $extra): string
{
    $q = array_merge($_GET, $extra);
    return '/admin/today_visitors.php?' . http_build_query($q);
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>本日の来客</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
    body {
        margin: 0;
        font-family: system-ui, -apple-system, "Hiragino Sans", "Noto Sans JP", sans-serif;
        color: #111;
        background: #fff;
    }

    .page {
        max-width: 1220px;
        margin: 0 auto;
        padding: 20px 20px 48px;
    }

    h1 {
        margin: 0 0 8px;
        font-size: 22px;
        letter-spacing: .02em;
    }

    .meta {
        color: #666;
        font-size: 12px;
        margin-bottom: 14px;
    }

    .panel {
        border: 1px solid #e9e9e9;
        border-radius: 14px;
        background: #fff;
        padding: 14px 14px;
        margin: 14px 0 16px;
    }

    .filters {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .filters label {
        font-size: 12px;
        color: #666;
        font-weight: 700;
    }

    input[type="date"] {
        height: 34px;
        padding: 0 10px;
        border: 1px solid #d6d6d6;
        border-radius: 10px;
        font-size: 13px;
        background: #fff;
    }

    .btn {
        height: 34px;
        padding: 0 12px;
        border-radius: 10px;
        border: 1px solid #d6d6d6;
        background: #111;
        color: #fff;
        font-weight: 800;
        cursor: pointer;
    }

    .bigRow {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .big {
        font-size: 40px;
        font-weight: 900;
        margin: 0;
        line-height: 1;
    }

    .sub {
        margin: 6px 0 0;
        color: #666;
        font-size: 13px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    th,
    td {
        padding: 12px 10px;
        border-bottom: 1px solid #eee;
        vertical-align: middle;
    }

    th {
        color: #666;
        font-weight: 700;
        background: #fafafa;
        border-bottom: 1px solid #e9e9e9;
        white-space: nowrap;
    }

    tfoot td {
        background: #fafafa;
        font-weight: 900;
        border-top: 1px solid #e9e9e9;
    }

    .right {
        text-align: right;
    }

    .pager {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-top: 12px;
        flex-wrap: wrap;
    }

    .pager a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 34px;
        padding: 0 12px;
        border: 1px solid #d6d6d6;
        border-radius: 10px;
        text-decoration: none;
        color: #111;
        background: #fff;
        font-weight: 800;
        font-size: 13px;
    }

    .pager .muted {
        color: #666;
        font-size: 12px;
        font-weight: 700;
    }

    .warn {
        color: #b00020;
        font-weight: 800;
        font-size: 13px;
        padding: 10px 0;
    }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="page">
        <h1>本日の来客</h1>
        <div class="meta">本日: <b><?= h($today) ?></b> ／ store_id: <b><?= (int)$storeId ?></b></div>

        <?php if (!$hasTable): ?>
        <div class="warn">daily_store_reports テーブルが存在しません。</div>
        <?php else: ?>

        <div class="panel">
            <div class="bigRow">
                <div>
                    <?php if (!$todayRow): ?>
                    <div class="big">0<span style="font-size:.55em;font-weight:800;"> 人</span></div>
                    <div class="sub">本日はまだ入力されていません</div>
                    <?php else: ?>
                    <div class="big"><?= (int)$todayRow['visitors'] ?><span style="font-size:.55em;font-weight:800;">
                            人</span></div>
                    <div class="sub">
                        売上 <?= (int)$todayRow['sales_yen'] ?> 円 /
                        <?= h((string)($todayRow['updated_by'] ?? '')) ?> /
                        <?= h((string)$todayRow['updated_at']) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <form class="filters" method="get" action="/admin/today_visitors.php">
                    <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                    <label>from</label>
                    <input type="date" name="from" value="<?= h($from) ?>">
                    <label>to</label>
                    <input type="date" name="to" value="<?= h($to) ?>">
                    <button class="btn" type="submit">表示</button>
                </form>
            </div>
        </div>

        <div class="panel" style="padding:0; overflow:auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width:120px;">日付</th>
                        <th style="width:110px;" class="right">来客</th>
                        <th style="width:140px;" class="right">売上(円)</th>
                        <th style="width:180px;">更新日時</th>
                        <th>入力者</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                    <tr>
                        <td colspan="5" style="color:#666; padding:16px 10px;">この期間のデータはありません。</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string)$r['business_date']) ?></td>
                        <td class="right"><?= (int)$r['visitors'] ?> 人</td>
                        <td class="right"><?= (int)$r['sales_yen'] ?> 円</td>
                        <td><?= h((string)$r['updated_at']) ?></td>
                        <td><?= h((string)($r['updated_by'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>合計</td>
                        <td class="right"><?= (int)$sumVisitors ?> 人</td>
                        <td class="right"><?= (int)$sumSales ?> 円</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

            <div class="pager" style="padding:12px 14px;">
                <div class="muted">
                    <?= (int)$totalRows ?> 件（<?= (int)$perPage ?>件/ページ） / <?= (int)$page ?> / <?= (int)$totalPages ?>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <?php if ($page > 1): ?>
                    <a href="<?= h(buildUrl(['page' => 1])) ?>">最初</a>
                    <a href="<?= h(buildUrl(['page' => $page - 1])) ?>">前へ</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                    <a href="<?= h(buildUrl(['page' => $page + 1])) ?>">次へ</a>
                    <a href="<?= h(buildUrl(['page' => $totalPages])) ?>">最後</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>
</body>

</html>