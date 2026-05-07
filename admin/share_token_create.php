<?php

declare(strict_types=1);

/**
 * ✅ /admin/share_token_create.php（POST専用 / JSON返却）
 * - 管理者ログイン済み前提
 * - store_id / date を受け取り、スタッフ共有用 token を作る
 * - 共有URL（/s/back_events_sp.php?token=...）を返す
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    http_response_code(401);
    echo 'tenant invalid';
    exit;
}
$tenantId = (int)$tenantId;

date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=utf-8');

// ✅ POST以外は弾く（ここをGETで開くと method not allowed になるのは正常）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// JSON body
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) $body = [];

$storeId = (int)($body['store_id'] ?? 0);
$date = (string)($body['date'] ?? '');

if ($storeId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'store_id invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'date invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

// DB
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
if (!$dbFile) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db.php not found'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ✅ store が tenant のものかチェック
$st = $pdo->prepare("SELECT id FROM stores WHERE tenant_id=? AND id=? LIMIT 1");
$st->execute([$tenantId, $storeId]);
if (!$st->fetch()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'store not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// token 生成
$token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

// 期限（例：24時間）
$expiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');

// 保存（テーブルが必要）
$ins = $pdo->prepare("
    INSERT INTO staff_share_tokens (token, tenant_id, store_id, date_ymd, expires_at, created_at)
    VALUES (:token, :tenant_id, :store_id, :date_ymd, :expires_at, NOW())
");
$ins->execute([
    ':token' => $token,
    ':tenant_id' => $tenantId,
    ':store_id' => $storeId,
    ':date_ymd' => $date,
    ':expires_at' => $expiresAt,
]);

$sharePath = '/s/back_events_sp.php?token=' . urlencode($token);

echo json_encode([
    'ok' => true,
    'share_url' => $sharePath,
    'expires_at' => $expiresAt,
], JSON_UNESCAPED_UNICODE);