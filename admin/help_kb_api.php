<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_db.php';
$pdo = $pdo ?? db();

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$req = json_decode($raw, true);

if (!is_array($req)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

if (($req['action'] ?? '') !== 'update_keywords') {
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
    exit;
}

$id = (int)($req['id'] ?? 0);
$keywords = trim((string)($req['keywords'] ?? ''));

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'id_invalid']);
    exit;
}

$st = $pdo->prepare("
    UPDATE help_kb
    SET keywords = :k, updated_at = NOW()
    WHERE id = :id
");
$st->execute([
    ':k' => $keywords,
    ':id' => $id
]);

echo json_encode(['ok' => true]);