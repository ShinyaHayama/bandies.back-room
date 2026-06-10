<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php'; // $tenantId
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/app_url.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'tenant_id missing'], JSON_UNESCAPED_UNICODE);
    exit;
}
$tenantId = (int)$tenantId;

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

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
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

try {
    ensure_help_tables($pdo);
} catch (Throwable $e) {
    out(['ok' => false, 'error' => 'table_setup_failed', 'detail' => $e->getMessage()], 500);
}

$hasSenderRole = table_has_column($pdo, 'help_messages', 'sender_role');
$hasBody       = table_has_column($pdo, 'help_messages', 'body');
$hasSenderType = table_has_column($pdo, 'help_messages', 'sender_type');
$hasMsgText    = table_has_column($pdo, 'help_messages', 'message_text');
$hasSenderId   = table_has_column($pdo, 'help_messages', 'sender_id');
$hasAttachPath = table_has_column($pdo, 'help_messages', 'attachment_path');
$hasAttachName = table_has_column($pdo, 'help_messages', 'attachment_name');
$hasAttachMime = table_has_column($pdo, 'help_messages', 'attachment_mime');
$hasAttachSize = table_has_column($pdo, 'help_messages', 'attachment_size');
$hasThreadStatus = table_has_column($pdo, 'help_threads', 'status');
$hasThreadLast   = table_has_column($pdo, 'help_threads', 'last_message_at');
$hasThreadCreatedBy = table_has_column($pdo, 'help_threads', 'created_by_admin_id');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');

$in = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $in = json_decode((string)$raw, true);
    if (!is_array($in)) $in = [];
    $action = (string)($in['action'] ?? $action);
}

