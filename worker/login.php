<?php
// /worker/login.php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../lib/billing_access.php';
worker_session_bootstrap();

if (isset($_GET['logout']) && (string)$_GET['logout'] === '1') {
    worker_logout();
    header('Location: /worker/login.php');
    exit;
}

// return_to（外部URL禁止）
$returnTo = (string)($_GET['return_to'] ?? '/worker/shifts.php');
if ($returnTo === '' || $returnTo[0] !== '/') $returnTo = '/worker/shifts.php';
if (strpos($returnTo, '/worker/') !== 0) $returnTo = '/worker/shifts.php';

if (isset($_SESSION['worker_auth']) && $_SESSION['worker_auth'] === 1) {
    header('Location: ' . $returnTo);
    exit;
}

// ===== DB =====
$paths = [
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
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

if (empty($_SESSION['worker_csrf'])) {
    $_SESSION['worker_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['worker_csrf'];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function pin_matches(array $emp, string $pin): bool
{
    $hash = $emp['auth_pin_hash'] ?? null;
    $salt = $emp['auth_pin_salt'] ?? null;
    if (!empty($hash) && !empty($salt)) {
        $calc = hash('sha256', $salt . $pin, true);
        return hash_equals($hash, $calc);
    }
    $code = (string)($emp['auth_pin_code'] ?? '');
    return ($code !== '') && hash_equals($code, $pin);
}

$error = '';
$tenantId = '';
$storeId = '';
$pin = '';
$loginCode = '';

if (isset($_GET['locked']) && (string)$_GET['locked'] === '1') {
    $error = '現在利用できません。';
}

function tenant_is_active(PDO $pdo, int $tenantId): bool
{
    try {
        return billing_access_should_allow($pdo, $tenantId);
    } catch (Throwable $e) {
        return true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, (string)$_POST['csrf_token'])) {
        $error = '再読み込みして、もう一度お試しください。';
    } else {
        $loginCode = trim((string)($_POST['login_code'] ?? ''));

        if ($loginCode === '') {
            $error = 'ログインコードを入力してください。';
        } elseif (preg_match('/^\\s*(\\d+)A(\\d+)A(\\d{4})\\s*$/i', $loginCode, $m)) {
            $tenantId = $m[1];
            $storeId = $m[2];
            $pin = $m[3];
        } else {
            $error = 'ログインコードの形式が正しくありません（例: 1A2A1234）。';
        }

        if ($error === '' && (!ctype_digit($tenantId) || (int)$tenantId <= 0)) {
            $error = 'テナントIDを正しく入力してください。';
        } elseif ($error === '' && (!ctype_digit($storeId) || (int)$storeId <= 0)) {
            $error = '店舗IDを正しく入力してください。';
        } elseif ($error === '' && !preg_match('/^\d{4}$/', $pin)) {
            $error = 'PINは4桁の数字で入力してください。';
        } else {
            $tId = (int)$tenantId;
            $sId = (int)$storeId;

            if (!tenant_is_active($pdo, $tId)) {
                $error = '現在利用できません。';
            } else {
            $empStmt = $pdo->prepare("
                SELECT id, display_name, auth_pin_code, auth_pin_hash, auth_pin_salt
                FROM employees
                WHERE tenant_id = :tenant_id
                  AND store_id  = :store_id
                  AND employment_status = 'active'
                ORDER BY id ASC
            ");
            $empStmt->execute([
                ':tenant_id' => $tId,
                ':store_id'  => $sId,
            ]);
            $rows = $empStmt->fetchAll();

            $match = null;
            foreach ($rows as $r) {
                if (pin_matches($r, $pin)) {
                    $match = $r;
                    break;
                }
            }

            if ($match) {
                $_SESSION['worker_auth'] = 1;
                $_SESSION['worker_tenant_id'] = $tId;
                $_SESSION['worker_store_id'] = $sId;
                $_SESSION['worker_employee_id'] = (int)$match['id'];
                $_SESSION['worker_employee_name'] = (string)($match['display_name'] ?? '');
                $_SESSION['worker_pin'] = $pin;
                $_SESSION['worker_sales_prompt_login_pending'] = 1;
                session_regenerate_id(true);
                if (function_exists('worker_refresh_session_cookie')) {
                    worker_refresh_session_cookie();
                }
                header('Location: ' . $returnTo);
                exit;
            }

            $error = 'ログイン情報が正しくありません。';
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
    <title>作業員ログイン</title>
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #fff;
            --text: #111827;
            --muted: rgba(17, 24, 39, .6);
            --line: rgba(17, 24, 39, .12);
            --accent: #2563eb;
            --accent-dark: #1d4ed8;
            --radius: 16px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        .card {
            width: min(420px, 100%);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 22px 20px;
            box-shadow: 0 18px 50px rgba(15, 23, 42, .12);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .logo img {
            height: 24px;
            width: auto;
            display: block;
        }

        .title {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 6px;
        }

        .subtitle {
            font-size: 13px;
            color: var(--muted);
            margin: 0 0 16px;
        }

        .field {
            margin-bottom: 12px;
        }

        .label {
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .input {
            width: 100%;
            height: 44px;
            padding: 0 12px;
            border: 1px solid var(--line);
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
        }

        .btn {
            width: 100%;
            height: 46px;
            border: none;
            border-radius: 12px;
            background: var(--accent);
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn:active {
            background: var(--accent-dark);
        }

        .error {
            background: #fff1f2;
            color: #9f1239;
            border: 1px solid rgba(190, 24, 93, .3);
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .hint {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.6;
        }
    </style>
</head>

<body>
    <div class="page">
        <form class="card" method="post">
            <div class="logo">
                <img src="/images/logo_main.png" alt="SHIMENABI">
            </div>
            <h1 class="title">作業員ログイン</h1>
            <p class="subtitle">ログインコード（テナントID A 店舗ID A PIN）でログインできます。</p>

            <?php if ($error !== ''): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>

            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

            <div class="field">
                <div class="label">ログインコード</div>
                <input class="input" name="login_code" inputmode="text" pattern="[A-Za-z0-9]+"
                    autocapitalize="off" autocomplete="one-time-code"
                    value="<?= h($loginCode) ?>" placeholder="例：1A2A1234" required>
            </div>

            <button class="btn" type="submit">ログイン</button>

            <p class="hint" style="margin-top:12px;">
                ※ ログインコードは「テナントID A 店舗ID A PIN」の形式です。<br>
                ※ 退職・非在籍の従業員はログインできません。
            </p>
        </form>
    </div>
</body>

</html>
