<?php

declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/app_url.php';

date_default_timezone_set('Asia/Tokyo');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * ✅ このスクリプトが置かれているベースパスを自動判定
 * 例）
 * - /kintai/trial_submit.php なら $basePath = "/kintai"
 * - /trial_submit.php なら $basePath = ""
 */
function base_path(): string
{
    $p = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($p === '/' ? '' : $p);
}

function base_url(): string
{
    return app_public_base_url();
}

function redirect_with_flash(string $msg, string $type = 'info'): void
{
    $_SESSION['flash'] = ['message' => $msg, 'type' => $type];
    $bp = base_path();
    header('Location: ' . $bp . '/trial.php');
    exit;
}

function isValidCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)($_SESSION['csrf_token']), $token);
}

/**
 * ✅ DB 接続（login.php と同じ探索方式）
 * - /kintai 配下でも動くように候補を増やす
 */
$paths = [
    __DIR__ . '/api/lib/db.php',
    __DIR__ . '/lib/db.php',
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
    __DIR__ . '/admin/../api/lib/db.php',
    __DIR__ . '/admin/../lib/db.php',
    __DIR__ . '/../admin/../api/lib/db.php',
    __DIR__ . '/../admin/../lib/db.php',
];

$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if (!$dbFile) {
    http_response_code(500);
    exit('db.php not found');
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// POST以外は入口へ戻す（/kintai を自動対応）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $bp = base_path();
    header('Location: ' . $bp . '/trial.php');
    exit;
}

// CSRF
if (!isValidCsrf((string)($_POST['csrf_token'] ?? ''))) {
    redirect_with_flash('CSRFトークンが不正です', 'error');
}

$email = trim((string)($_POST['email'] ?? ''));
$hp = (string)($_POST['website'] ?? '');

// bot避け：黙って成功扱い
if ($hp !== '') {
    redirect_with_flash('送信を受け付けました。メールをご確認ください。', 'success');
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_flash('メールアドレスを正しく入力してください', 'error');
}
if ((string)($_POST['agree'] ?? '') !== '1') {
    redirect_with_flash('利用規約とプライバシーポリシーへの同意が必要です', 'error');
}

/* =========================================================================
 * ✅ 追加: 既に登録済みのメールなら「この時点で」弾いて送信しない
 * - trial_requests（申請履歴）ではなく、既存アカウント側の候補テーブルを横断チェック
 * - DB構成差異に強いように、テーブル/カラム存在を確認してから照会
 * ========================================================================= */
function table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function table_columns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        foreach ($rows as $r) {
            if (!empty($r['Field'])) $cols[(string)$r['Field']] = true;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $cols;
}

function email_already_registered(PDO $pdo, string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    // 既存アカウント系でありがちな候補（無ければスキップ）
    $candidates = [
        ['table' => 'tenants',        'cols' => ['email', 'owner_email', 'admin_email']],
        ['table' => 'users',          'cols' => ['email', 'login_email']],
        ['table' => 'admins',         'cols' => ['email', 'login_email']],
        ['table' => 'admin_users',    'cols' => ['email', 'login_email']],
        ['table' => 'accounts',       'cols' => ['email']],
        ['table' => 'trial_accounts', 'cols' => ['email']],
    ];

    foreach ($candidates as $c) {
        $table = (string)$c['table'];
        if (!table_exists($pdo, $table)) continue;

        $cols = table_columns($pdo, $table);
        if (empty($cols)) continue;

        foreach ($c['cols'] as $col) {
            if (!isset($cols[$col])) continue;

            try {
                $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE LOWER(TRIM(`$col`)) = :email LIMIT 1");
                $st->execute([':email' => $email]);
                if ($st->fetchColumn()) return true;
            } catch (Throwable $e) {
                // 次へ（壊さない）
            }
        }
    }

    return false;
}

// ✅ ここが今回の主目的：登録済みメールはこの時点で停止
try {
    if (email_already_registered($pdo, $email)) {
        redirect_with_flash('このメールアドレスはすでに登録されています。別のメールアドレスをご利用ください。', 'error');
    }
} catch (Throwable $e) {
    redirect_with_flash('メール確認に失敗しました。時間をおいて再度お試しください。', 'error');
}
/* ===== /追加ここまで ===== */

/**
 * 連投抑制（同一メールで直近60秒の再発行を抑止）
 */
$stmt = $pdo->prepare("SELECT created_at FROM trial_requests WHERE email=:email ORDER BY id DESC LIMIT 1");
$stmt->execute([':email' => $email]);
$last = $stmt->fetchColumn();
if ($last) {
    $lastTs = strtotime((string)$last);
    if ($lastTs !== false && (time() - $lastTs) < 60) {
        redirect_with_flash('連続送信を検知しました。1分ほど待ってから再度お試しください。', 'error');
    }
}

/**
 * トークン発行（DBには hash だけ保存）
 */
$token = bin2hex(random_bytes(24)); // 48文字
$tokenHash = hash('sha256', $token);
$expiresAt = (new DateTimeImmutable('now'))->modify('+24 hours')->format('Y-m-d H:i:s');

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$pdo->prepare("
    INSERT INTO trial_requests (email, token_hash, expires_at, request_ip, user_agent)
    VALUES (:email, :token_hash, :expires_at, :ip, :ua)
")->execute([
    ':email' => $email,
    ':token_hash' => $tokenHash,
    ':expires_at' => $expiresAt,
    ':ip' => $ip,
    ':ua' => $ua,
]);

/**
 * ✅ メール送信
 * - /kintai 配下でもリンクが正しくなるよう basePath を含める
 */
$bp = base_path();
$link = base_url() . $bp . '/trial_register.php?t=' . rawurlencode($token);

$subject = '【シメナビ】アカウント発行のご案内';
$body = ""
    . "シメナビ 無料お試しのアカウント発行リンクです。\n\n"
    . "▼24時間以内にこちらから登録してください\n"
    . $link . "\n\n"
    . "※このメールに心当たりがない場合は破棄してください。\n";

$ok = send_mail($email, $subject, $body, 'SHIMENABI', '');

if (!$ok) {
    error_log('[TRIAL] mail failed to=' . $email . ' link=' . $link);
    redirect_with_flash('送信処理を受け付けました。メールが届かない場合は時間をおいて再度お試しください。', 'info');
}

redirect_with_flash('送信しました。メールをご確認ください。', 'success');
