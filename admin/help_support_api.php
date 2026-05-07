<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

/*
  ✅ ここをあなたの「スーパー管理者判定」に差し替えてください
  if (!is_super_admin()) { http_response_code(403); exit; }
*/

date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
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
if (!$dbFile) out(['ok' => false, 'error' => 'db.php not found'], 500);
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');

$in = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $in = json_decode((string)$raw, true);
    if (!is_array($in)) $in = [];
    $action = (string)($in['action'] ?? $action);
}

if ($action === 'list') {
    // 既存の tenants / stores がある前提でタイトル表示（無ければID表示）
    $sql = "
    SELECT
      ht.id,
      ht.tenant_id,
      ht.store_id,
      ht.status,
      ht.last_message_at,
      COALESCE(t.name, CONCAT('tenant#', ht.tenant_id)) AS tenant_name,
      COALESCE(s.name, CONCAT('store#', ht.store_id)) AS store_name
    FROM help_threads ht
    LEFT JOIN tenants t ON t.id = ht.tenant_id
    LEFT JOIN stores s ON s.id = ht.store_id
    WHERE ht.status='open'
    ORDER BY COALESCE(ht.last_message_at, ht.updated_at) DESC, ht.id DESC
    LIMIT 200
  ";
    $rows = [];
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        // tenants/stores が無い環境でも落とさない
        $rows = $pdo->query("
      SELECT id, tenant_id, store_id, status, last_message_at
      FROM help_threads
      WHERE status='open'
      ORDER BY COALESCE(last_message_at, updated_at) DESC, id DESC
      LIMIT 200
    ")->fetchAll();
    }

    $threads = [];
    foreach ($rows as $r) {
        $title = isset($r['tenant_name'], $r['store_name'])
            ? ((string)$r['tenant_name'] . ' / ' . (string)$r['store_name'])
            : ('tenant#' . (int)$r['tenant_id'] . ' / store#' . (int)$r['store_id']);
        $sub = 'thread#' . (int)$r['id'] . ' / last=' . (string)($r['last_message_at'] ?? '');
        $threads[] = ['id' => (int)$r['id'], 'title' => $title, 'sub' => $sub];
    }

    out(['ok' => true, 'threads' => $threads]);
}

if ($action === 'fetch') {
    $threadId = (int)($_GET['thread_id'] ?? 0);
    if ($threadId <= 0) out(['ok' => false, 'error' => 'invalid'], 400);

    $st = $pdo->prepare("SELECT id FROM help_threads WHERE id=? LIMIT 1");
    $st->execute([$threadId]);
    if (!(int)$st->fetchColumn()) out(['ok' => false, 'error' => 'not_found'], 404);

    $st2 = $pdo->prepare("SELECT sender_role, body, created_at FROM help_messages WHERE thread_id=? ORDER BY id ASC LIMIT 500");
    $st2->execute([$threadId]);
    out(['ok' => true, 'messages' => $st2->fetchAll()]);
}

if ($action === 'send') {
    $threadId = (int)($in['thread_id'] ?? 0);
    $body = trim((string)($in['body'] ?? ''));
    if ($threadId <= 0 || $body === '') out(['ok' => false, 'error' => 'invalid'], 400);

    $st = $pdo->prepare("SELECT id FROM help_threads WHERE id=? AND status='open' LIMIT 1");
    $st->execute([$threadId]);
    if (!(int)$st->fetchColumn()) out(['ok' => false, 'error' => 'thread_closed_or_not_found'], 404);

    $ins = $pdo->prepare("INSERT INTO help_messages (thread_id, sender_role, sender_id, body) VALUES (?,?,?,?)");
    $ins->execute([$threadId, 'support_admin', 0, $body]);

    $up = $pdo->prepare("UPDATE help_threads SET last_message_at=CURRENT_TIMESTAMP WHERE id=?");
    $up->execute([$threadId]);

    out(['ok' => true]);
}

if ($action === 'close') {
    $threadId = (int)($in['thread_id'] ?? 0);
    if ($threadId <= 0) out(['ok' => false, 'error' => 'invalid'], 400);

    $up = $pdo->prepare("UPDATE help_threads SET status='closed', updated_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
    $up->execute([$threadId]);

    out(['ok' => true]);
}

out(['ok' => false, 'error' => 'unknown_action'], 400);
