<?php

/**
 * ✅ ファイル名: /admin/_header.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * 目的:
 * - 添付（HRMOS風）みたいに「縦線で区切る」「ボタンを大きく」「分かりやすい」ヘッダーに変更
 * - 既存要件: キャッシュバック（/admin/back_events.php）を「シフト管理」の右に配置
 */

if (!isset($storeId, $stores) || !is_array($stores)) {
    return;
}
$storeId = (int)$storeId;

function hd_current_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/index.php', PHP_URL_PATH);
    return $path ?: '/admin/index.php';
}

function hd_hidden_query_inputs(array $excludeKeys = []): void
{
    $exclude = array_flip($excludeKeys);
    foreach ($_GET as $k => $v) {
        if (isset($exclude[$k])) continue;
        if (is_array($v)) continue;
        echo '<input type="hidden" name="' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '" value="' .
            htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
}

function hd_is_active(string $path): bool
{
    $cur = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    return $cur === $path;
}

$currentPath = hd_current_path();
$hdAiConfigs = [
    '/admin/index.php' => [
        'scope' => 'store',
        'title' => '店舗運営AI改善',
        'text' => '店舗データから改善提案を受け取る',
    ],
    '/admin/time_punch_daily.php' => [
        'scope' => 'attendance',
        'title' => '日別勤怠AI改善',
        'text' => '勤怠データから改善提案を受け取る',
    ],
    '/admin/shifts.php' => [
        'scope' => 'shifts',
        'title' => 'シフト管理AI改善',
        'text' => 'シフトデータから改善提案を受け取る',
    ],
];
$hdAiConfig = $hdAiConfigs[$currentPath] ?? null;
$hdAiHref = '';
$hdAiFrom = (isset($periodStart) && is_string($periodStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) ? $periodStart : '';
$hdAiTo = (isset($periodEnd) && is_string($periodEnd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) ? $periodEnd : '';
if ($hdAiConfig) {
    $hdAiParams = $_GET;
    $hdAiParams['store_id'] = (string)$storeId;
    $hdAiParams['open_ai'] = '1';
    if ($hdAiFrom !== '') $hdAiParams['ai_from'] = $hdAiFrom;
    if ($hdAiTo !== '') $hdAiParams['ai_to'] = $hdAiTo;
    $hdAiHref = $currentPath . '?' . http_build_query($hdAiParams);
}
$hdNoticeRows = [];
$hdUnreadNoticeCount = 0;
$hdNoticeError = false;
$hdAdminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$hdCsrf = (string)$_SESSION['csrf_token'];
$hdReturnTo = (string)($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        require_once __DIR__ . '/../lib/app_notices.php';
        app_notices_ensure_schema($pdo);
        $hdNoticeRows = app_notices_latest($pdo, 8);
        $hdUnreadNoticeCount = app_notices_unread_count($pdo, $hdAdminUserId);
    } catch (Throwable $e) {
        $hdNoticeRows = [];
        $hdUnreadNoticeCount = 0;
        $hdNoticeError = true;
    }
}

$hdThemeFile = __DIR__ . '/../lib/admin_theme.php';
if (is_file($hdThemeFile)) {
    require_once $hdThemeFile;
}
$hdTheme = ['mode' => 'dark', 'accent' => 'purple'];
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        if (function_exists('admin_theme_current')) {
            $hdTheme = admin_theme_current($pdo, $hdAdminUserId);
        }
    } catch (Throwable $e) {
        $hdTheme = ['mode' => 'dark', 'accent' => 'purple'];
    }
}
$hdThemeBodyClass = function_exists('admin_theme_body_class') ? admin_theme_body_class($hdTheme) : 'adminTheme adminHomeDark themeAccentPurple';
?>
<script>
(function() {
    var classes = <?= json_encode(explode(' ', $hdThemeBodyClass), JSON_UNESCAPED_SLASHES) ?>;
    if (!document.body || !Array.isArray(classes)) return;
    var remove = ['adminTheme', 'adminHomeDark'];
    ['Red','Pink','Orange','Green','Cyan','Blue','Purple','Gold','Silver'].forEach(function(name) {
        remove.push('themeAccent' + name);
    });
    remove.forEach(function(name) { document.body.classList.remove(name); });
    classes.forEach(function(name) { if (name) document.body.classList.add(name); });
})();
</script>
<style>
<?= function_exists('admin_theme_global_css') ? admin_theme_global_css() : '' ?>

/* ===== Header (HRMOS-like) ===== */
.azHd,
.azHd * {
    box-sizing: border-box;
}

.azHd {
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    position: sticky;
    top: 0;
    z-index: 100;
}

.azHdInner {
    display: flex;
    align-items: stretch;
    justify-content: space-between;
    gap: 0;
    min-height: 64px;
    padding: 0 14px;
}

/* left block */
.azHdLeft {
    display: flex;
    align-items: stretch;
    gap: 0;
    min-width: 0;
}

/* right block */
.azHdRight {
    display: flex;
    align-items: stretch;
    gap: 0;
}

/* vertical divider */
.azVLine {
    width: 1px;
    background: #e5e7eb;
    align-self: stretch;
}

/* Logo area */
.azLogo {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 16px;
    text-decoration: none;
    color: #111827;
    font-weight: 900;
    letter-spacing: .02em;
    min-width: 0px;
    width: fit-content;
    /* 追加：中身の幅だけにする */
}

.azLogo img {
    height: 32px;
    width: auto;
    display: block;
}

body.adminTheme .azLogo img {
    display: none;
}

body.adminTheme .azLogo::before {
    content: "〆ナビAI";
    display: block;
    font-size: 34px;
    line-height: 1;
    font-weight: 1000;
    letter-spacing: .02em;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    text-shadow: 0 0 20px var(--accentSoft);
}



/* store switch area */
.azStore {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 16px;
    white-space: nowrap;
}

.azStore select {
    height: 38px;
    border-radius: 12px;
    border: 1px solid rgba(0, 0, 0, .14);
    padding: 0 12px;
    font-weight: 800;
    background: #fff;
    font-size: 12px;
}

.azStore button {
    display: none;
}

