<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_db.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$req = json_decode($raw ?: '[]', true);
if (!is_array($req)) $req = [];

if (!empty($_POST) || !empty($_FILES)) {
    $req = $_POST;
}

$action = (string)($req['action'] ?? '');

function out(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $st->execute([':col' => $column]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_help_message_attachments(PDO $pdo): void
{
    $cols = [
        'attachment_path' => "VARCHAR(255) NULL",
        'attachment_name' => "VARCHAR(255) NULL",
        'attachment_mime' => "VARCHAR(120) NULL",
        'attachment_size' => "INT UNSIGNED NULL",
    ];
    foreach ($cols as $col => $def) {
        if (!table_has_column($pdo, 'help_messages', $col)) {
            $pdo->exec("ALTER TABLE help_messages ADD COLUMN {$col} {$def}");
        }
    }
}

function get_msg_cols(PDO $pdo): array
{
    $hasSenderRole = table_has_column($pdo, 'help_messages', 'sender_role');
    $hasBody = table_has_column($pdo, 'help_messages', 'body');
    $hasSenderId = table_has_column($pdo, 'help_messages', 'sender_id');
    $hasAttachPath = table_has_column($pdo, 'help_messages', 'attachment_path');
    $hasAttachName = table_has_column($pdo, 'help_messages', 'attachment_name');
    $hasAttachMime = table_has_column($pdo, 'help_messages', 'attachment_mime');
    $hasAttachSize = table_has_column($pdo, 'help_messages', 'attachment_size');
    return [
        'role_col' => ($hasSenderRole ? 'sender_role' : 'sender_type'),
        'body_col' => ($hasBody ? 'body' : 'message_text'),
        'has_sender_id' => $hasSenderId,
        'has_attach_path' => $hasAttachPath,
        'has_attach_name' => $hasAttachName,
        'has_attach_mime' => $hasAttachMime,
        'has_attach_size' => $hasAttachSize,
    ];
}

function ensure_help_thread_views(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS help_thread_views (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            viewer_role VARCHAR(20) NOT NULL,
            viewer_session VARCHAR(128) NOT NULL,
            last_seen_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_thread_viewer (thread_id, viewer_session),
            KEY idx_seen (last_seen_at),
            KEY idx_thread (thread_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// help_messages の列差異を吸収
ensure_help_message_attachments($pdo);
ensure_help_thread_views($pdo);
$msgCols = get_msg_cols($pdo);

function threadRow(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT * FROM help_threads WHERE id=:id LIMIT 1");
    $st->execute([':id' => $id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) out(['ok' => false, 'error' => 'thread not found'], 404);
    return $t;
}

function listThreads(PDO $pdo): array
{
    global $msgCols;
    $previewCol = $msgCols['body_col'];
    // 最終メッセージのプレビューを取る
    $rows = $pdo->query("
        SELECT ht.*,
          (SELECT hm.{$previewCol} FROM help_messages hm WHERE hm.thread_id=ht.id ORDER BY hm.id DESC LIMIT 1) AS last_preview,
          (SELECT hm.attachment_path FROM help_messages hm WHERE hm.thread_id=ht.id ORDER BY hm.id DESC LIMIT 1) AS last_attach
        FROM help_threads ht
        WHERE ht.status <> 'closed'
        ORDER BY ht.last_message_at DESC, ht.id DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        foreach ($rows as &$r) {
            if ((string)($r['last_preview'] ?? '') === '' && (string)($r['last_attach'] ?? '') !== '') {
                $r['last_preview'] = '[ファイル]';
            }
            unset($r['last_attach']);
        }
        unset($r);
    }
    return $rows ?: [];
}

function listMessages(PDO $pdo, int $threadId): array
{
    global $msgCols;
    $roleCol = $msgCols['role_col'];
    $bodyCol = $msgCols['body_col'];
    $cols = "{$roleCol} AS sender_role, {$bodyCol} AS body, created_at, id";
    if ($msgCols['has_attach_path']) $cols .= ", attachment_path";
    if ($msgCols['has_attach_name']) $cols .= ", attachment_name";
    if ($msgCols['has_attach_mime']) $cols .= ", attachment_mime";
    if ($msgCols['has_attach_size']) $cols .= ", attachment_size";
    $where = "thread_id=:tid";
    if ($msgCols['has_attach_path']) {
        $where .= " AND (COALESCE({$bodyCol}, '') <> '' OR attachment_path IS NOT NULL)";
    } else {
        $where .= " AND COALESCE({$bodyCol}, '') <> ''";
    }
    $st = $pdo->prepare("
        SELECT {$cols}
        FROM help_messages
        WHERE {$where}
        ORDER BY id ASC
        LIMIT 400
    ");
    $st->execute([':tid' => $threadId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$msgCols['has_attach_path']) {
        foreach ($rows as &$r) {
            $r['attachment_path'] = null;
            $r['attachment_name'] = null;
            $r['attachment_mime'] = null;
            $r['attachment_size'] = null;
        }
        unset($r);
    }
    return $rows;
}

function addMessage(PDO $pdo, int $threadId, string $senderRole, string $text, ?array $attach = null): void
{
    global $msgCols;
    $roleCol = $msgCols['role_col'];
    $bodyCol = $msgCols['body_col'];
    $hasSenderId = $msgCols['has_sender_id'];
    $cols = "thread_id, {$roleCol}, {$bodyCol}";
    $vals = ":tid, :sender, :txt";
    $params = [':tid' => $threadId, ':sender' => $senderRole, ':txt' => $text];
    if ($hasSenderId) {
        $cols .= ", sender_id";
        $vals .= ", :sender_id";
        $params[':sender_id'] = 0;
    }
    if ($attach && $msgCols['has_attach_path']) {
        $cols .= ", attachment_path, attachment_name, attachment_mime, attachment_size";
        $vals .= ", :apath, :aname, :amime, :asize";
        $params[':apath'] = $attach['path'];
        $params[':aname'] = $attach['name'];
        $params[':amime'] = $attach['mime'];
        $params[':asize'] = $attach['size'];
    }
    $st = $pdo->prepare("
        INSERT INTO help_messages({$cols})
        VALUES({$vals})
    ");
    $st->execute($params);

    $pdo->prepare("UPDATE help_threads SET last_message_at=NOW(), updated_at=NOW() WHERE id=:id")
        ->execute([':id' => $threadId]);
}

function save_help_attachment(array $file, int $tenantId, int $storeId, int $threadId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('upload error');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException('upload invalid');
    }
    $maxBytes = 10 * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('file too large');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file((string)$file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/zip' => 'zip',
    ];
    if (!isset($allowed[$mime])) {
        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $extMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'zip' => 'application/zip',
        ];
        if ($ext !== '' && isset($extMap[$ext])) {
            $mime = $extMap[$ext];
        } else {
            throw new RuntimeException('invalid file type');
        }
    }

    $ext = $allowed[$mime];
    $baseRoot = __DIR__ . '/../_private/help_uploads';
    $baseDir = $baseRoot . '/t' . $tenantId . '/s' . $storeId . '/thread' . $threadId;
    if (!is_dir($baseDir)) {
        if (!@mkdir($baseDir, 0770, true) && !is_dir($baseDir)) {
            throw new RuntimeException('mkdir failed');
        }
    }
    $rand = bin2hex(random_bytes(8));
    $filename = $rand . '.' . $ext;
    $absPath = $baseDir . '/' . $filename;
    if (!@move_uploaded_file((string)$file['tmp_name'], $absPath)) {
        throw new RuntimeException('move failed');
    }
    $dbPath = '_private/help_uploads'
        . '/t' . $tenantId
        . '/s' . $storeId
        . '/thread' . $threadId
        . '/' . $filename;
    $orig = (string)($file['name'] ?? ('file.' . $ext));
    return [
        'path' => $dbPath,
        'name' => $orig,
        'mime' => $mime,
        'size' => $size,
    ];
}

try {
    if ($action === 'list') {
        $threads = listThreads($pdo);
        out(['ok' => true, 'threads' => $threads]);
    }

    if ($action === 'history') {
        $threadId = (int)($req['thread_id'] ?? 0);
        if ($threadId <= 0) out(['ok' => false, 'error' => 'thread_id invalid'], 400);
        $t = threadRow($pdo, $threadId);
        $msgs = listMessages($pdo, $threadId);
        out(['ok' => true, 'thread' => [
            'id' => (int)$t['id'],
            'tenant_id' => (int)$t['tenant_id'],
            'store_id' => $t['store_id'] !== null ? (int)$t['store_id'] : null,
            'status' => (string)$t['status'],
            'last_message_at' => (string)($t['last_message_at'] ?? ''),
        ], 'messages' => $msgs]);
    }

    if ($action === 'send') {
        $threadId = (int)($req['thread_id'] ?? 0);
        $msg = trim((string)($req['message'] ?? ''));
        if ($threadId <= 0) out(['ok' => false, 'error' => 'thread_id invalid'], 400);
        if ($msg === '') out(['ok' => false, 'error' => 'message empty'], 400);

        $t = threadRow($pdo, $threadId);

        // 人が返信したらステータス open に寄せる
        addMessage($pdo, $threadId, 'support_admin', $msg);
        $pdo->prepare("UPDATE help_threads SET status='open', last_message_at=NOW(), updated_at=NOW() WHERE id=:id")
            ->execute([':id' => $threadId]);

        out(['ok' => true]);
    }

    if ($action === 'seen') {
        $threadId = (int)($req['thread_id'] ?? 0);
        if ($threadId <= 0) out(['ok' => false, 'error' => 'thread_id invalid'], 400);
        threadRow($pdo, $threadId);
        $sid = session_id();
        if ($sid === '') $sid = bin2hex(random_bytes(8));
        $st = $pdo->prepare("
            INSERT INTO help_thread_views (thread_id, viewer_role, viewer_session, last_seen_at)
            VALUES (:tid, 'support_admin', :sid, NOW())
            ON DUPLICATE KEY UPDATE last_seen_at=NOW()
        ");
        $st->execute([':tid' => $threadId, ':sid' => $sid]);
        out(['ok' => true]);
    }

    if ($action === 'send_upload') {
        $threadId = (int)($req['thread_id'] ?? 0);
        $msg = trim((string)($req['message'] ?? ''));
        if ($threadId <= 0) out(['ok' => false, 'error' => 'thread_id invalid'], 400);
        if (empty($_FILES['file']) && $msg === '') out(['ok' => false, 'error' => 'message empty'], 400);

        $t = threadRow($pdo, $threadId);
        $attach = null;
        if (!empty($_FILES['file'])) {
            $attach = save_help_attachment($_FILES['file'], (int)$t['tenant_id'], (int)$t['store_id'], $threadId);
        }

        addMessage($pdo, $threadId, 'support_admin', $msg, $attach);
        $pdo->prepare("UPDATE help_threads SET status='open', last_message_at=NOW(), updated_at=NOW() WHERE id=:id")
            ->execute([':id' => $threadId]);

        out(['ok' => true]);
    }

    if ($action === 'close') {
        $threadId = (int)($req['thread_id'] ?? 0);
        if ($threadId <= 0) out(['ok' => false, 'error' => 'thread_id invalid'], 400);
        threadRow($pdo, $threadId);
        $pdo->prepare("UPDATE help_threads SET status='closed', last_message_at=NOW(), updated_at=NOW() WHERE id=:id")
            ->execute([':id' => $threadId]);
        out(['ok' => true]);
    }

    out(['ok' => false, 'error' => 'unknown action'], 400);
} catch (Throwable $e) {
    out(['ok' => false, 'error' => 'server error', 'detail' => $e->getMessage()], 500);
}
