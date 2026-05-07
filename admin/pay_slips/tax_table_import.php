<?php

declare(strict_types=1);

/**
 * ✅ 源泉税額表（CSV）取り込み画面（最小）
 * - tax_withholding_tables / tax_withholding_rows に投入する
 * - CSVは「lower_yen,upper_yen,tax_yen」の3列（ヘッダ行OK）
 *   - upper_yen は空欄なら NULL（上限なし）
 *
 * 使い方：
 * 1) pay_cycle（monthly/weekly/daily）と tax_type（ko/otsu）を選ぶ
 * 2) CSVを選んでアップロード
 */

require_once __DIR__ . '/../_auth.php';
require_admin_login();

require_once __DIR__ . '/../_tenant_context.php'; // $tenantId（使わないが統一のため）
require_once __DIR__ . '/../lib/db.php';
$pdo = db(); // ←あなたのDB接続関数名に合わせてください

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$cycles = ['monthly' => '月払い', 'weekly' => '週払い', 'daily' => '日払い'];
$types  = ['ko' => '甲（扶養申告書あり）', 'otsu' => '乙（扶養申告書なし）'];

// 現在の器（tax_withholding_tables）を一覧表示（確認用）
$stmt = $pdo->query("SELECT id, pay_cycle, tax_type, version_label, effective_from, effective_to FROM tax_withholding_tables ORDER BY pay_cycle, tax_type, id");
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>源泉税額表 CSV 取り込み</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
    body {
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        margin: 20px;
        line-height: 1.6
    }

    .box {
        border: 1px solid #ddd;
        border-radius: 10px;
        padding: 14px;
        margin: 12px 0
    }

    table {
        border-collapse: collapse;
        width: 100%
    }

    th,
    td {
        border: 1px solid #ddd;
        padding: 8px;
        font-size: 13px
    }

    th {
        background: #f7f7f7;
        text-align: left
    }

    .note {
        font-size: 12px;
        color: #444
    }

    .btn {
        padding: 10px 14px;
        border: 1px solid #111;
        border-radius: 8px;
        background: #111;
        color: #fff;
        cursor: pointer
    }

    .inp {
        padding: 8px;
        border: 1px solid #ccc;
        border-radius: 8px
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center
    }
    </style>
</head>

<body>

    <h2>源泉税額表 CSV 取り込み（最小）</h2>

    <div class="box">
        <h3>1) CSVアップロード</h3>

        <form action="tax_table_import_handler.php" method="post" enctype="multipart/form-data">
            <div class="row">
                <label>支給サイクル</label>
                <select class="inp" name="pay_cycle" required>
                    <?php foreach ($cycles as $k => $v): ?>
                    <option value="<?= h($k) ?>"><?= h($v) ?> (<?= h($k) ?>)</option>
                    <?php endforeach; ?>
                </select>

                <label>区分</label>
                <select class="inp" name="tax_type" required>
                    <?php foreach ($types as $k => $v): ?>
                    <option value="<?= h($k) ?>"><?= h($v) ?> (<?= h($k) ?>)</option>
                    <?php endforeach; ?>
                </select>

                <label>version_label</label>
                <input class="inp" type="text" name="version_label" value="v1" required>

                <label>CSV</label>
                <input class="inp" type="file" name="csv_file" accept=".csv,text/csv" required>

                <button class="btn" type="submit">取り込む</button>
            </div>

            <p class="note">
                CSV形式：<b>lower_yen,upper_yen,tax_yen</b>（ヘッダ行があってもOK）<br>
                例：<code>0,87999,0</code> / <code>88000,92999,1000</code> / 上限なしは <code>93000,,1500</code>
            </p>
        </form>
    </div>

    <div class="box">
        <h3>2) 登録済み tax_withholding_tables（器）一覧</h3>
        <table>
            <thead>
                <tr>
                    <th>id</th>
                    <th>pay_cycle</th>
                    <th>tax_type</th>
                    <th>version</th>
                    <th>effective_from</th>
                    <th>effective_to</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tables as $t): ?>
                <tr>
                    <td><?= h((string)$t['id']) ?></td>
                    <td><?= h((string)$t['pay_cycle']) ?></td>
                    <td><?= h((string)$t['tax_type']) ?></td>
                    <td><?= h((string)$t['version_label']) ?></td>
                    <td><?= h((string)($t['effective_from'] ?? '')) ?></td>
                    <td><?= h((string)($t['effective_to'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>

</html>