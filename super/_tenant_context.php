<?php

declare(strict_types=1);

/**
 * ✅ /super/help_kb_manage.php（tenants.php に飛ぶのを「確実に」止める版）
 *
 * 【結論】
 * tenants.php に飛ばしている犯人は、ほぼ確実に
 * - /super/_tenant_context.php もしくは
 * - そこから呼ばれる共通ヘッダ類
 * です。
 *
 * なのでこの画面では「_tenant_context.php を一切読まない」構成にします。
 * tenant_id は store_id からDBで逆引きして確定します。
 *
 * ✅ このファイルは「丸ごと置き換え」
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

$debug = (((string)($_GET['debug'] ?? '')) === '1');

// --- ログ
$__logDir = __DIR__ . '/logs';
@mkdir($__logDir, 0777, true);
$__logFile = $__logDir . '/help_kb_manage.log';

function hkm_log(string $msg): void
{
    global $__logFile;
    @file_put_contents($__logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

set_exception_handler(function (Throwable $e) use ($debug) {
    hkm_log('[EXCEPTION] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "server_error\n";
        echo $e->getMessage() . "\n";
        echo $e->getFile() . ":" . $e->getLine() . "\n";
        exit;
    }
    echo 'server_error';
    exit;
});

register_shutdown_function(function () use ($debug) {
    $e = error_get_last();
    if (!$e) return;

    $fatal = in_array((int)($e['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    if (!$fatal) return;

    hkm_log('[FATAL] ' . ($e['message'] ?? '') . ' @ ' . ($e['file'] ?? '') . ':' . ($e['line'] ?? 0));

    http_response_code(500);
    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "fatal_error\n";
        echo ($e['message'] ?? '') . "\n";
        echo ($e['file'] ?? '') . ':' . ($e['line'] ?? 0) . "\n";
        exit;
    }
    echo 'server_error';
});

// --- auth（super）
require_once __DIR__ . '/_auth.php';
require_super_admin_login();
super_session_bootstrap();

// --- DB（tenant_context を読む前にDBを使えるようにする）
require_once __DIR__ . '/_db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'db_not_ready';
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// --- この画面専用CSRF
if (empty($_SESSION['help_kb_csrf'])) {
    $_SESSION['help_kb_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['help_kb_csrf'];

function hkm_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * ✅ tenant_id を「store_id から逆引きして確定」する
 * - これで _tenant_context.php が不要になり、tenants.php への強制リダイレクトが発生しない
 */
$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0) {
    // store_id が無いと tenant を確定できないので、ここだけは明示的に案内
    http_response_code(400);
    echo 'store_id_missing';
    exit;
}

$st = $pdo->prepare("SELECT id, tenant_id, name FROM stores WHERE id=:sid LIMIT 1");
$st->execute([':sid' => $storeId]);
$storeRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$storeRow) {
    http_response_code(404);
    echo 'store_not_found';
    exit;
}
$tenantId = (int)$storeRow['tenant_id'];

// tenant内の店舗一覧（プルダウン用）
$st = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:tid ORDER BY id ASC");
$st->execute([':tid' => $tenantId]);
$stores = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$stores) {
    http_response_code(400);
    echo 'stores_not_found';
    exit;
}

// store_id が tenant 外だったら（普通は起きないが）先頭に寄せる
$validStoreIds = array_map(static fn($r) => (int)$r['id'], $stores);
if (!in_array($storeId, $validStoreIds, true)) {
    $storeId = (int)$stores[0]['id'];
}

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

    .btn {
        padding: 10px 12px;
        border: 0;
        border-radius: 12px;
        font-weight: 900;
        cursor: pointer
    }

    .btnPrimary {
        background: #2563eb;
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

    a.link {
        color: #2563eb;
        text-decoration: none;
        font-weight: 900
    }
    </style>
</head>

<body>
    <!-- ✅ tenants.php へ勝手に飛ぶ共通ヘッダを排除。最小ナビだけ -->
    <div style="padding:10px 14px; background:#fff; border-bottom:1px solid #e5e7eb">
        <a class="link" href="/super/tenants.php">← tenants</a>
        <span style="margin-left:10px" class="mono">tenant_id=<?= (int)$tenantId ?></span>
        <span style="margin-left:10px" class="mono">store_id=<?= (int)$storeId ?></span>
        <?php if ($debug): ?><span style="margin-left:10px" class="mono">debug=1</span><?php endif; ?>
    </div>

    <div class="wrap">
        <div class="card">
            <div class="top">
                <div class="title">🧠 Help KB 管理</div>
                <div class="pill">tenant_id: <?= (int)$tenantId ?></div>
                <div class="pill">csrf: <?= hkm_h(substr($csrf, 0, 6)) ?>…</div>

                <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto">
                    <span class="pill">store</span>
                    <select name="store_id" style="padding:8px;border:1px solid #e5e7eb;border-radius:12px">
                        <?php foreach ($stores as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $storeId ? 'selected' : '') ?>>
                            <?= hkm_h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <label style="display:flex;gap:6px;align-items:center">
                        <input type="checkbox" name="debug" value="1" <?= $debug ? 'checked' : '' ?>> debug
                    </label>
                    <button class="btn btnGray" type="submit">切替</button>
                </form>
            </div>

            <div class="sec">
                <div class="grid">
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
        const res = await fetch('/super/help_kb_api.php', {
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
        document.getElementById('mTitle').value = String(question || '').slice(0, 240);
        document.getElementById('mBody').value = '';
        document.getElementById('mKeywords').value = '';
        document.getElementById('mDeleteMiss').checked = true;
        openModal();
    }

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
        document.getElementById('modalMissId').value = String(id || 0);
        document.getElementById('modalTitle').textContent = 'KB 編集';
        document.getElementById('mTitle').value = String(title || '');
        document.getElementById('mBody').value = String(body || '');
        document.getElementById('mKeywords').value = String(kw || '');
        document.getElementById('mDeleteMiss').checked = false;
        openModal();
    }

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

    (async () => {
        await reloadMisses();
        await reloadKb();
    })();
    </script>
</body>

</html>
