<?php

declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Tokyo');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * ✅ このスクリプトが置かれているベースパスを自動判定
 * 例）
 * - /kintai/trial_register.php なら "/kintai"
 * - /trial_register.php なら ""
 */
function base_path(): string
{
    $p = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($p === '/' ? '' : $p);
}

$basePath = base_path();

$token = (string)($_GET['t'] ?? '');
if ($token === '') {
    http_response_code(400);
    exit('invalid token');
}
$tokenHash = hash('sha256', $token);

/**
 * ✅ CSSパス自動判定（/kintai 配下でもOK）
 * 優先順:
 * 1) /assets/style.css
 * 2) /admin/assets/style.css
 * 3) /kintai/assets/style.css（= このファイルの階層基準）
 * 4) ./assets/style.css
 */
$cssHref = '/assets/style.css?v=3';
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

if ($docRoot !== '' && !is_file($docRoot . '/assets/style.css')) {
    if (is_file($docRoot . '/admin/assets/style.css')) {
        $cssHref = '/admin/assets/style.css?v=3';
    } elseif ($basePath !== '' && is_file($docRoot . $basePath . '/assets/style.css')) {
        $cssHref = $basePath . '/assets/style.css?v=3';
    } else {
        $cssHref = './assets/style.css?v=3';
    }
}

/**
 * ✅ DB 接続（/kintai 配下でも見つかるように候補を増やす）
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

$stmt = $pdo->prepare("SELECT * FROM trial_requests WHERE token_hash=:h LIMIT 1");
$stmt->execute([':h' => $tokenHash]);
$req = $stmt->fetch();

if (!$req) {
    http_response_code(404);
    exit('token not found');
}
if ((string)$req['status'] !== 'pending') {
    http_response_code(410);
    exit('token already used');
}
if (strtotime((string)$req['expires_at']) < time()) {
    $pdo->prepare("UPDATE trial_requests SET status='expired' WHERE id=:id")->execute([':id' => $req['id']]);
    http_response_code(410);
    exit('token expired');
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

$email = (string)$req['email'];
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>シメナビ｜新規登録</title>
    <link rel="stylesheet" href="<?= h($cssHref) ?>" />
    <style>
        body {
            font-family: system-ui, -apple-system, "Hiragino Sans", "Noto Sans JP", sans-serif;
            background: #f7f7f7;
            margin: 0;
        }

        .regWrap {
            max-width: 920px;
            margin: 24px auto;
            padding: 0 14px;
        }

        .regCard {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 14px;
            padding: 18px;
        }

        .row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .field label {
            display: block;
            font-size: 12px;
            color: #333;
            margin-bottom: 6px;
        }

        .field input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 10px;
            font-size: 14px;
            background: #fff;
        }

        .help {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
            line-height: 1.6;
        }

        .pwRow {
            position: relative;
        }

        .pwToggle {
            position: absolute;
            right: 10px;
            top: 36px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 8px;
            padding: 6px 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .pwToggle:focus {
            outline: none;
            border-color: #000;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #000;
            background: #000;
            color: #fff;
            font-weight: 700;
            width: 100%;
            cursor: pointer;
        }

        .btn:hover {
            opacity: .9;
        }

        .muted {
            color: #666;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <div class="regWrap">
        <div class="regCard">
            <h1 style="margin:0 0 6px;font-size:20px;">新規登録（無料お試し）</h1>
            <div class="muted" style="margin:0 0 14px;">この情報で、管理ログイン用アカウントと初期店舗を作成します。</div>

            <!-- ✅ /kintai 配下でもOKな submit 先 -->
            <form method="post" action="<?= h($basePath) ?>/trial_register_submit.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="t" value="<?= h($token) ?>">

                <div class="row">
                    <div class="field">
                        <label>メールアドレス（管理ログインID）</label>
                        <input type="email" value="<?= h($email) ?>" readonly>
                        <div class="help">※管理画面ログインで使用します。</div>
                    </div>

                    <div class="field">
                        <label>会社名（テナント名）</label>
                        <input type="text" name="tenant_name" placeholder="例）株式会社〇〇" required>
                    </div>

                    <div class="field">
                        <label>店舗名（初期）</label>
                        <input type="text" name="store_name" value="本店" required>
                    </div>

                    <div class="field">
                        <label>パスワード（管理ログイン用）</label>
                        <div class="pwRow">
                            <input type="password" id="password" name="password" minlength="8" required>
                            <button type="button" class="pwToggle" data-pw-toggle>表示</button>
                        </div>
                        <div class="help">※8文字以上推奨。あとで変更もできます。</div>
                    </div>

                    <div class="field">
                        <label>パスワード（確認）</label>
                        <div class="pwRow">
                            <input type="password" id="password_confirm" name="password_confirm" minlength="8" required>
                            <button type="button" class="pwToggle" data-pw-toggle>表示</button>
                        </div>
                    </div>

                    <button class="btn" type="submit">この内容でアカウントを作成</button>
                </div>

                <p class="help" style="margin-top:12px;">
                    作成後、管理画面ログインへ移動します。
                </p>
            </form>

        </div>
    </div>

    <script>
    document.querySelectorAll('[data-pw-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = btn.parentElement.querySelector('input');
            if (!input) return;
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            btn.textContent = isPassword ? '非表示' : '表示';
        });
    });
    </script>
</body>

</html>
