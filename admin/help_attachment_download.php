<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    http_response_code(403);
    echo 'tenant missing';
    exit;
}

require_once __DIR__ . '/../api/lib/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$messageId = (int)($_GET['message_id'] ?? 0);
if ($messageId <= 0) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

$st = $pdo->prepare("
    SELECT hm.attachment_path, hm.attachment_name, hm.attachment_mime,
           ht.tenant_id, ht.store_id
    FROM help_messages hm
    JOIN help_threads ht ON ht.id = hm.thread_id
    WHERE hm.id = :id
    LIMIT 1
");
$st->execute([':id' => $messageId]);
$row = $st->fetch();

if (!$row || (int)$row['tenant_id'] !== $tenantId) {
    http_response_code(404);
    echo 'not found';
    exit;
}

$path = (string)($row['attachment_path'] ?? '');
if ($path === '') {
    http_response_code(404);
    echo 'file not found';
    exit;
}

$baseDir = realpath(__DIR__ . '/../_private/help_uploads');
if ($baseDir === false) {
    http_response_code(500);
    echo 'storage not found';
    exit;
}

$prefix = '_private/help_uploads/';
$rel = $path;
if (strpos($rel, $prefix) === 0) {
    $rel = substr($rel, strlen($prefix));
}
$abs = $baseDir . DIRECTORY_SEPARATOR . str_replace(['..', '\\'], ['', '/'], $rel);
$real = realpath($abs);
if ($real === false || strpos($real, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
    http_response_code(404);
    echo 'file not found';
    exit;
}

$mime = (string)($row['attachment_mime'] ?? '');
if ($mime === '') {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($real);
    if ($mime === '') $mime = 'application/octet-stream';
}

$origName = (string)($row['attachment_name'] ?? 'file');
$safeName = preg_replace('/[\r\n]+/', ' ', $origName) ?? 'file';
if ($safeName === '') $safeName = 'file';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($real));
header('Content-Disposition: inline; filename="' . rawurlencode($safeName) . '"');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

readfile($real);
exit;
