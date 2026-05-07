<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/back_event_confirm.php
 * ✅ 書き込み場所: このファイルを「新規作成」
 *
 * 役割:
 * - back_events の1件を confirmed にする（CSRF必須）
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
    echo 'db.php not found';
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = (string)($_SESSION['csrf_token'] ?? '');
$postCsrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '' || $postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
    http_response_code(400);
    echo 'CSRF invalid';
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$return = (string)($_POST['return'] ?? '/admin/back_events.php');
if ($return === '' || $return[0] !== '/' || strpos($return, '/admin/') !== 0) {
    $return = '/admin/back_events.php';
}

if ($id <= 0) {
    http_response_code(400);
    echo 'id invalid';
    exit;
}

// 自テナントのものだけ確定
$st = $pdo->prepare("
    UPDATE back_events
    SET status='confirmed', updated_at=CURRENT_TIMESTAMP
    WHERE id=:id AND tenant_id=:tenant_id
    LIMIT 1
");
$st->execute([':id' => $id, ':tenant_id' => $tenantId]);

header('Location: ' . $return);
exit;