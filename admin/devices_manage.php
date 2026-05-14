<?php

/**
 * ✅ ファイル名: /admin/devices_manage.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 今回の修正（あなたの症状の原因と対策）
 * - 原因: この画面だけ「store_id をセッションに保存していない」ため
 *         → 他画面で $_SESSION['store_id'] が本店(1)に戻されると、この画面の初期値も本店に戻る
 * - 対策:
 *   1) GET/POST で受け取った store_id を「tenant内で妥当性チェック」
 *   2) 妥当なら必ず $_SESSION['store_id'] を更新（この画面に来た時点で選択店舗を確定）
 *   3) 不正なら tenant の最初の店舗へフォールバック
 *
 * 既存維持:
 * - 端末一覧の名称: device_name
 * - 無効化/有効化（status更新）
 * - CSRF
 * - tenant_id で必ず縛る
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

date_default_timezone_set('Asia/Tokyo');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function isValidCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
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
if (!$dbFile) {
    http_response_code(500);
    exit('db.php not found');
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ===== CSRF（この画面のPOST操作用）=====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

// ===== flash =====
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ===== 店舗一覧（先に取得して store_id 妥当性チェックに使う）=====
$stores = [];
try {
    $st = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:t ORDER BY id ASC");
    $st->execute([':t' => $tenantId]);
    $stores = $st->fetchAll();
} catch (Throwable $e) {
    $stores = [];
}

// tenant内の店舗ID一覧
$storeIdSet = [];
foreach ($stores as $s) {
    $storeIdSet[(int)$s['id']] = true;
}

// ===== store_id（選択中の店舗）=====
// 優先順位: POST > GET > SESSION > tenantの先頭 > 1
$postStoreId = (int)($_POST['store_id'] ?? 0);
$getStoreId  = (int)($_GET['store_id'] ?? 0);
$sesStoreId  = (int)($_SESSION['store_id'] ?? 0);

$storeId = 0;
if ($postStoreId > 0) $storeId = $postStoreId;
elseif ($getStoreId > 0) $storeId = $getStoreId;
elseif ($sesStoreId > 0) $storeId = $sesStoreId;

// 妥当性チェック（tenant内に存在しない store_id は弾く）
if ($storeId <= 0 || !isset($storeIdSet[$storeId])) {
    // tenantの先頭店舗へフォールバック
    $storeId = (int)($stores[0]['id'] ?? 0);
    if ($storeId <= 0) $storeId = 1; // 最終保険
}

// ✅ ここが肝：この画面に来たら「選択店舗」を必ずセッションに確定させる
$_SESSION['store_id'] = $storeId;

/**
 * ✅ POST: 端末の無効化/有効化
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'CSRFが無効です。画面を更新してから再実行してください。'];
        header('Location: /admin/devices_manage.php?store_id=' . (int)$storeId);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $deviceId = (int)($_POST['device_id'] ?? 0);

    if ($deviceId <= 0 || !in_array($action, ['deactivate', 'activate'], true)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '不正な操作です'];
        header('Location: /admin/devices_manage.php?store_id=' . (int)$storeId);
        exit;
    }

    $newStatus = ($action === 'deactivate') ? 'inactive' : 'active';

    try {
        // ✅ tenant_id で縛る（他テナントの端末を絶対に触らない）
        $st = $pdo->prepare("
            UPDATE devices
            SET status = :status
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $st->execute([
            ':status' => $newStatus,
            ':id' => $deviceId,
            ':tenant_id' => $tenantId,
        ]);

        if ($st->rowCount() > 0) {
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => ($newStatus === 'inactive') ? '端末を無効化しました（過去勤怠は残ります）' : '端末を有効化しました',
            ];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => '更新対象が見つかりません（tenant不一致の可能性）'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '更新に失敗しました: ' . $e->getMessage()];
    }

    header('Location: /admin/devices_manage.php?store_id=' . (int)$storeId);
    exit;
}

// ===== devices一覧 =====
$devices = [];
$deviceErr = '';
try {
    $st = $pdo->prepare("
        SELECT id, dev_key, tenant_id, store_id, device_name, device_key_hash, status, created_at, updated_at
        FROM devices
        WHERE tenant_id = :t AND store_id = :s
        ORDER BY updated_at DESC, id DESC
        LIMIT 300
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId]);
    $devices = $st->fetchAll();
} catch (Throwable $e) {
    $deviceErr = $e->getMessage();
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>端末管理（iPad）</title>
    <style>
    body {
        margin: 0;
        font-family: system-ui;
        background: #fff;
        color: #111;
    }

    .wrap {
        max-width: none;
        margin: 0;
        padding: 14px;
        padding-bottom: 64px;
    }

    .tabsBar {
        box-sizing: border-box;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        padding: 0;
        margin-bottom: 16px;
        background: transparent;
        border: none;
        overflow: visible;
    }

    .tabBtn {
        box-sizing: border-box;
        appearance: none;
        min-height: 44px;
        border: 1px solid #d0d7de;
        border-radius: 999px;
        padding: 0 18px;
        min-width: 132px;
        background: #fff;
        color: #0f172a;
        font-family: system-ui, -apple-system, sans-serif;
        font-size: 13px;
        font-weight: 900;
        line-height: 1;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        white-space: nowrap;
        cursor: pointer;
        transition: background .18s ease, color .18s ease, border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }

    .tabBtn.isActive {
        background: linear-gradient(135deg, #365EAB, #4b74c2);
        color: #fff;
        border-color: rgba(54, 94, 171, .32);
        box-shadow: 0 10px 24px rgba(54, 94, 171, .18);
    }

    .tabBtn:focus {
        outline: 2px solid rgba(111, 137, 155, .35);
        outline-offset: 2px;
    }

    .card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 16px;
        padding: 18px;
    }

    h1 {
        margin: 0 0 10px;
        font-size: 20px;
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 14px;
    }

    select {
        padding: 10px 12px;
        border: 1px solid #ccc;
        border-radius: 10px;
        background: #fff;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid #000;
        background: #000;
        color: #fff;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        line-height: 1;
    }

    .btnOutline {
        background: #fff;
        color: #000;
    }

    .btnRowFixed {
        height: 40px;
        padding: 0 12px;
        font-size: 13px;
        line-height: 1;
        min-height: 40px;
        box-sizing: border-box;
        appearance: none;
        -webkit-appearance: none;
    }

    .btnSm {
        padding: 8px 10px;
        font-size: 12px;
        border-radius: 10px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th,
    td {
        border-bottom: 1px solid #eee;
        padding: 10px 8px;
        font-size: 13px;
        text-align: left;
        vertical-align: top;
    }

    th {
        color: #666;
        font-weight: 700;
    }

    .muted {
        color: #666;
        font-size: 12px;
    }

    .pill {
        display: inline-block;
        padding: 3px 8px;
        border: 1px solid #ddd;
        border-radius: 999px;
        font-size: 12px;
        background: #fafafa;
    }

    .flash {
        margin: 0 0 12px;
        padding: 10px 12px;
        border: 1px solid #ddd;
        background: #fafafa;
        border-radius: 10px;
        font-size: 13px;
    }

    .flash.success {
        border-color: #b7e3c2;
        background: #f0fff4;
    }

    .flash.error {
        border-color: #f1b2b2;
        background: #fff5f5;
    }

    .opCell form {
        display: inline;
    }

    .opCell .btn {
        padding: 8px 10px;
        font-size: 12px;
        border-radius: 10px;
    }

    @media (max-width: 860px) {
        .tabBtn {
            min-width: 120px;
            min-height: 40px;
            padding: 0 14px;
        }
    }
    </style>
</head>

<body>
    <?php @include __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="tabsBar" role="tablist" aria-label="設定">
            <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#list">従業員設定</a>
            <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#add">従業員追加</a>
            <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#payroll">店舗設定</a>
            <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#store">店舗追加</a>
            <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#labor">人件費率設定</a>
            <a class="tabBtn" href="/admin/expenses.php?store_id=<?= (int)$storeId ?>">経費</a>
            <a class="tabBtn isActive" href="/admin/devices_manage.php?store_id=<?= (int)$storeId ?>">端末管理</a>
            <a class="tabBtn" href="/admin/color_settings.php?store_id=<?= (int)$storeId ?>">色変更</a>
        </div>

        <div class="card">
            <h1>端末管理（iPad）</h1>

            <?php if ($flash): ?>
            <div class="flash <?= h((string)($flash['type'] ?? '')) ?>">
                <?= h((string)($flash['message'] ?? '')) ?>
            </div>
            <?php endif; ?>

            <?php if ($deviceErr): ?>
            <div class="flash error">devices 取得エラー: <?= h($deviceErr) ?></div>
            <?php endif; ?>

            <div class="row">
                <form method="get" action="/admin/devices_manage.php"
                    style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <label class="muted">店舗</label>
                    <select name="store_id" onchange="this.form.submit()">
                        <?php foreach ($stores as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $storeId ? 'selected' : '') ?>>
                            <?= h((string)$s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <a class="btn btnRowFixed"
                    href="/admin/device_activation_qr.php?tenant_id=<?= (int)$tenantId ?>&store_id=<?= (int)$storeId ?>">
                    新しいiPadを追加（QR）
                </a>
                <button class="btn btnOutline btnRowFixed" type="button" id="download-app-btn">
                    打刻アプリをダウンロード
                </button>
                <a class="btn btnOutline btnRowFixed" href="/admin/clock_qr_print.php?store_id=<?= (int)$storeId ?>" target="_blank" rel="noopener">
                    出退勤QRを印刷
                </a>
            </div>
            <div class="muted" style="margin-top:6px;">
                このURLから打刻アプリをインストールしてください。
            </div>

            <!-- <div class="muted" style="margin-bottom:10px;">
                ※端末は削除ではなく「無効化」します（過去の勤怠データが devices.id に紐づくため）。
            </div> -->

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>名称</th>
                        <th>状態</th>
                        <th>作成</th>
                        <th>更新</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$devices): ?>
                    <tr>
                        <td colspan="6" class="muted">この店舗にはまだ端末が登録されていません。</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($devices as $d): ?>
                    <tr>
                        <td><?= (int)$d['id'] ?></td>
                        <td><?= h((string)($d['device_name'] ?? '')) ?></td>
                        <td><span class="pill"><?= h((string)($d['status'] ?? '')) ?></span></td>
                        <td><?= h((string)($d['created_at'] ?? '')) ?></td>
                        <td><?= h((string)($d['updated_at'] ?? '')) ?></td>
                        <td class="opCell">
                            <form method="post" action="/admin/devices_manage.php?store_id=<?= (int)$storeId ?>">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                                <!-- ✅ store_id をPOSTにも持たせて、常に同じ店舗で戻る -->
                                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">

                                <?php if ((string)($d['status'] ?? '') === 'active'): ?>
                                <input type="hidden" name="action" value="deactivate">
                                <button class="btn" type="submit" onclick="return confirm('この端末を無効化しますか？（過去勤怠は消えません）')">
                                    無効化
                                </button>
                                <?php else: ?>
                                <input type="hidden" name="action" value="activate">
                                <button class="btn btnOutline" type="submit">
                                    有効化
                                </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        </div>
    </div>
    <script>
    (function() {
        var btn = document.getElementById('download-app-btn');
        if (!btn) return;
        var url = 'https://apps.apple.com/us/app/%E3%80%86%E3%83%8A%E3%83%93%E5%8B%A4%E6%80%A0/id6758080712';
        btn.addEventListener('click', function() {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).catch(function() {});
            }
            window.prompt('URLをコピーしてください。', url);
        });
    })();
    </script>
</body>

</html>
