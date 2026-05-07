<?php

declare(strict_types=1);

/**
 * ✅ /admin/_share_token.php（新規）
 * 共有トークンの生成・検証
 */

function share_token_secret(): string
{
    $p = __DIR__ . '/_share_token_secret.php';
    if (!is_file($p)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "_share_token_secret.php not found";
        exit;
    }
    $cfg = require $p;
    $s = (string)($cfg['secret'] ?? '');
    if ($s === '' || strlen($s) < 16) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "share token secret is invalid (too short)";
        exit;
    }
    return $s;
}

function share_token_generate_raw(): string
{
    // URLに載せるトークン本体（漏れても期限で切れる）
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function share_token_hash(string $raw): string
{
    // DBには生トークンを保存しない（漏洩耐性）
    return hash_hmac('sha256', $raw, share_token_secret());
}

function share_token_read_from_request(): string
{
    $t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
    return trim($t);
}

/**
 * ✅ 共有トークン検証
 * 戻り値: tokenRow（tenant_id/store_id/permissions_json/expires_at など）
 */
function share_token_require(PDO $pdo): array
{
    $raw = share_token_read_from_request();
    if ($raw === '') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "共有リンクが無効です（トークンなし）";
        exit;
    }

    $hash = share_token_hash($raw);

    $st = $pdo->prepare("
        SELECT *
        FROM share_tokens
        WHERE token_hash = :h
        LIMIT 1
    ");
    $st->execute([':h' => $hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "共有リンクが無効です（トークン不一致）";
        exit;
    }

    if (!empty($row['revoked_at'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "共有リンクは無効化されています";
        exit;
    }

    $exp = (string)($row['expires_at'] ?? '');
    if ($exp === '' || strtotime($exp) < time()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "共有リンクの有効期限が切れています";
        exit;
    }

    return $row;
}

function share_token_permissions(array $tokenRow): array
{
    $j = json_decode((string)($tokenRow['permissions_json'] ?? '{}'), true);
    return is_array($j) ? $j : [];
}

function share_token_can(array $perms, string $key): bool
{
    return !empty($perms[$key]);
}