<?php

declare(strict_types=1);

/**
 * ✅ /admin/help_ai_api.php（最小・安定版）
 * - ブラウザからの単体テスト用（POST JSON）
 * - 実処理は /admin/help_ai_core.php を呼ぶだけ
 *
 * 重要：
 * - ここは「ブラウザで叩ける」デバッグ用途
 * - help.php の動作は /admin/help_api.php 側で core を直呼びするので、302問題を根本回避
 */

date_default_timezone_set('Asia/Tokyo');

$__logDir = __DIR__ . '/logs';
@mkdir($__logDir, 0777, true);
$__logFile = $__logDir . '/help_ai_api.log';

function ailog(string $msg): void
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

set_exception_handler(function (Throwable $e) {
    ailog('[EXCEPTION] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()], 500);
});

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '');
$ct     = (string)($_SERVER['CONTENT_TYPE'] ?? '');
ailog("BOOT method={$method} ct={$ct} uri={$uri}");

require_once __DIR__ . '/_auth.php';
require_admin_login();
ailog('auth ok');

require_once __DIR__ . '/_tenant_context.php';
$tenantId = (int)($tenantId ?? 0);
ailog('CTX tenant_id=' . $tenantId);

require_once __DIR__ . '/_db.php';

// PDO取得（環境差吸収）
$pdo = null;
if (isset($GLOBALS['pdo']) && ($GLOBALS['pdo'] instanceof PDO)) {
    $pdo = $GLOBALS['pdo'];
} else {
    foreach (['db', 'fl_db', 'get_pdo', 'pdo'] as $fn) {
        if (function_exists($fn)) {
            $ret = $fn();
            if ($ret instanceof PDO) {
                $pdo = $ret;
                break;
            }
        }
    }
}
if (!($pdo instanceof PDO)) out(['ok' => false, 'error' => 'db_not_ready'], 500);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

require_once __DIR__ . '/help_ai_core.php';

if ($method !== 'POST') {
    out(['ok' => false, 'error' => 'method_not_allowed', 'need' => 'POST JSON'], 405);
}

$raw = (string)file_get_contents('php://input');
$req = json_decode($raw !== '' ? $raw : 'null', true);
if (!is_array($req)) {
    out(['ok' => false, 'error' => 'invalid_json', 'json_error' => json_last_error_msg()], 400);
}

$storeId  = (int)($req['store_id'] ?? 0);
$question = (string)($req['question'] ?? '');

$r = help_ai_answer_from_kb($pdo, $tenantId, $question);
out($r, 200);