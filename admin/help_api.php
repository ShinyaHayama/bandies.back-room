<?php

declare(strict_types=1);

/**
 * ✅ /admin/help_api.php（not_implemented を消して通常動作に戻す＋unknown_action原因特定強化）
 *
 * ポイント：
 * - 前回の「not_implemented」は、私が“途中省略”のダミーを入れてしまったためです。
 * - このファイルを「丸ごと置き換え」れば、ensure_thread / history / send が動きます。
 * - unknown_action の時は「受け取ったaction」「許可action一覧」「raw_head」を返します。
 */

date_default_timezone_set('Asia/Tokyo');

$__logDir = __DIR__ . '/logs';
@mkdir($__logDir, 0777, true);
$__logFile = $__logDir . '/help_api.log';

function hapilog(string $msg): void
{
    global $__logFile;
    @file_put_contents($__logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
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

/** ✅ 何が来ても「必ず文字列」にする（Array事故根絶） */
function as_text(mixed $v): string
{
    if ($v === null) return '';
    if (is_string($v)) return $v;
    if (is_int($v) || is_float($v) || is_bool($v)) return (string)$v;

    $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false ? $json : '';
}

set_exception_handler(function (Throwable $e) {
    hapilog('[EXCEPTION] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()], 500);
});

register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e) return;
    $fatal = in_array((int)($e['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    if (!$fatal) return;
    hapilog('[FATAL] ' . ($e['message'] ?? '') . ' @ ' . ($e['file'] ?? '') . ':' . ($e['line'] ?? 0));
});

// ---- boot log
$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '');
$ct     = (string)($_SERVER['CONTENT_TYPE'] ?? '');
hapilog("BOOT method={$method} uri={$uri} ct={$ct}");

// ---- auth / db
require_once __DIR__ . '/_auth.php';
require_admin_login();
hapilog('auth ok');

require_once __DIR__ . '/_db.php';

// PDO取得（環境差吸収）
$pdo = null;
if (isset($GLOBALS['pdo']) && ($GLOBALS['pdo'] instanceof PDO)) {
    $pdo = $GLOBALS['pdo'];
    hapilog('DB pdo=GLOBALS');
} else {
    foreach (['db', 'fl_db', 'get_pdo', 'pdo'] as $fn) {
        if (function_exists($fn)) {
            $ret = $fn();
            if ($ret instanceof PDO) {
                $pdo = $ret;
                hapilog('DB pdo=' . $fn . '()');
                break;
            }
        }
    }
}
if (!($pdo instanceof PDO)) out(['ok' => false, 'error' => 'db_not_ready'], 500);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

try {
    $pdo->exec("SET NAMES utf8mb4");
} catch (Throwable $e) {
}
hapilog('DB ok');

// ---- request parse
$raw = (string)file_get_contents('php://input');
hapilog('RAW len=' . strlen($raw));

if ($method !== 'POST') {
    out(['ok' => false, 'error' => 'method_not_allowed', 'need' => 'POST JSON'], 405);
}

$req = json_decode($raw !== '' ? $raw : 'null', true);
if (!is_array($req)) {
    hapilog('JSON parse failed: ' . json_last_error_msg() . ' raw=' . $raw);
    out(['ok' => false, 'error' => 'invalid_json', 'json_error' => json_last_error_msg()], 400);
}

$action   = (string)($req['action'] ?? '');
$storeId  = (int)($req['store_id'] ?? 0);
$threadId = (int)($req['thread_id'] ?? 0);
$message  = as_text($req['message'] ?? '');
$debug    = (((int)($req['debug'] ?? 0)) === 1);

hapilog("REQ action={$action} store_id={$storeId} thread_id={$threadId}" . ($debug ? ' debug=1' : ''));

if ($action === '') out(['ok' => false, 'error' => 'missing_action'], 400);

/**
 * ✅ unknown_action を「原因特定できる形」で返す
 * ※help.php 側がここを拾って表示する想定
 */
$allowed = ['ensure_thread', 'history', 'send'];
if (!in_array($action, $allowed, true)) {
    out([
        'ok' => false,
        'error' => 'unknown_action',
        'action' => $action,
        'allowed' => $allowed,
        'raw_head' => mb_substr($raw, 0, 300, 'UTF-8'),
    ], 400);
}

// ---- tables
const HA_THREADS  = 'ha_threads';
const HA_MESSAGES = 'ha_messages';

function ensure_ha_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ha_threads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_message_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_store (store_id),
            KEY idx_last (store_id, last_message_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ha_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            sender_role VARCHAR(10) NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_thread (thread_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
ensure_ha_tables($pdo);

function ha_thread_exists(PDO $pdo, int $storeId, int $threadId): array
{
    $st = $pdo->prepare("SELECT * FROM " . HA_THREADS . " WHERE id=:id AND store_id=:sid LIMIT 1");
    $st->execute([':id' => $threadId, ':sid' => $storeId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) out(['ok' => false, 'error' => 'thread_not_found'], 404);
    return $t;
}

/** ✅ 最終防衛：bodyは必ず文字列化して保存 */
function ha_add_msg(PDO $pdo, int $threadId, string $role, mixed $body): int
{
    $body = as_text($body);

    $st = $pdo->prepare("INSERT INTO " . HA_MESSAGES . " (thread_id, sender_role, body) VALUES(:tid,:r,:b)");
    $st->execute([':tid' => $threadId, ':r' => $role, ':b' => $body]);
    $id = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE " . HA_THREADS . " SET last_message_at=NOW(), updated_at=NOW() WHERE id=:id")
        ->execute([':id' => $threadId]);

    return $id;
}

function ha_list_msgs(PDO $pdo, int $threadId): array
{
    $st = $pdo->prepare("SELECT sender_role, body, created_at FROM " . HA_MESSAGES . " WHERE thread_id=:tid ORDER BY id ASC LIMIT 500");
    $st->execute([':tid' => $threadId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ---- action: ensure_thread
if ($action === 'ensure_thread') {
    if ($storeId <= 0) out(['ok' => false, 'error' => 'store_id_missing'], 400);

    $st = $pdo->prepare("SELECT id FROM " . HA_THREADS . " WHERE store_id=:sid ORDER BY id DESC LIMIT 1");
    $st->execute([':sid' => $storeId]);
    $id = (int)($st->fetchColumn() ?: 0);

    if ($id <= 0) {
        $ins = $pdo->prepare("INSERT INTO " . HA_THREADS . " (store_id, status) VALUES(:sid,'open')");
        $ins->execute([':sid' => $storeId]);
        $id = (int)$pdo->lastInsertId();
        ha_add_msg($pdo, $id, 'ai', "こんにちは！困ってることを聞いてね。");
    }

    hapilog('ensure_thread ok thread_id=' . $id);
    out(['ok' => true, 'thread_id' => $id]);
}

// ---- action: history
if ($action === 'history') {
    if ($storeId <= 0) out(['ok' => false, 'error' => 'store_id_missing'], 400);
    if ($threadId <= 0) out(['ok' => false, 'error' => 'thread_id_invalid'], 400);

    ha_thread_exists($pdo, $storeId, $threadId);
    $msgs = ha_list_msgs($pdo, $threadId);

    out(['ok' => true, 'messages' => $msgs]);
}

// ---- action: send
if ($action === 'send') {
    if ($storeId <= 0) out(['ok' => false, 'error' => 'store_id_missing'], 400);
    if ($threadId <= 0) out(['ok' => false, 'error' => 'thread_id_invalid'], 400);
    if (trim($message) === '') out(['ok' => false, 'error' => 'message_empty'], 400);

    ha_thread_exists($pdo, $storeId, $threadId);

    // 1) staff 保存
    $staffId = ha_add_msg($pdo, $threadId, 'staff', $message);
    hapilog('saved staff msg_id=' . $staffId);

    // 2) AI（KB + OpenAI）
    hapilog('AI begin: require help_ai_core.php');
    require_once __DIR__ . '/help_ai_core.php';

    hapilog('AI begin: call help_ai_answer_from_kb');
    $r = help_ai_answer_from_kb($pdo, $storeId, $message, $debug);

    $answer = '';
    $kbHits = 0;
    $usedOpenAI = 0;

    if (is_array($r) && !empty($r['ok'])) {
        $answer     = as_text($r['answer'] ?? '');
        $kbHits     = (int)($r['kb_hits'] ?? 0);
        $usedOpenAI = (int)($r['used_openai'] ?? 0);
    } else {
        $answer = 'AIの返り値が不正でした。';
    }

    $answer = trim($answer);
    if ($answer === '') $answer = 'AIの回答が空でした。';

    hapilog("AI done: kb_hits={$kbHits} used_openai={$usedOpenAI} answer_len=" . strlen($answer));

    // 3) ai 保存
    $aiId = ha_add_msg($pdo, $threadId, 'ai', $answer);
    hapilog('saved ai msg_id=' . $aiId);

    out(['ok' => true, 'kb_hits' => $kbHits, 'used_openai' => $usedOpenAI]);
}

// ここには来ない想定（上でunknown_actionを弾いてる）
out(['ok' => false, 'error' => 'unknown_action', 'action' => $action, 'allowed' => $allowed], 400);