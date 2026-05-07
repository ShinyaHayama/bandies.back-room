<?php
// /admin/clock_qr_print.php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$storeId = (int)($_GET['store_id'] ?? 0);
if ($tenantId <= 0 || $storeId <= 0) {
    http_response_code(400);
    exit('invalid request');
}

$pdo = admin_db_pdo();
if (!$pdo) {
    http_response_code(500);
    exit('db error');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$cols = $pdo->query("SHOW COLUMNS FROM stores")->fetchAll(PDO::FETCH_COLUMN, 0);
if (!in_array('clock_qr_token', $cols, true)) {
    http_response_code(400);
    exit('QR not enabled');
}

$st = $pdo->prepare("
    SELECT name, clock_qr_token
    FROM stores
    WHERE tenant_id = :t AND id = :s
    LIMIT 1
");
$st->execute([':t' => $tenantId, ':s' => $storeId]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    exit('store not found');
}

$storeName = (string)($row['name'] ?? '');
$token = (string)($row['clock_qr_token'] ?? '');
if ($token === '') {
    http_response_code(400);
    exit('QR not issued');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'shimenavi.com';
$qrValue = $scheme . '://' . $host . '/worker/qr_clock.php?token=' . rawurlencode($token);
$qrImgUrl = "https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=" . rawurlencode($qrValue);
$loginUrl = $scheme . '://' . $host . '/worker/login.php';
$loginQrImgUrl = "https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=" . rawurlencode($loginUrl);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title></title>
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif;
            margin: 0;
            padding: 24px;
            color: #0f172a;
            background: #fff;
        }
        .page {
            max-width: 640px;
            margin: 0 auto;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            position: relative;
        }
        .mainStack {
            flex: 1;
            display: grid;
            align-content: center;
            justify-items: center;
            gap: 12px;
            width: 100%;
        }
        .store {
            font-size: 12px;
            color: #475569;
        }
        .qr {
            display: inline-block;
            padding: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            margin: 0 auto;
        }
        .qr img {
            width: 360px;
            height: 360px;
        }
        .note {
            margin-top: 16px;
            font-size: 13px;
            color: #475569;
        }
        .footerRow {
            position: absolute;
            left: 24px;
            bottom: 24px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .footerRow img {
            height: 28px;
            width: auto;
            display: block;
        }
        .qrTitle {
            font-size: 16px;
            font-weight: 800;
            margin: 4px 0 10px;
        }
        .loginBox {
            margin-top: 28px;
            display: inline-grid;
            gap: 8px;
            align-items: center;
            justify-items: center;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
        }
        .loginBox img {
            width: 140px;
            height: 140px;
        }
        .loginLabel {
            font-size: 12px;
            color: #475569;
        }
        .loginUrl {
            font-size: 11px;
            color: #334155;
            word-break: break-all;
        }
        @media print {
            @page { margin: 0; }
            body { padding: 0; }
            .page { max-width: none; }
            .footerRow { left: 16px; bottom: 16px; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="mainStack">
            <div class="qrTitle">出退勤QR</div>
            <div class="qr">
                <img src="<?= h($qrImgUrl) ?>" alt="出退勤QR">
            </div>
            <div class="note">ログイン後に「QRで出退勤」から読み取ってください。</div>
            <div class="loginBox">
                <div class="loginLabel">ログイン画面（QR）</div>
                <img src="<?= h($loginQrImgUrl) ?>" alt="作業員ログインQR">
                <div class="loginUrl"><?= h($loginUrl) ?></div>
            </div>
        </div>
        <div class="footerRow">
            <img src="/images/logo_main.png" alt="SHIMENABI">
            <div class="store"><?= h($storeName) ?>（ID: <?= (int)$storeId ?>）</div>
        </div>
    </div>
    <script>
        window.addEventListener('load', () => {
            setTimeout(() => window.print(), 200);
        });
    </script>
</body>
</html>
