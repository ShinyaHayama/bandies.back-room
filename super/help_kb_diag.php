<?php

declare(strict_types=1);

/**
 * ✅ /super/help_kb_diag.php
 * - 500の「原因箇所」を特定するための診断ページ
 * - /super/help_kb_diag.php?debug=1 で開く
 */

$__dbg = (((string)($_GET['debug'] ?? '')) === '1');
ini_set('display_errors', $__dbg ? '1' : '0');
ini_set('display_startup_errors', $__dbg ? '1' : '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

$__logDir = __DIR__ . '/logs';
@mkdir($__logDir, 0777, true);
$__logFile = $__logDir . '/help_kb_diag.log';

function dlog(string $m): void
{
    global $__logFile;
    @file_put_contents($__logFile, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n", FILE_APPEND);
}

function out(string $m, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $m;
    exit;
}

set_exception_handler(function (Throwable $e) {
    dlog('[EXCEPTION] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out("EXCEPTION:\n" . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n", 500);
});

register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e) return;
    $isFatal = in_array((int)($e['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    if (!$isFatal) return;
    dlog('[FATAL] ' . ($e['message'] ?? '') . ' @ ' . ($e['file'] ?? '') . ':' . ($e['line'] ?? 0));
    out("FATAL:\n" . ($e['message'] ?? '') . "\n" . ($e['file'] ?? '') . ':' . ($e['line'] ?? 0) . "\n", 500);
});

dlog('boot uri=' . (string)($_SERVER['REQUEST_URI'] ?? ''));

$auth = __DIR__ . '/_auth.php';
$db   = __DIR__ . '/_db.php';
$hdr  = __DIR__ . '/_header.php';

$lines = [];
$lines[] = 'diag: /super/help_kb_diag.php';
$lines[] = 'time: ' . date('Y-m-d H:i:s');
$lines[] = 'php: ' . PHP_VERSION;
$lines[] = 'cwd: ' . getcwd();
$lines[] = 'dir: ' . __DIR__;
$lines[] = 'log: ' . __logFileExplain($GLOBALS['__logFile'] ?? '');

function __logFileExplain(string $p): string
{
    if ($p === '') return '(none)';
    return $p . ' (exists=' . (is_file($p) ? '1' : '0') . ')';
}

function fileStatLine(string $label, string $path): string
{
    return $label . ': ' . $path
        . ' exists=' . (is_file($path) ? '1' : '0')
        . ' readable=' . (is_readable($path) ? '1' : '0');
}

$lines[] = fileStatLine('_auth.php', $auth);
$lines[] = fileStatLine('_db.php', $db);
$lines[] = fileStatLine('_header.php', $hdr);
$lines[] = '';

if (!is_file($auth)) out(implode("\n", $lines) . "\n\nERROR: _auth.php が見つかりません\n", 500);
if (!is_file($db))   out(implode("\n", $lines) . "\n\nERROR: _db.php が見つかりません\n", 500);

$lines[] = '--- include _auth.php start ---';
dlog('include _auth.php start');
require_once $auth;
$lines[] = '--- include _auth.php OK ---';
dlog('include _auth.php OK');

$lines[] = '--- include _db.php start ---';
dlog('include _db.php start');
require_once $db;
$lines[] = '--- include _db.php OK ---';
dlog('include _db.php OK');

$hasGlobalPdo = (isset($GLOBALS['pdo']) && ($GLOBALS['pdo'] instanceof PDO)) ? '1' : '0';
$lines[] = 'global $pdo: ' . $hasGlobalPdo;

$fn = [];
foreach (['fl_db', 'db', 'get_pdo', 'pdo'] as $f) {
    $fn[] = $f . '=' . (function_exists($f) ? '1' : '0');
}
$lines[] = 'functions: ' . implode(' ', $fn);

$lines[] = '';
$lines[] = 'OK: includes succeeded. 次は /super/help_kb.php?debug=1 で同様に原因が出ます。';

out(implode("\n", $lines) . "\n", 200);
