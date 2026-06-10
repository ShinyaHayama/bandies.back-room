<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/../lib/punch_capture.php';

if (!isset($tenantId) || (int)$tenantId <= 0) {
    http_response_code(401);
    exit;
}
$tenantId = (int)$tenantId;
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

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
if ($dbFile === null) {
    http_response_code(500);
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

try {
    $st = $pdo->prepare("
        SELECT punch_face_photo_path
        FROM time_punches
        WHERE id = :id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $st->execute([':id' => $id, ':tenant_id' => $tenantId]);
    $row = $st->fetch();
} catch (Throwable $e) {
    http_response_code(404);
    exit;
}

$rel = (string)($row['punch_face_photo_path'] ?? '');
$path = punch_capture_private_path($rel);
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
