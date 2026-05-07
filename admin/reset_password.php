<?php

declare(strict_types=1);

session_name('ADMINSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

require_once __DIR__ . '/../api/lib/db.php'; // あなたの構成に合わせてOK

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$tenantId = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);
$rawToken = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$tokenHash = ($rawToken !== '') ? hash('sha256', $rawToken) : '';

$err = '';
$ok = '';

function isStrongEnough(string $pw): bool
{
    // 最低ライン（必要なら強化）
    if (strlen($pw) < 10) return false;
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPw = (string)($_POST['new_password'] ?? '');
    $newPw2 = (string)($_POST['new_password2'] ?? '');

    if ($tenantId <= 0 || $rawToken === '' || $tokenHash === '') {
        $err = 'リンクが不正です';
    } elseif ($newPw === '' || $newPw2 === '') {
        $err = '新しいパスワードを入力してください';
    } elseif ($newPw !== $newPw2) {
        $err = 'パスワードが一致しません';
    } elseif (!isStrongEnough($newPw)) {
        $err = 'パスワードは10文字以上にしてください';
    } else {
        // トークン検証（未使用・期限内）
        $stmt = $pdo->prepare("
            SELECT id, tenant_admin_user_id, expires_at, used_at
            FROM password_reset_tokens
            WHERE tenant_id = :tid
              AND token_hash = :hash
            LIMIT 1
        ");
        $stmt->execute([':tid' => $tenantId, ':hash' => $tokenHash]);
        $t = $stmt->fetch();

        if (!$t) {
            $err = 'リンクが無効です';
        } elseif (!empty($t['used_at'])) {
            $err = 'このリンクはすでに使用されています';
        } else {
            $exp = (string)$t['expires_at'];
            if (strtotime($exp) < time()) {
                $err = 'リンクの有効期限が切れています';
            } else {
                $tokenId = (int)$t['id'];
                $userId = (int)$t['tenant_admin_user_id'];

                // パスワード更新
                $hash = password_hash($newPw, PASSWORD_DEFAULT);

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("
                        UPDATE tenant_admin_users
                        SET password_hash = :ph
                        WHERE id = :uid
                          AND tenant_id = :tid
                        LIMIT 1
                    ")->execute([':ph' => $hash, ':uid' => $userId, ':tid' => $tenantId]);

                    // トークンを使用済みに
                    $pdo->prepare("
                        UPDATE password_reset_tokens
                        SET used_at = NOW()
                        WHERE id = :id
                          AND tenant_id = :tid
                        LIMIT 1
                    ")->execute([':id' => $tokenId, ':tid' => $tenantId]);

                    $pdo->commit();

                    // 念のためセッションをログアウト状態に寄せる
                    unset($_SESSION['admin_auth']);

                    $ok = 'パスワードを更新しました。ログインしてください。';
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $err = '更新に失敗しました';
                }
            }
        }
    }
}

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>新しいパスワード設定</title>
    <style>
    body {
        font-family: system-ui;
        padding: 18px;
    }

    .card {
        max-width: 520px;
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 14px;
    }

    input,
    button {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 10px;
        margin-top: 8px;
    }

    input:focus {
        outline: none;
        border-color: #365EAB;
    }

    button {
        background: #365EAB;
        color: #fff;
        border-color: #365EAB;
        cursor: pointer;
    }

    .ok {
        background: #eaffea;
        border: 1px solid #9be59b;
        padding: 10px;
        border-radius: 10px;
        margin-top: 10px;
    }

    .err {
        background: #ffecec;
        border: 1px solid #ffb3b3;
        padding: 10px;
        border-radius: 10px;
        margin-top: 10px;
    }

    .muted {
        color: #666;
        font-size: 12px;
        margin-top: 10px;
        line-height: 1.5;
    }

    a {
        color: #365EAB;
    }
    </style>
</head>

<body>
    <div class="card">
        <h2 style="margin:0 0 8px;">新しいパスワード設定</h2>

        <?php if ($ok): ?>
        <div class="ok"><?= h($ok) ?></div>
        <div class="muted"><a href="/admin/login.php">ログインへ →</a></div>
        <?php else: ?>
        <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
            <input type="hidden" name="token" value="<?= h($rawToken) ?>">

            <label class="muted">新しいパスワード（10文字以上）</label>
            <input type="password" name="new_password" required>

            <label class="muted">確認</label>
            <input type="password" name="new_password2" required>

            <button type="submit">更新する</button>
        </form>

        <div class="muted">
            ※リンクの有効期限は30分です。
        </div>
        <?php endif; ?>
    </div>
</body>

</html>
