<?php

declare(strict_types=1);

// ✅ デバッグ中だけON（原因が取れたらOFF推奨）
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ✅ headers already sent を防ぐ：出力より先に処理を完了させる
try {
    // ✅ 正しいパス：admin → api/lib/db.php
    require_once __DIR__ . '/../api/lib/db.php';

    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    $storeId  = (int)($_GET['store_id'] ?? 0);

    if ($tenantId <= 0 || $storeId <= 0) {
        throw new RuntimeException('tenant_id / store_id が不正です。例: ?tenant_id=1&store_id=1');
    }

    $pdo = db();

    $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5分
    $code = strtoupper(bin2hex(random_bytes(4)));   // 8文字

    $stmt = $pdo->prepare(
        "INSERT INTO device_activation_codes
            (code, tenant_id, store_id, expires_at, created_at)
         VALUES
            (:code, :tenant_id, :store_id, :expires_at, NOW())"
    );
    $stmt->execute([
        ':code' => $code,
        ':tenant_id' => $tenantId,
        ':store_id' => $storeId,
        ':expires_at' => $expiresAt,
    ]);

    // ✅ QRの中身：URL形式（アプリ側で ?code= を抜ける）
    $qrValue = "https://a-zure.me/activate?code={$code}";
    $qrImgUrl = "https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=" . rawurlencode($qrValue);
    $qrImgData = '';
    try {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 3,
            ],
            'https' => [
                'timeout' => 3,
            ],
        ]);
        $bin = @file_get_contents($qrImgUrl, false, $ctx);
        if ($bin !== false && $bin !== '') {
            $qrImgData = 'data:image/png;base64,' . base64_encode($bin);
        }
    } catch (Throwable $e) {
        $qrImgData = '';
    }
} catch (Throwable $e) {
    // ✅ ここは header を触らず plain text で出す（warning回避）
    echo "device_activation_qr.php error\n";
    echo $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>端末アクティベーション</title>
    <style>
    body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont;
        background: #f7f7f7;
        padding: 30px;
    }

    .card {
        max-width: 420px;
        margin: auto;
        background: #fff;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
        text-align: center;
    }

    h1 {
        margin-bottom: 8px;
    }

    .qr {
        margin: 20px 0;
    }

    .code {
        font-size: 20px;
        font-weight: 700;
        letter-spacing: 2px;
    }

    .expire {
        color: #c00;
        font-weight: 700;
    }

    .small {
        color: #666;
        font-size: 14px;
    }
    </style>
</head>

<body>
    <div class="card">
        <h1>端末アクティベーション</h1>
        <p>iPadでこのQRを読み取ってください</p>

        <div class="qr">
            <?php if ($qrImgData !== ''): ?>
                <img src="<?= htmlspecialchars($qrImgData, ENT_QUOTES, 'UTF-8') ?>" alt="QRコード">
            <?php else: ?>
                <img src="<?= htmlspecialchars($qrImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="QRコード">
            <?php endif; ?>
        </div>

        <div class="code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></div>

        <p class="expire">有効期限：<?= htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8') ?></p>

        <p class="small">※ 1回のみ使用可能 / 5分で失効</p>
    </div>
</body>

</html>
