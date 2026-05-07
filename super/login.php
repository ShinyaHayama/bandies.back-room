<?php

declare(strict_types=1);

/**
 * /super/login.php
 * Super管理 ログイン画面
 */

require_once __DIR__ . '/_auth.php';

// ====== ここだけあなた用に変更 ======
const SUPER_ADMIN_PASSWORD = 'Timetrip0415?'; // ←強固なPWに変更
// ===================================

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// すでにログイン済みなら tenants へ
if (!empty($_SESSION['super_admin_ok'])) {
    header('Location: /super/tenants.php');
    exit;
}

// CSRF
if (empty($_SESSION['super_csrf_token'])) {
    $_SESSION['super_csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['super_csrf_token'];

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $err = 'CSRFトークンが不正です（再読み込みしてください）';
    } else {
        $pw = (string)($_POST['password'] ?? '');
        if (hash_equals(SUPER_ADMIN_PASSWORD, $pw)) {
            // ✅ ログイン成功
            session_regenerate_id(true);

            $_SESSION['super_admin_ok'] = true;
            $_SESSION['super_admin_login_at'] = time();

            header('Location: /super/tenants.php');
            exit;
        } else {
            $err = 'パスワードが違います';
        }
    }
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Super 管理ログイン</title>
    <style>
    body {
        font-family: system-ui, -apple-system, sans-serif;
        padding: 24px;
        background: #fafafa;
    }

    .card {
        max-width: 420px;
        margin: 40px auto 0;
        padding: 18px;
        border: 1px solid #ddd;
        border-radius: 12px;
        background: #fff;
        box-sizing: border-box;
    }

    label {
        display: block;
        font-weight: 700;
        margin-top: 12px;
    }

    input {
        width: 100%;
        padding: 10px;
        margin-top: 8px;
        box-sizing: border-box;
        border: 1px solid #ddd;
        border-radius: 10px;
    }

    button {
        margin-top: 12px;
        padding: 12px 14px;
        width: 100%;
        font-weight: 800;
        border-radius: 10px;
        border: 1px solid #111;
        background: #111;
        color: #fff;
        cursor: pointer;
    }

    .err {
        background: #ffecec;
        border: 1px solid #ffb3b3;
        padding: 10px;
        border-radius: 10px;
        margin: 10px 0 12px;
    }

    .muted {
        color: #666;
        font-size: 12px;
        margin-top: 10px;
    }
    </style>
</head>

<body>
    <div class="card">
        <h2 style="margin:0 0 10px;">Super 管理ログイン</h2>

        <?php if ($err): ?>
        <div class="err"><?= h($err) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <label>パスワード</label>
            <input type="password" name="password" required autocomplete="current-password">
            <button type="submit">ログイン</button>
        </form>

        <div class="muted">※Super管理はSaaS運営者用です</div>
    </div>
</body>

</html>
