<?php
declare(strict_types=1);

/**
 * ✅ /admin/help_support.php（Help診断）
 * - send の後に ai_answer を表示＆historyを自動で取り直して「返信が見える」ようにする
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

$storeId = (int)($_GET['store_id'] ?? 0);
$debug = (((string)($_GET['debug'] ?? '')) === '1');

require_once __DIR__ . '/_db.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// .env 表示（診断用）
$envPath = dirname(__DIR__) . '/.env';
$envExists = is_file($envPath);
$k1 = (string)(getenv('AI_API_KEY_HELP') ?: '');
$k2 = (string)(getenv('OPENAI_API_KEY_HELP') ?: '');
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
        background: #f6f7fb;
        color: #111
    }

    .wrap {
        max-width: 980px;
        margin: 0 auto;
        padding: 16px
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 18px;
        padding: 16px
    }

    h1 {
        margin: 0 0 10px;
        font-size: 28px
    }

    .pills {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin: 10px 0 18px
    }

    .pill {
        border: 1px solid #e5e7eb;
        border-radius: 999px;
        padding: 8px 12px;
        font-weight: 900;
        background: #fff
    }

    .ok {
        color: #16a34a;
        font-weight: 900
    }

    .box {
        background: #0b0b0e;
        color: #fff;
        border-radius: 14px;
        padding: 12px;
        white-space: pre-wrap;
        word-break: break-word
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
        margin: 10px 0
    }

    input,
    button {
        font-size: 16px;
        border-radius: 12px;
        border: 1px solid #d1d5db;
        padding: 10px 12px
    }

    button {
        cursor: pointer;
        font-weight: 900
    }

    .btn {
        background: #365EAB;
        color: #fff;
        border: 0
    }

    .btn2 {
        background: #e5e7eb;
        color: #111;
        border: 0
    }

    .small {
        font-size: 13px;
        color: #6b7280
    }
    </style>
</head>

<body>
    <?php require __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card">
            <h1>🔎 Help 診断</h1>

            <div class="pills">
                <div class="pill">tenant_id: <?= (int)$tenantId ?></div>
                <div class="pill">store_id: <?= (int)$storeId ?></div>
                <div class="pill">debug=<?= $debug ? '1' : '0' ?></div>
                <div class="pill">AI_API_KEY_HELP: <?= $k1 ? 'set' : '(empty)' ?></div>
                <div class="pill">OPENAI_API_KEY_HELP: <?= $k2 ? 'set' : '(empty)' ?></div>
                <div class="pill">.env path: <?= h($envPath) ?> (exists=<?= $envExists ? '1' : '0' ?>)</div>
            </div>

            <div class="row">
                <button class="btn" onclick="doList()">list</button>
                <button class="btn2" onclick="doHistory()">history</button>
                <input id="threadId" placeholder="thread_id" style="width:140px" value="1">
            </div>

            <div class="row">
                <input id="msg" placeholder="send用メッセージ（例：こんにちは）" style="flex:1;min-width:240px">
                <button class="btn" onclick="doSend()">send</button>
                <button class="btn2" onclick="doClose()">close</button>
            </div>

            <div class="small">※ send は「AIの返事（ai_answer）」も返すので、ここで見えます。</div>

            <h3>結果（生ログ）</h3>
            <div class="box" id="out">---</div>
        </div>
    </div>

    <script>
    const STORE_ID = <?= json_encode((int)$storeId, JSON_UNESCAPED_UNICODE) ?>;

    function setOut(t) {
        document.getElementById('out').textContent = String(t);
    }

    async function callApi(payload) {
        const res = await fetch('/admin/help_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(Object.assign({
                store_id: STORE_ID
            }, payload))
        });
        const txt = await res.text();
        return {
            status: res.status,
            txt
        };
    }

    async function doList() {
        const r = await callApi({
            action: 'list'
        });
        setOut('HTTP ' + r.status + "\n" + r.txt);
    }

    async function doHistory() {
        const tid = Number(document.getElementById('threadId').value || 0);
        const r = await callApi({
            action: 'history',
            thread_id: tid
        });
        setOut('HTTP ' + r.status + "\n" + r.txt);
    }

    async function doSend() {
        const tid = Number(document.getElementById('threadId').value || 0);
        const msg = String(document.getElementById('msg').value || '').trim();
        if (!tid) {
            setOut('thread_id が空');
            return;
        }
        if (!msg) {
            setOut('message が空');
            return;
        }

        // ① send（ai_answer も返ってくる）
        const r = await callApi({
            action: 'send',
            thread_id: tid,
            message: msg
        });
        let out = 'HTTP ' + r.status + "\n" + r.txt;

        // ② ついでに history を自動で取り直して「DBに入ったか」も見えるようにする
        const h = await callApi({
            action: 'history',
            thread_id: tid
        });
        out += "\n\n--- auto history ---\nHTTP " + h.status + "\n" + h.txt;

        setOut(out);
    }

    async function doClose() {
        const tid = Number(document.getElementById('threadId').value || 0);
        const r = await callApi({
            action: 'close',
            thread_id: tid
        });
        setOut('HTTP ' + r.status + "\n" + r.txt);
    }
    </script>

</body>

</html>
