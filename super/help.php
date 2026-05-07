<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_db.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Help Desk</title>
    <style>
        body {
            font-family: system-ui;
            margin: 0;
            background: #fff;
            color: #111
        }

        .layout {
            display: grid;
            grid-template-columns: 360px 1fr;
            height: calc(100vh - 0px)
        }

        .left {
            border-right: 1px solid #eee;
            overflow: auto
        }

        .right {
            display: flex;
            flex-direction: column
        }

        .head {
            padding: 12px;
            border-bottom: 1px solid #eee;
            font-weight: 900
        }

        .list {
            padding: 8px
        }

        .item {
            border: 1px solid #eee;
            background: #fff;
            border-radius: 12px;
            padding: 10px;
            margin: 8px 0;
            cursor: pointer
        }

        .item .t {
            font-weight: 900
        }

        .item .s {
            font-size: 12px;
            color: #666;
            margin-top: 4px
        }

        .item.active {
            border-color: #111
        }

        .chat {
            flex: 1;
            overflow: auto;
            padding: 12px;
            background: #fafafa
        }

        .msg {
            display: flex;
            margin: 10px 0
        }

        .msg .b {
            max-width: 78%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #ddd;
            background: #fff;
            white-space: pre-wrap;
            word-break: break-word
        }

        .msg.tenant {
            justify-content: flex-start
        }

        .msg.staff {
            justify-content: flex-end
        }

        .msg.staff .b {
            background: #111;
            color: #fff;
            border-color: #111
        }

        .msg.ai .b {
            background: #fff;
            border-color: #ddd
        }

        .bar {
            display: flex;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid #eee;
            background: #fff;
            align-items: flex-end
        }

        textarea {
            flex: 1;
            min-height: 44px;
            max-height: 140px;
            resize: vertical;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 12px
        }

        .fileInput {
            display: flex;
            align-items: center;
            gap: 6px
        }

        .fileInput input[type="file"] {
            display: none
        }

        .attachBtn {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer
        }

        .attachBtn svg {
            width: 18px;
            height: 18px;
            stroke: #111
        }

        .attachImg {
            display: block;
            max-width: 240px;
            border-radius: 10px
        }

        .attachLink {
            display: inline-block;
            font-size: 13px;
            color: #111;
            text-decoration: none;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 6px 8px;
            background: #fff
        }

        button {
            padding: 10px 12px;
            border: 1px solid #111;
            background: #111;
            color: #fff;
            border-radius: 10px;
            font-weight: 900;
            cursor: pointer
        }

        button.secondary {
            background: #fff;
            color: #111
        }

        .sendLine {
            background: #06c755;
            border: 0;
            color: #fff;
            font-weight: 900;
            border-radius: 14px;
            padding: 10px 16px
        }

        .metaRow {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            font-size: 12px;
            color: #666;
            padding: 0 12px 12px
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 999px;
            background: #fff;
            font-weight: 900;
            color: #111
        }

        @media (max-width: 980px) {
            .layout {
                grid-template-columns: 1fr
            }

            .left {
                height: 40vh
            }

            .right {
                height: 60vh
            }
        }
    </style>
</head>

