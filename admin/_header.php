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

.azAiAskCard {
    display: none;
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
    margin-top: 10px;
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

            <a class="azAiAskCard" data-ai-ask-launch="1"
                href="/admin/index.php?store_id=<?= (int)$storeId ?>&open_ai=1">
                <span class="azAiAskTitle">AIに質問する</span>
                <span class="azAiAskText">データから改善提案を受け取る</span>
                <span class="azAiAskIcon" aria-hidden="true">AI</span>
            </a>
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
                    <a href="/admin/color_settings.php">色変更</a>
                    <a href="/admin/manual.php">取扱説明書</a>
                    <a class="is-danger" href="/admin/logout.php">ログアウト</a>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="azNavOverlay" id="azNavOverlay" aria-hidden="true"></div>

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
    if (!actions) return;

    const placeActions = () => {
        const isMobile = window.matchMedia('(max-width: 900px)').matches;
        const target = isMobile
            ? document.getElementById('azNav')
            : document.querySelector('.azAiAskCard');

        if (!target) return;

        if (isMobile) {
            if (!target.contains(actions)) target.appendChild(actions);
            return;
        }

        if (target.nextElementSibling !== actions) {
            target.insertAdjacentElement('afterend', actions);
        }
    };

    placeActions();
    window.addEventListener('resize', placeActions);
})();

(function() {
    const launch = document.querySelector('[data-ai-ask-launch]');
    if (!launch) return;

    launch.addEventListener('click', (ev) => {
        if (!document.body.classList.contains('adminHomeDashboard')) return;
        if (typeof window.openAiConsultation !== 'function') return;
        ev.preventDefault();
        window.openAiConsultation();
    });
})();
</script>