if ($action === 'create') {
    $storeId = (int)($in['store_id'] ?? 0);

    // 既に open があれば再利用（同テナント/店舗）
    if ($hasThreadStatus) {
        $st = $pdo->prepare("SELECT id FROM help_threads WHERE tenant_id=? AND store_id=? AND status='open' ORDER BY id DESC LIMIT 1");
        $st->execute([$tenantId, $storeId]);
    } else {
        $st = $pdo->prepare("SELECT id FROM help_threads WHERE tenant_id=? AND store_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([$tenantId, $storeId]);
    }
    $tid = (int)($st->fetchColumn() ?: 0);

    $created = false;
    if ($tid <= 0) {
        $cols = ['tenant_id', 'store_id'];
        $vals = [':t', ':s'];
        $params = [':t' => $tenantId, ':s' => $storeId];
        if ($hasThreadStatus) {
            $cols[] = 'status';
            $vals[] = ':status';
            $params[':status'] = 'open';
        }
        if ($hasThreadCreatedBy) {
            $cols[] = 'created_by_admin_id';
            $vals[] = ':created_by';
            $params[':created_by'] = 0;
        }
        if ($hasThreadLast) {
            $cols[] = 'last_message_at';
            $vals[] = 'CURRENT_TIMESTAMP';
        }
        $sql = "INSERT INTO help_threads (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        $ins = $pdo->prepare($sql);
        $ins->execute($params);
        $tid = (int)$pdo->lastInsertId();
        $created = true;
    }

    if ($created) {
        try {
            $tenantName = '';
            $storeName = '';
            $st = $pdo->prepare("SELECT name FROM tenants WHERE id=? LIMIT 1");
            $st->execute([$tenantId]);
            $tenantName = (string)($st->fetchColumn() ?: '');
            $st2 = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
            $st2->execute([$storeId]);
            $storeName = (string)($st2->fetchColumn() ?: '');

            $link = app_public_url('/super/help.php?thread_id=' . urlencode((string)$tid));

            $subject = '【シメナビ】新規お問い合わせ';
            $body = "新規お問い合わせが届きました。\n\n"
                . "テナント: " . ($tenantName !== '' ? $tenantName : ('ID ' . $tenantId)) . "\n"
                . "店舗: " . ($storeName !== '' ? $storeName : ('ID ' . $storeId)) . "\n"
                . "スレッド: #" . $tid . "\n"
                . "\nチャット画面:\n" . $link . "\n";

            $to = 'work@fader.group';
            send_mail($to, $subject, $body, 'SHIMENABI', '');
        } catch (Throwable $e) {
        }
    }

    out(['ok' => true, 'thread_id' => $tid]);
}

if ($action === 'send') {
    $threadId = (int)($in['thread_id'] ?? 0);
    $body = trim((string)($in['body'] ?? ''));
    if ($threadId <= 0 || $body === '') out(['ok' => false, 'error' => 'invalid'], 400);

    // 自テナントのスレッドのみ
    $st = $pdo->prepare("SELECT id FROM help_threads WHERE id=? AND tenant_id=? LIMIT 1");
    $st->execute([$threadId, $tenantId]);
    if (!(int)$st->fetchColumn()) out(['ok' => false, 'error' => 'not_found'], 404);

    $cols = ['thread_id'];
    $vals = [':tid'];
    $params = [':tid' => $threadId];

    if ($hasSenderRole && $hasBody) {
        $cols[] = 'sender_role';
        $vals[] = ':role';
        $params[':role'] = 'tenant_admin';
        $cols[] = 'body';
        $vals[] = ':body';
        $params[':body'] = $body;
    } elseif ($hasSenderType && $hasMsgText) {
        $cols[] = 'sender_type';
        $vals[] = ':role';
        $params[':role'] = 'tenant_admin';
        $cols[] = 'message_text';
        $vals[] = ':body';
        $params[':body'] = $body;
    } else {
        out(['ok' => false, 'error' => 'message_columns_missing'], 500);
    }

    if ($hasSenderId) {
        $cols[] = 'sender_id';
        $vals[] = ':sender_id';
        $params[':sender_id'] = 0;
    }

    $sql = "INSERT INTO help_messages (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $ins = $pdo->prepare($sql);
    $ins->execute($params);

    if ($hasThreadLast) {
        $up = $pdo->prepare("UPDATE help_threads SET last_message_at=CURRENT_TIMESTAMP WHERE id=?");
        $up->execute([$threadId]);
    }

    out(['ok' => true]);
}

if ($action === 'fetch') {
    $threadId = (int)($_GET['thread_id'] ?? 0);
    if ($threadId <= 0) out(['ok' => false, 'error' => 'invalid'], 400);

    $st = $pdo->prepare("SELECT id FROM help_threads WHERE id=? AND tenant_id=? LIMIT 1");
    $st->execute([$threadId, $tenantId]);
    if (!(int)$st->fetchColumn()) out(['ok' => false, 'error' => 'not_found'], 404);

    $cols = "id, created_at";
    $bodyColName = '';
    if ($hasSenderRole && $hasBody) {
        $cols .= ", sender_role AS sender_role, body AS body";
        $bodyColName = 'body';
    } elseif ($hasSenderType && $hasMsgText) {
        $cols .= ", sender_type AS sender_role, message_text AS body";
        $bodyColName = 'message_text';
    } else {
        out(['ok' => false, 'error' => 'message_columns_missing'], 500);
    }
    if ($hasAttachPath) $cols .= ", attachment_path";
    if ($hasAttachName) $cols .= ", attachment_name";
    if ($hasAttachMime) $cols .= ", attachment_mime";
    if ($hasAttachSize) $cols .= ", attachment_size";
    $where = "thread_id=?";
    if ($hasAttachPath) {
        $where .= " AND (COALESCE({$bodyColName}, '') <> '' OR attachment_path IS NOT NULL)";
    } else {
        $where .= " AND COALESCE({$bodyColName}, '') <> ''";
    }
    $st2 = $pdo->prepare("SELECT {$cols} FROM help_messages WHERE {$where} ORDER BY id ASC LIMIT 300");
    $st2->execute([$threadId]);
    $msgs = $st2->fetchAll();

    out(['ok' => true, 'messages' => $msgs]);
}

out(['ok' => false, 'error' => 'unknown_action'], 400);
