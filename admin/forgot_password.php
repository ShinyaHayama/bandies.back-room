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
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/app_url.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

function isValidCsrf(string $t): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $t);
}

$okMessage = '';
$errMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!isValidCsrf($token)) {
        $errMessage = 'CSRFエラー。再読み込みしてください。';
    } else {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $email = trim((string)($_POST['email'] ?? ''));

        // 重要：存在しないメールでも「送信しました」風に返す（列挙攻撃対策）
        $okMessage = 'パスワード再設定メールを送信しました（該当があれば届きます）';

        if ($tenantId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // 入力不正でも同じ文言に寄せる（情報を出さない）
        } else {
            // active の管理者だけ対象
            $stmt = $pdo->prepare("
                SELECT id, email
                FROM tenant_admin_users
                WHERE tenant_id = :tid
                  AND email = :email
                  AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([':tid' => $tenantId, ':email' => $email]);
            $u = $stmt->fetch();

            if ($u) {
                $userId = (int)$u['id'];

                // トークン生成（生トークンはDBに保存しない）
                $rawToken = bin2hex(random_bytes(32)); // 64 chars
                $tokenHash = hash('sha256', $rawToken);

                // 30分有効
                $expiresAt = (new DateTimeImmutable('now'))->modify('+30 minutes')->format('Y-m-d H:i:s');

                // 既存未使用トークンを潰す（同一ユーザー）
                $pdo->prepare("
                    UPDATE password_reset_tokens
                    SET used_at = NOW()
                    WHERE tenant_id = :tid
                      AND tenant_admin_user_id = :uid
                      AND used_at IS NULL
                ")->execute([':tid' => $tenantId, ':uid' => $userId]);

                // 登録
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

                $pdo->prepare("
                    INSERT INTO password_reset_tokens
                        (tenant_id, tenant_admin_user_id, token_hash, expires_at, request_ip, user_agent)
                    VALUES
                        (:tid, :uid, :hash, :exp, :ip, :ua)
                ")->execute([
                    ':tid' => $tenantId,
                    ':uid' => $userId,
                    ':hash' => $tokenHash,
                    ':exp' => $expiresAt,
                    ':ip' => $ip,
                    ':ua' => $ua,
                ]);

                $baseUrl = app_public_base_url();

                $resetUrl = $baseUrl . '/admin/reset_password.php?tenant_id=' . urlencode((string)$tenantId)
                    . '&token=' . urlencode($rawToken);

                $subject = '【Azure勤怠】パスワード再設定';
                $body = "パスワード再設定の申請を受け付けました。\n\n";
                $body .= "下のURLを開いて、新しいパスワードを設定してください。\n";
                $body .= "（有効期限：30分）\n\n";
                $body .= $resetUrl . "\n\n";
                $body .= "心当たりがない場合は、このメールを破棄してください。\n";

                // 送信（fromは SMTP 設定に統一）
                send_mail($email, $subject, $body, 'SHIMENABI', '');
            }
        }
    }
}

// UI用（tenant_id は入力必須にするが、補助として GET で初期値）
$tenantIdDefault = (int)($_GET['tenant_id'] ?? 0);

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>パスワード再設定</title>

    <style>
    * {
        box-sizing: border-box;
    }

    html,
    body {
        margin: 0;
        height: 100%;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
            "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
        background: #ffffff;
        color: #111;
    }

    .wrapper {
        min-height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }

    .card {
        width: 100%;
        max-width: 520px;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 28px;
    }

    h1 {
        margin: 0 0 8px;
        font-size: 22px;
        font-weight: 700;
    }

    .sub {
        margin: 0 0 18px;
        font-size: 13px;
        color: #666;
        line-height: 1.6;
    }

    .notice {
        margin: 12px 0 0;
        padding: 10px 12px;
        border-radius: 10px;
        font-size: 13px;
        border: 1px solid #e5e5e5;
        background: #fafafa;
        color: #111;
    }

    .notice.ok {
        border-color: #d9d9d9;
    }

    .notice.err {
        border-color: #d9d9d9;
    }

    .field {
        margin-top: 14px;
    }

    label {
        display: block;
        font-size: 12px;
        margin-bottom: 6px;
        color: #333;
    }

    input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ccc;
        border-radius: 8px;
        font-size: 14px;
        background: #fff;
    }

    input:focus {
        outline: none;
        border-color: #365EAB;
    }

    button {
        width: 100%;
        margin-top: 16px;
        padding: 11px;
        border-radius: 8px;
        border: 1px solid #365EAB;
        background: #365EAB;
        color: #fff;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
    }

    button:hover {
        background: #2f4f93;
        border-color: #2f4f93;
    }

    .links {
        margin-top: 16px;
        display: flex;
        gap: 10px;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        font-size: 12px;
    }

    .links a {
        color: #365EAB;
        text-decoration: none;
        border-bottom: 1px solid #d6def4;
        padding-bottom: 1px;
    }

    .links a:hover {
        border-bottom-color: #365EAB;
    }

    .small {
        margin-top: 18px;
        font-size: 11px;
        color: #777;
        line-height: 1.6;
    }
    </style>
</head>

<body>
    <div class="wrapper">
        <div class="card">
            <h1>パスワード再設定</h1>
            <p class="sub">
                登録済みメール宛に再設定用URLを送信します。<br>
                セキュリティのため、該当がない場合でも同じ案内を表示します。
            </p>

            <?php if ($okMessage): ?>
            <div class="notice ok"><?= h($okMessage) ?></div>
            <?php endif; ?>
            <?php if ($errMessage): ?>
            <div class="notice err"><?= h($errMessage) ?></div>
            <?php endif; ?>

            <form method="post" style="margin-top:10px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                <div class="field">
                    <label>テナントID（必須）</label>
                    <input type="number" name="tenant_id" required min="1" value="<?= (int)$tenantIdDefault ?>"
                        placeholder="例: 1">
                </div>

                <div class="field">
                    <label>メールアドレス（必須）</label>
                    <input type="email" name="email" required placeholder="例: admin@example.com">
                </div>

                <button type="submit">再設定メールを送る</button>
            </form>

            <div class="links">
                <a href="/admin/login.php">ログインに戻る</a>
                <a href="/admin/">ダッシュボード</a>
            </div>

            <div class="small">
                ※メールが届かない場合：迷惑メール/受信拒否設定をご確認ください。<br>
                ※URLの有効期限：30分
            </div>
        </div>
    </div>

    <script>
    const first = document.querySelector('input[name="tenant_id"]');
    first?.focus();
    </script>
</body>

</html>