/* nav */
.azNav {
    display: flex;
    align-items: stretch;
    gap: 0;
    min-width: 0;
    overflow: auto;
    -webkit-overflow-scrolling: touch;
}

.azMenuBtn {
    display: none;
    align-items: center;
    justify-content: center;
    padding: 0 14px;
    background: #fff;
    border: none;
    font-size: 20px;
    cursor: pointer;
}

.azNavOverlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .35);
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s ease;
    z-index: 80;
}

.azNav a {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 18px;
    min-width: 120px;
    font-size: 14px;
    font-weight: 900;
    color: #111827;
    text-decoration: none;
    position: relative;
}

.azNav a:hover {
    background: #f8fafc;
}

.azNav a.is-active {
    background: #2563eb;
    color: #fff;
}

.azNav a.is-active::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    background: #1d4ed8;
}

/* icons/label like HRMOS (optional small) */
.azNav a .azIcon {
    font-size: 16px;
    margin-right: 8px;
    opacity: .85;
}

/* user / logout */
.azUser {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 16px;
    white-space: nowrap;
    color: #111827;
    font-weight: 900;
}

.azUser .azUserName {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
}

.azUser .azUserName .small {
    font-size: 11px;
    color: #6b7280;
    font-weight: 800;
}

.azLogout {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 16px;
    color: #b91c1c;
    font-weight: 900;
    text-decoration: none;
}

.azLogout:hover {
    background: #fff5f5;
}

.azUserMenu {
    position: relative;
    display: flex;
    align-items: stretch;
}

.azUserBtn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 16px;
    background: #fff;
    border: none;
    cursor: pointer;
    font-weight: 900;
    color: #111827;
}

.azUserBtn .azUserEmail {
    font-size: 12px;
    color: #374151;
    font-weight: 800;
}

.azUserBtn .azUserCaret {
    font-size: 12px;
    opacity: .8;
}

.azUserDropdown {
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 6px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    min-width: 200px;
    box-shadow: 0 14px 40px rgba(0, 0, 0, .12);
    display: none;
    z-index: 99;
}

.azUserDropdown a {
    display: block;
    padding: 12px 14px;
    color: #111827;
    text-decoration: none;
    font-weight: 900;
}

.azUserDropdown a:hover {
    background: #f8fafc;
}

.azUserDropdown a.is-danger {
    color: #b91c1c;
}

.azUserMenu.is-open .azUserDropdown {
    display: block;
}

.azNoticeMenu {
    position: relative;
    display: flex;
    align-items: stretch;
}

.azNoticeBtn {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 58px;
    background: #fff;
    border: none;
    cursor: pointer;
    color: #111827;
    font-size: 20px;
}

.azNoticeBtn:hover {
    background: #f8fafc;
}

.azNoticeBadge {
    position: absolute;
    top: 14px;
    right: 12px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: #ef4444;
    color: #fff;
    font-size: 11px;
    font-weight: 900;
    line-height: 18px;
    text-align: center;
    border: 2px solid #fff;
}

.azNoticeDropdown {
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 6px;
    width: min(380px, 92vw);
    max-height: min(520px, 78vh);
    overflow: auto;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    box-shadow: 0 18px 48px rgba(0, 0, 0, .14);
    display: none;
    z-index: 99;
}

.azNoticeMenu.is-open .azNoticeDropdown {
    display: block;
}

.azNoticeHead {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid #eef2f7;
}

.azNoticeHead strong {
    font-size: 14px;
    font-weight: 900;
    color: #111827;
}

.azNoticeReadBtn {
    border: 1px solid #d1d5db;
    border-radius: 999px;
    background: #fff;
    color: #374151;
    cursor: pointer;
    font-size: 12px;
    font-weight: 900;
    padding: 7px 10px;
    white-space: nowrap;
}

.azNoticeReadBtn:hover {
    background: #f9fafb;
}

.azNoticeItem {
    padding: 14px 16px;
    border-bottom: 1px solid #eef2f7;
}

.azNoticeItem:last-child {
    border-bottom: none;
}

.azNoticeDate {
    font-size: 11px;
    color: #6b7280;
    font-weight: 800;
    margin-bottom: 5px;
}

.azNoticeTitle {
    font-size: 14px;
    color: #111827;
    font-weight: 900;
    line-height: 1.45;
    margin-bottom: 7px;
}

.azNoticeBody {
    font-size: 13px;
    color: #4b5563;
    line-height: 1.65;
    white-space: normal;
}

.azNoticeEmpty {
    padding: 22px 16px;
    color: #6b7280;
    font-size: 13px;
    font-weight: 800;
    text-align: center;
}

.azAiAskCard,
.azPageAiPanel {
    display: none;
}

.azPageAiPanel,
.azPageAiPanel * {
    box-sizing: border-box;
}

.azPageAiPanel.is-open {
    display: block;
}

.azPageAiBackdrop {
    position: fixed;
    inset: 0;
    background: rgba(2, 6, 23, .48);
    z-index: 360;
}

.azPageAiShell {
    position: fixed;
    top: 28px;
    right: 28px;
    bottom: 28px;
    width: min(720px, calc(100vw - 56px));
    z-index: 361;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--accentBorder);
    border-radius: 18px;
    background: linear-gradient(180deg, rgba(18, 24, 38, .98), rgba(8, 11, 18, .98));
    color: #f8fafc;
    box-shadow: 0 24px 70px rgba(0, 0, 0, .34), 0 0 48px var(--accentSoft);
}

.azPageAiHead {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 18px;
    border-bottom: 1px solid rgba(148, 163, 184, .18);
}

.azPageAiTitle {
    font-size: 18px;
    font-weight: 1000;
}

.azPageAiClose {
    width: 38px;
    height: 38px;
    border: 1px solid rgba(148, 163, 184, .24);
    border-radius: 10px;
    background: rgba(15, 23, 42, .82);
    color: #f8fafc;
    cursor: pointer;
    font-size: 18px;
    font-weight: 1000;
}

.azPageAiBody {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding: 18px;
    display: grid;
    align-content: start;
    gap: 12px;
}

.azPageAiLead,
.azPageAiStatus {
    color: rgba(226, 232, 240, .78);
    font-size: 13px;
    font-weight: 800;
    line-height: 1.7;
}

