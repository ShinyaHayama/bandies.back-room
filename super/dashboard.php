<?php

declare(strict_types=1);
require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_db.php';

$tenantCnt = (int)($pdo->query("SELECT COUNT(*) c FROM tenants")->fetch()['c'] ?? 0);
$storeCnt  = (int)($pdo->query("SELECT COUNT(*) c FROM stores")->fetch()['c'] ?? 0);
$empCnt    = (int)($pdo->query("SELECT COUNT(*) c FROM employees")->fetch()['c'] ?? 0);
$punchCnt  = (int)($pdo->query("SELECT COUNT(*) c FROM time_punches")->fetch()['c'] ?? 0);
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Super Dashboard</title>
    <style>
    body {
        font-family: system-ui;
        padding: 18px
    }

    .card {
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 14px;
        max-width: 1100px
    }

    .kpi {
        display: flex;
        gap: 12px;
        flex-wrap: wrap
    }

    .box {
        border: 1px solid #eee;
        border-radius: 12px;
        padding: 12px;
        min-width: 180px
    }

    b {
        font-size: 20px
    }

    a {
        color: #111
    }
    </style>
</head>

<body>
    <?php require __DIR__ . '/_top.php'; ?>
    <div class="card">
        <h2 style="margin:0 0 10px;">Super Admin Dashboard</h2>
        <div class="kpi">
            <div class="box">Tenants<br><b><?= $tenantCnt ?></b></div>
            <div class="box">Stores<br><b><?= $storeCnt ?></b></div>
            <div class="box">Employees<br><b><?= $empCnt ?></b></div>
            <div class="box">Punches<br><b><?= $punchCnt ?></b></div>
        </div>
    </div>
</body>

</html>