<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';

if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;
$adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);
if ($adminUserId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

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
require_once __DIR__ . '/../lib/admin_theme.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$storesStmt = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id = :t ORDER BY id ASC");
$storesStmt->execute([':t' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (!$stores) {
    http_response_code(400);
    exit('stores not found');
}
$storeId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
$storeIds = array_map(static fn($s): int => (int)$s['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) {
    $storeId = (int)$stores[0]['id'];
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

$errors = [];
$success = '';
$modes = admin_theme_modes();
$accents = admin_theme_accents();
$theme = admin_theme_current($pdo, $adminUserId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $errors[] = 'CSRFトークンが不正です。';
    } else {
        $mode = admin_theme_normalize_mode((string)($_POST['theme_mode'] ?? 'dark'));
        $accent = admin_theme_normalize_accent((string)($_POST['theme_accent'] ?? 'purple'));
        admin_theme_set_pref($pdo, $adminUserId, 'ui_theme_mode', $mode);
        admin_theme_set_pref($pdo, $adminUserId, 'ui_theme_accent', $accent);
        $theme = ['mode' => $mode, 'accent' => $accent];
        $success = '色設定を保存しました。';
    }
}

$bodyClass = admin_theme_body_class($theme);

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>色変更</title>
    <style>
    :root {
        --bg: #f8fafc;
        --card: #fff;
        --text: #111827;
        --muted: #64748b;
        --line: #e5e7eb;
        --accent: #7c3aed;
        --accent2: #9333ea;
        --accentSoft: rgba(124, 58, 237, .10);
        --accentBorder: rgba(124, 58, 237, .24);
    }

    .adminTheme.themeAccentPurple { --accent:#7c3aed; --accent2:#9333ea; --accentSoft:rgba(124,58,237,.12); --accentBorder:rgba(167,139,250,.34); }
    .adminTheme.themeAccentBlue { --accent:#2563eb; --accent2:#0ea5e9; --accentSoft:rgba(37,99,235,.12); --accentBorder:rgba(96,165,250,.34); }
    .adminTheme.themeAccentGreen { --accent:#059669; --accent2:#10b981; --accentSoft:rgba(5,150,105,.12); --accentBorder:rgba(52,211,153,.34); }
    .adminTheme.themeAccentOrange { --accent:#ea580c; --accent2:#f59e0b; --accentSoft:rgba(234,88,12,.12); --accentBorder:rgba(251,146,60,.34); }
    .adminTheme.themeAccentRed { --accent:#dc2626; --accent2:#f43f5e; --accentSoft:rgba(220,38,38,.12); --accentBorder:rgba(248,113,113,.34); }
    .adminTheme.themeAccentPink { --accent:#db2777; --accent2:#ec4899; --accentSoft:rgba(219,39,119,.12); --accentBorder:rgba(244,114,182,.34); }
    .adminTheme.themeAccentGold { --accent:#b8860b; --accent2:#f5d06f; --accentSoft:rgba(212,175,55,.13); --accentBorder:rgba(245,208,111,.44); }
    .adminTheme.themeAccentSilver { --accent:#94a3b8; --accent2:#f8fafc; --accentSoft:rgba(226,232,240,.12); --accentBorder:rgba(248,250,252,.40); }
    .adminTheme.themeAccentCyan { --accent:#0891b2; --accent2:#06b6d4; --accentSoft:rgba(8,145,178,.12); --accentBorder:rgba(34,211,238,.34); }

    body {
        margin: 0;
        font-family: system-ui, -apple-system, sans-serif;
        background: var(--bg);
        color: var(--text);
    }

    body.adminHomeDark {
        --bg: #080b12;
        --card: rgba(15, 20, 31, .92);
        --text: #f8fafc;
        --muted: rgba(203, 213, 225, .72);
        --line: rgba(148, 163, 184, .18);
        background:
            radial-gradient(720px 420px at 20% -10%, var(--accentSoft), transparent 62%),
            linear-gradient(180deg, #070a11 0%, #0b111d 100%);
    }

    body.adminHomeDark .azHd {
        background: rgba(8, 11, 18, .88) !important;
        border-bottom-color: rgba(148, 163, 184, .16) !important;
        backdrop-filter: blur(14px);
    }
    body.adminHomeDark .azLogo,
    body.adminHomeDark .azNav a,
    body.adminHomeDark .azNoticeBtn,
    body.adminHomeDark .azUserBtn,
    body.adminHomeDark .azUserBtn .azUserEmail { color: #e5e7eb !important; }
    body.adminHomeDark .azNav,
    body.adminHomeDark .azNav a,
    body.adminHomeDark .azNoticeBtn,
    body.adminHomeDark .azUserBtn { background: transparent !important; }
    body.adminHomeDark .azNav a.is-active { background: linear-gradient(135deg, var(--accent), var(--accent2)) !important; color:#fff !important; }

    .page {
        padding: 14px;
    }

    .wrap {
        max-width: none;
        margin: 0;
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
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 44px;
        padding: 0 18px;
        min-width: 132px;
        border-radius: 999px;
        border: 1px solid #d0d7de;
        background: #fff;
        color: #0f172a;
        text-decoration: none;
        font-family: system-ui, -apple-system, sans-serif;
        font-size: 13px;
        font-weight: 900;
        line-height: 1;
        gap: 8px;
        white-space: nowrap;
        cursor: pointer;
        transition: background .18s ease, color .18s ease, border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }

    .tabBtn.isActive {
        background: linear-gradient(135deg, #365EAB, #4b74c2);
        border-color: rgba(54, 94, 171, .32);
        color: #fff;
        box-shadow: 0 10px 24px rgba(54, 94, 171, .18);
    }

    .tabBtn:focus {
        outline: 2px solid rgba(111, 137, 155, .35);
        outline-offset: 2px;
    }

    .panel {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 16px;
        overflow: hidden;
    }

    .settingRow {
        display: grid;
        grid-template-columns: 220px 1fr;
        gap: 16px;
        padding: 18px;
        border-bottom: 1px solid var(--line);
        align-items: start;
    }

    .settingRow:last-child {
        border-bottom: 0;
    }

    .settingTitle {
        font-size: 15px;
        font-weight: 900;
    }

    .settingDesc {
        margin-top: 4px;
        font-size: 12px;
        color: var(--muted);
        font-weight: 700;
        line-height: 1.5;
    }

    .segmented {
        display: inline-flex;
        padding: 4px;
        border-radius: 12px;
        background: color-mix(in srgb, var(--text) 6%, transparent);
        border: 1px solid var(--line);
        gap: 4px;
    }

    .segmented input,
    .swatches input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .segment {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 92px;
        height: 36px;
        border-radius: 9px;
        font-size: 13px;
        font-weight: 900;
        cursor: pointer;
        color: var(--muted);
    }

    .segmented input:checked + .segment {
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        color: #fff;
    }

    .swatches {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(112px, 1fr));
        gap: 10px;
    }

    .swatch {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 52px;
        border: 1px solid var(--line);
        background: color-mix(in srgb, var(--card) 88%, var(--accent) 12%);
        border-radius: 12px;
        padding: 10px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 900;
    }

    .swatchChip {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        background: linear-gradient(135deg, var(--chip), var(--chip2));
        box-shadow: inset 0 1px 0 rgba(255,255,255,.20);
    }

    .swatches input:checked + .swatch {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accentSoft);
    }

    .preview {
        display: grid;
        gap: 10px;
        max-width: 360px;
        padding: 14px;
        border: 1px solid var(--line);
        border-radius: 14px;
        background:
            radial-gradient(220px 120px at 80% 0%, var(--accentSoft), transparent 70%),
            var(--card);
    }

    .previewCard {
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 12px;
        background: color-mix(in srgb, var(--card) 88%, #000 4%);
    }

    .previewValue {
        margin-top: 8px;
        font-size: 24px;
        font-weight: 1000;
    }

    .previewLine {
        height: 5px;
        border-radius: 999px;
        background: linear-gradient(90deg, var(--accent), var(--accent2));
    }

    .actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 16px 18px;
        border-top: 1px solid var(--line);
    }

    .btn {
        height: 40px;
        padding: 0 16px;
        border-radius: 12px;
        border: 1px solid var(--accentBorder);
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        color: #fff;
        font-weight: 900;
        cursor: pointer;
    }

    .notice {
        margin-bottom: 12px;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid var(--accentBorder);
        background: var(--accentSoft);
        font-size: 13px;
        font-weight: 800;
    }

    .notice.err {
        border-color: rgba(248, 113, 113, .34);
        background: rgba(248, 113, 113, .12);
    }

    @media (max-width: 720px) {
        .tabBtn {
            min-width: 120px;
            min-height: 40px;
            padding: 0 14px;
        }

        .settingRow {
            grid-template-columns: 1fr;
        }
    }
    </style>
</head>

<body class="<?= h($bodyClass) ?>">
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="page">
        <div class="wrap">
            <div class="tabsBar" role="tablist" aria-label="設定">
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#list">従業員設定</a>
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#add">従業員追加</a>
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#payroll">店舗設定</a>
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#store">店舗追加</a>
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#labor">人件費率設定</a>
                <a class="tabBtn" href="/admin/expenses.php?store_id=<?= (int)$storeId ?>">経費</a>
                <a class="tabBtn" href="/admin/devices_manage.php?store_id=<?= (int)$storeId ?>">端末管理</a>
                <a class="tabBtn isActive" href="/admin/color_settings.php?store_id=<?= (int)$storeId ?>">色変更</a>
            </div>

            <?php if ($success !== ''): ?>
            <div class="notice"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
            <div class="notice err"><?= h(implode(' / ', $errors)) ?></div>
            <?php endif; ?>

            <form class="panel" method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">

                <div class="settingRow">
                    <div>
                        <div class="settingTitle">モードを選ぶ</div>
                        <div class="settingDesc">ライトは変更前の白基調、ダークは黒基調で表示します。</div>
                    </div>
                    <div class="segmented">
                        <?php foreach ($modes as $value => $label): ?>
                        <label>
                            <input type="radio" name="theme_mode" value="<?= h($value) ?>" <?= $theme['mode'] === $value ? 'checked' : '' ?>>
                            <span class="segment"><?= h($label) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="settingRow">
                    <div>
                        <div class="settingTitle">アクセントカラー</div>
                        <div class="settingDesc">ボタン、選択状態、グラフ線などの強調色を変えます。</div>
                    </div>
                    <div class="swatches">
                        <?php foreach ($accents as $value => $meta): ?>
                        <label>
                            <input type="radio" name="theme_accent" value="<?= h($value) ?>" <?= $theme['accent'] === $value ? 'checked' : '' ?>>
                            <span class="swatch">
                                <span class="swatchChip" style="--chip:<?= h((string)$meta['main']) ?>;--chip2:<?= h((string)$meta['main2']) ?>;"></span>
                                <?= h((string)$meta['label']) ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="settingRow">
                    <div>
                        <div class="settingTitle">プレビュー</div>
                        <div class="settingDesc">保存後、管理画面ホームに反映されます。</div>
                    </div>
                    <div class="preview">
                        <div class="previewLine"></div>
                        <div class="previewCard">
                            <div class="settingDesc">人件費率</div>
                            <div class="previewValue">55.1%</div>
                        </div>
                        <div class="previewCard">
                            <div class="settingDesc">売上</div>
                            <div class="previewValue">314,280円</div>
                        </div>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn" type="submit">保存</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (() => {
        const body = document.body;
        const accents = ['Purple', 'Blue', 'Green', 'Orange', 'Red', 'Pink', 'Gold', 'Silver', 'Cyan'];
        const syncTheme = () => {
            const mode = document.querySelector('input[name="theme_mode"]:checked')?.value || 'dark';
            const accent = document.querySelector('input[name="theme_accent"]:checked')?.value || 'purple';
            body.classList.toggle('adminHomeDark', mode === 'dark');
            accents.forEach(name => body.classList.remove('themeAccent' + name));
            body.classList.add('themeAccent' + accent.charAt(0).toUpperCase() + accent.slice(1));
        };
        document.querySelectorAll('input[name="theme_mode"], input[name="theme_accent"]').forEach(input => {
            input.addEventListener('change', syncTheme);
        });
    })();
    </script>
</body>

</html>
