<?php
declare(strict_types=1);

/**
 * ✅ /admin/help_kb_manage.php（Help KB 管理：miss一覧 + KB一覧 + keywords即編集）
 *
 * できること：
 * - help_ai_misses（KB未ヒット質問）の一覧表示・削除・KBへ昇格（ワンクリック）
 * - help_kb の一覧表示・keywords 即編集（blur/EnterでAJAX保存）
 * - KBの新規作成（簡易）
 *
 * 前提：
 * - /admin/help_kb_api.php が存在（この後のファイル）
 * - _auth.php / _tenant_context.php / _db.php が既存にある前提
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
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

date_default_timezone_set('Asia/Tokyo');
session_start();

// CSRF（この画面専用。既存CSRFと衝突しないキーにする）
if (empty($_SESSION['help_kb_csrf'])) {
    $_SESSION['help_kb_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['help_kb_csrf'];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// 店舗一覧（tenant_idで絞る：既存 stores がある前提）
$st = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:tid ORDER BY id ASC");
$st->execute([':tid' => $tenantId]);
$stores = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$stores) {
    http_response_code(400);
    echo 'stores not found';
    exit;
}

$storeId = (int)($_GET['store_id'] ?? 0);
$validStoreIds = array_map(fn($r) => (int)$r['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $validStoreIds, true)) {
    $storeId = (int)$stores[0]['id'];
}

$debug = (((string)($_GET['debug'] ?? '')) === '1');

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Help KB 管理</title>
    <style>
    body {
        font-family: system-ui;
        margin: 0;
        background: #f7f7fb;
        color: #111
    }

    .wrap {
        max-width: 1180px;
        margin: 0 auto;
        padding: 14px
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, .04)
    }

    .top {
        padding: 14px 16px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap
    }

    .title {
        font-size: 18px;
        font-weight: 900
    }

    .pill {
        padding: 6px 10px;
        border: 1px solid #e5e7eb;
        border-radius: 999px;
        background: #fff;
        font-weight: 900;
        font-size: 12px
    }

    .row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap
    }

    select,
    input,
    textarea {
        font-size: 14px
    }

    .btn {
        padding: 10px 12px;
        border: 0;
        border-radius: 12px;
        font-weight: 900;
        cursor: pointer
    }

    .btnPrimary {
        background: #365EAB;
        color: #fff
    }

    .btnGray {
        background: #e5e7eb;
        color: #111
    }

    .btnDanger {
        background: #ef4444;
        color: #fff
    }

    .grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px
    }

    @media(max-width:980px) {
        .grid {
            grid-template-columns: 1fr
        }
    }

    .sec {
        padding: 12px
    }

    .sec h2 {
        margin: 0 0 10px;
        font-size: 16px
    }

    .hint {
        color: #6b7280;
        font-size: 13px;
        margin: 0 0 10px
    }

    .tbl {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px
    }

    .tbl th,
    .tbl td {
        border-bottom: 1px solid #eef2f7;
        padding: 10px;
        vertical-align: top
    }

    .tbl th {
        background: #fafafa;
        text-align: left;
        font-size: 12px;
        color: #374151
    }

    .kwInput {
        width: 100%;
        padding: 8px;
        border: 2px solid #e5e7eb;
        border-radius: 12px
    }

    .mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 12px
    }

    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        background: #fff;
        font-size: 12px;
        font-weight: 900
    }

    .ok {
        background: #ecfdf5;
        border-color: #bbf7d0
    }

    .ng {
        background: #fef2f2;
        border-color: #fecaca
    }

    .modalBg {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .35);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 14px
    }

    .modal {
        width: min(760px, 100%);
        background: #fff;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 10px 40px rgba(0, 0, 0, .2)
    }

    .modalHead {
        padding: 12px 14px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center
    }

    .modalBody {
        padding: 12px 14px
    }

    .modalBody label {
        display: block;
        font-size: 12px;
        color: #6b7280;
        margin: 10px 0 4px
    }

    .modalBody input,
    .modalBody textarea {
        width: 100%;
        padding: 10px;
        border: 2px solid #e5e7eb;
        border-radius: 12px
    }

    .modalFoot {
        padding: 12px 14px;
        border-top: 1px solid #e5e7eb;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap
    }

    .toast {
        position: fixed;
        right: 14px;
        bottom: 14px;
        background: #111;
        color: #fff;
        padding: 10px 12px;
        border-radius: 12px;
        display: none;
        max-width: min(520px, 92vw)
    }
    </style>
</head>

<body>
    <?php require __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card">
            <div class="top">
                <div class="title">🧠 Help KB 管理</div>
                <div class="pill">tenant_id: <?= (int)$tenantId ?></div>
                <div class="pill">csrf: <?= h(substr($csrf,0,6)) ?>…</div>
                <?php if ($debug): ?><div class="pill">debug: ON</div><?php endif; ?>

                <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto">
                    <span class="pill">store</span>
                    <select name="store_id" style="padding:8px;border:1px solid #e5e7eb;border-radius:12px">
                        <?php foreach ($stores as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId?'selected':'') ?>>
                            <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <label style="display:flex;gap:6px;align-items:center">
                        <input type="checkbox" name="debug" value="1" <?= $debug?'checked':'' ?>> debug
                    </label>
                    <button class="btn btnGray" type="submit">切替</button>
                </form>
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
                    API: <span class="mono">/admin/help_kb_api.php</span>（POST JSON / CSRF 必須）
                </div>
            </div>
        </div>
    </div>

    <!-- 昇格/新規作成モーダル（共用） -->
    <div class="modalBg" id="modalBg">
        <div class="modal">
            <div class="modalHead">
                <div style="font-weight:900" id="modalTitle">KB編集</div>
                <button class="btn btnGray" onclick="closeModal()">閉じる</button>
            </div>
            <div class="modalBody">
                <input type="hidden" id="modalMode" value="">
                <input type="hidden" id="modalMissId" value="0">

                <label>title</label>
                <input id="mTitle" placeholder="例：出勤ボタンはどこ？">

                <label>body</label>
                <textarea id="mBody" rows="8" placeholder="案内文を書いてください（短く・手順で）"></textarea>

                <label>keywords（カンマ区切り推奨）</label>
                <input id="mKeywords" placeholder="例：出勤,打刻,ボタン">

                <label style="display:flex;gap:8px;align-items:center">
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
    const STORE_ID = <?= json_encode((int)$storeId, JSON_UNESCAPED_UNICODE) ?>;
    const DEBUG = <?= json_encode($debug ? 1 : 0, JSON_UNESCAPED_UNICODE) ?>;
    const CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;

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
        const res = await fetch('/admin/help_kb_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(Object.assign({
                action,
                store_id: STORE_ID,
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
                const tr = document.createElement('tr');
                const dt = String(m.created_at || '').slice(5, 16).replace('T', ' ');
                const q = String(m.question || '');

                tr.innerHTML = `
          <td><span class="mono">${esc(dt)}</span></td>
          <td>${esc(q)}</td>
          <td>
            <button class="btn btnPrimary" onclick="openPromote(${Number(m.id||0)}, ${JSON.stringify(q)})">KBに追加</button>
            <button class="btn btnDanger" onclick="deleteMiss(${Number(m.id||0)})">削除</button>
          </td>
        `;
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
        document.getElementById('modalMissId').value = String(missId || 0);
        document.getElementById('modalTitle').textContent = 'KBに追加（missから昇格）';

        // 初期値：title=質問、keywordsは空（人が付ける）
        document.getElementById('mTitle').value = String(question || '').slice(0, 240);
        document.getElementById('mBody').value = '';
        document.getElementById('mKeywords').value = '';
        document.getElementById('mDeleteMiss').checked = true;

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
                tr.innerHTML = `
          <td class="mono">${id}</td>
          <td>${esc(title)}</td>
          <td>${esc(body).slice(0,360)}${body.length>360?'…':''}</td>
          <td>
            <input class="kwInput" data-kbid="${id}" value="${esc(kw)}"
              placeholder="例：出勤,打刻,ボタン"
              onkeydown="kwKeydown(event)"
              onblur="saveKeywords(this)">
          </td>
          <td>
            <button class="btn btnGray" onclick="openEditKb(${id}, ${JSON.stringify(title)}, ${JSON.stringify(body)}, ${JSON.stringify(kw)})">編集</button>
          </td>
        `;
                tb.appendChild(tr);
            });

        } catch (e) {
            toast('KB取得失敗：' + (e && e.message ? e.message : e), false);
        }
    }

    function kwKeydown(ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            saveKeywords(ev.target);
        }
    }

    async function saveKeywords(inputEl) {
        const id = Number(inputEl.getAttribute('data-kbid') || 0);
        if (!id) return;
        const kw = String(inputEl.value || '').trim();

        try {
            await api('update_kb_keywords', {
                kb_id: id,
                keywords: kw
            });
            toast('keywords 保存OK');
        } catch (e) {
            toast('keywords 保存失敗：' + (e && e.message ? e.message : e), false);
        }
    }

    function openNewKb() {
        document.getElementById('modalMode').value = 'new';
        document.getElementById('modalMissId').value = '0';
        document.getElementById('modalTitle').textContent = 'KB 新規作成';

        document.getElementById('mTitle').value = '';
        document.getElementById('mBody').value = '';
        document.getElementById('mKeywords').value = '';
        document.getElementById('mDeleteMiss').checked = false;

        openModal();
    }

    function openEditKb(id, title, body, kw) {
        document.getElementById('modalMode').value = 'edit';
        document.getElementById('modalMissId').value = String(id || 0); // edit時は kb_id をここに入れる
        document.getElementById('modalTitle').textContent = 'KB 編集';

        document.getElementById('mTitle').value = String(title || '');
        document.getElementById('mBody').value = String(body || '');
        document.getElementById('mKeywords').value = String(kw || '');
        document.getElementById('mDeleteMiss').checked = false;

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
        const idVal = Number(document.getElementById('modalMissId').value || 0);

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
    </script>
</body>

</html>
