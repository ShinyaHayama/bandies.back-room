<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/help_diag.php
 * ✅ 書き込み場所: このファイルを「新規作成」
 *
 * 【目的】
 * - サーバー側で「今どんなリクエストが来てるか」「DB列は揃ってるか」「.envが読めてるか」を即確認する診断ページ
 * - 画面で試す→ログファイルを見る、を最短にする
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

require_once __DIR__ . '/_db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'DB Error';
    exit;
}

$debug = (((string)($_GET['debug'] ?? '')) === '1');
$storeId = (int)($_GET['store_id'] ?? 0);

date_default_timezone_set('Asia/Tokyo');

function load_dotenv_if_needed(string $path): void
{
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);

        if ($v !== '' && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }

        $exists = getenv($k);
        if ($exists !== false && $exists !== '') continue;

        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
    }
}

load_dotenv_if_needed(dirname(__DIR__) . '/.env');

function table_columns(PDO $pdo, string $table): array
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cols = [];
    foreach ($rows as $r) {
        $cols[] = (string)($r['Field'] ?? '');
    }
    return array_values(array_filter($cols, fn($v) => $v !== ''));
}

$colsThreads = [];
$colsMessages = [];
$errThreads = '';
$errMessages = '';

try { $colsThreads = table_columns($pdo, 'help_threads'); } catch (Throwable $e) { $errThreads = $e->getMessage(); }
try { $colsMessages = table_columns($pdo, 'help_messages'); } catch (Throwable $e) { $errMessages = $e->getMessage(); }

$k = (string)(getenv('OPENAI_API_KEY_HELP') ?: '');
$kMasked = ($k === '') ? '(empty)' : (substr($k, 0, 6) . '...' . substr($k, -4));

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Help 診断</title>
    <style>
    body {
        font-family: system-ui;
        margin: 0;
        background: #f7f7fb;
        color: #111
    }

    .wrap {
        max-width: 980px;
        margin: 0 auto;
        padding: 14px
    }

    .card {
        background: #fff;
        border: 2px solid #e5e7eb;
        border-radius: 18px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
        padding: 14px
    }

    .h {
        font-weight: 900;
        margin: 0 0 10px 0
    }

    pre {
        background: #111;
        color: #fff;
        padding: 12px;
        border-radius: 12px;
        white-space: pre-wrap;
        word-break: break-word
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin: 10px 0
    }

    .pill {
        padding: 6px 10px;
        border: 2px solid #e5e7eb;
        border-radius: 999px;
        background: #fff;
        font-weight: 900;
        font-size: 12px
    }

    button {
        padding: 10px 12px;
        border-radius: 12px;
        border: 0;
        background: #365EAB;
        color: #fff;
        font-weight: 900;
        cursor: pointer
    }

    button.gray {
        background: #e5e7eb;
        color: #111
    }

    input {
        padding: 10px;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        min-width: 280px
    }

    .bad {
        color: #b91c1c;
        font-weight: 900
    }

    .ok {
        color: #16a34a;
        font-weight: 900
    }
    </style>
</head>

<body>
    <?php require __DIR__ . '/_header.php'; ?>
    <div class="wrap">
        <div class="card">
            <h2 class="h">🔎 Help 診断</h2>

            <div class="row">
                <div class="pill">tenant_id: <?= (int)$tenantId ?></div>
                <div class="pill">store_id: <?= (int)$storeId ?></div>
                <div class="pill">debug=<?= $debug ? '1' : '0' ?></div>
                <div class="pill">OPENAI_API_KEY_HELP: <?= htmlspecialchars($kMasked, ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <h3 class="h">DB: help_threads columns</h3>
            <?php if ($errThreads): ?>
            <div class="bad">NG: <?= htmlspecialchars($errThreads, ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
            <div class="ok">OK</div>
            <pre><?= htmlspecialchars(json_encode($colsThreads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
            <?php endif; ?>

            <h3 class="h">DB: help_messages columns</h3>
            <?php if ($errMessages): ?>
            <div class="bad">NG: <?= htmlspecialchars($errMessages, ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
            <div class="ok">OK</div>
            <pre><?= htmlspecialchars(json_encode($colsMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
            <?php endif; ?>

            <h3 class="h">API 簡易テスト</h3>
            <div class="row">
                <button type="button" onclick="callApi('list', {})">list</button>
                <button type="button" class="gray"
                    onclick="callApi('history', {thread_id: Number(document.getElementById('threadId').value||0)})">history</button>
                <input id="threadId" placeholder="thread_id を入力（history/send/close用）" />
            </div>

            <div class="row">
                <input id="msg" placeholder="send 用メッセージ（例：こんにちは）" />
                <button type="button"
                    onclick="callApi('send', {thread_id: Number(document.getElementById('threadId').value||0), message: String(document.getElementById('msg').value||'')})">send</button>
                <button type="button" class="gray"
                    onclick="callApi('close', {thread_id: Number(document.getElementById('threadId').value||0)})">close</button>
            </div>

            <h3 class="h">結果（生ログ）</h3>
            <pre id="out">ここに表示されます</pre>
        </div>
    </div>

    <script>
    const STORE_ID = <?= json_encode((int)$storeId, JSON_UNESCAPED_UNICODE) ?>;
    const DEBUG = <?= json_encode($debug, JSON_UNESCAPED_UNICODE) ?>;

    async function callApi(action, payload) {
        const url = '/admin/help_api.php' +
            '?action=' + encodeURIComponent(String(action)) +
            '&store_id=' + encodeURIComponent(String(STORE_ID)) +
            (DEBUG ? '&debug=1' : '');

        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(Object.assign({
                action: action,
                store_id: STORE_ID
            }, payload || {}))
        });

        const txt = await res.text();
        document.getElementById('out').textContent =
            'HTTP ' + res.status + '\n\n' + txt;
    }
    </script>
</body>

</html>
