<?php

declare(strict_types=1);

/**
 * ✅ /super/help_kb_manage.php（tenant_id / store_id 完全撤去版）
 *
 * 方針：
 * - tenant_id / store_id を一切使わない（画面もAPIもDB条件も無し）
 * - super 管理者だけアクセス可能
 * - CSRF はこの画面専用セッションキー（help_kb_csrf）
 * - miss（未ヒット質問）一覧・削除・KB昇格
 * - KB 一覧・新規・編集・keywords 即編集（blur/EnterでAJAX保存）
 *
 * 前提：
 * - /super/_auth.php に require_super_admin_login() がある
 * - /super/_db.php で $pdo (PDO) が利用できる
 * - /super/help_kb_api.php が同階層にある
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

$debug = (((string)($_GET['debug'] ?? '')) === '1');

// super auth
require_once __DIR__ . '/_auth.php';
require_super_admin_login();

// session (CSRF用)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['help_kb_csrf'])) {
    $_SESSION['help_kb_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['help_kb_csrf'];

// db
require_once __DIR__ . '/_db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'db_not_ready';
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// escape helper（衝突回避のため function_exists ガード）
if (!function_exists('hkm_h')) {
    function hkm_h(string $s): string
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
    <title>Help KB 管理</title>
    <!-- ✅ この <style> を「丸ごと置き換え」 -->
    <style>
    /* =========================
   Base
========================= */
    :root {
        --bg: #f6f7fb;
        --card: #ffffff;
        --text: #0f172a;
        --muted: #64748b;
        --line: #e5e7eb;
        --line2: #eef2f7;
        --primary: #2563eb;
        --primary2: #1d4ed8;
        --danger: #ef4444;
        --shadow: 0 6px 24px rgba(15, 23, 42, .06);
        --shadow2: 0 18px 60px rgba(15, 23, 42, .18);
        --r12: 12px;
        --r16: 16px;
        --r20: 20px;
    }

    * {
        box-sizing: border-box;
    }

    html,
    body {
        height: 100%;
    }

    body {
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
        margin: 0;
        background: var(--bg);
        color: var(--text);
    }

    /* =========================
   Layout
========================= */
    .wrap {
        max-width: 1180px;
        margin: 0 auto;
        padding: 14px;
    }

    .card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: var(--r20);
        box-shadow: var(--shadow);
    }

    .top {
        padding: 14px 16px;
        border-bottom: 1px solid var(--line);
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .title {
        font-size: 18px;
        font-weight: 900;
        letter-spacing: .2px;
    }

    .pill {
        padding: 6px 10px;
        border: 1px solid var(--line);
        border-radius: 999px;
        background: #fff;
        font-weight: 900;
        font-size: 12px;
        color: #111;
    }

    .row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    /* ✅ 2カラムをやめて「常に縦積み」にする（壊れ防止） */
    .grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .sec {
        padding: 12px;
    }

    .sec h2 {
        margin: 0 0 10px;
        font-size: 16px;
    }

    .hint {
        color: var(--muted);
        font-size: 13px;
        margin: 0 0 10px;
        line-height: 1.5;
    }

    /* =========================
   Inputs / Buttons
========================= */
    select,
    input,
    textarea {
        font-size: 14px;
        color: var(--text);
    }

    input,
    textarea {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid var(--line);
        border-radius: var(--r12);
        background: #fff;
        outline: none;
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    input:focus,
    textarea:focus {
        border-color: rgba(37, 99, 235, .55);
        box-shadow: 0 0 0 4px rgba(37, 99, 235, .12);
    }

    .btn {
        padding: 10px 12px;
        border: 0;
        border-radius: 14px;
        font-weight: 900;
        cursor: pointer;
        transition: transform .04s ease, filter .12s ease;
        user-select: none;
    }

    .btn:active {
        transform: translateY(1px);
    }

    .btnPrimary {
        background: var(--primary);
        color: #fff;
    }

    .btnPrimary:hover {
        filter: brightness(.98);
        background: var(--primary2);
    }

    .btnGray {
        background: #e5e7eb;
        color: #111;
    }

    .btnGray:hover {
        filter: brightness(.98);
    }

    .btnDanger {
        background: var(--danger);
        color: #fff;
    }

    .btnDanger:hover {
        filter: brightness(.98);
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        border: 1px solid var(--line);
        background: #fff;
        font-size: 12px;
        font-weight: 900;
        color: #111;
    }

    .mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 12px;
        color: #0f172a;
    }

    /* =========================
   Table (壊れ防止・可読性UP)
========================= */
    .tbl {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        table-layout: fixed;
        /* ✅ 列幅を固定して暴れない */
        font-size: 14px;
    }

    .tbl th,
    .tbl td {
        border-bottom: 1px solid var(--line2);
        padding: 12px 12px;
        vertical-align: top;
        overflow: hidden;
    }

    .tbl th {
        background: #fafafa;
        text-align: left;
        font-size: 12px;
        color: #334155;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    /* ✅ 長文が縦1文字になって崩れるのを抑える */
    .tbl td {
        word-break: break-word;
        overflow-wrap: anywhere;
        line-height: 1.55;
    }

    /* ✅ ID列などの見た目 */
    .tbl td.mono,
    .tbl td .mono {
        color: #0f172a;
    }

    /* ✅ body列：縦に長くなりすぎない（セル内スクロール） */
    .kbBodyCell {
        max-height: 110px;
        /* 好みで 90〜160 */
        overflow: auto;
        padding-right: 8px;
    }

    /* ✅ title列：程よく折り返す */
    .kbTitleCell {
        max-height: 80px;
        overflow: auto;
        padding-right: 8px;
    }

    /* ✅ 操作列は中央寄せ気味 */
    .kbOpsCell {
        white-space: nowrap;
    }

    /* =========================
   keywords 即編集：入力を「大きく見せる」
   ※inputなので改行は不可。見た目だけ広く・大きく・読みやすく。
========================= */
    .kwInput {
        width: 100%;
        padding: 12px 12px;
        border: 2px solid var(--line);
        border-radius: 14px;
        background: #fff;
        font-weight: 700;
        letter-spacing: .2px;
        min-height: 44px;
        /* ✅ 小さすぎ対策 */
    }

    /* ✅ 文字が長い時に見やすいように */
    .kwInput {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 13px;
    }

    /* =========================
   Header bar link area
========================= */
    a.link {
        color: var(--primary);
        text-decoration: none;
        font-weight: 900;
    }

    a.link:hover {
        text-decoration: underline;
    }

    /* =========================
   Modal
========================= */
    .modalBg {
        position: fixed;
        inset: 0;
        background: rgba(2, 6, 23, .45);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        z-index: 50;
    }

    .modal {
        width: min(980px, 96vw);
        /* ✅ もっと広く */
        background: #fff;
        border-radius: 22px;
        border: 1px solid var(--line);
        box-shadow: var(--shadow2);
        overflow: hidden;
    }

    .modalHead {
        padding: 14px 16px;
        border-bottom: 1px solid var(--line);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        background: #fff;
    }

    .modalBody {
        padding: 14px 16px;
    }

    .modalBody label {
        display: block;
        font-size: 12px;
        color: var(--muted);
        margin: 12px 0 6px;
        font-weight: 900;
    }

    /* ✅ モーダル内の body は広く・縦も確保 */
    .modalBody textarea {
        min-height: 220px;
        /* ✅ 縦に長く書ける */
        resize: vertical;
    }

    /* ✅ keywords も少し大きく */
    .modalBody input {
        min-height: 44px;
    }

    /* ✅ チェック行を左寄せで読みやすく */
    #delMissRow {
        gap: 10px !important;
        align-items: center !important;
        justify-content: flex-start !important;
        padding-top: 10px;
    }

    .modalFoot {
        padding: 14px 16px;
        border-top: 1px solid var(--line);
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
        background: #fff;
    }

    /* =========================
   Toast
========================= */
    .toast {
        position: fixed;
        right: 14px;
        bottom: 14px;
        background: #111;
        color: #fff;
        padding: 10px 12px;
        border-radius: 14px;
        display: none;
        max-width: min(520px, 92vw);
        box-shadow: 0 10px 30px rgba(0, 0, 0, .18);
        z-index: 60;
    }

    /* =========================
   Responsive tweaks
========================= */
    @media (max-width: 720px) {
        .top {
            padding: 12px 12px;
        }

        .sec {
            padding: 10px;
        }

        .tbl th,
        .tbl td {
            padding: 10px 10px;
        }

        .modal {
            width: min(980px, 98vw);
        }

        .modalBody textarea {
            min-height: 180px;
        }
    }
    </style>
</head>

<body>
    <div style="padding:10px 14px; background:#fff; border-bottom:1px solid #e5e7eb">
        <a class="link" href="/super/tenants.php">← 戻る</a>
        <span style="margin-left:10px" class="mono">csrf=<?= hkm_h(substr($csrf, 0, 6)) ?>…</span>
        <?php if ($debug): ?><span style="margin-left:10px" class="mono">debug=1</span><?php endif; ?>
    </div>

    <div class="wrap">
        <div class="card">
            <div class="top">
                <div class="title">🧠 Help KB 管理（tenant/store無し）</div>
                <div class="pill">csrf: <?= hkm_h(substr($csrf, 0, 6)) ?>…</div>
                <?php if ($debug): ?><div class="pill">debug: ON</div><?php endif; ?>
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <label style="display:flex;gap:6px;align-items:center">
                        <input type="checkbox" id="dbg" <?= $debug ? 'checked' : '' ?>> debug
                    </label>
                    <button class="btn btnGray" type="button" onclick="toggleDebug()">反映</button>
                </div>
            </div>

            <div class="sec">
                <div class="grid">
                    <!-- Misses -->
                    <div class="card" style="border-radius:14px">
                        <div class="sec">
                            <h2>① KB未ヒット質問（miss）</h2>
                            <p class="hint">kb_hits=0 の質問が貯まります。ここからKBへ昇格できます。</p>

                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
                                <button class="btn btnPrimary" onclick="reloadMisses()">更新</button>
                                <span class="badge" id="missCount">0件</span>
                            </div>

                            <div style="overflow:auto">
                                <table class="tbl" id="missTbl">
                                    <thead>
                                        <tr>
                                            <th style="width:90px">日時</th>
                                            <th>質問</th>
                                            <th style="width:190px">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                    <!-- KB -->
                    <div class="card" style="border-radius:14px">
                        <div class="sec">
                            <h2>② Help KB（keywords 即編集）</h2>
                            <p class="hint">keywords を編集して Enter/フォーカスアウトで即保存します。</p>

                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
                                <button class="btn btnPrimary" onclick="reloadKb()">更新</button>
                                <button class="btn btnGray" onclick="openNewKb()">新規作成</button>
                                <input id="kbQ" placeholder="検索（title/body/keywords）"
                                    style="flex:1;min-width:220px;padding:10px;border:2px solid #e5e7eb;border-radius:12px">
                                <button class="btn btnGray" onclick="reloadKb()">検索</button>
                                <span class="badge" id="kbCount">0件</span>
                            </div>

                            <div style="overflow:auto">
                                <table class="tbl" id="kbTbl">
                                    <thead>
                                        <tr>
                                            <th style="width:68px">ID</th>
                                            <th style="width:220px">title</th>
                                            <th>body</th>
                                            <th style="width:240px">keywords（即編集）</th>
                                            <th style="width:140px">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="hint" style="margin-top:12px">
                    API: <span class="mono">/super/help_kb_api.php</span>（POST JSON / CSRF 必須）
                </div>
            </div>
        </div>
    </div>

    <!-- モーダル -->
    <div class="modalBg" id="modalBg">
        <div class="modal">
            <div class="modalHead">
                <div style="font-weight:900" id="modalTitle">KB編集</div>
                <button class="btn btnGray" onclick="closeModal()">閉じる</button>
            </div>
            <div class="modalBody">
                <input type="hidden" id="modalMode" value="">
                <input type="hidden" id="modalId" value="0">

                <label>title</label>
                <input id="mTitle" placeholder="例：出勤ボタンはどこ？">

                <label>body</label>
                <textarea id="mBody" rows="8" placeholder="案内文を書いてください（短く・手順で）"></textarea>

                <label>keywords（カンマ区切り推奨）</label>
                <input id="mKeywords" placeholder="例：出勤,打刻,ボタン">

                <label style="display:flex;gap:8px;align-items:center" id="delMissRow">
                    <input type="checkbox" id="mDeleteMiss" checked>
                    昇格元の miss を削除する
                </label>
            </div>
            <div class="modalFoot">
                <button class="btn btnPrimary" onclick="saveModal()">保存</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
    const DEBUG = <?= json_encode($debug ? 1 : 0, JSON_UNESCAPED_UNICODE) ?>;
    const CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;

    function toggleDebug() {
        const on = document.getElementById('dbg').checked ? 1 : 0;
        const u = new URL(location.href);
        if (on) u.searchParams.set('debug', '1');
        else u.searchParams.delete('debug');
        location.href = u.toString();
    }

    function toast(msg, ok = true) {
        const t = document.getElementById('toast');
        t.textContent = String(msg);
        t.style.display = 'block';
        t.style.background = ok ? '#111' : '#b91c1c';
        setTimeout(() => {
            t.style.display = 'none';
        }, 2400);
    }

    async function api(action, payload) {
        const res = await fetch('/super/help_kb_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(Object.assign({
                action,
                debug: DEBUG,
                csrf: CSRF
            }, payload || {}))
        });

        const txt = await res.text();
        let data = null;
        try {
            data = JSON.parse(txt);
        } catch (e) {}

        if (!res.ok || !data || !data.ok) {
            const e1 = (data && data.error) ? String(data.error) : ('HTTP ' + res.status);
            const e2 = (data && data.detail) ? String(data.detail) : (txt ? String(txt).slice(0, 400) : '(empty)');
            throw new Error(DEBUG ? (e1 + ' / detail=' + e2) : e1);
        }
        return data;
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, m => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        } [m]));
    }

    // ---------------- Misses ----------------
    // ✅ 修正場所：<script> 内の reloadMisses() を「丸ごと置き換え」
    // 理由：
    // 今の実装は `<button onclick="openPromote(..., ${JSON.stringify(q)})">` の形なので、
    // 質問文 q に「改行」「ダブルクォート」「特殊文字」が混ざると HTML 属性が壊れて
    // “ボタンが反応しない（クリックしてもJSが実行されない）” が起きます。
    // → onclick文字列をやめて addEventListener で確実に動かします。

    async function reloadMisses() {
        try {
            const r = await api('list_misses', {
                limit: 200
            });
            const rows = r.misses || [];
            document.getElementById('missCount').textContent = rows.length + '件';

            const tb = document.querySelector('#missTbl tbody');
            tb.innerHTML = '';

            rows.forEach(m => {
                const id = Number(m.id || 0);
                const q = String(m.question || '');
                const dt = String(m.created_at || '').slice(5, 16).replace('T', ' ');

                const tr = document.createElement('tr');

                const tdDt = document.createElement('td');
                const span = document.createElement('span');
                span.className = 'mono';
                span.textContent = dt;
                tdDt.appendChild(span);

                const tdQ = document.createElement('td');
                tdQ.textContent = q;

                const tdOp = document.createElement('td');

                const btnAdd = document.createElement('button');
                btnAdd.className = 'btn btnPrimary';
                btnAdd.textContent = 'KBに追加';
                btnAdd.addEventListener('click', () => {
                    // ✅ 文字列をonclick属性に埋め込まないので、特殊文字でも確実に動く
                    openPromote(id, q);
                });

                const btnDel = document.createElement('button');
                btnDel.className = 'btn btnDanger';
                btnDel.textContent = '削除';
                btnDel.style.marginLeft = '8px';
                btnDel.addEventListener('click', () => deleteMiss(id));

                tdOp.appendChild(btnAdd);
                tdOp.appendChild(btnDel);

                tr.appendChild(tdDt);
                tr.appendChild(tdQ);
                tr.appendChild(tdOp);

                tb.appendChild(tr);
            });

        } catch (e) {
            toast('miss取得失敗：' + (e && e.message ? e.message : e), false);
        }
    }

    async function deleteMiss(id) {
        if (!id) return;
        if (!confirm('この miss を削除しますか？')) return;
        try {
            await api('delete_miss', {
                miss_id: id
            });
            toast('削除しました');
            await reloadMisses();
        } catch (e) {
            toast('削除失敗：' + (e && e.message ? e.message : e), false);
        }
    }

    function openPromote(missId, question) {
        document.getElementById('modalMode').value = 'promote';
        document.getElementById('modalId').value = String(missId || 0);
        document.getElementById('modalTitle').textContent = 'KBに追加（missから昇格）';

        document.getElementById('mTitle').value = String(question || '').slice(0, 240);
        document.getElementById('mBody').value = '';
        document.getElementById('mKeywords').value = '';
        document.getElementById('mDeleteMiss').checked = true;

        document.getElementById('delMissRow').style.display = 'flex';
        openModal();
    }

    // ---------------- KB ----------------
    async function reloadKb() {
        try {
            const q = document.getElementById('kbQ').value.trim();
            const r = await api('list_kb', {
                q,
                limit: 200
            });
            const rows = r.kb || [];
            document.getElementById('kbCount').textContent = rows.length + '件';

            const tb = document.querySelector('#kbTbl tbody');
            tb.innerHTML = '';

            rows.forEach(k => {
                const id = Number(k.id || 0);
                const title = String(k.title || '');
                const body = String(k.body || '');
                const kw = String(k.keywords || '');

                const tr = document.createElement('tr');

                // ✅ 重要：onclick + JSON.stringify を使わない（特殊文字で壊れる）
                // ✅ 代わりに addEventListener で確実に動くようにする
                tr.innerHTML = `
                    <td class="mono">${id}</td>
                    <td class="kbTitleCell">${esc(title)}</td>
                    <td><div class="kbBodyCell">${esc(body).slice(0,360)}${body.length>360?'…':''}</div></td>
                    <td>
                        <input class="kwInput" data-kbid="${id}" value="${esc(kw)}"
                            placeholder="例：出勤,打刻,ボタン"
                            onkeydown="kwKeydown(event)"
                            onblur="saveKeywords(this)">
                            <div style="margin-top:6px;">
    <button
        type="button"
        class="btn btnGray"
        style="font-size:12px;padding:6px 10px"
        onclick="copyKbPrompt(this)">
        🤖 ChatGPT用プロンプトをコピー
    </button>
</div>
                    </td>
                    <td class="kbOpsCell">
                        <button class="btn btnGray btnEdit">編集</button>
                        <button class="btn btnDanger btnDel" style="margin-left:8px;">削除</button>
                    </td>
                `;

                // ✅ 編集
                tr.querySelector('.btnEdit').addEventListener('click', () => {
                    openEditKb(id, title, body, kw);
                });

                // ✅ 削除（確認ポップアップ）
                tr.querySelector('.btnDel').addEventListener('click', async () => {
                    if (!confirm('このKBを削除しますか？\n（削除すると元に戻せません）')) return;
                    await deleteKb(id);
                });

                tb.appendChild(tr);
            });

        } catch (e) {
            toast('KB取得失敗：' + (e && e.message ? e.message : e), false);
        }
    }

    // ✅ 追加：KB削除（APIの action=delete_kb を呼ぶ）
    // ※ unknown_action が出る場合は /super/help_kb_api.php に delete_kb が未実装です
    async function deleteKb(id) {
        if (!id) return;
        try {
            await api('delete_kb', {
                kb_id: id
            });
            toast('KBを削除しました');
            await reloadKb();
        } catch (e) {
            toast('KB削除失敗：' + (e && e.message ? e.message : e), false);
        }
    }

    function kwKeydown(ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            saveKeywords(ev.target);
        }
    }


    // ✅ 修正場所：saveKeywords() を「丸ごと置き換え」
    // 目的：保存直前に必ず正規化し、入力欄も正規化結果に上書き（＝勝手に崩れない）
    async function saveKeywords(inputEl) {
        const id = Number(inputEl.getAttribute('data-kbid') || 0);
        if (!id) return;

        // ✅ 正規化してから保存
        const normalized = normalizeKeywords(String(inputEl.value || ''));

        // ✅ 画面側も正規化結果に揃える（“上書きされてしまう”体感をなくす）
        inputEl.value = normalized;

        try {
            await api('update_kb_keywords', {
                kb_id: id,
                keywords: normalized
            });
            toast('keywords 保存OK');
        } catch (e) {
            toast('keywords 保存失敗：' + (e && e.message ? e.message : e), false);
        }
    }

    function openNewKb() {
        document.getElementById('modalMode').value = 'new';
        document.getElementById('modalId').value = '0';
        document.getElementById('modalTitle').textContent = 'KB 新規作成';

        document.getElementById('mTitle').value = '';
        document.getElementById('mBody').value = '';
        document.getElementById('mKeywords').value = '';
        document.getElementById('mDeleteMiss').checked = false;

        document.getElementById('delMissRow').style.display = 'none';
        openModal();
    }

    function openEditKb(id, title, body, kw) {
        document.getElementById('modalMode').value = 'edit';
        document.getElementById('modalId').value = String(id || 0);
        document.getElementById('modalTitle').textContent = 'KB 編集';

        document.getElementById('mTitle').value = String(title || '');
        document.getElementById('mBody').value = String(body || '');
        document.getElementById('mKeywords').value = String(kw || '');
        document.getElementById('mDeleteMiss').checked = false;

        document.getElementById('delMissRow').style.display = 'none';
        openModal();
    }

    // ---------------- Modal ----------------
    function openModal() {
        document.getElementById('modalBg').style.display = 'flex';
        setTimeout(() => {
            try {
                document.getElementById('mTitle').focus();
            } catch (e) {}
        }, 50);
    }

    function closeModal() {
        document.getElementById('modalBg').style.display = 'none';
    }

    async function saveModal() {
        const mode = document.getElementById('modalMode').value;
        const idVal = Number(document.getElementById('modalId').value || 0);

        const title = document.getElementById('mTitle').value.trim();
        const body = document.getElementById('mBody').value.trim();
        const kw = document.getElementById('mKeywords').value.trim();
        const delMiss = document.getElementById('mDeleteMiss').checked;

        if (!title) {
            toast('title は必須です', false);
            return;
        }

        try {
            if (mode === 'promote') {
                await api('promote_miss_to_kb', {
                    miss_id: idVal,
                    title,
                    body,
                    keywords: kw,
                    delete_miss: delMiss ? 1 : 0
                });
                toast('KBに追加しました');
            } else if (mode === 'new') {
                await api('create_kb', {
                    title,
                    body,
                    keywords: kw
                });
                toast('KBを作成しました');
            } else if (mode === 'edit') {
                await api('update_kb', {
                    kb_id: idVal,
                    title,
                    body,
                    keywords: kw
                });
                toast('KBを更新しました');
            } else {
                throw new Error('unknown_modal_mode');
            }

            closeModal();
            await reloadMisses();
            await reloadKb();
        } catch (e) {
            toast('保存失敗：' + (e && e.message ? e.message : e), false);
        }
    }

    // 初期ロード
    (async () => {
        await reloadMisses();
        await reloadKb();
    })();

    // ✅ 修正場所：<script> 内に「正規化関数」を追加（toast関数の下あたりに追加してください）
    // 目的：
    // - 「、」「，」「全角スペース」「改行」などを “カンマ区切り” に統一
    // - 「カンマの前後の空白」を除去
    // - 連続カンマを潰す
    function normalizeKeywords(raw) {
        let s = String(raw || '');

        // 全角/和文の区切りを半角カンマへ
        s = s.replace(/[、，]/g, ',');

        // 改行/タブ/全角スペース/連続スペース → カンマに寄せる（区切りとして扱う）
        s = s.replace(/[\r\n\t　]+/g, ' ');
        s = s.replace(/\s+/g, ' ');

        // 「スペース区切り」を「カンマ区切り」に統一したい場合は↓を有効化
        // （スペースを区切り扱いにする）
        s = s.replace(/ /g, ',');

        // カンマ前後の空白を除去
        s = s.replace(/\s*,\s*/g, ',');

        // 連続カンマ/先頭末尾カンマを整理
        s = s.replace(/,+/g, ',');
        s = s.replace(/^,|,$/g, '');

        return s;
    }

    function copyKbPrompt(btn) {
        // ボタン → 行 → title / body を取得
        const tr = btn.closest('tr');
        if (!tr) return;

        const titleCell = tr.querySelector('td:nth-child(2)');
        const bodyCell = tr.querySelector('td:nth-child(3)');

        const title = titleCell ? titleCell.innerText.trim() : '';
        const body = bodyCell ? bodyCell.innerText.trim() : '';

        // ✅ ここを「丸ごと置き換え」してください（const prompt の中身だけ）
        // ✅ ここを「丸ごと置き換え」してください（const prompt の中身だけ）
        const prompt = `あなたは勤怠管理SaaSの「Help KB全文日本語吸い上げAI」です。

目的：
ユーザーがヘルプページのチャットに自然文で質問したとき、
またはAIが文章で回答したときに含まれる「あらゆる日本語表現」が
検索インデックス（LIKE検索）で認識されるように、
検索用キーワード（カンマ区切り）を生成してください。

前提（厳守）：
・これはFAQ文ではなく「検索インデックス用キーワード」です
・日本語のみで出力してください
・説明文・前置き・補足は一切出力しないでください
・必ず【カンマ区切り】で出力してください
・必ず【1行】で出力してください
・長くなってもOK（省略禁止）

生成ルール（必須・省略不可）：
【A】Title/Bodyに含まれる日本語を「単語」「短文」「質問形」「回答文フレーズ」まで分解して含める
【B】各概念について必ずセットで含める：
  - 正式名称
  - 口語・省略・言い換え
  - 語順違い（例：出し方,出す方法,見方,確認方法）
  - 質問形（例：〜のやり方,〜方法,〜どこ,〜できない,〜出てこない）
  - できない/迷い/エラー系（表示されない,見つからない,出てこない,反映されない,できない）
【C】管理者視点・従業員視点の両方を必ず含める
【D】UI文言（画面名,ボタン名,メニュー名）を優先し、表示語をそのまま含める
【E】操作動詞と最終ゴールを必ず含める（ログイン,選択,クリック,表示,ダウンロード,登録,設定,修正,削除,印刷,PDF,送信,確認）
【F】AIが回答で使いがちな文章表現も必ず含める：
  - 以下の通り,手順,〜してください,〜を選択,〜をクリック,〜の場合,わからない場合,管理者に問い合わせ,管理者に確認
【G】助詞あり/なし両方を混ぜてよい（検索ヒット最優先）
【H】長文化OK・省略禁止（ただし出力はカンマ区切り1行のみ）

入力形式：
Title:
${title}

Body:
${body}

出力形式：
カンマ区切りのキーワードのみ（1行）
`;

        navigator.clipboard.writeText(prompt).then(() => {
            toast('ChatGPT用プロンプトをコピーしました');
        }).catch(() => {
            toast('コピーに失敗しました', false);
        });
    }
    </script>

</body>

</html>