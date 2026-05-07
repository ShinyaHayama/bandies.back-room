<?php
// /admin/login.php
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

/**
 * return_to（外部URL禁止）
 */
$returnTo = (string)($_GET['return_to'] ?? '/admin/employees_new.php');
if ($returnTo === '' || $returnTo[0] !== '/') $returnTo = '/admin/employees_new.php';
if (strpos($returnTo, '/admin/') !== 0) $returnTo = '/admin/employees_new.php';

/**
 * すでにログイン済みなら戻す
 */
if (isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === 1) {
    header('Location: ' . $returnTo);
    exit;
}

/**
 * DB 接続
 */
$paths = [
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
    __DIR__ . '/../../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if ($dbFile === null) {
    http_response_code(500);
    exit('db.php not found');
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/**
 * CSRF
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function isValidCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'CSRFトークンが不正です';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'email を正しく入力してください';
        } elseif ($pw === '') {
            $error = 'パスワードを入力してください';
        } else {
            $stmt = $pdo->prepare("
            SELECT id, tenant_id, password_hash
            FROM tenant_admin_users
            WHERE email = :email AND status = 'active'
            LIMIT 1
        ");
            $stmt->execute([':email' => $email]);

            $r = $stmt->fetch();
            if ($r && password_verify($pw, (string)$r['password_hash'])) {
                $_SESSION['admin_auth'] = 1;
                $_SESSION['tenant_id'] = (int)$r['tenant_id'];
                $_SESSION['tenant_admin_user_id'] = (int)$r['id'];
                session_regenerate_id(true);
                header('Location: ' . $returnTo);
                exit;
            }
            $error = 'email またはパスワードが違います';

            foreach ($stmt->fetchAll() as $r) {
                if (password_verify($pw, (string)$r['password_hash'])) {
                    $_SESSION['admin_auth'] = 1;
                    $_SESSION['tenant_id'] = (int)$r['tenant_id'];
                    $_SESSION['tenant_admin_user_id'] = (int)$r['id'];
                    session_regenerate_id(true);
                    header('Location: ' . $returnTo);
                    exit;
                }
            }
            $error = 'email またはパスワードが違います';
        }
    }
}

$query = 'return_to=' . rawurlencode($returnTo);

/**
 * ✅ ロゴをドメイン直下の /images/logo_main.png に統一
 * - ブラウザキャッシュで「変わらない」事故を避けるため ?v= を付ける
 */
$logoUrl = '../images/main_logo.png?v=' . date('YmdHis');
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>Admin Login</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">

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
        max-width: 420px;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 28px;
    }

    .logo {
        text-align: center;
        margin-bottom: 16px;
    }

    .logo img {
        max-width: 220px;
        width: 100%;
        height: auto;
        display: block;
        margin: 0 auto;
    }

    h1 {
        margin: 0 0 8px;
        font-size: 22px;
        font-weight: 700;
        text-align: center;
    }

    .sub {
        margin: 0 0 20px;
        font-size: 13px;
        color: #666;
        text-align: center;
    }

    .error {
        margin-bottom: 14px;
        padding: 10px 12px;
        border: 1px solid #ddd;
        background: #fafafa;
        color: #000;
        font-size: 13px;
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
        border-color: #000;
    }

    .field {
        margin-bottom: 14px;
    }

    button {
        width: 100%;
        padding: 11px;
        border-radius: 8px;
        border: 1px solid #000;
        background: #000;
        color: #fff;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
    }

    button:hover {
        opacity: 0.9;
    }

    .links {
        margin-top: 16px;
        display: flex;
        justify-content: space-between;
        font-size: 12px;
    }

    .links a {
        color: #000;
        text-decoration: none;
        border-bottom: 1px solid #ddd;
    }

    .links a:hover {
        border-bottom-color: #000;
    }

    .note {
        margin-top: 20px;
        font-size: 11px;
        color: #777;
        line-height: 1.6;
    }
    </style>
</head>

<body>
    <div class="wrapper">
        <div class="card">

            <div class="logo">
                <!-- ✅ ここを差し替え（../images/main_logo.png → /images/logo_main.png） -->
                <img src="<?= h($logoUrl) ?>" alt="Logo">
            </div>

            <h1>管理ログイン</h1>
            <p class="sub">管理者用アカウントでログインしてください</p>

            <?php if ($error): ?>
            <div class="error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/admin/login.php?<?= h($query) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                <div class="field">
                    <label>email</label>
                    <input type="email" name="email" value="<?= h($email) ?>" required>
                </div>

                <div class="field">
                    <label>パスワード</label>
                    <input type="password" name="password" required>
                </div>

                <button type="submit">ログイン</button>
            </form>

            <div class="links">
                <a href="/admin/forgot_password.php">パスワードを忘れた場合</a>
                <a href="<?= h($returnTo) ?>">管理画面へ</a>
            </div>

            <div class="note">
                tenant_admin_users の email + password で認証します。<br>
                status=active の管理者のみログイン可能です。
            </div>
        </div>
    </div>

    <script>
    document.querySelector('input[name="<?= $error ? 'password' : 'email' ?>"]')?.focus();
    </script>
</body>

</html>