<body>
    <?php require __DIR__ . '/_top.php'; ?>

    <div class="layout">
        <div class="left">
            <div class="head">問い合わせ（テナント/店舗別）</div>
            <div class="list" id="threadList"></div>
        </div>

        <div class="right">
            <div class="head" id="chatHead">スレッドを選択してください</div>
            <div class="metaRow" id="chatMeta"></div>
            <div class="chat" id="chat"></div>
            <div class="bar">
                <textarea id="text" placeholder="返信を入力"></textarea>
                <div class="fileInput">
                    <label class="attachBtn">
                        <input type="file" id="file" accept="image/*,.heic,.heif,.pdf,.txt,.csv,.xlsx,.xls,.doc,.docx,.zip">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21.44 11.05l-8.49 8.49a5 5 0 0 1-7.07-7.07l8.49-8.49a3.5 3.5 0 0 1 4.95 4.95l-8.5 8.49a2 2 0 1 1-2.83-2.83l8.49-8.49"/>
                        </svg>
                    </label>
                </div>
                <button class="sendLine" id="sendBtn" type="button" onclick="sendMsg()" disabled>送信</button>
                <button class="secondary" type="button" onclick="markClosed()" disabled id="closeBtn">解決</button>
            </div>
        </div>
    </div>

    <script>
        let CURRENT_THREAD = 0;
        const INITIAL_THREAD = <?= (int)($_GET['thread_id'] ?? 0) ?>;

        function esc(s) {
            return String(s).replace(/[&<>"']/g, m => ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#039;"
            } [m]));
        }

        function formatBytes(n) {
            const v = Number(n || 0);
            if (!v) return '';
            if (v < 1024) return v + ' B';
            if (v < 1024 * 1024) return (v / 1024).toFixed(1) + ' KB';
            return (v / (1024 * 1024)).toFixed(1) + ' MB';
        }

        const chat = document.getElementById('chat');
        function scrollToBottom() {
            if (!chat) return;
            chat.scrollTop = chat.scrollHeight;
        }
        function isNearBottom() {
            if (!chat) return false;
            return (chat.scrollHeight - chat.scrollTop - chat.clientHeight) < 40;
        }

        async function api(action, payload) {
            const res = await fetch('/super/help_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(Object.assign({
                    action
                }, payload || {}))
            });
            const txt = await res.text();
            let data = null;
            try {
                data = JSON.parse(txt);
            } catch (e) {}
            if (!res.ok || !data || !data.ok) throw new Error((data && data.error) ? data.error : ('API error ' + res
                .status + ' ' + txt));
            return data;
        }

        async function markSeen() {
            if (!CURRENT_THREAD) return;
            try {
                await api('seen', { thread_id: CURRENT_THREAD });
            } catch (e) {}
        }

        function renderList(threads) {
            const box = document.getElementById('threadList');
            box.innerHTML = '';
            threads.forEach(t => {
                const div = document.createElement('div');
                div.className = 'item' + ((t.id === CURRENT_THREAD) ? ' active' : '');
                div.onclick = () => openThread(t.id);
                div.innerHTML =
                    '<div class="t">tenant#' + esc(t.tenant_id) + ' / store#' + esc(t.store_id || '-') +
                    ' <span class="badge">' + esc(t.status) + '</span></div>' +
                    '<div class="s">' + esc(t.last_message_at || '') + '</div>' +
                    '<div class="s">' + esc(t.last_preview || '') + '</div>';
                box.appendChild(div);
            });
        }

        function appendMsg(sender, text, attach) {
            const div = document.createElement('div');
            div.className = 'msg ' + sender;
            let html = '';
            if (text) {
                html += '<div class="b">' + esc(text) + '</div>';
            }
            if (attach && attach.path) {
                const url = '/super/help_attachment_download.php?message_id=' + encodeURIComponent(attach.id);
                html += '<div class="b">';
                if (attach.mime && ['image/jpeg', 'image/png', 'image/gif', 'image/webp'].indexOf(attach.mime) >= 0) {
                    html += '<a href="' + url + '" target="_blank" rel="noopener">' +
                        '<img class="attachImg" src="' + url + '" alt="' + esc(attach.name || 'image') + '">' +
                        '</a>';
                }
                const size = attach.size ? (' (' + esc(formatBytes(attach.size)) + ')') : '';
                html += '<a class="attachLink" href="' + url + '" target="_blank" rel="noopener">' +
                    esc(attach.name || 'file') + size + '</a>';
                html += '</div>';
            }
            if (html !== '') {
                div.innerHTML = html;
                chat.appendChild(div);
                scrollToBottom();
            }
        }

        function maxId(messages) {
            let m = 0;
            (messages || []).forEach(x => {
                const id = Number(x.id || 0);
                if (id > m) m = id;
            });
            return m;
        }

        function renderAll(messages) {
            if (!chat) return;
            chat.innerHTML = '';
            (messages || []).forEach(m => {
                const role = String(m.sender_role || m.sender_type || '');
                const sender = (role === 'tenant_admin') ? 'tenant' : (role === 'support_admin' ? 'staff' : 'ai');
                const body = (m.body !== undefined ? m.body : m.message_text);
                appendMsg(sender, body, {
                    id: m.id,
                    path: m.attachment_path,
                    name: m.attachment_name,
                    mime: m.attachment_mime,
                    size: m.attachment_size
                });
            });
            scrollToBottom();
        }

        async function reloadList() {
            const data = await api('list', {});
            renderList(data.threads || []);
        }

        async function openThread(threadId) {
            CURRENT_THREAD = threadId;
            document.getElementById('sendBtn').disabled = false;
            document.getElementById('closeBtn').disabled = false;

            const data = await api('history', {
                thread_id: threadId
            });
            renderAll(data.messages || []);
            lastId = maxId(data.messages || []);
            await markSeen();

            const th = data.thread;
            document.getElementById('chatHead').textContent = 'tenant#' + th.tenant_id + ' / store#' + (th.store_id ||
                '-') + ' / thread#' + th.id;
            document.getElementById('chatMeta').innerHTML =
                '<span class="badge">' + esc(th.status) + '</span>' +
                '<span class="badge">last: ' + esc(th.last_message_at || '') + '</span>';

            await reloadList();
        }

        async function sendMsg() {
            if (!CURRENT_THREAD) return;
            const ta = document.getElementById('text');
            const v = ta.value.trim();
            const fileInput = document.getElementById('file');
            const file = fileInput.files && fileInput.files[0];
            if (!v && !file) return;
            ta.value = '';
            fileInput.value = '';
            if (file) {
                const fd = new FormData();
                fd.append('action', 'send_upload');
                fd.append('thread_id', String(CURRENT_THREAD));
                if (v) fd.append('message', v);
                fd.append('file', file);
                const res = await fetch('/super/help_api.php', {
                    method: 'POST',
                    body: fd
                });
                const txt = await res.text();
                let data = null;
                try { data = JSON.parse(txt); } catch (e) {}
                if (!res.ok || !data || !data.ok) {
                    throw new Error((data && data.error) ? data.error : ('API error ' + res.status + ' ' + txt));
                }
            } else {
                await api('send', {
                    thread_id: CURRENT_THREAD,
                    message: v
                });
            }
            await pollThread();
        }

        async function markClosed() {
            if (!CURRENT_THREAD) return;
            await api('close', {
                thread_id: CURRENT_THREAD
            });
            await reloadList();
            document.getElementById('chatMeta').innerHTML += ' <span class="badge">closed</span>';
        }

        let lastId = 0;
        async function pollThread() {
            if (!CURRENT_THREAD) return;
            const data = await api('history', { thread_id: CURRENT_THREAD });
            const messages = data.messages || [];
            const newest = maxId(messages);
            if (newest > lastId) {
                const near = isNearBottom();
                messages.forEach(m => {
                    const id = Number(m.id || 0);
                    if (id > lastId) {
                        const role = String(m.sender_role || m.sender_type || '');
                        const sender = (role === 'tenant_admin') ? 'tenant' : (role === 'support_admin' ? 'staff' : 'ai');
                        const body = (m.body !== undefined ? m.body : m.message_text);
                        appendMsg(sender, body, {
                            id: m.id,
                            path: m.attachment_path,
                            name: m.attachment_name,
                            mime: m.attachment_mime,
                            size: m.attachment_size
                        });
                    }
                });
                lastId = newest;
                if (near) scrollToBottom();
                await reloadList();
            }
            const th = data.thread;
            if (th) {
                document.getElementById('chatMeta').innerHTML =
                    '<span class="badge">' + esc(th.status) + '</span>' +
                    '<span class="badge">last: ' + esc(th.last_message_at || '') + '</span>';
            }
            await markSeen();
        }

        reloadList();

        setInterval(() => {
            if (!CURRENT_THREAD) return;
            pollThread();
        }, 5000);

        (async () => {
            if (INITIAL_THREAD > 0) {
                await openThread(INITIAL_THREAD);
            }
        })();

        setInterval(() => {
            reloadList();
        }, 5000);
    </script>
</body>

</html>
