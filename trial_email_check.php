<?php
// ✅ ファイル名: /kintai/trial_email_check.php
// ✅ 書き込み場所: このファイルを「丸ごと置き換え」
// ※先頭に空白/BOM/改行を入れないでください（<?php を1文字目）

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const TRIAL_EMAIL_CHECK_VERSION = '2026-01-06-FIX-API-DB-01';

function out(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_email_format(string $v): bool
{
    return (bool)preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $v);
}

/**
 * ✅ あなたの実DB接続は /api/lib/db.php の db() を使う
 * - /kintai から見た相対パスで ../api/lib/db.php
 */
function trial_get_pdo(): PDO
{
    $paths = [
        __DIR__ . '/api/lib/db.php',
        __DIR__ . '/../api/lib/db.php',
    ];
    $dbFile = null;
    foreach ($paths as $p) {
        if (is_file($p)) {
            $dbFile = $p;
            break;
        }
    }
    if ($dbFile === null) {
        throw new RuntimeException('api/lib/db.php not found');
    }

    require_once $dbFile;

    if (!function_exists('db')) {
        throw new RuntimeException('db() not found in api/lib/db.php');
    }

    $pdo = db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('db() did not return PDO');
    }

    return $pdo;
}

function email_exists(PDO $pdo, string $email): array
{
    $emailNorm = trim($email);
    $emailNorm = function_exists('mb_strtolower') ? mb_strtolower($emailNorm) : strtolower($emailNorm);

    $candidates = [
        ['table' => 'tenant_admin_users', 'cols' => ['email']],
        ['table' => 'tenants',           'cols' => ['email', 'owner_email', 'admin_email']],
        ['table' => 'users',             'cols' => ['email', 'login_email']],
        ['table' => 'admins',            'cols' => ['email', 'login_email']],
        ['table' => 'admin_users',       'cols' => ['email', 'login_email']],
        ['table' => 'accounts',          'cols' => ['email']],
        ['table' => 'trial_accounts',    'cols' => ['email']],
    ];

    foreach ($candidates as $c) {
        $table = (string)$c['table'];
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($c['cols'] as $col) {
            if (!in_array($col, $cols, true)) continue;
            try {
                $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE LOWER(TRIM(`$col`)) = :email LIMIT 1");
                $st->execute([':email' => $emailNorm]);
                if ($st->fetchColumn()) {
                    return [
                        'exists' => true,
                        'matched_table' => $table,
                        'matched_column' => $col,
                    ];
                }
            } catch (Throwable $e) {
                // 次へ
            }
        }
    }

    return [
        'exists' => false,
        'matched_table' => null,
        'matched_column' => null,
    ];
}

// ========= main =========

$email = trim((string)($_GET['email'] ?? ''));

if ($email === '') {
    out([
        'ok' => true,
        'version' => TRIAL_EMAIL_CHECK_VERSION,
        'valid' => false,
        'exists' => false,
        'status' => 'invalid',
        'reason' => 'empty',
    ]);
}

if (!is_email_format($email)) {
    out([
        'ok' => true,
        'version' => TRIAL_EMAIL_CHECK_VERSION,
        'valid' => false,
        'exists' => false,
        'status' => 'invalid',
        'reason' => 'invalid_format',
    ]);
}

try {
    $pdo = trial_get_pdo();

    // 任意：DB名（デバッグ用）
    $dbName = '';
    try {
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        $dbName = '';
    }

    $r = email_exists($pdo, $email);

    out([
        'ok' => true,
        'version' => TRIAL_EMAIL_CHECK_VERSION,
        'db' => $dbName,
        'valid' => true,
        'exists' => (bool)$r['exists'],
        'status' => ($r['exists'] ? 'blocked' : 'ok'),
        'matched_table' => $r['matched_table'],
        'matched_column' => $r['matched_column'],
    ], 200);
} catch (Throwable $e) {
    // ✅ 500にしない（JS側で unknown にして送信不可にできる）
    out([
        'ok' => true,
        'version' => TRIAL_EMAIL_CHECK_VERSION,
        'valid' => true,
        'exists' => false,
        'status' => 'unknown',
        'reason' => 'db_error',
        'err' => $e->getMessage(),
    ], 200);
}
