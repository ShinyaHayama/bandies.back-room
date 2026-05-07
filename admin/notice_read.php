<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/app_notices.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$token = (string)($_POST['csrf_token'] ?? '');
if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

$returnTo = (string)($_POST['return_to'] ?? '/admin/index.php');
if ($returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//')) {
    $returnTo = '/admin/index.php';
}

try {
    app_notices_ensure_schema($pdo);
    app_notices_mark_all_read($pdo, (int)($_SESSION['tenant_admin_user_id'] ?? 0));
} catch (Throwable $e) {
    // 通知既読の失敗で操作中ページを止めない
}

header('Location: ' . $returnTo);
exit;
