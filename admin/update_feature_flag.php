<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function json_exit(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ★最低限の保護（本番はセッション/管理者ログインに置き換えてOK）
    // いまは簡易で X-ADMIN-TOKEN を必須にします
    $ADMIN_TOKEN = 'azure202510';
    $reqToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if ($reqToken !== $ADMIN_TOKEN) {
        json_exit(401, ['ok' => false, 'error' => 'unauthorized']);
    }

    // JSON受け取り
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) json_exit(400, ['ok' => false, 'error' => 'invalid_json']);

    $tenantId = (int)($body['tenant_id'] ?? 0);
    $featureKey = trim((string)($body['feature_key'] ?? ''));
    $enabled = (int)($body['enabled'] ?? -1);

    if ($tenantId <= 0 || $featureKey === '' || !in_array($enabled, [0, 1], true)) {
        json_exit(400, ['ok' => false, 'error' => 'invalid_params']);
    }

    // DB接続（punch.phpと同じ）
    $pdo = new PDO(
        'mysql:host=mysql80-3.lolipop.lan;dbname=LAA1686629-azure;charset=utf8mb4',
        'LAA1686629',
        'ftpaiwebf0918',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // UPSERT
    $stmt = $pdo->prepare("
    INSERT INTO tenant_feature_flags (tenant_id, feature_key, enabled)
    VALUES (:tenant_id, :feature_key, :enabled)
    ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)
  ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':feature_key' => $featureKey,
        ':enabled' => $enabled,
    ]);

    json_exit(200, ['ok' => true, 'tenant_id' => $tenantId, 'feature_key' => $featureKey, 'enabled' => $enabled]);
} catch (Throwable $e) {
    json_exit(500, ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
}