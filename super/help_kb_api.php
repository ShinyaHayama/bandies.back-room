<?php

declare(strict_types=1);

/**
 * ✅ /super/help_kb_api.php（ログ強化版・delete_kb 実装済みフルコード）
 *
 * 目的：
 * - 「KBに追加」や「削除」が効かない原因をログで“確定”させる
 * - action=delete_kb を実装して、DBからKBを削除できるようにする
 *
 * 仕様（現状維持）：
 * - super 管理者のみ
 * - POST JSON のみ
 * - CSRF は help_kb_csrf（このAPI専用）を使用
 * - help_ai_misses / help_kb が無ければ作る（既存踏襲）
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

// ✅ ログ
$__logDir = __DIR__ . '/logs';
@mkdir($__logDir, 0777, true);
$__logFile = $__logDir . '/help_kb_api.log';

function hklog(string $msg): void
{
    global $__logFile;
    @file_put_contents($__logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

require_once __DIR__ . '/_auth.php';
require_super_admin_login();

// ✅ セッション（CSRF用）
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['help_kb_csrf'])) {
    $_SESSION['help_kb_csrf'] = bin2hex(random_bytes(16));
}
$csrfExpected = (string)$_SESSION['help_kb_csrf'];

// ✅ DB
require_once __DIR__ . '/_db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    hklog('db_not_ready');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'db_not_ready'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
try {
    $pdo->exec("SET NAMES utf8mb4");
} catch (Throwable $e) {
    // 握りつぶし（互換性優先）
}

function out(array $a, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tbl_cols(PDO $pdo, string $table): array
{
    try {
        $st = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
        ");
        $st->execute([':t' => $table]);
        $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map('strval', $cols);
    } catch (Throwable $e) {
        return [];
    }
}

function ensure_table_if_missing(PDO $pdo, string $table, string $ddl): void
{
    $cols = tbl_cols($pdo, $table);
    if (!empty($cols)) {
        return; // 既にある
    }
    $pdo->exec($ddl);
}

set_exception_handler(function (Throwable $e) {
    hklog('[EXCEPTION] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()], 500);
});

// ---- request parse
$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$ct     = (string)($_SERVER['CONTENT_TYPE'] ?? '');
hklog("BOOT method={$method} ct={$ct}");

if ($method !== 'POST') {
    out(['ok' => false, 'error' => 'method_not_allowed', 'need' => 'POST JSON'], 405);
}

$raw = (string)file_get_contents('php://input');
hklog('RAW len=' . strlen($raw));

$req = json_decode($raw !== '' ? $raw : 'null', true);
if (!is_array($req)) {
    out(['ok' => false, 'error' => 'invalid_json', 'json_error' => json_last_error_msg()], 400);
}

$debug  = (((int)($req['debug'] ?? 0)) === 1);
$csrfIn = (string)($req['csrf'] ?? '');
$action = (string)($req['action'] ?? '');
hklog("REQ action={$action} debug=" . ($debug ? '1' : '0'));

if ($csrfIn === '' || !hash_equals($csrfExpected, $csrfIn)) {
    hklog('CSRF invalid in=' . substr($csrfIn, 0, 6) . ' exp=' . substr($csrfExpected, 0, 6));
    out(['ok' => false, 'error' => 'csrf_invalid'], 403);
}
if ($action === '') {
    out(['ok' => false, 'error' => 'missing_action'], 400);
}

// ---- tables
ensure_table_if_missing($pdo, 'help_ai_misses', "
    CREATE TABLE IF NOT EXISTS help_ai_misses (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id BIGINT UNSIGNED NULL,
        question TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

ensure_table_if_missing($pdo, 'help_kb', "
    CREATE TABLE IF NOT EXISTS help_kb (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        body  TEXT NOT NULL,
        keywords TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_title (title(50))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$kbCols   = tbl_cols($pdo, 'help_kb');
$missCols = tbl_cols($pdo, 'help_ai_misses');
$kbHasKeywords = in_array('keywords', $kbCols, true);
$kbHasUpdated  = in_array('updated_at', $kbCols, true);

$allowed = [
    'list_misses',
    'delete_miss',
    'promote_miss_to_kb',
    'list_kb',
    'create_kb',
    'update_kb',
    'update_kb_keywords',
    'delete_kb', // ✅ これが無いと unknown_action になります
];

if (!in_array($action, $allowed, true)) {
    out(['ok' => false, 'error' => 'unknown_action', 'action' => $action, 'allowed' => $allowed], 400);
}

// ---- list_misses
if ($action === 'list_misses') {
    $limit = (int)($req['limit'] ?? 200);
    if ($limit <= 0) $limit = 200;
    if ($limit > 500) $limit = 500;

    if (!in_array('question', $missCols, true) || !in_array('created_at', $missCols, true)) {
        hklog('miss table columns missing');
        out(['ok' => true, 'misses' => []]);
    }

    $st = $pdo->prepare("SELECT id, question, created_at FROM help_ai_misses ORDER BY id DESC LIMIT {$limit}");
    $st->execute();
    $rows = $st->fetchAll() ?: [];
    out(['ok' => true, 'misses' => $rows]);
}

// ---- delete_miss
if ($action === 'delete_miss') {
    $missId = (int)($req['miss_id'] ?? 0);
    if ($missId <= 0) out(['ok' => false, 'error' => 'miss_id_invalid'], 400);

    $st = $pdo->prepare("DELETE FROM help_ai_misses WHERE id=:id");
    $st->execute([':id' => $missId]);
    hklog("delete_miss id={$missId} affected=" . $st->rowCount());

    out(['ok' => true]);
}

// ---- promote_miss_to_kb
if ($action === 'promote_miss_to_kb') {
    $missId = (int)($req['miss_id'] ?? 0);
    if ($missId <= 0) out(['ok' => false, 'error' => 'miss_id_invalid'], 400);

    $title = trim((string)($req['title'] ?? ''));
    $body  = trim((string)($req['body'] ?? ''));
    $kw    = trim((string)($req['keywords'] ?? ''));
    $del   = (((int)($req['delete_miss'] ?? 0)) === 1);

    if ($title === '') out(['ok' => false, 'error' => 'title_required'], 400);

    hklog("promote start miss_id={$missId} del=" . ($del ? '1' : '0') . " title_len=" . strlen($title));

    $mst = $pdo->prepare("SELECT id, question FROM help_ai_misses WHERE id=:id LIMIT 1");
    $mst->execute([':id' => $missId]);
    $m = $mst->fetch();
    if (!$m) {
        hklog("promote miss_not_found miss_id={$missId}");
        out(['ok' => false, 'error' => 'miss_not_found'], 404);
    }

    $cols = ['title', 'body'];
    $vals = [':t' => $title, ':b' => $body];
    if ($kbHasKeywords) {
        $cols[] = 'keywords';
        $vals[':k'] = $kw;
    }

    // ✅ VALUES の順序を cols と合わせて作る（安全）
    $ph = [];
    foreach ($cols as $c) {
        if ($c === 'title') $ph[] = ':t';
        if ($c === 'body') $ph[] = ':b';
        if ($c === 'keywords') $ph[] = ':k';
    }

    $sql = "INSERT INTO help_kb (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
    hklog("promote insert_sql=" . $sql);

    $st = $pdo->prepare($sql);
    $st->execute($vals);
    $kbId = (int)$pdo->lastInsertId();
    hklog("promote inserted kb_id={$kbId}");

    if ($del) {
        $dst = $pdo->prepare("DELETE FROM help_ai_misses WHERE id=:id");
        $dst->execute([':id' => $missId]);
        hklog("promote deleted miss_id={$missId} affected=" . $dst->rowCount());
    }

    out(['ok' => true, 'kb_id' => $kbId]);
}

// ---- list_kb
if ($action === 'list_kb') {
    $limit = (int)($req['limit'] ?? 200);
    if ($limit <= 0) $limit = 200;
    if ($limit > 500) $limit = 500;

    $q = trim((string)($req['q'] ?? ''));

    $where = "";
    $params = [];
    if ($q !== '') {
        $params[':q'] = '%' . $q . '%';
        $parts = ["title LIKE :q", "body LIKE :q"];
        if ($kbHasKeywords) $parts[] = "keywords LIKE :q";
        $where = "WHERE (" . implode(' OR ', $parts) . ")";
    }

    $select = "id, title, body";
    if ($kbHasKeywords) $select .= ", keywords";
    if ($kbHasUpdated)  $select .= ", updated_at";

    $order = $kbHasUpdated ? "ORDER BY updated_at DESC, id DESC" : "ORDER BY id DESC";

    $sql = "SELECT {$select} FROM help_kb {$where} {$order} LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll() ?: [];

    if (!$kbHasKeywords) {
        foreach ($rows as &$r) {
            $r['keywords'] = '';
        }
        unset($r);
    }

    out(['ok' => true, 'kb' => $rows]);
}

// ---- create_kb
if ($action === 'create_kb') {
    $title = trim((string)($req['title'] ?? ''));
    $body  = trim((string)($req['body'] ?? ''));
    $kw    = trim((string)($req['keywords'] ?? ''));

    if ($title === '') out(['ok' => false, 'error' => 'title_required'], 400);

    $cols = ['title', 'body'];
    $vals = [':t' => $title, ':b' => $body];
    if ($kbHasKeywords) {
        $cols[] = 'keywords';
        $vals[':k'] = $kw;
    }

    // ✅ VALUES の順序を cols と合わせて作る（安全）
    $ph = [];
    foreach ($cols as $c) {
        if ($c === 'title') $ph[] = ':t';
        if ($c === 'body') $ph[] = ':b';
        if ($c === 'keywords') $ph[] = ':k';
    }

    $sql = "INSERT INTO help_kb (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
    $st = $pdo->prepare($sql);
    $st->execute($vals);

    $kbId = (int)$pdo->lastInsertId();
    hklog("create_kb kb_id={$kbId}");

    out(['ok' => true, 'kb_id' => $kbId]);
}

// ---- update_kb
if ($action === 'update_kb') {
    $kbId  = (int)($req['kb_id'] ?? 0);
    $title = trim((string)($req['title'] ?? ''));
    $body  = trim((string)($req['body'] ?? ''));
    $kw    = trim((string)($req['keywords'] ?? ''));

    if ($kbId <= 0) out(['ok' => false, 'error' => 'kb_id_invalid'], 400);
    if ($title === '') out(['ok' => false, 'error' => 'title_required'], 400);

    $sets = ["title=:t", "body=:b"];
    $params = [':t' => $title, ':b' => $body, ':id' => $kbId];
    if ($kbHasKeywords) {
        $sets[] = "keywords=:k";
        $params[':k'] = $kw;
    }

    $sql = "UPDATE help_kb SET " . implode(', ', $sets) . " WHERE id=:id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    hklog("update_kb kb_id={$kbId} affected=" . $st->rowCount());

    out(['ok' => true]);
}

// ---- update_kb_keywords
if ($action === 'update_kb_keywords') {
    $kbId = (int)($req['kb_id'] ?? 0);
    $kw   = trim((string)($req['keywords'] ?? ''));

    if ($kbId <= 0) out(['ok' => false, 'error' => 'kb_id_invalid'], 400);
    if (!$kbHasKeywords) out(['ok' => false, 'error' => 'keywords_column_missing'], 400);

    $st = $pdo->prepare("UPDATE help_kb SET keywords=:k WHERE id=:id");
    $st->execute([':k' => $kw, ':id' => $kbId]);
    hklog("update_kb_keywords kb_id={$kbId} affected=" . $st->rowCount());

    out(['ok' => true]);
}

// ---- ✅ delete_kb（ここが今回の追加：DBから削除）
if ($action === 'delete_kb') {
    $kbId = (int)($req['kb_id'] ?? 0);
    if ($kbId <= 0) out(['ok' => false, 'error' => 'kb_id_invalid'], 400);

    $st = $pdo->prepare("DELETE FROM help_kb WHERE id=:id");
    $st->execute([':id' => $kbId]);
    hklog("delete_kb kb_id={$kbId} affected=" . $st->rowCount());

    out(['ok' => true]);
}

// ここには来ない想定
out(['ok' => false, 'error' => 'unreachable'], 500);
