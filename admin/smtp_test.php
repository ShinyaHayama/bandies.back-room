<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SMTP Test Error\n\n";
    echo $e;
    exit;
});
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/../lib/mailer.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

mailer_load_env_once();

$smtpHost = (string)(getenv('SMTP_HOST') ?: '');
$smtpPort = (string)(getenv('SMTP_PORT') ?: '');
$smtpUser = (string)(getenv('SMTP_USER') ?: '');
$smtpPass = (string)(getenv('SMTP_PASS') ?: '');
$smtpSecure = (string)(getenv('SMTP_SECURE') ?: '');

$result = '';
$resultClass = '';

$to = $smtpUser !== '' ? $smtpUser : '';
$subject = 'SMTP テスト送信';
$body = "SMTP テスト送信です。\n\n送信元: SHIMENABI\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $postCsrf)) {
        $result = 'CSRFエラーです。再読み込みしてください。';
        $resultClass = 'err';
    } else {
        $to = trim((string)($_POST['to'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));

        if ($to === '' || $subject === '' || $body === '') {
            $result = '宛先・件名・本文は必須です。';
            $resultClass = 'err';
        } else {
            if (function_exists('send_mail_with_error')) {
                [$ok, $err] = send_mail_with_error($to, $subject, $body, 'SHIMENABI', '');
            } else {
                $ok = send_mail($to, $subject, $body, 'SHIMENABI', '');
                $err = $ok ? '' : 'error_detail_unavailable';
            }

            if ($ok) {
                $result = '送信成功しました。';
                $resultClass = 'ok';
            } else {
                $result = '送信失敗: ' . ($err !== '' ? $err : 'unknown_error');
                $resultClass = 'err';
            }
        }
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$passMask = $smtpPass !== '' ? str_repeat('*', min(16, max(6, strlen($smtpPass)))) : '(未設定)';
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>SMTP テスト送信</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: system-ui, -apple-system, "Hiragino Sans", "Noto Sans JP", sans-serif; background:#f7f7f7; margin:0; }
        .wrap { max-width: 760px; margin: 24px auto; padding: 0 14px; }
        .card { background:#fff; border:1px solid #ddd; border-radius:12px; padding:16px; }
        .row { display:grid; grid-template-columns: 1fr; gap:12px; }
        label { font-size:12px; color:#333; margin-bottom:6px; display:block; font-weight:700; }
        input, textarea { width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:10px; font-size:14px; }
        textarea { min-height: 160px; }
        .btn { padding:12px 14px; border-radius:10px; border:1px solid #000; background:#000; color:#fff; font-weight:700; cursor:pointer; width:100%; }
        .meta { font-size:12px; color:#666; line-height:1.6; }
        .ok { color:#0f766e; font-weight:700; }
        .err { color:#b91c1c; font-weight:700; }
        .envBox { background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:10px; font-size:12px; color:#444; }
        .envBox div { margin-bottom:4px; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/_header.php'; ?>
    <div class="wrap">
        <div class="card">
            <h2 style="margin:0 0 8px;">SMTP テスト送信</h2>
            <div class="meta" style="margin-bottom:12px;">.env のSMTP設定で送信テストを行います。</div>

            <div class="envBox" style="margin-bottom:12px;">
                <div>SMTP_HOST: <?= h($smtpHost ?: '(未設定)') ?></div>
                <div>SMTP_PORT: <?= h($smtpPort ?: '(未設定)') ?></div>
                <div>SMTP_USER: <?= h($smtpUser ?: '(未設定)') ?></div>
                <div>SMTP_PASS: <?= h($passMask) ?></div>
                <div>SMTP_SECURE: <?= h($smtpSecure !== '' ? $smtpSecure : '(自動)') ?></div>
            </div>

            <?php if ($result !== ''): ?>
                <div class="<?= h($resultClass) ?>" style="margin:8px 0 12px;"><?= h($result) ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <div class="row">
                    <div>
                        <label>送信先</label>
                        <input type="email" name="to" value="<?= h($to) ?>" required>
                    </div>
                    <div>
                        <label>件名</label>
                        <input type="text" name="subject" value="<?= h($subject) ?>" required>
                    </div>
                    <div>
                        <label>本文</label>
                        <textarea name="body" required><?= h($body) ?></textarea>
                    </div>
                    <button class="btn" type="submit">SMTP送信テスト</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
