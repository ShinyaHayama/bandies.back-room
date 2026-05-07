<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/sales_edit.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * 変更点
 * - ✅ 客数（visitors）も編集できるように追加
 * - 保存時に sales_yen / visitors を両方保存
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

// ===== DB（index.php と同じ探索方式）=====
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

function has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $st->execute([':col' => $column]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

$storeId = (int)($_GET['store_id'] ?? 0);
$date    = (string)($_GET['date'] ?? '');

if ($storeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    exit('不正なパラメータ');
}

// ===== updated_by_employee_id を必ず「存在する employee_id」にする =====
$editorEmployeeId = (int)($_SESSION['admin_employee_id'] ?? 0);

if ($editorEmployeeId <= 0) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM employees
        WHERE tenant_id = :t AND store_id = :s AND employment_status = 'active'
        ORDER BY sort_order ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([':t' => $tenantId, ':s' => $storeId]);
    $editorEmployeeId = (int)($stmt->fetchColumn() ?: 0);
}

if ($editorEmployeeId <= 0) {
    http_response_code(500);
    exit('編集者(employee)が特定できません。employees が空です。');
}

// 現在値取得
$hasSalesConfirmed = has_column($pdo, 'daily_store_reports', 'sales_confirmed');
if (!$hasSalesConfirmed) {
    try {
        $pdo->exec("ALTER TABLE daily_store_reports ADD COLUMN sales_confirmed TINYINT(1) NOT NULL DEFAULT 0");
        $hasSalesConfirmed = has_column($pdo, 'daily_store_reports', 'sales_confirmed');
    } catch (Throwable $e) {
        $hasSalesConfirmed = false;
    }
}

$selectConfirmed = $hasSalesConfirmed ? ", sales_confirmed" : "";
$stmt = $pdo->prepare("
    SELECT sales_yen, visitors {$selectConfirmed}
    FROM daily_store_reports
    WHERE tenant_id = :t AND store_id = :s AND business_date = :d
");
$stmt->execute([':t' => $tenantId, ':s' => $storeId, ':d' => $date]);
$row = $stmt->fetch() ?: null;

$currentSales = (int)($row['sales_yen'] ?? 0);
$currentVisitors = (int)($row['visitors'] ?? 0);
$currentConfirmed = (int)($row['sales_confirmed'] ?? 0);

// POST保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sales = max(0, (int)($_POST['sales_yen'] ?? 0));
        $visitors = max(0, (int)($_POST['visitors'] ?? 0));
        $salesConfirmed = isset($_POST['sales_confirmed']) ? 1 : 0;

        $cols = "tenant_id, store_id, business_date, sales_yen, visitors, updated_by_employee_id";
        $vals = ":t, :s, :d, :sales, :visitors, :eid";
        $updates = "
              sales_yen = VALUES(sales_yen),
              visitors = VALUES(visitors),
              updated_by_employee_id = VALUES(updated_by_employee_id),
              updated_at = NOW()
        ";
        $params = [
            ':t' => $tenantId,
            ':s' => $storeId,
            ':d' => $date,
            ':sales' => $sales,
            ':visitors' => $visitors,
            ':eid' => $editorEmployeeId,
        ];

        if ($hasSalesConfirmed) {
            $cols .= ", sales_confirmed";
            $vals .= ", :confirmed";
            $updates = "
              sales_yen = VALUES(sales_yen),
              visitors = VALUES(visitors),
              sales_confirmed = VALUES(sales_confirmed),
              updated_by_employee_id = VALUES(updated_by_employee_id),
              updated_at = NOW()
            ";
            $params[':confirmed'] = $salesConfirmed;
        }

        $up = $pdo->prepare("
            INSERT INTO daily_store_reports
              ({$cols})
            VALUES
              ({$vals})
            ON DUPLICATE KEY UPDATE
              {$updates}
        ");
        $up->execute($params);

        header("Location: /admin/index.php?store_id={$storeId}");
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "500 Internal Server Error\n\n";
        echo "Message: " . $e->getMessage() . "\n";
        exit;
    }
}

$backUrl = "/admin/index.php?store_id=" . (int)$storeId;
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>売上編集</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
    /* ✅ はみ出しの主因（padding + width 100%）を確実に潰す */
    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    body {
        font-family: system-ui;
        background: #f7f7f7;
        padding: 20px;
        margin: 0;
        font-size: 13px;
    }

    .card {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        max-width: 420px;
        margin: 0 auto;
        border: 1px solid #ddd;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        text-decoration: none;
        border: 1px solid #ddd;
        background: #fff;
        padding: 10px 12px;
        border-radius: 10px;
        font-weight: 800;
        color: #111;
        font-size: 13px;
        line-height: 1;
        white-space: nowrap;
    }

    .muted {
        color: #666;
        font-size: 11px;
    }

    label {
        display: block;
        margin-top: 12px;
        font-size: 12px;
    }

    input {
        display: block;
        width: 100%;
        max-width: 100%;
        font-size: 16px;
        padding: 10px 12px;
        margin-top: 8px;
        border: 1px solid #ddd;
        border-radius: 10px;
        background: #fff;
        outline: none;
    }

    input:focus {
        border-color: #999;
    }

    button {
        margin-top: 12px;
        width: 100%;
        padding: 12px;
        font-size: 14px;
        font-weight: 900;
        border-radius: 12px;
        border: 1px solid #111;
        background: #111;
        color: #fff;
        cursor: pointer;
    }

    .footerLinks {
        margin-top: 12px;
        text-align: center;
    }

    .row2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 8px;
    }

    .row2 input {
        margin-top: 6px;
    }
    </style>
</head>

<body>
    <div class="card">

        <h2 style="margin:0 0 8px;"><?= h($date) ?> の売上</h2>

        <form method="post">
            <div class="row2">
                <div>
                    <label class="muted">売上（円）</label>
                    <input type="number" name="sales_yen" value="<?= (int)$currentSales ?>" min="0" step="1"
                        inputmode="numeric">
                </div>

                <div>
                    <label class="muted">客数（人）</label>
                    <input type="number" name="visitors" value="<?= (int)$currentVisitors ?>" min="0" step="1"
                        inputmode="numeric">
                </div>
            </div>

            <?php if ($hasSalesConfirmed): ?>
            <label class="muted" style="display:flex;align-items:center;gap:8px;margin-top:12px;">
                <input type="checkbox" name="sales_confirmed" value="1"
                    <?= $currentConfirmed === 1 ? 'checked' : '' ?>>
                売上確定（0円でも確定する場合はチェック）
            </label>
            <?php endif; ?>

            <button type="submit">保存する</button>
        </form>

        <div class="footerLinks">
            <a class="btn" href="<?= h($backUrl) ?>">← トップに戻る</a>
        </div>
    </div>
</body>

</html>