.azPageAiPrimary,
.azPageAiSend {
    min-height: 42px;
    border: 1px solid var(--accentBorder);
    border-radius: 12px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: #fff;
    cursor: pointer;
    font-weight: 1000;
}

.azPageAiPrimary:disabled,
.azPageAiSend:disabled {
    cursor: wait;
    opacity: .7;
}

.azPageAiAnswer,
.azPageAiChat {
    padding: 14px;
    border: 1px solid rgba(148, 163, 184, .18);
    border-radius: 14px;
    background: rgba(15, 23, 42, .58);
    color: rgba(248, 250, 252, .92);
    font-size: 13px;
    font-weight: 800;
    line-height: 1.8;
    white-space: pre-wrap;
}

.azPageAiChat {
    display: none;
    gap: 10px;
}

.azPageAiChat.is-open {
    display: grid;
}

.azPageAiTurnQ {
    color: #fde68a;
}

.azPageAiAsk {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 96px;
    gap: 8px;
}

.azPageAiInput {
    min-width: 0;
    min-height: 42px;
    border: 1px solid rgba(148, 163, 184, .24);
    border-radius: 12px;
    background: rgba(8, 11, 18, .78);
    color: #f8fafc;
    padding: 0 12px;
    font-weight: 800;
}

body.adminTheme:not(.adminHomeDark) .azPageAiShell {
    background: #ffffff;
    color: #111827;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .18), 0 0 36px var(--accentSoft);
}

body.adminTheme:not(.adminHomeDark) .azPageAiHead,
body.adminTheme:not(.adminHomeDark) .azPageAiAnswer,
body.adminTheme:not(.adminHomeDark) .azPageAiChat {
    border-color: rgba(15, 23, 42, .10);
}

body.adminTheme:not(.adminHomeDark) .azPageAiLead,
body.adminTheme:not(.adminHomeDark) .azPageAiStatus {
    color: #475569;
}

body.adminTheme:not(.adminHomeDark) .azPageAiAnswer,
body.adminTheme:not(.adminHomeDark) .azPageAiChat {
    background: #f8fafc;
    color: #111827;
}

body.adminTheme:not(.adminHomeDark) .azPageAiInput,
body.adminTheme:not(.adminHomeDark) .azPageAiClose {
    background: #ffffff;
    color: #111827;
}

