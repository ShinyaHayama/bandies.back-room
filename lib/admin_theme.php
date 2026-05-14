<?php

declare(strict_types=1);

function admin_theme_ensure_prefs_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_user_prefs (
            tenant_admin_user_id INT NOT NULL,
            pref_key VARCHAR(64) NOT NULL,
            pref_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tenant_admin_user_id, pref_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function admin_theme_pref(PDO $pdo, int $adminUserId, string $key, string $default): string
{
    if ($adminUserId <= 0) return $default;

    try {
        admin_theme_ensure_prefs_table($pdo);
        $st = $pdo->prepare("
            SELECT pref_value
            FROM admin_user_prefs
            WHERE tenant_admin_user_id = :uid AND pref_key = :pref_key
            LIMIT 1
        ");
        $st->execute([':uid' => $adminUserId, ':pref_key' => $key]);
        $value = $st->fetchColumn();
        return ($value === false || $value === null) ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function admin_theme_set_pref(PDO $pdo, int $adminUserId, string $key, string $value): void
{
    admin_theme_ensure_prefs_table($pdo);
    $st = $pdo->prepare("
        INSERT INTO admin_user_prefs (tenant_admin_user_id, pref_key, pref_value)
        VALUES (:uid, :pref_key, :pref_value)
        ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([':uid' => $adminUserId, ':pref_key' => $key, ':pref_value' => $value]);
}

function admin_theme_modes(): array
{
    return [
        'dark' => 'Dark',
        'light' => 'Light',
    ];
}

function admin_theme_accents(): array
{
    return [
        'red' => ['label' => 'Red', 'main' => '#dc2626', 'main2' => '#f43f5e', 'chart' => '#fb7185'],
        'pink' => ['label' => 'Pink', 'main' => '#db2777', 'main2' => '#ec4899', 'chart' => '#f472b6'],
        'orange' => ['label' => 'Orange', 'main' => '#ea580c', 'main2' => '#f59e0b', 'chart' => '#fb923c'],
        'green' => ['label' => 'Green', 'main' => '#059669', 'main2' => '#10b981', 'chart' => '#34d399'],
        'cyan' => ['label' => 'Cyan', 'main' => '#0891b2', 'main2' => '#06b6d4', 'chart' => '#22d3ee'],
        'blue' => ['label' => 'Blue', 'main' => '#2563eb', 'main2' => '#0ea5e9', 'chart' => '#38bdf8'],
        'purple' => ['label' => 'Purple', 'main' => '#7c3aed', 'main2' => '#9333ea', 'chart' => '#a855f7'],
        'gold' => ['label' => 'Gold', 'main' => '#c99700', 'main2' => '#ffe58a', 'chart' => '#f4c430'],
        'silver' => ['label' => 'Silver', 'main' => '#94a3b8', 'main2' => '#f8fafc', 'chart' => '#e2e8f0'],
    ];
}

function admin_theme_normalize_mode(string $mode): string
{
    return array_key_exists($mode, admin_theme_modes()) ? $mode : 'dark';
}

function admin_theme_normalize_accent(string $accent): string
{
    return array_key_exists($accent, admin_theme_accents()) ? $accent : 'purple';
}

function admin_theme_current(PDO $pdo, int $adminUserId): array
{
    $mode = admin_theme_normalize_mode(admin_theme_pref($pdo, $adminUserId, 'ui_theme_mode', 'dark'));
    $accent = admin_theme_normalize_accent(admin_theme_pref($pdo, $adminUserId, 'ui_theme_accent', 'purple'));
    return ['mode' => $mode, 'accent' => $accent];
}

function admin_theme_body_class(array $theme): string
{
    $mode = admin_theme_normalize_mode((string)($theme['mode'] ?? 'dark'));
    $accent = admin_theme_normalize_accent((string)($theme['accent'] ?? 'purple'));
    $classes = ['adminTheme', 'themeAccent' . ucfirst($accent)];
    if ($mode === 'dark') {
        $classes[] = 'adminHomeDark';
    }
    return implode(' ', $classes);
}

function admin_theme_accent_css(): string
{
    $accents = admin_theme_accents();
    $meta = [
        'red' => ['glow' => 'rgba(220,38,38,.34)', 'soft' => 'rgba(220,38,38,.12)', 'border' => 'rgba(248,113,113,.34)'],
        'pink' => ['glow' => 'rgba(219,39,119,.34)', 'soft' => 'rgba(219,39,119,.12)', 'border' => 'rgba(244,114,182,.34)'],
        'orange' => ['glow' => 'rgba(234,88,12,.34)', 'soft' => 'rgba(234,88,12,.12)', 'border' => 'rgba(251,146,60,.34)'],
        'green' => ['glow' => 'rgba(5,150,105,.34)', 'soft' => 'rgba(5,150,105,.12)', 'border' => 'rgba(52,211,153,.34)'],
        'cyan' => ['glow' => 'rgba(8,145,178,.34)', 'soft' => 'rgba(8,145,178,.12)', 'border' => 'rgba(34,211,238,.34)'],
        'blue' => ['glow' => 'rgba(37,99,235,.34)', 'soft' => 'rgba(37,99,235,.12)', 'border' => 'rgba(96,165,250,.34)'],
        'purple' => ['glow' => 'rgba(124,58,237,.34)', 'soft' => 'rgba(124,58,237,.12)', 'border' => 'rgba(167,139,250,.34)'],
        'gold' => ['glow' => 'rgba(255,213,79,.48)', 'soft' => 'rgba(244,196,48,.18)', 'border' => 'rgba(255,229,138,.56)'],
        'silver' => ['glow' => 'rgba(226,232,240,.36)', 'soft' => 'rgba(226,232,240,.12)', 'border' => 'rgba(248,250,252,.40)'],
    ];
    $css = '';
    foreach ($accents as $key => $accent) {
        $name = ucfirst($key);
        $m = $meta[$key] ?? ['glow' => 'rgba(124,58,237,.34)', 'soft' => 'rgba(124,58,237,.12)', 'border' => 'rgba(167,139,250,.34)'];
        $css .= '.adminTheme.themeAccent' . $name . '{--accent:' . $accent['main'] . ';--accent2:' . $accent['main2'] . ';--accentGlow:' . $m['glow'] . ';--accentSoft:' . $m['soft'] . ';--accentBorder:' . $m['border'] . ';}' . "\n";
    }
    return $css;
}

function admin_theme_global_css(): string
{
    $css = admin_theme_accent_css();
    $css .= <<<'CSS'
.adminTheme {
    --theme-bg: #ffffff;
    --theme-surface: #ffffff;
    --theme-surface2: #f8fafc;
    --theme-text: #111827;
    --theme-muted: #6b7280;
    --theme-line: #e5e7eb;
    --blue: var(--accent);
    --violet: var(--accent);
    --black: var(--accent);
    color-scheme: light;
}
.adminTheme.adminHomeDark {
    --theme-bg: #080b12;
    --theme-surface: rgba(15, 20, 31, .92);
    --theme-surface2: rgba(20, 27, 43, .96);
    --theme-text: #e5e7eb;
    --theme-muted: #94a3b8;
    --theme-line: rgba(148, 163, 184, .18);
    color-scheme: dark;
}
body.adminTheme {
    background: var(--theme-bg) !important;
    color: var(--theme-text);
}
body.adminTheme .azNav a.is-active,
body.adminTheme .tabBtn.isActive,
body.adminTheme .tabBtn[aria-selected="true"],
body.adminTheme .btn.primary,
body.adminTheme button.primary,
body.adminTheme .actionBtn,
body.adminTheme .aiBtn,
body.adminTheme .aiAskSend {
    background: linear-gradient(135deg, var(--accent), var(--accent2)) !important;
    border-color: var(--accentBorder) !important;
    color: #fff !important;
    box-shadow: 0 10px 24px var(--accentSoft);
}
body.adminTheme a:not(.azLogo):not(.azNav a):not(.tabBtn):not(.btn):not(.azLogout):not(.azUserDropdown a) {
    color: var(--accent);
}
body.adminTheme .azNav a.is-active::after {
    background: var(--accent2) !important;
}
body.adminTheme .azNoticeBadge {
    background: var(--accent) !important;
}
body.adminTheme.adminHomeDark .azHd {
    background: rgba(8, 11, 18, .88) !important;
    border-bottom-color: var(--theme-line) !important;
    backdrop-filter: blur(16px);
}
body.adminTheme.adminHomeDark .azVLine,
body.adminTheme.adminHomeDark .azNav .azVLine {
    background: var(--theme-line) !important;
}
body.adminTheme.adminHomeDark .azLogo,
body.adminTheme.adminHomeDark .azNav a,
body.adminTheme.adminHomeDark .azNoticeBtn,
body.adminTheme.adminHomeDark .azMenuBtn,
body.adminTheme.adminHomeDark .azStore,
body.adminTheme.adminHomeDark .azUserBtn,
body.adminTheme.adminHomeDark .azUserBtn .azUserEmail {
    color: var(--theme-text) !important;
}
body.adminTheme.adminHomeDark .azNav,
body.adminTheme.adminHomeDark .azNav a,
body.adminTheme.adminHomeDark .azNoticeBtn,
body.adminTheme.adminHomeDark .azUserBtn {
    background: transparent !important;
}
body.adminTheme.adminHomeDark .azNav a:hover,
body.adminTheme.adminHomeDark .azNoticeBtn:hover,
body.adminTheme.adminHomeDark .azUserBtn:hover {
    background: rgba(148, 163, 184, .10) !important;
}
body.adminTheme.adminHomeDark .azStore select,
body.adminTheme.adminHomeDark .azUserDropdown,
body.adminTheme.adminHomeDark .azNoticeDropdown,
body.adminTheme.adminHomeDark input,
body.adminTheme.adminHomeDark select,
body.adminTheme.adminHomeDark textarea,
body.adminTheme.adminHomeDark .tabBtn,
body.adminTheme.adminHomeDark .btn,
body.adminTheme.adminHomeDark .pill,
body.adminTheme.adminHomeDark .chartToggle,
body.adminTheme.adminHomeDark .editLink {
    background: var(--theme-surface) !important;
    border-color: var(--theme-line) !important;
    color: var(--theme-text) !important;
}
body.adminTheme.adminHomeDark .card,
body.adminTheme.adminHomeDark .box,
body.adminTheme.adminHomeDark .panel,
body.adminTheme.adminHomeDark .tabWrap,
body.adminTheme.adminHomeDark .notice,
body.adminTheme.adminHomeDark .summary,
body.adminTheme.adminHomeDark .empty,
body.adminTheme.adminHomeDark .receiptPanel,
body.adminTheme.adminHomeDark .azNoticeItem {
    background: linear-gradient(180deg, rgba(18, 24, 38, .92), rgba(11, 17, 29, .92)) !important;
    border-color: var(--theme-line) !important;
    color: var(--theme-text) !important;
}
body.adminTheme.adminHomeDark table,
body.adminTheme.adminHomeDark .table {
    color: var(--theme-text) !important;
}
body.adminTheme.adminHomeDark th,
body.adminTheme.adminHomeDark .table thead,
body.adminTheme.adminHomeDark .table thead th {
    background: var(--theme-surface2) !important;
    border-color: var(--theme-line) !important;
    color: var(--theme-text) !important;
}
body.adminTheme.adminHomeDark td,
body.adminTheme.adminHomeDark .table td,
body.adminTheme.adminHomeDark .rowForm,
body.adminTheme.adminHomeDark .monthlyRow {
    border-color: var(--theme-line) !important;
    color: var(--theme-text) !important;
}
body.adminTheme.adminHomeDark .tpDaily tbody td,
body.adminTheme.adminHomeDark .tpDaily .timeCell,
body.adminTheme.adminHomeDark .tpDaily .punchTimeCell,
body.adminTheme.adminHomeDark .tpDaily .timeWithSourceLabel,
body.adminTheme.adminHomeDark .tpDaily .wk,
body.adminTheme.adminHomeDark .tpDaily .dateLine,
body.adminTheme.adminHomeDark .tpDaily .dateLine > span:first-child {
    color: #f8fafc !important;
}
body.adminTheme.adminHomeDark .tpDaily table,
body.adminTheme.adminHomeDark .tpDaily tbody,
body.adminTheme.adminHomeDark .tpDaily tbody tr {
    background: rgba(11, 17, 29, .96) !important;
}
body.adminTheme.adminHomeDark .tpDaily tbody tr:nth-child(odd) {
    background: rgba(15, 23, 42, .96) !important;
}
body.adminTheme.adminHomeDark .tpDaily tbody tr:nth-child(even) {
    background: rgba(12, 18, 30, .96) !important;
}
body.adminTheme.adminHomeDark .tpDaily tbody tr:hover {
    background: rgba(30, 41, 59, .96) !important;
}
body.adminTheme.adminHomeDark .tpDaily .tpRowWarn {
    background: rgba(127, 29, 29, .38) !important;
}
body.adminTheme.adminHomeDark .tpDaily .tpRowUnconfirmed {
    background: rgba(120, 53, 15, .38) !important;
}
body.adminTheme.adminHomeDark .tpDaily .tpRowPaid {
    background: rgba(30, 41, 59, .78) !important;
}
body.adminTheme.adminHomeDark .tpDaily .tpRowCanceled {
    background: rgba(127, 29, 29, .44) !important;
}
body.adminTheme.adminHomeDark .tpDaily tbody td,
body.adminTheme.adminHomeDark .tpDaily thead th,
body.adminTheme.adminHomeDark .tpDaily .tableWrap table th:nth-child(5),
body.adminTheme.adminHomeDark .tpDaily .tableWrap table td:nth-child(5),
body.adminTheme.adminHomeDark .tpDaily .tableWrap table th:nth-child(10),
body.adminTheme.adminHomeDark .tpDaily .tableWrap table td:nth-child(10),
body.adminTheme.adminHomeDark .tpDaily .tableWrap table th:nth-child(11),
body.adminTheme.adminHomeDark .tpDaily .tableWrap table td:nth-child(11),
body.adminTheme.adminHomeDark .tpDaily .tableWrap table th:nth-child(12),
body.adminTheme.adminHomeDark .tpDaily .tableWrap table td:nth-child(12) {
    border-color: rgba(148, 163, 184, .26) !important;
}
body.adminTheme.adminHomeDark.shiftPage {
    --bg: #080b12;
    --card: rgba(15, 20, 31, .92);
    --border: rgba(148, 163, 184, .22);
    --text: #e5e7eb;
    --muted: #94a3b8;
    --sunBg: rgba(127, 29, 29, .24);
    --sunBorder: rgba(248, 113, 113, .28);
    --sunText: #fb7185;
    --satBg: rgba(30, 64, 175, .22);
    --satBorder: rgba(96, 165, 250, .26);
    --satText: #93c5fd;
    --selectedBlue: rgba(37, 99, 235, .20);
    --selectedBlueBorder: rgba(96, 165, 250, .36);
    --dropOk: rgba(5, 150, 105, .22);
    --dropOkBorder: rgba(52, 211, 153, .46);
    --dropNg: rgba(127, 29, 29, .34);
    --dropNgBorder: rgba(248, 113, 113, .44);
}
body.adminTheme.adminHomeDark.shiftPage .page,
body.adminTheme.adminHomeDark.shiftPage .shiftNavTabsHost {
    background: #080b12 !important;
}
body.adminTheme.adminHomeDark.shiftPage .card,
body.adminTheme.adminHomeDark.shiftPage .subCard,
body.adminTheme.adminHomeDark.shiftPage .tableWrap,
body.adminTheme.adminHomeDark.shiftPage .timelinePanel,
body.adminTheme.adminHomeDark.shiftPage .modal,
body.adminTheme.adminHomeDark.shiftPage .dayListBox {
    background: linear-gradient(180deg, rgba(18, 24, 38, .94), rgba(11, 17, 29, .94)) !important;
    border-color: var(--border) !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.shiftPage .topBand,
body.adminTheme.adminHomeDark.shiftPage .timelineHead,
body.adminTheme.adminHomeDark.shiftPage .timelineScale,
body.adminTheme.adminHomeDark.shiftPage .timelineNameHead,
body.adminTheme.adminHomeDark.shiftPage .timelineName,
body.adminTheme.adminHomeDark.shiftPage .shiftTable thead th,
body.adminTheme.adminHomeDark.shiftPage .shiftTable th:first-child,
body.adminTheme.adminHomeDark.shiftPage .shiftTable td:first-child,
body.adminTheme.adminHomeDark.shiftPage .pager .pill,
body.adminTheme.adminHomeDark.shiftPage .metaPill {
    background: rgba(15, 23, 42, .98) !important;
    border-color: var(--border) !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.shiftPage .shiftTable td,
body.adminTheme.adminHomeDark.shiftPage .timelineRow,
body.adminTheme.adminHomeDark.shiftPage .timelineLane {
    background-color: rgba(11, 17, 29, .96) !important;
    border-color: var(--border) !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.shiftPage .timelineTicks,
body.adminTheme.adminHomeDark.shiftPage .timelineLane {
    background-image: repeating-linear-gradient(to right, rgba(148, 163, 184, .22) 0, rgba(148, 163, 184, .22) 1px, transparent 1px, transparent var(--timelineHourWidth, 80px)) !important;
}
body.adminTheme.adminHomeDark.shiftPage .timeBox,
body.adminTheme.adminHomeDark.shiftPage .moreBadge,
body.adminTheme.adminHomeDark.shiftPage .attLabel,
body.adminTheme.adminHomeDark.shiftPage .dayRow,
body.adminTheme.adminHomeDark.shiftPage .headCount {
    background: rgba(15, 20, 31, .92) !important;
    border-color: var(--border) !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.shiftPage .shiftTable th.sunCol,
body.adminTheme.adminHomeDark.shiftPage .shiftTable td.sunCol {
    background: var(--sunBg) !important;
}
body.adminTheme.adminHomeDark.shiftPage .shiftTable th.satCol,
body.adminTheme.adminHomeDark.shiftPage .shiftTable td.satCol {
    background: var(--satBg) !important;
}
body.adminTheme.adminHomeDark.shiftPage .shiftTable thead th .d1,
body.adminTheme.adminHomeDark.shiftPage .timelineTitle,
body.adminTheme.adminHomeDark.shiftPage .modalTitle,
body.adminTheme.adminHomeDark.shiftPage .dayListTitle,
body.adminTheme.adminHomeDark.shiftPage .dayRowMain,
body.adminTheme.adminHomeDark.shiftPage .timeRow .t,
body.adminTheme.adminHomeDark.shiftPage .empName,
body.adminTheme.adminHomeDark.shiftPage .toggleRow .label,
body.adminTheme.adminHomeDark.shiftPage .subCard summary {
    color: #f8fafc !important;
}
body.adminTheme.adminHomeDark.shiftPage .shiftTable thead th .d2,
body.adminTheme.adminHomeDark.shiftPage .timelineMeta,
body.adminTheme.adminHomeDark.shiftPage .timelineTick,
body.adminTheme.adminHomeDark.shiftPage .empMeta,
body.adminTheme.adminHomeDark.shiftPage .timeRow .m,
body.adminTheme.adminHomeDark.shiftPage .dayRowSub {
    color: var(--muted) !important;
}
body.adminTheme.adminHomeDark.shiftPage .timelineBar {
    background: linear-gradient(135deg, rgba(5, 150, 105, .28), rgba(16, 185, 129, .20)) !important;
    border-color: rgba(52, 211, 153, .38) !important;
    color: #d1fae5 !important;
}
body.adminTheme.adminHomeDark.shiftPage .cell-warn {
    background: rgba(127, 29, 29, .38) !important;
}
body.adminTheme.adminHomeDark.shiftPage .todayCol {
    box-shadow: inset 0 0 0 2px var(--accentBorder), 0 0 0 1px var(--accentSoft) !important;
}
body.adminTheme.adminHomeDark.backEventsPage {
    --bg: #080b12;
    --card: rgba(15, 20, 31, .92);
    --text: #e5e7eb;
    --muted: #94a3b8;
    --border: rgba(148, 163, 184, .22);
    --border2: rgba(148, 163, 184, .14);
    --shadow: 0 16px 36px rgba(0, 0, 0, .28);
    --blueBg: rgba(37, 99, 235, .12);
    --blueBd: rgba(96, 165, 250, .24);
    --orangeBg: rgba(120, 53, 15, .18);
    --orangeBd: rgba(251, 146, 60, .28);
    --warnBg: rgba(120, 53, 15, .28);
    --warnBd: rgba(251, 191, 36, .32);
    --warnTx: #fde68a;
    --dangerBg: rgba(127, 29, 29, .34);
    --dangerBd: rgba(248, 113, 113, .34);
    --dangerTx: #fecdd3;
    background: #080b12 !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.backEventsPage .pageWrap,
body.adminTheme.adminHomeDark.backEventsPage .layoutGrid,
body.adminTheme.adminHomeDark.backEventsPage .leftBigBox {
    background: #080b12 !important;
}
body.adminTheme.adminHomeDark.backEventsPage .tabsBar {
    background: rgba(8, 11, 18, .86) !important;
    border-color: var(--border) !important;
}
body.adminTheme.adminHomeDark.backEventsPage .card,
body.adminTheme.adminHomeDark.backEventsPage .rightPanel,
body.adminTheme.adminHomeDark.backEventsPage details.histBox,
body.adminTheme.adminHomeDark.backEventsPage .histTotalCard,
body.adminTheme.adminHomeDark.backEventsPage .draftBox,
body.adminTheme.adminHomeDark.backEventsPage .sheet,
body.adminTheme.adminHomeDark.backEventsPage .helpPopup {
    background: linear-gradient(180deg, rgba(18, 24, 38, .94), rgba(11, 17, 29, .94)) !important;
    border-color: var(--border) !important;
    color: var(--text) !important;
    box-shadow: var(--shadow) !important;
}
body.adminTheme.adminHomeDark.backEventsPage .topBadges,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapConfirmed,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapDraft,
body.adminTheme.adminHomeDark.backEventsPage .histTopBar,
body.adminTheme.adminHomeDark.backEventsPage .histModePill,
body.adminTheme.adminHomeDark.backEventsPage .sheetHeader,
body.adminTheme.adminHomeDark.backEventsPage .sheetFooter {
    background: rgba(15, 23, 42, .86) !important;
    border-color: var(--border) !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.backEventsPage table,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapConfirmed table,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapDraft table,
body.adminTheme.adminHomeDark.backEventsPage .rankTable,
body.adminTheme.adminHomeDark.backEventsPage .histDailyTable {
    background: rgba(11, 17, 29, .96) !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.backEventsPage table thead th,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapConfirmed thead th,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapDraft thead th {
    background: rgba(15, 23, 42, .98) !important;
    border-color: var(--border) !important;
    color: #f8fafc !important;
}
body.adminTheme.adminHomeDark.backEventsPage th,
body.adminTheme.adminHomeDark.backEventsPage td,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapConfirmed th,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapDraft th,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapConfirmed td,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapDraft td {
    border-color: var(--border2) !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.backEventsPage table tbody tr:nth-child(odd),
body.adminTheme.adminHomeDark.backEventsPage .tableWrapConfirmed tbody tr:nth-child(even),
body.adminTheme.adminHomeDark.backEventsPage .tableWrapDraft tbody tr:nth-child(even) {
    background: rgba(15, 23, 42, .72) !important;
}
body.adminTheme.adminHomeDark.backEventsPage table tbody tr:hover,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapConfirmed tbody tr:hover,
body.adminTheme.adminHomeDark.backEventsPage .tableWrapDraft tbody tr:hover {
    background: rgba(30, 41, 59, .82) !important;
}
body.adminTheme.adminHomeDark.backEventsPage .tabBtn,
body.adminTheme.adminHomeDark.backEventsPage .btn,
body.adminTheme.adminHomeDark.backEventsPage .badge,
body.adminTheme.adminHomeDark.backEventsPage .select,
body.adminTheme.adminHomeDark.backEventsPage .input,
body.adminTheme.adminHomeDark.backEventsPage .numBtn {
    background: rgba(15, 20, 31, .92) !important;
    border-color: var(--border) !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.backEventsPage .tabBtnActive,
body.adminTheme.adminHomeDark.backEventsPage .btnPrimary,
body.adminTheme.adminHomeDark.backEventsPage .topRight .btnCompact,
body.adminTheme.adminHomeDark.backEventsPage .bigBtns button:not(.btnOther) {
    background: linear-gradient(135deg, var(--accent), var(--accent2)) !important;
    border-color: var(--accentBorder) !important;
    color: #fff !important;
}
body.adminTheme.adminHomeDark.backEventsPage .tabBtn small,
body.adminTheme.adminHomeDark.backEventsPage .tabBtnActive small {
    color: inherit !important;
}
body.adminTheme.adminHomeDark.backEventsPage .bigBtns button.btnOther {
    background: rgba(15, 20, 31, .92) !important;
    border-color: var(--accentBorder) !important;
    color: var(--text) !important;
}
body.adminTheme.adminHomeDark.backEventsPage .stepTitle,
body.adminTheme.adminHomeDark.backEventsPage .sectionTitle,
body.adminTheme.adminHomeDark.backEventsPage .castCell,
body.adminTheme.adminHomeDark.backEventsPage .histTotalCard .v,
body.adminTheme.adminHomeDark.backEventsPage details.histBox > summary {
    color: #f8fafc !important;
}
body.adminTheme.adminHomeDark.backEventsPage .small,
body.adminTheme.adminHomeDark.backEventsPage .stepSub,
body.adminTheme.adminHomeDark.backEventsPage .topNote,
body.adminTheme.adminHomeDark.backEventsPage .memoCell,
body.adminTheme.adminHomeDark.backEventsPage .histMeta,
body.adminTheme.adminHomeDark.backEventsPage .histTotalCard .k,
body.adminTheme.adminHomeDark.backEventsPage .histTotalCard .sub {
    color: var(--muted) !important;
}
body.adminTheme.adminHomeDark.backEventsPage .eventLabel.nomination {
    background: rgba(180, 83, 9, .18) !important;
    border-color: rgba(251, 146, 60, .34) !important;
    color: #fdba74 !important;
}
body.adminTheme.adminHomeDark.backEventsPage .eventLabel.drink_back {
    background: rgba(28, 126, 214, .18) !important;
    border-color: rgba(96, 165, 250, .34) !important;
    color: #93c5fd !important;
}
body.adminTheme.adminHomeDark.backEventsPage .eventLabel.escort {
    background: rgba(95, 61, 196, .20) !important;
    border-color: rgba(167, 139, 250, .34) !important;
    color: #c4b5fd !important;
}
body.adminTheme.adminHomeDark.backEventsPage .rankHero {
    border-color: var(--accentBorder) !important;
    box-shadow: 0 20px 54px var(--accentSoft) !important;
}
body.adminTheme.adminHomeDark.backEventsPage tr[style*="background:#fff1f2"] {
    background: rgba(127, 29, 29, .34) !important;
}
body.adminTheme.adminHomeDark.backEventsPage td[style*="color:#9f1239"] {
    color: #fecdd3 !important;
}
body.adminTheme.adminHomeDark[data-mode="settings"] .page,
body.adminTheme.adminHomeDark[data-mode="settings"] .wrap {
    background: #080b12 !important;
}
body.adminTheme.adminHomeDark[data-mode="settings"] li.item,
body.adminTheme.adminHomeDark[data-mode="settings"] .fieldGrid,
body.adminTheme.adminHomeDark[data-mode="settings"] .listWrap,
body.adminTheme.adminHomeDark[data-mode="settings"] .handle,
body.adminTheme.adminHomeDark[data-mode="settings"] .footerCopy {
    background: rgba(15, 20, 31, .92) !important;
    border-color: var(--theme-line) !important;
    color: var(--theme-text) !important;
}
body.adminTheme.adminHomeDark[data-mode="settings"] li.item.inactive {
    background: rgba(15, 20, 31, .58) !important;
    opacity: .72;
}
body.adminTheme.adminHomeDark[data-mode="settings"] .nameLine,
body.adminTheme.adminHomeDark[data-mode="settings"] .pinMasked {
    color: #f8fafc !important;
}
body.adminTheme.adminHomeDark[data-mode="settings"] .tagLine,
body.adminTheme.adminHomeDark[data-mode="settings"] .hint,
body.adminTheme.adminHomeDark[data-mode="settings"] .footerCopy {
    color: var(--theme-muted) !important;
}
body.adminTheme.adminHomeDark[data-mode="settings"] button[style*="#4BC251"] {
    background: #4BC251 !important;
    border-color: #4BC251 !important;
    color: #fff !important;
    box-shadow: 0 10px 24px rgba(75, 194, 81, .18) !important;
}
body.adminTheme.adminHomeDark[data-mode="settings"] button[style*="#92A8D0"] {
    background: #92A8D0 !important;
    border-color: #92A8D0 !important;
    color: #fff !important;
    box-shadow: 0 10px 24px rgba(146, 168, 208, .20) !important;
}
body.adminTheme.adminHomeDark h1,
body.adminTheme.adminHomeDark h2,
body.adminTheme.adminHomeDark h3,
body.adminTheme.adminHomeDark .settingTitle,
body.adminTheme.adminHomeDark .azNoticeTitle,
body.adminTheme.adminHomeDark .azNoticeHead strong,
body.adminTheme.adminHomeDark .azUserDropdown a {
    color: #f8fafc !important;
}
body.adminTheme.adminHomeDark .muted,
body.adminTheme.adminHomeDark .label,
body.adminTheme.adminHomeDark .settingDesc,
body.adminTheme.adminHomeDark .azNoticeBody,
body.adminTheme.adminHomeDark .azNoticeDate,
body.adminTheme.adminHomeDark .azNoticeEmpty {
    color: var(--theme-muted) !important;
}
body.adminTheme.adminHomeDark input::placeholder,
body.adminTheme.adminHomeDark textarea::placeholder {
    color: rgba(203, 213, 225, .50) !important;
}
body.adminTheme.adminHomeDark .tabBtn:not(.isActive):not([aria-selected="true"]):hover,
body.adminTheme.adminHomeDark .btn:not(.primary):hover,
body.adminTheme.adminHomeDark .editLink:hover {
    background: var(--accentSoft) !important;
    border-color: var(--accentBorder) !important;
}
@media print {
    body.adminTheme {
        background: #fff !important;
        color: #111827 !important;
    }
}
CSS;
    return $css;
}

function admin_theme_chart_palette(string $accent): array
{
    $accents = admin_theme_accents();
    $accent = admin_theme_normalize_accent($accent);
    $chart = (string)$accents[$accent]['chart'];
    $mutedMap = [
        'orange' => '#FDBA74',
        'blue' => '#93C5FD',
        'green' => '#6EE7B7',
        'pink' => '#F9A8D4',
        'gold' => '#E7D8A5',
        'silver' => '#F8FAFC',
        'red' => '#FCA5A5',
        'cyan' => '#67E8F9',
        'purple' => '#A855F7',
    ];

    return [
        'accent' => $chart,
        'sales' => $chart,
        'labor' => '#FB7185',
        'muted' => $mutedMap[$accent] ?? '#A855F7',
        'warn' => '#FACC15',
        'danger' => '#FB4D6D',
    ];
}
