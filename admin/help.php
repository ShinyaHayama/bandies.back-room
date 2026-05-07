<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/../lib/mailer.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

require_once __DIR__ . '/_db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('db error');
}

$storesStmt = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id = :t ORDER BY id ASC");
$storesStmt->execute([':t' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (!$stores) {
    http_response_code(400);
    exit('stores なし');
}
$storeId = (int)($_GET['store_id'] ?? 0);
$storeIds = array_map(fn($s) => (int)$s['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) {
    $storeId = (int)$stores[0]['id'];
}
$storeName = '';
foreach ($stores as $st) {
    if ((int)$st['id'] === $storeId) {
        $storeName = (string)$st['name'];
        break;
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
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

function ensure_help_tables(PDO $pdo): void
{
    if (!table_exists($pdo, 'help_threads')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_threads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id BIGINT UNSIGNED NOT NULL,
                store_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                created_by_admin_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_message_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tenant_store (tenant_id, store_id),
                KEY idx_status (status),
                KEY idx_last (last_message_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    if (!table_exists($pdo, 'help_messages')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                thread_id BIGINT UNSIGNED NOT NULL,
                sender_role VARCHAR(20) NULL,
                sender_type VARCHAR(20) NULL,
                sender_id BIGINT UNSIGNED NULL,
                body TEXT NULL,
                message_text TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_thread (thread_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    $threadCols = [
        'tenant_id' => "BIGINT UNSIGNED NOT NULL",
        'store_id' => "BIGINT UNSIGNED NOT NULL",
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'open'",
        'created_by_admin_id' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
        'last_message_at' => "DATETIME NULL",
        'created_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($threadCols as $col => $def) {
        if (!table_has_column($pdo, 'help_threads', $col)) {
            $pdo->exec("ALTER TABLE help_threads ADD COLUMN {$col} {$def}");
        }
    }

    $msgCols = [
        'sender_role' => "VARCHAR(20) NULL",
        'sender_type' => "VARCHAR(20) NULL",
        'sender_id' => "BIGINT UNSIGNED NULL",
        'body' => "TEXT NULL",
        'message_text' => "TEXT NULL",
        'attachment_path' => "VARCHAR(255) NULL",
        'attachment_name' => "VARCHAR(255) NULL",
        'attachment_mime' => "VARCHAR(120) NULL",
        'attachment_size' => "INT UNSIGNED NULL",
    ];
    foreach ($msgCols as $col => $def) {
        if (!table_has_column($pdo, 'help_messages', $col)) {
            $pdo->exec("ALTER TABLE help_messages ADD COLUMN {$col} {$def}");
        }
    }
}

function ensure_help_views(PDO $pdo): void
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

ensure_help_tables($pdo);
ensure_help_views($pdo);

$hasThreadStatus = table_has_column($pdo, 'help_threads', 'status');
$hasThreadLast   = table_has_column($pdo, 'help_threads', 'last_message_at');
$hasThreadCreatedBy = table_has_column($pdo, 'help_threads', 'created_by_admin_id');

$hasMsgSenderRole = table_has_column($pdo, 'help_messages', 'sender_role');
$hasMsgBody = table_has_column($pdo, 'help_messages', 'body');
$hasMsgSenderType = table_has_column($pdo, 'help_messages', 'sender_type');
$hasMsgText = table_has_column($pdo, 'help_messages', 'message_text');
$hasMsgSenderId = table_has_column($pdo, 'help_messages', 'sender_id');
$hasMsgAttachPath = table_has_column($pdo, 'help_messages', 'attachment_path');
$hasMsgAttachName = table_has_column($pdo, 'help_messages', 'attachment_name');
$hasMsgAttachMime = table_has_column($pdo, 'help_messages', 'attachment_mime');
$hasMsgAttachSize = table_has_column($pdo, 'help_messages', 'attachment_size');

$flashError = '';

function format_bytes(int $bytes): string
{
    if ($bytes <= 0) return '';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

function save_help_attachment(array $file, int $tenantId, int $storeId, int $threadId, array &$errors): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'ファイルのアップロードに失敗しました';
        return null;
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        $errors[] = 'ファイルのアップロードに失敗しました';
        return null;
    }

    $maxBytes = 10 * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        $errors[] = 'ファイルサイズは10MB以内にしてください';
        return null;
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
            $errors[] = '対応していないファイル形式です（検出MIME: ' . $mime . '）';
            return null;
        }
    }

    $ext = $allowed[$mime];
    $baseRoot = __DIR__ . '/../_private/help_uploads';
    $baseDir = $baseRoot . '/t' . $tenantId . '/s' . $storeId . '/thread' . $threadId;
    if (!is_dir($baseDir)) {
        if (!@mkdir($baseDir, 0770, true) && !is_dir($baseDir)) {
            $errors[] = '保存フォルダを作成できません';
            return null;
        }
    }

    $rand = bin2hex(random_bytes(8));
    $filename = $rand . '.' . $ext;
    $absPath = $baseDir . '/' . $filename;
    if (!@move_uploaded_file((string)$file['tmp_name'], $absPath)) {
        $errors[] = 'ファイルを保存できませんでした';
        return null;
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

function find_thread_id(PDO $pdo, int $tenantId, int $storeId, bool $hasStatus): int
{
    if ($hasStatus) {
        $st = $pdo->prepare("SELECT id FROM help_threads WHERE tenant_id=? AND store_id=? AND status='open' ORDER BY id DESC LIMIT 1");
        $st->execute([$tenantId, $storeId]);
    } else {
        $st = $pdo->prepare("SELECT id FROM help_threads WHERE tenant_id=? AND store_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([$tenantId, $storeId]);
    }
    return (int)($st->fetchColumn() ?: 0);
}

function create_thread(PDO $pdo, int $tenantId, int $storeId, bool $hasStatus, bool $hasCreatedBy, bool $hasLast): int
{
    $cols = ['tenant_id', 'store_id'];
    $vals = [':t', ':s'];
    $params = [':t' => $tenantId, ':s' => $storeId];
    if ($hasStatus) {
        $cols[] = 'status';
        $vals[] = ':status';
        $params[':status'] = 'open';
    }
    if ($hasCreatedBy) {
        $cols[] = 'created_by_admin_id';
        $vals[] = ':created_by';
        $params[':created_by'] = 0;
    }
    if ($hasLast) {
        $cols[] = 'last_message_at';
        $vals[] = 'CURRENT_TIMESTAMP';
    }

    $sql = "INSERT INTO help_threads (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $ins = $pdo->prepare($sql);
    $ins->execute($params);
    return (int)$pdo->lastInsertId();
}

function send_new_help_mail(PDO $pdo, int $tenantId, int $storeId, int $threadId, string $message): void
{
    try {
        $tenantName = '';
        $storeName = '';
        $st = $pdo->prepare("SELECT name FROM tenants WHERE id=? LIMIT 1");
        $st->execute([$tenantId]);
        $tenantName = (string)($st->fetchColumn() ?: '');
        $st2 = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
        $st2->execute([$storeId]);
        $storeName = (string)($st2->fetchColumn() ?: '');

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $host !== '' ? ($scheme . '://' . $host) : '';
        $link = $baseUrl . '/super/help.php?thread_id=' . urlencode((string)$threadId);

        $subject = '【シメナビ】新規お問い合わせ';
        $body = "新規お問い合わせが届きました。\n\n"
            . "テナント: " . ($tenantName !== '' ? $tenantName : ('ID ' . $tenantId)) . "\n"
            . "店舗: " . ($storeName !== '' ? $storeName : ('ID ' . $storeId)) . "\n"
            . "スレッド: #" . $threadId . "\n";
        if ($message !== '') {
            $body .= "\n内容:\n" . $message . "\n";
        }
        $body .= "\nチャット画面:\n" . $link . "\n";

        $to = 'work@fader.group';
        send_mail($to, $subject, $body, 'SHIMENABI', '');
    } catch (Throwable $e) {
        // ignore mail errors
    }
}

function is_super_viewing(PDO $pdo, int $threadId, int $seconds = 60): bool
{
    $st = $pdo->prepare("
        SELECT 1
        FROM help_thread_views
        WHERE thread_id = :tid
          AND viewer_role = 'support_admin'
          AND last_seen_at >= (NOW() - INTERVAL :sec SECOND)
        LIMIT 1
    ");
    $st->bindValue(':tid', $threadId, PDO::PARAM_INT);
    $st->bindValue(':sec', $seconds, PDO::PARAM_INT);
    $st->execute();
    return (bool)$st->fetchColumn();
}

function insert_support_auto_reply(PDO $pdo, int $threadId, string $text, bool $hasMsgSenderRole, bool $hasMsgBody, bool $hasMsgSenderType, bool $hasMsgText, bool $hasMsgSenderId): void
{
    $cols = ['thread_id'];
    $vals = [':tid'];
    $params = [':tid' => $threadId];
    if ($hasMsgSenderRole && $hasMsgBody) {
        $cols[] = 'sender_role';
        $vals[] = ':role';
        $params[':role'] = 'support_admin';
        $cols[] = 'body';
        $vals[] = ':body';
        $params[':body'] = $text;
    } elseif ($hasMsgSenderType && $hasMsgText) {
        $cols[] = 'sender_type';
        $vals[] = ':role';
        $params[':role'] = 'support_admin';
        $cols[] = 'message_text';
        $vals[] = ':body';
        $params[':body'] = $text;
    }
    if ($hasMsgSenderId) {
        $cols[] = 'sender_id';
        $vals[] = ':sender_id';
        $params[':sender_id'] = 0;
    }
    $sql = "INSERT INTO help_messages (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $ins = $pdo->prepare($sql);
    $ins->execute($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim((string)($_POST['message'] ?? ''));
    $postStoreId = (int)($_POST['store_id'] ?? $storeId);
    if ($postStoreId <= 0 || !in_array($postStoreId, $storeIds, true)) {
        $postStoreId = $storeId;
    }

    $hasFile = isset($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($message !== '' || $hasFile) {
        $threadId = find_thread_id($pdo, $tenantId, $postStoreId, $hasThreadStatus);
        if ($threadId <= 0) {
            $threadId = create_thread($pdo, $tenantId, $postStoreId, $hasThreadStatus, $hasThreadCreatedBy, $hasThreadLast);
            send_new_help_mail($pdo, $tenantId, $postStoreId, $threadId, $message);
        }

        $errors = [];
        $attach = null;
        if ($hasFile && isset($_FILES['attachment'])) {
            $attach = save_help_attachment($_FILES['attachment'], $tenantId, $postStoreId, $threadId, $errors);
        }
        if ($attach === null && $message === '' && !empty($errors)) {
            $flashError = implode(' / ', $errors);
            $storeId = $postStoreId;
        } else {

            $cols = ['thread_id'];
            $vals = [':tid'];
            $params = [':tid' => $threadId];
            if ($hasMsgSenderRole && $hasMsgBody) {
                $cols[] = 'sender_role';
                $vals[] = ':role';
                $params[':role'] = 'tenant_admin';
                $cols[] = 'body';
                $vals[] = ':body';
                $params[':body'] = $message;
            } elseif ($hasMsgSenderType && $hasMsgText) {
                $cols[] = 'sender_type';
                $vals[] = ':role';
                $params[':role'] = 'tenant_admin';
                $cols[] = 'message_text';
                $vals[] = ':body';
                $params[':body'] = $message;
            }
            if ($hasMsgSenderId) {
                $cols[] = 'sender_id';
                $vals[] = ':sender_id';
                $params[':sender_id'] = 0;
            }
            if ($attach) {
                if ($hasMsgAttachPath) {
                    $cols[] = 'attachment_path';
                    $vals[] = ':apath';
                    $params[':apath'] = $attach['path'];
                }
                if ($hasMsgAttachName) {
                    $cols[] = 'attachment_name';
                    $vals[] = ':aname';
                    $params[':aname'] = $attach['name'];
                }
                if ($hasMsgAttachMime) {
                    $cols[] = 'attachment_mime';
                    $vals[] = ':amime';
                    $params[':amime'] = $attach['mime'];
                }
                if ($hasMsgAttachSize) {
                    $cols[] = 'attachment_size';
                    $vals[] = ':asize';
                    $params[':asize'] = $attach['size'];
                }
            }
            $sql = "INSERT INTO help_messages (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
            $ins = $pdo->prepare($sql);
            $ins->execute($params);

            if ($hasThreadLast) {
                $up = $pdo->prepare("UPDATE help_threads SET last_message_at=CURRENT_TIMESTAMP WHERE id=?");
                $up->execute([$threadId]);
            }

            if (!is_super_viewing($pdo, $threadId, 60)) {
                $autoText = 'お問い合わせありがとうございます。担当者が内容を確認し、折り返しご連絡いたします。';
                insert_support_auto_reply($pdo, $threadId, $autoText, $hasMsgSenderRole, $hasMsgBody, $hasMsgSenderType, $hasMsgText, $hasMsgSenderId);
            }
        }
    }

    if ($flashError === '') {
        header("Location: /admin/help.php?store_id={$postStoreId}");
        exit;
    }
}

$threadId = find_thread_id($pdo, $tenantId, $storeId, $hasThreadStatus);
$messages = [];
$lastMessageId = 0;
if ($threadId > 0) {
    if ($hasMsgSenderRole && $hasMsgBody) {
        $st2 = $pdo->prepare("
            SELECT id, sender_role AS sender_role, body AS body, created_at,
                   attachment_path, attachment_name, attachment_mime, attachment_size
            FROM help_messages
            WHERE thread_id=?
            ORDER BY id ASC
            LIMIT 300
        ");
    } else {
        $st2 = $pdo->prepare("
            SELECT id, sender_type AS sender_role, message_text AS body, created_at,
                   attachment_path, attachment_name, attachment_mime, attachment_size
            FROM help_messages
            WHERE thread_id=?
            ORDER BY id ASC
            LIMIT 300
        ");
    }
    $st2->execute([$threadId]);
    $messages = $st2->fetchAll();
    if ($messages) {
        foreach ($messages as $m) {
            $mid = (int)($m['id'] ?? 0);
            if ($mid > $lastMessageId) $lastMessageId = $mid;
        }
    }
}

?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>お問い合わせ</title>
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #ffffff;
            --line: #e5e7eb;
            --text: #0f172a;
            --muted: #64748b;
            --primary: #365EAB;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans JP", sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .wrap {
            max-width: 980px;
            margin: 0 auto;
            padding: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .06);
            overflow: hidden;
        }

        .top {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .title {
            font-size: 18px;
            font-weight: 900;
        }

        .pill {
            padding: 6px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            font-weight: 800;
            font-size: 12px;
            color: #111827;
        }

        .errorBox {
            margin: 12px 16px 0;
            padding: 10px 12px;
            border-radius: 12px;
            background: #fff1f2;
            color: #9f1239;
            border: 1px solid #fecdd3;
            font-weight: 700;
            font-size: 13px;
        }

        .chat {
            padding: 16px 14px;
            background: #e6e6e6;
            max-height: 60vh;
            overflow: auto;
        }

        .row {
            display: flex;
            margin: 8px 0;
        }
        .row.me { justify-content: flex-end; }
        .row.staff { justify-content: flex-start; }
        .row.sys { justify-content: center; }

        .bubbleWrap {
            display: flex;
            flex-direction: column;
            max-width: 70%;
        }
        .row.me .bubbleWrap { align-items: flex-end; }
        .row.staff .bubbleWrap { align-items: flex-start; }

        .bub {
            padding: 8px 12px;
            border-radius: 16px;
            border: 0;
            background: #fff;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 15px;
            line-height: 1.5;
            box-shadow: 0 1px 0 rgba(0,0,0,.06);
            display: inline-block;
            max-width: 100%;
        }

        .row.me .bub {
            background: #9eea6a;
            color: #111827;
        }

        .row.sys .bub {
            background: #ffffff;
            color: #475569;
        }

        .meta {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }

        .attachBox {
            margin-top: 4px;
        }

        .attachImg {
            display: block;
            max-width: 240px;
            border-radius: 12px;
        }

        .attachLink {
            display: inline-block;
            font-size: 13px;
            color: #111827;
            text-decoration: none;
            padding: 6px 8px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #fff;
        }

        .inputRow {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            width: 100%;
        }

        .fileInput {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .actionBtns {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .fileName {
            font-size: 12px;
            color: #475569;
            max-width: 240px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .bar {
            display: flex;
            padding: 14px;
            border-top: 1px solid var(--line);
            background: #fff;
        }

        textarea {
            flex: 1 1 auto;
            min-height: 64px;
            max-height: 200px;
            resize: vertical;
            padding: 12px;
            border: 2px solid var(--line);
            border-radius: 14px;
            font-size: 15px;
            outline: none;
            width: 100%;
        }

        .lineSend {
            background: #06c755;
            border: 0;
            color: #fff;
            font-weight: 800;
            padding: 10px 16px;
            border-radius: 16px;
            cursor: pointer;
        }

        .attachBtn {
            width: 40px;
            height: 40px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .attachBtn svg {
            width: 18px;
            height: 18px;
            stroke: #0f172a;
        }

        .fileInput input[type="file"] {
            display: none;
        }

        textarea:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(147, 197, 253, .35);
        }

        button {
            padding: 12px 14px;
            border: 0;
            border-radius: 14px;
            font-weight: 900;
            font-size: 14px;
            cursor: pointer;
        }

        .btn {
            background: var(--primary);
            color: #fff;
        }

        .btn2 {
            background: #e5e7eb;
            color: #111;
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card">
            <div class="top">
                <div class="title">💬 お問い合わせ</div>
                <div class="pill">店舗：<?= h($storeName !== '' ? $storeName : ('ID ' . $storeId)) ?></div>
                <?php if ($threadId > 0): ?>
                <div class="pill">thread#<?= (int)$threadId ?></div>
                <?php endif; ?>
            </div>
            <?php if ($flashError !== ''): ?>
            <div class="errorBox"><?= h($flashError) ?></div>
            <?php endif; ?>

            <div class="chat" id="chat">
                <?php if (empty($messages)): ?>
                    <div class="row sys">
                        <div class="bub">お問い合わせ内容を入力して送信してください。</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $m): ?>
                        <?php
                            $role = (string)($m['sender_role'] ?? '');
                            $body = (string)($m['body'] ?? '');
                            $createdAt = (string)($m['created_at'] ?? '');
                            $attachPath = (string)($m['attachment_path'] ?? '');
                            $attachName = (string)($m['attachment_name'] ?? '');
                            $attachMime = (string)($m['attachment_mime'] ?? '');
                            $attachSize = (int)($m['attachment_size'] ?? 0);
                            $rowClass = 'sys';
                            if ($role === 'tenant_admin') $rowClass = 'me';
                            if ($role === 'support_admin') $rowClass = 'staff';
                        ?>
                        <div class="row <?= h($rowClass) ?>">
                            <div class="bubbleWrap">
                                <?php if ($body !== ''): ?>
                                <div class="bub"><?= h($body) ?></div>
                                <?php endif; ?>
                                <?php if ($attachPath !== ''): ?>
                                <div class="attachBox">
                                    <?php if (in_array($attachMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)): ?>
                                        <a href="/admin/help_attachment_download.php?message_id=<?= (int)$m['id'] ?>" target="_blank" rel="noopener">
                                            <img class="attachImg" src="/admin/help_attachment_download.php?message_id=<?= (int)$m['id'] ?>" alt="<?= h($attachName !== '' ? $attachName : 'image') ?>">
                                        </a>
                                    <?php endif; ?>
                                    <a class="attachLink" href="/admin/help_attachment_download.php?message_id=<?= (int)$m['id'] ?>" target="_blank" rel="noopener">
                                        <?= h($attachName !== '' ? $attachName : 'file') ?>
                                        <?php if ($attachSize > 0): ?>
                                            (<?= h(format_bytes($attachSize)) ?>)
                                        <?php endif; ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                                <?php if ($createdAt !== ''): ?>
                                <div class="meta"><?= h($createdAt) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form class="bar" method="post" action="/admin/help.php?store_id=<?= (int)$storeId ?>" enctype="multipart/form-data">
                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                <div class="inputRow">
                    <div class="fileInput">
                        <label class="attachBtn">
                            <input type="file" name="attachment" id="attachmentInput" accept="image/*,.heic,.heif,.pdf,.txt,.csv,.xlsx,.xls,.doc,.docx,.zip">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21.44 11.05l-8.49 8.49a5 5 0 0 1-7.07-7.07l8.49-8.49a3.5 5 0 0 1 4.95 4.95l-8.5 8.49a2 2 0 1 1-2.83-2.83l8.49-8.49"/>
                            </svg>
                        </label>
                        <span class="fileName" id="fileName" style="display:none;"></span>
                    </div>
                    <textarea name="message" placeholder="ここに問い合わせ内容を入力してください"></textarea>
                    <div class="actionBtns">
                        <button class="lineSend" type="submit">送信</button>
                        <a class="btn2" href="/admin/help.php?store_id=<?= (int)$storeId ?>" style="text-decoration:none;display:inline-flex;align-items:center;">更新</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        const chat = document.getElementById('chat');
        function scrollToBottom() {
            if (!chat) return;
            chat.scrollTop = chat.scrollHeight;
        }
        function isNearBottom() {
            if (!chat) return false;
            return (chat.scrollHeight - chat.scrollTop - chat.clientHeight) < 40;
        }
        scrollToBottom();
        const fileInput = document.getElementById('attachmentInput');
        const fileName = document.getElementById('fileName');
        if (fileInput && fileName) {
            fileInput.addEventListener('change', () => {
                const f = fileInput.files && fileInput.files[0];
                if (f) {
                    fileName.textContent = f.name;
                    fileName.style.display = 'inline-block';
                } else {
                    fileName.textContent = '';
                    fileName.style.display = 'none';
                }
            });
        }
        const THREAD_ID = <?= (int)$threadId ?>;
        let lastId = <?= (int)$lastMessageId ?>;

        function esc(s) {
            return String(s).replace(/[&<>"']/g, m => ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#039;"
            }[m]));
        }

        function buildMessageRow(m) {
            const role = String(m.sender_role || '');
            const body = String(m.body || '');
            const createdAt = String(m.created_at || '');
            const attachPath = String(m.attachment_path || '');
            const attachName = String(m.attachment_name || '');
            const attachMime = String(m.attachment_mime || '');
            const attachSize = Number(m.attachment_size || 0);
            if (!body && !attachPath) return null;

            let rowClass = 'sys';
            if (role === 'tenant_admin') rowClass = 'me';
            if (role === 'support_admin') rowClass = 'staff';

            const row = document.createElement('div');
            row.className = 'row ' + rowClass;
            const wrap = document.createElement('div');
            wrap.className = 'bubbleWrap';

            if (body) {
                const bub = document.createElement('div');
                bub.className = 'bub';
                bub.innerHTML = esc(body);
                wrap.appendChild(bub);
            }
            if (attachPath) {
                const box = document.createElement('div');
                box.className = 'attachBox';
                const url = '/admin/help_attachment_download.php?message_id=' + encodeURIComponent(m.id);
                if (['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(attachMime)) {
                    const a = document.createElement('a');
                    a.href = url;
                    a.target = '_blank';
                    a.rel = 'noopener';
                    const img = document.createElement('img');
                    img.className = 'attachImg';
                    img.src = url;
                    img.alt = attachName || 'image';
                    a.appendChild(img);
                    box.appendChild(a);
                }
                const link = document.createElement('a');
                link.className = 'attachLink';
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener';
                let sizeText = '';
                if (attachSize && attachSize > 0) {
                    if (attachSize < 1024) sizeText = attachSize + ' B';
                    else if (attachSize < 1024 * 1024) sizeText = (attachSize / 1024).toFixed(1) + ' KB';
                    else sizeText = (attachSize / (1024 * 1024)).toFixed(1) + ' MB';
                }
                link.textContent = (attachName || 'file') + (sizeText ? ` (${sizeText})` : '');
                box.appendChild(link);
                wrap.appendChild(box);
            }
            if (createdAt) {
                const meta = document.createElement('div');
                meta.className = 'meta';
                meta.textContent = createdAt;
                wrap.appendChild(meta);
            }
            row.appendChild(wrap);
            return row;
        }

        function renderMessages(messages) {
            if (!chat) return;
            chat.innerHTML = '';
            if (!messages || messages.length === 0) {
                chat.innerHTML = '<div class="row sys"><div class="bub">お問い合わせ内容を入力して送信してください。</div></div>';
                return;
            }
            messages.forEach(m => {
                const row = buildMessageRow(m);
                if (row) chat.appendChild(row);
            });
            scrollToBottom();
        }

        function maxId(messages) {
            if (!messages || messages.length === 0) return 0;
            let m = 0;
            messages.forEach(x => {
                const id = Number(x.id || 0);
                if (id > m) m = id;
            });
            return m;
        }

        async function pollMessages() {
            if (!THREAD_ID) return;
            try {
                const res = await fetch('/admin/help_thread_api.php?action=fetch&thread_id=' + encodeURIComponent(THREAD_ID) + '&t=' + Date.now(), {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                if (!data || !data.ok) return;
                const messages = data.messages || [];
                const newest = maxId(messages);
                if (newest > lastId) {
                    const near = isNearBottom();
                    messages.forEach(m => {
                        const id = Number(m.id || 0);
                        if (id > lastId) {
                            const row = buildMessageRow(m);
                            if (row) chat.appendChild(row);
                        }
                    });
                    lastId = newest;
                    if (near) scrollToBottom();
                }
            } catch (e) {}
        }

        if (THREAD_ID) {
            pollMessages();
            setInterval(pollMessages, 5000);
        }
    </script>
</body>
</html>