@media (max-width: 900px) {
    body.adminTheme .azAiAskCard {
        position: relative;
        display: grid;
        gap: 8px;
        margin: 12px 0;
        padding: 16px 14px 12px;
        min-height: 140px;
        border: 1px solid var(--accentBorder);
        border-radius: 16px;
        color: #f8fafc !important;
        text-decoration: none;
        background:
            radial-gradient(110px 76px at 52% 78%, var(--accentGlow), transparent 70%),
            linear-gradient(180deg, color-mix(in srgb, var(--accent) 28%, #08111f), color-mix(in srgb, var(--accent) 12%, #070b13));
        box-shadow: 0 12px 32px var(--accentSoft), inset 0 1px 0 rgba(255, 255, 255, .10);
        overflow: hidden;
    }

    body.adminTheme .azAiAskTitle {
        position: relative;
        font-size: 14px;
        font-weight: 1000;
        text-align: center;
        color: color-mix(in srgb, var(--accent2) 42%, #ffffff);
    }

    body.adminTheme .azAiAskText {
        position: relative;
        color: rgba(248, 250, 252, .76);
        font-size: 10px;
        font-weight: 800;
        line-height: 1.6;
        text-align: center;
    }

    body.adminTheme .azAiAskIcon {
        position: relative;
        width: 52px;
        height: 52px;
        margin: 0 auto;
        border-radius: 999px;
        border: 1px solid var(--accentBorder);
        display: grid;
        place-items: center;
        font-size: 22px;
        font-weight: 1000;
        color: color-mix(in srgb, var(--accent2) 66%, #ffffff);
        background: rgba(8, 11, 18, .28);
    }

    .azPageAiShell {
        inset: 10px;
        width: auto;
        border-radius: 14px;
    }

    .azPageAiAsk {
        grid-template-columns: 1fr;
    }
}

@media (min-width: 901px) {
    body.adminTheme .azAiAskCard {
        position: relative;
        display: grid;
        gap: 8px;
        margin: auto 0 0;
        padding: 18px 14px 12px;
        min-height: 176px;
        border: 1px solid var(--accentBorder);
        border-radius: 18px;
        color: #f8fafc !important;
        text-decoration: none;
        background:
            radial-gradient(120px 86px at 52% 76%, var(--accentGlow), transparent 70%),
            radial-gradient(130px 90px at 45% 34%, var(--accentSoft), transparent 72%),
            linear-gradient(180deg, color-mix(in srgb, var(--accent) 28%, #08111f), color-mix(in srgb, var(--accent) 12%, #070b13));
        box-shadow: 0 18px 48px var(--accentSoft), 0 0 0 1px var(--accentSoft), inset 0 1px 0 rgba(255, 255, 255, .10);
        overflow: hidden;
    }

    body.adminTheme .azAiAskCard::before {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background:
            linear-gradient(135deg, rgba(255,255,255,.16), transparent 34%),
            linear-gradient(180deg, transparent, color-mix(in srgb, var(--accent) 18%, transparent));
        pointer-events: none;
    }

    body.adminTheme .azAiAskTitle {
        position: relative;
        font-size: 15px;
        font-weight: 1000;
        text-align: center;
        color: color-mix(in srgb, var(--accent2) 42%, #ffffff);
        text-shadow: 0 0 16px var(--accentGlow);
    }

    body.adminTheme .azAiAskText {
        position: relative;
        color: rgba(248, 250, 252, .76);
        font-size: 10px;
        font-weight: 800;
        line-height: 1.6;
        text-align: center;
    }

    body.adminTheme .azAiAskIcon {
        position: relative;
        width: 58px;
        height: 58px;
        margin: 2px auto 0;
        border-radius: 999px;
        border: 1px solid var(--accentBorder);
        color: color-mix(in srgb, var(--accent2) 66%, #ffffff);
        background:
            radial-gradient(circle at 50% 42%, color-mix(in srgb, var(--accent) 24%, transparent), transparent 62%),
            rgba(8, 11, 18, .28);
        display: grid;
        place-items: center;
        font-size: 24px;
        font-weight: 1000;
        text-shadow: 0 0 14px var(--accentGlow);
        box-shadow: 0 0 30px var(--accentGlow), inset 0 0 18px var(--accentSoft);
    }

    body.adminTheme .azAiAskIcon::before,
    body.adminTheme .azAiAskIcon::after {
        content: "";
        position: absolute;
        border-radius: 999px;
        background: var(--accent2);
    }

    body.adminTheme .azAiAskIcon::before {
        width: 6px;
        height: 6px;
        right: -10px;
        top: 10px;
    }

    body.adminTheme .azAiAskIcon::after {
        width: 4px;
        height: 4px;
        left: -12px;
        bottom: 12px;
    }
}

/* ===== Sidebar layout for admin pages except dashboard home ===== */
@media (min-width: 901px) {
    body.adminTheme:not(.adminHomeDashboard) {
        padding-left: 230px;
        padding-top: 72px;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHd {
        position: fixed;
        inset: 0 auto 0 0;
        width: 230px;
        height: 100vh;
        border-right: 1px solid #e5e7eb;
        border-bottom: 0;
        z-index: 120;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHdInner {
        min-height: 100%;
        padding: 16px 10px;
        flex-direction: column;
        align-items: stretch;
        justify-content: flex-start;
        gap: 10px;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHdLeft,
    body.adminTheme:not(.adminHomeDashboard) .azNav {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        gap: 6px;
        overflow: visible;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHdLeft {
        flex: 1;
    }

    body.adminTheme:not(.adminHomeDashboard) .azLogo {
        width: 100%;
        padding: 8px 12px 24px;
    }

    body.adminTheme:not(.adminHomeDashboard) .azLogo img {
        height: 34px;
    }

    body.adminTheme:not(.adminHomeDashboard) .azLogo::before {
        font-size: 34px;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHdLeft > .azVLine,
    body.adminTheme:not(.adminHomeDashboard) .azNav .azVLine {
        display: none;
    }

    body.adminTheme:not(.adminHomeDashboard) .azMenuBtn {
        display: none;
    }

    body.adminTheme:not(.adminHomeDashboard) .azNav a {
        min-width: 0;
        width: 100%;
        min-height: 46px;
        padding: 0 14px;
        justify-content: flex-start;
        border-radius: 10px;
        font-size: 13px;
    }

    body.adminTheme:not(.adminHomeDashboard) .azNav a.is-active::after {
        display: none;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHdRight {
        position: fixed;
        top: 16px;
        right: 24px;
        width: auto;
        max-width: calc(100vw - 286px);
        min-height: 42px;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        z-index: 140;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHdRight > .azVLine {
        height: 28px;
        align-self: center;
    }

    body.adminTheme:not(.adminHomeDashboard) .azNoticeMenu,
    body.adminTheme:not(.adminHomeDashboard) .azStore,
    body.adminTheme:not(.adminHomeDashboard) .azUserMenu {
        min-width: 0;
        min-height: 42px;
    }

    body.adminTheme:not(.adminHomeDashboard) .azNoticeBtn,
    body.adminTheme:not(.adminHomeDashboard) .azUserBtn,
    body.adminTheme:not(.adminHomeDashboard) .azStore select {
        min-height: 42px;
        border-radius: 10px;
    }

    body.adminTheme:not(.adminHomeDashboard) .azUserBtn {
        max-width: 260px;
    }

    body.adminTheme:not(.adminHomeDashboard) .azUserEmail {
        display: block;
        max-width: 190px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    body.adminTheme:not(.adminHomeDashboard) .azStore select {
        max-width: 180px;
    }
}

body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions {
    position: fixed !important;
    top: 16px !important;
    right: 24px !important;
    left: auto !important;
    width: auto !important;
    max-width: calc(100vw - 286px) !important;
    min-width: 0;
    min-height: 42px;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-end !important;
    flex-wrap: nowrap !important;
    gap: 10px !important;
    z-index: 300 !important;
}

body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions > .azVLine {
    height: 28px;
    align-self: center;
}

/* mobile tune */
@media (max-width: 900px) {
    .azHdInner {
        flex-wrap: wrap;
    }

    .azHdLeft,
    .azHdRight {
        width: 100%;
    }

    .azNav {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .azMenuBtn {
        display: flex;
    }

    .azNav {
        position: fixed;
        top: 0;
        left: 0;
        height: 100%;
        width: min(78vw, 320px);
        background: #fff;
        flex-direction: column;
        align-items: stretch;
        transform: translateX(-105%);
        transition: transform .25s ease;
        z-index: 90;
        overflow-y: auto;
        border-right: 1px solid #e5e7eb;
    }

    .azNav.is-open {
        transform: translateX(0);
    }

    .azNavOverlay.is-open {
        opacity: 1;
        pointer-events: auto;
    }

    .azLogo {
        min-width: 140px;
        padding: 0 12px;
    }

    .azNav a {
        min-width: 96px;
        padding: 0 14px;
        font-size: 13px;
        flex: 0 0 auto;
    }

    .azNav a {
        justify-content: flex-start;
        padding: 14px 16px;
        border-bottom: 1px solid #e5e7eb;
        min-width: 0;
    }

    .azNav .azVLine {
        display: none;
    }

    .azStore {
        padding: 0 12px;
    }

    .azNoticeBtn {
        width: 48px;
    }

    .azUser {
        display: none;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions {
        position: fixed !important;
        top: 12px !important;
        right: 12px !important;
        left: auto !important;
        width: auto !important;
        max-width: calc(100vw - 24px) !important;
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: flex-end !important;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions .azStore {
        display: flex;
    }

    body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions .azNoticeMenu,
    body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions .azStore,
    body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions .azUserMenu,
    body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions .azNoticeBtn,
    body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions .azUserBtn,
    body.adminTheme:not(.adminHomeDashboard) .azHdRight.azGlobalActions .azStore select {
        width: auto;
    }

    /* まずは見やすさ優先 */
}

/* unified sidebar actions */
body.adminTheme .azHd .azHdRight {
    position: static !important;
    top: auto !important;
    right: auto !important;
    left: auto !important;
    z-index: auto !important;
    display: grid !important;
    grid-template-columns: 1fr;
    align-items: stretch !important;
    justify-content: stretch !important;
    gap: 8px !important;
    width: 100% !important;
    max-width: none !important;
    min-height: 0 !important;
    margin: 0 !important;
    flex: 0 0 auto;
}

@media (min-width: 901px) {
    body.adminTheme .azHd .azHdInner {
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        justify-content: flex-start !important;
        height: 100%;
        min-height: 100%;
    }

    body.adminTheme .azHd .azHdLeft {
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        min-height: 0;
        flex: 1 1 auto;
    }

    body.adminTheme .azHd .azNav {
        flex: 0 0 auto;
    }

    body.adminTheme .azHd .azAiAskCard {
        flex: 0 0 auto;
        margin-top: auto !important;
        margin-bottom: 12px !important;
    }

    body.adminTheme .azHd .azHdLeft + .azHdRight {
        flex: 0 0 auto;
    }
}

body.adminTheme .azHd .azHdRight > .azVLine {
    display: none !important;
}

body.adminTheme .azHd .azNoticeMenu,
body.adminTheme .azHd .azStore,
body.adminTheme .azHd .azUserMenu {
    width: 100% !important;
    min-height: 0 !important;
    padding: 0 !important;
}

body.adminTheme .azHd .azNoticeBtn,
body.adminTheme .azHd .azStore select,
body.adminTheme .azHd .azUserBtn {
    width: 100% !important;
    max-width: none !important;
    min-height: 42px !important;
    border: 1px solid rgba(148, 163, 184, .22) !important;
    border-radius: 10px !important;
    background: rgba(15, 23, 42, .72) !important;
    color: #f8fafc !important;
}

body.adminTheme .azHd .azNoticeBtn {
    position: relative;
    justify-content: flex-start !important;
    gap: 10px;
    padding: 0 14px !important;
    font-size: 18px;
}

body.adminTheme .azHd .azNoticeIcon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

body.adminTheme .azHd .azNoticeLabel {
    display: inline;
    font-size: 12px;
    font-weight: 900;
    color: #f8fafc;
}

body.adminTheme .azHd .azNoticeBadge {
    top: -8px !important;
    right: -10px !important;
    border-color: #0f172a !important;
}

body.adminTheme .azHd .azStore select {
    padding: 0 12px !important;
}

body.adminTheme .azHd .azUserBtn {
    justify-content: space-between !important;
}

body.adminTheme .azHd .azUserEmail {
    display: block;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #f8fafc !important;
}

body.adminTheme .azHd .azNoticeDropdown,
body.adminTheme .azHd .azUserDropdown {
    top: auto !important;
    right: auto !important;
    bottom: 100% !important;
    left: 0 !important;
    width: min(380px, calc(100vw - 24px));
    min-width: 0;
    margin: 0 0 8px !important;
}

@media (max-width: 900px) {
    body.adminTheme .azHd .azHdRight {
        margin: auto 14px 16px !important;
        width: auto !important;
    }
}

@media (min-width: 901px) {
    body.adminTheme:not(.adminHomeDashboard) {
        padding-top: 0 !important;
    }
}

/* ===== Final fixed menu layout guard =====
   Keep sidebar controls in one deterministic place across every admin page. */
@media (min-width: 901px) {
    body.adminTheme:not(.adminHomeDashboard) {
        padding-left: 230px !important;
    }

    body.adminTheme .azHd {
        position: fixed !important;
        inset: 0 auto 0 0 !important;
        width: 230px !important;
        height: 100vh !important;
        z-index: 320 !important;
        border-right: 1px solid rgba(148, 163, 184, .16) !important;
        border-bottom: 0 !important;
        overflow: visible !important;
    }

    body.adminTheme .azHd .azHdInner {
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        justify-content: flex-start !important;
        width: 100% !important;
        height: 100% !important;
        min-height: 100% !important;
        padding: 16px 10px !important;
        gap: 10px !important;
    }

    body.adminTheme .azHd .azHdLeft {
        order: 1 !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        width: 100% !important;
        min-width: 0 !important;
        min-height: 0 !important;
        flex: 1 1 auto !important;
        gap: 10px !important;
    }

    body.adminTheme .azHd .azLogo {
        width: 100% !important;
        flex: 0 0 auto !important;
        padding: 8px 12px 24px !important;
    }

    body.adminTheme .azHd .azNav {
        display: flex !important;
        flex: 0 0 auto !important;
        flex-direction: column !important;
        align-items: stretch !important;
        width: 100% !important;
        gap: 6px !important;
        overflow: visible !important;
    }

    body.adminTheme .azHd .azNav a {
        width: 100% !important;
        min-width: 0 !important;
        min-height: 46px !important;
        justify-content: flex-start !important;
        border-radius: 10px !important;
        padding: 0 14px !important;
    }

    body.adminTheme .azHd .azNav a.is-active::after,
    body.adminTheme .azHd .azHdLeft > .azVLine,
    body.adminTheme .azHd .azNav > .azVLine {
        display: none !important;
    }

    body.adminTheme .azHd .azAiAskCard {
        order: 20 !important;
        flex: 0 0 auto !important;
        margin-top: auto !important;
        margin-bottom: 12px !important;
    }

    body.adminTheme .azHd .azHdRight {
        order: 2 !important;
        position: static !important;
        inset: auto !important;
        display: grid !important;
        grid-template-columns: 1fr !important;
        align-items: stretch !important;
        justify-content: stretch !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        min-height: 0 !important;
        flex: 0 0 auto !important;
        gap: 8px !important;
        margin: 0 !important;
        padding: 0 !important;
        transform: none !important;
        z-index: auto !important;
    }

    body.adminTheme .azHd .azHdRight > .azVLine {
        display: none !important;
    }

    body.adminTheme .azHd .azNoticeMenu {
        order: 1 !important;
    }

    body.adminTheme .azHd .azStore {
        order: 2 !important;
    }

    body.adminTheme .azHd .azUserMenu {
        order: 3 !important;
    }

    body.adminTheme .azHd .azNoticeMenu,
    body.adminTheme .azHd .azStore,
    body.adminTheme .azHd .azUserMenu {
        position: relative !important;
        display: flex !important;
        align-items: stretch !important;
        width: 100% !important;
        height: 42px !important;
        min-height: 42px !important;
        max-height: 42px !important;
        margin: 0 !important;
        padding: 0 !important;
        flex: 0 0 42px !important;
    }

    body.adminTheme .azHd .azNoticeBtn,
    body.adminTheme .azHd .azStore select,
    body.adminTheme .azHd .azUserBtn {
        display: flex !important;
        align-items: center !important;
        width: 100% !important;
        height: 42px !important;
        min-height: 42px !important;
        max-height: 42px !important;
        margin: 0 !important;
        padding: 0 12px !important;
        line-height: 1 !important;
        border-radius: 10px !important;
        box-sizing: border-box !important;
    }

    body.adminTheme .azHd .azNoticeBtn {
        justify-content: flex-start !important;
        gap: 10px !important;
        font-size: 18px !important;
    }

    body.adminTheme .azHd .azNoticeIcon {
        position: relative !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 22px !important;
        min-width: 22px !important;
        height: 22px !important;
        font-size: 18px !important;
        line-height: 1 !important;
        flex: 0 0 22px !important;
    }

    body.adminTheme .azHd .azNoticeLabel {
        display: block !important;
        min-width: 0 !important;
        line-height: 1 !important;
    }

    body.adminTheme .azHd .azNoticeBadge {
        top: -7px !important;
        right: -9px !important;
    }

    body.adminTheme .azHd .azStore {
        overflow: hidden !important;
    }

    body.adminTheme .azHd .azStore select {
        min-width: 0 !important;
    }

    body.adminTheme .azHd .azUserEmail {
        min-width: 0 !important;
    }
}

@media (max-width: 900px) {
    body.adminTheme {
        padding-left: 0 !important;
    }

    body.adminTheme .azHd .azHdRight {
        order: 200 !important;
        display: grid !important;
        grid-template-columns: 1fr !important;
        align-items: stretch !important;
        width: auto !important;
        max-width: none !important;
        margin: auto 0 0 !important;
        gap: 8px !important;
    }

    body.adminTheme .azHd .azNoticeMenu,
    body.adminTheme .azHd .azStore,
    body.adminTheme .azHd .azUserMenu {
        width: 100% !important;
        height: 42px !important;
        min-height: 42px !important;
        flex: 0 0 42px !important;
    }

    body.adminTheme .azHd .azNoticeBtn,
    body.adminTheme .azHd .azStore select,
    body.adminTheme .azHd .azUserBtn {
        width: 100% !important;
        height: 42px !important;
        min-height: 42px !important;
    }
}

/* print */
@media print {
    .azHd {
        display: none !important;
    }
}
</style>

<div class="azHd">
    <div class="azHdInner">

        <div class="azHdLeft">
            <!-- ロゴ -->
            <a class="azLogo" href="/admin/index.php?store_id=<?= (int)$storeId ?>">
                <img src="../images/logo_main.png" alt="SHIMENABI">
            </a>

            <div class="azVLine"></div>

            <button class="azMenuBtn" type="button" id="azMenuBtn" aria-controls="azNav"
                aria-expanded="false">☰</button>

            <!-- ナビ（縦線で区切り / 大きく） -->
            <nav class="azNav" id="azNav" aria-label="admin navigation">
                <a class="<?= hd_is_active('/admin/index.php') ? 'is-active' : '' ?>"
                    href="/admin/index.php?store_id=<?= (int)$storeId ?>">
                    <span class="azIcon">🏠</span>ホーム
                </a>
                <div class="azVLine"></div>

                <a class="<?= hd_is_active('/admin/time_punch_daily.php') ? 'is-active' : '' ?>"
                    href="/admin/time_punch_daily.php?store_id=<?= (int)$storeId ?>">
                    <span class="azIcon">📅</span>日別勤怠
                </a>
                <div class="azVLine"></div>


                <a class="<?= hd_is_active('/admin/shifts.php') ? 'is-active' : '' ?>"
                    href="/admin/shifts.php?store_id=<?= (int)$storeId ?>&view=month">
                    <span class="azIcon">🗓️</span>シフト管理
                </a>
                <div class="azVLine"></div>

                <!-- ✅ キャッシュバック（シフト管理の右） -->
                <?php
            $backEnabled = true;
            if (isset($pdo) && $pdo instanceof PDO && isset($tenantId)) {
                try {
                    $st = $pdo->prepare("
                        SELECT COALESCE(back_enabled, 1) AS back_enabled
                        FROM stores
                        WHERE tenant_id = :t AND id = :s
                        LIMIT 1
                    ");
                    $st->execute([':t' => (int)$tenantId, ':s' => (int)$storeId]);
                    $backEnabled = ((int)($st->fetch()['back_enabled'] ?? 1) === 1);
                } catch (Throwable $e) {
                    $backEnabled = true;
                }
            }
            ?>
                <?php if ($backEnabled): ?>
                <a class="<?= hd_is_active('/admin/back_events.php') ? 'is-active' : '' ?>"
                    href="/admin/back_events.php?store_id=<?= (int)$storeId ?>">
                    <span class="azIcon">💰</span>バック
                </a>
                <?php endif; ?>
                <!-- <div class="azVLine"></div>
                <a class="<?= hd_is_active('/admin/payslip_view.php') ? 'is-active' : '' ?>"
                    href="/admin/payslip_view.php?store_id=<?= (int)$storeId ?>">
                    <span class="azIcon">🕘</span>打刻ログ
                </a> -->
                <!-- ✅ ヘルプ（AI） -->
                <!-- <a class="<?= hd_is_active('/admin/help.php') ? 'is-active' : '' ?>"
                    href="/admin/help.php?store_id=<?= (int)$storeId ?>">
                    <span class="azIcon">💬</span>AIヘルプ
                </a>
                <div class="azVLine"></div> -->

                <div class="azVLine"></div>
                <a class="<?= (hd_is_active('/admin/employees_new.php') || hd_is_active('/admin/expenses.php') || hd_is_active('/admin/devices_manage.php') || hd_is_active('/admin/color_settings.php')) ? 'is-active' : '' ?>"
                    href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>">
                    <span class="azIcon">⚙️</span>設定
                </a>
                <!-- <div class="azVLine"></div>
                <a class="<?= hd_is_active('/admin/manual.php') ? 'is-active' : '' ?>"
                    href="/admin/manual.php">
                    <span class="azIcon">📘</span>取扱説明
                </a> -->
            </nav>

            <?php if ($hdAiConfig): ?>
            <a class="azAiAskCard" data-ai-ask-launch="1" data-ai-scope="<?= h((string)$hdAiConfig['scope']) ?>"
                data-ai-title="<?= h((string)$hdAiConfig['title']) ?>"
                data-ai-from="<?= h($hdAiFrom) ?>"
                data-ai-to="<?= h($hdAiTo) ?>"
                href="<?= h($hdAiHref) ?>">
                <span class="azAiAskTitle"><?= h((string)$hdAiConfig['title']) ?></span>
                <span class="azAiAskText"><?= h((string)$hdAiConfig['text']) ?></span>
                <span class="azAiAskIcon" aria-hidden="true">AI</span>
            </a>
            <?php endif; ?>
        </div>

        <div class="azHdRight">
            <!-- <div class="azVLine"></div> -->

            <!-- 右側（ユーザー表示は雰囲気だけ寄せる） -->
            <!-- <div class="azUser" title="ログインユーザー">
                <div class="azUserName">
                    <div>Fader</div>
                    <div class="small">管理者</div>
                </div>
                <div style="font-size:18px;opacity:.8;">▾</div>
            </div> -->

            <div class="azVLine"></div>

            <!-- お知らせ -->
            <div class="azNoticeMenu" id="azNoticeMenu">
                <button class="azNoticeBtn" type="button" id="azNoticeBtn" aria-haspopup="true"
                    aria-expanded="false" aria-label="お知らせ">
                    <span class="azNoticeIcon" aria-hidden="true">
                        🔔
                        <?php if ($hdUnreadNoticeCount > 0): ?>
                        <span class="azNoticeBadge"><?= (int)min($hdUnreadNoticeCount, 99) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="azNoticeLabel">お知らせ</span>
                </button>
                <div class="azNoticeDropdown" id="azNoticeDropdown">
                    <div class="azNoticeHead">
                        <strong>お知らせ</strong>
                        <?php if ($hdUnreadNoticeCount > 0): ?>
                        <form method="post" action="/admin/notice_read.php">
                            <input type="hidden" name="csrf_token" value="<?= h($hdCsrf) ?>">
                            <input type="hidden" name="return_to" value="<?= h($hdReturnTo) ?>">
                            <button class="azNoticeReadBtn" type="submit">すべて既読</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($hdNoticeError): ?>
                    <div class="azNoticeEmpty">お知らせを読み込めませんでした</div>
                    <?php elseif (!$hdNoticeRows): ?>
                    <div class="azNoticeEmpty">現在お知らせはありません</div>
                    <?php else: ?>
                    <?php foreach ($hdNoticeRows as $notice): ?>
                    <article class="azNoticeItem">
                        <div class="azNoticeDate"><?= h(date('Y/m/d', strtotime((string)$notice['published_at']))) ?></div>
                        <div class="azNoticeTitle"><?= h((string)$notice['title']) ?></div>
                        <div class="azNoticeBody"><?= nl2br(h((string)$notice['body'])) ?></div>
                    </article>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="azVLine"></div>

            <!-- 店舗切替 -->
            <form class="azStore" method="get" action="<?= htmlspecialchars($currentPath, ENT_QUOTES, 'UTF-8') ?>">
                <?php hd_hidden_query_inputs(['store_id']); ?>
                <select name="store_id" onchange="this.form.submit()">
                    <?php foreach ($stores as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= ((int)$st['id'] === $storeId) ? 'selected' : '' ?>>
                        <?= h((string)$st['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">切替</button>
            </form>
            <div class="azVLine"></div>

            <?php $adminEmail = (string)($_SESSION['tenant_admin_email'] ?? ''); ?>
            <div class="azUserMenu" id="azUserMenu">
                <button class="azUserBtn" type="button" id="azUserBtn" aria-haspopup="true" aria-expanded="false">
                    <span class="azUserEmail"><?= h($adminEmail !== '' ? $adminEmail : 'アカウント') ?></span>
                    <span class="azUserCaret">▾</span>
                </button>
                <div class="azUserDropdown" id="azUserDropdown">
                    <a href="/admin/account.php">アカウント</a>
                    <a href="/admin/admin_users.php">ユーザー権限</a>
                    <a href="/admin/color_settings.php">テーマ変更</a>
                    <a href="/admin/manual.php">取扱説明書</a>
                    <a class="is-danger" href="/admin/logout.php">ログアウト</a>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="azNavOverlay" id="azNavOverlay" aria-hidden="true"></div>

<?php if ($hdAiConfig): ?>
<section class="azPageAiPanel" id="azPageAiPanel" aria-hidden="true"
    data-ai-scope="<?= h((string)$hdAiConfig['scope']) ?>" data-ai-title="<?= h((string)$hdAiConfig['title']) ?>">
    <div class="azPageAiBackdrop" data-ai-close="1"></div>
    <div class="azPageAiShell" role="dialog" aria-modal="true" aria-labelledby="azPageAiTitle">
        <div class="azPageAiHead">
            <div class="azPageAiTitle" id="azPageAiTitle"><?= h((string)$hdAiConfig['title']) ?></div>
            <button class="azPageAiClose" type="button" data-ai-close="1" aria-label="閉じる">×</button>
        </div>
        <div class="azPageAiBody">
            <div class="azPageAiLead"><?= h((string)$hdAiConfig['text']) ?>。表示中の店舗データをもとに、改善ポイントを短くまとめます。</div>
            <button class="azPageAiPrimary" type="button" id="azPageAiLoad"><?= h((string)$hdAiConfig['title']) ?>を作成</button>
            <div class="azPageAiStatus" id="azPageAiStatus"></div>
            <div class="azPageAiAnswer" id="azPageAiAnswer" style="display:none;"></div>
            <div class="azPageAiChat" id="azPageAiChat"></div>
            <div class="azPageAiAsk">
                <input class="azPageAiInput" id="azPageAiInput" type="text" maxlength="300"
                    placeholder="追加で聞きたいことを入力">
                <button class="azPageAiSend" type="button" id="azPageAiSend">送る</button>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<script>
(function() {
    const menu = document.getElementById('azNoticeMenu');
    const btn = document.getElementById('azNoticeBtn');
    if (!menu || !btn) return;

    const closeMenu = () => {
        menu.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
    };

    btn.addEventListener('click', () => {
        const open = menu.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', (e) => {
        if (!menu.contains(e.target)) closeMenu();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeMenu();
    });
})();

(function() {
    const menu = document.getElementById('azUserMenu');
    const btn = document.getElementById('azUserBtn');
    if (!menu || !btn) return;

    const closeMenu = () => {
        menu.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
    };

    btn.addEventListener('click', () => {
        const open = menu.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', (e) => {
        if (!menu.contains(e.target)) closeMenu();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeMenu();
    });
})();

(function() {
    const nav = document.getElementById('azNav');
    const btn = document.getElementById('azMenuBtn');
    const overlay = document.getElementById('azNavOverlay');
    if (!nav || !btn || !overlay) return;

    const closeNav = () => {
        nav.classList.remove('is-open');
        overlay.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    };

    const openNav = () => {
        nav.classList.add('is-open');
        overlay.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    };

    btn.addEventListener('click', () => {
        if (nav.classList.contains('is-open')) {
            closeNav();
        } else {
            openNav();
        }
    });

    overlay.addEventListener('click', closeNav);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeNav();
    });
})();

(function() {
    const actions = document.querySelector('.azHdRight');
    const aiCard = document.querySelector('.azAiAskCard');
    const left = document.querySelector('.azHdLeft');
    const inner = document.querySelector('.azHdInner');
    const nav = document.getElementById('azNav');
    if (!actions) return;

    const placeActions = () => {
        const isMobile = window.matchMedia('(max-width: 900px)').matches;
        if (isMobile) {
            if (!nav) return;
            if (aiCard && !nav.contains(aiCard)) nav.appendChild(aiCard);
            if (!nav.contains(actions)) nav.appendChild(actions);
            return;
        }

        if (left && aiCard && aiCard.parentElement !== left) {
            left.appendChild(aiCard);
        }

        if (inner && actions.parentElement !== inner) {
            inner.appendChild(actions);
        }

        if (inner && left && left.nextElementSibling !== actions) {
            left.insertAdjacentElement('afterend', actions);
        }
    };

    placeActions();
    window.addEventListener('resize', placeActions);
})();

(function() {
    const launch = document.querySelector('[data-ai-ask-launch]');
    if (!launch) return;
    const panel = document.getElementById('azPageAiPanel');
    const loadBtn = document.getElementById('azPageAiLoad');
    const status = document.getElementById('azPageAiStatus');
    const answer = document.getElementById('azPageAiAnswer');
    const chat = document.getElementById('azPageAiChat');
    const input = document.getElementById('azPageAiInput');
    const send = document.getElementById('azPageAiSend');
    const scope = launch.dataset.aiScope || '';
    const title = launch.dataset.aiTitle || 'AI改善提案';
    const aiFrom = launch.dataset.aiFrom || '';
    const aiTo = launch.dataset.aiTo || '';
    let loadedText = '';

    const setBusy = (busy) => {
        if (loadBtn) loadBtn.disabled = busy;
        if (send) send.disabled = busy;
    };

    const openPanel = () => {
        if (!panel) return false;
        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        return true;
    };

    const closePanel = () => {
        if (!panel) return;
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    const renderText = (text) => {
        if (!answer) return;
        answer.style.display = 'block';
        answer.textContent = text || 'AI改善提案を取得できませんでした。';
    };

    const requestAi = async (question = '') => {
        if (!scope) return;
        setBusy(true);
        if (status) status.textContent = question ? '追加質問を送信中...' : `${title}を作成中...`;
        try {
            const form = new FormData();
            form.append('store_id', '<?= (int)$storeId ?>');
            form.append('scope', scope);
            form.append('title', title);
            form.append('question', question);
            form.append('prior', loadedText);
            if (aiFrom && aiTo) {
                form.append('from', aiFrom);
                form.append('to', aiTo);
            }

            const res = await fetch('/admin/ai_page_improve.php', {
                method: 'POST',
                body: form,
                credentials: 'same-origin'
            });
            const ct = (res.headers.get('content-type') || '').toLowerCase();
            if (!ct.includes('application/json')) {
                const t = await res.text();
                throw new Error('JSONではない応答です: ' + t.slice(0, 160));
            }
            const json = await res.json();
            if (!json.ok) throw new Error(json.message || json.error || 'AI改善提案を取得できませんでした');

            if (question) {
                if (chat) {
                    chat.classList.add('is-open');
                    const turn = document.createElement('div');
                    turn.innerHTML = `<div class="azPageAiTurnQ">Q. ${question.replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}</div><div>${String(json.answer || '').replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}</div>`;
                    chat.appendChild(turn);
                }
            } else {
                loadedText = json.answer || '';
                renderText(loadedText);
            }
            if (status) status.textContent = '完了';
        } catch (e) {
            if (status) status.textContent = 'エラー';
            renderText(String(e.message || e));
        } finally {
            setBusy(false);
        }
    };

    launch.addEventListener('click', (ev) => {
        if (openPanel()) {
            ev.preventDefault();
            if (!loadedText) requestAi();
        }
    });

    if (loadBtn) loadBtn.addEventListener('click', () => requestAi());
    if (send && input) {
        send.addEventListener('click', () => {
            const q = input.value.trim();
            if (!q) return;
            input.value = '';
            requestAi(q);
        });
        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                send.click();
            }
        });
    }
    document.querySelectorAll('[data-ai-close]').forEach((el) => el.addEventListener('click', closePanel));
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') closePanel();
    });

    const params = new URLSearchParams(window.location.search);
    if (params.get('open_ai') === '1' && scope) {
        openPanel();
        requestAi();
    }
})();
</script>
