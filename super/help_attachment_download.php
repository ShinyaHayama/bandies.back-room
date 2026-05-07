<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_db.php';

$messageId = (int)($_GET['message_id'] ?? 0);
if ($messageId <= 0) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

$st = $pdo->prepare("
    SELECT attachment_path, attachment_name, attachment_mime
    FROM help_messages
    WHERE id = :id
    LIMIT 1
");
$st->execute([':id' => $messageId]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
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
