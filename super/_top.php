<?php

declare(strict_types=1);

/**
 * ✅ super/_top.php
 *
 * - /a-zure/ 配下でも壊れない base を自動計算
 * - Help KB（tenant/store無し版）へのリンクを追加
 */

require_once __DIR__ . '/_auth.php';
super_session_bootstrap();

/** 現在のディレクトリ（例: /a-zure/super）を返す */
function super_base_path(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/super/index.php'); // 例: /a-zure/super/help_kb_manage.php
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');       // 例: /a-zure/super
    if ($dir === '' || $dir === '.') return '/super';
    return $dir;
}

$base = super_base_path(); // 例: /a-zure/super

?>
<div style="display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;">
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($base . '/tenants.php', ENT_QUOTES, 'UTF-8') ?>">テナント</a>
        <a href="<?= htmlspecialchars($base . '/tenant_admins.php', ENT_QUOTES, 'UTF-8') ?>">管理者</a>
        <a href="<?= htmlspecialchars($base . '/stores.php', ENT_QUOTES, 'UTF-8') ?>">店舗</a>
        <a href="<?= htmlspecialchars($base . '/notices.php', ENT_QUOTES, 'UTF-8') ?>">お知らせ</a>
        <a href="<?= htmlspecialchars($base . '/social_insurance_rates.php', ENT_QUOTES, 'UTF-8') ?>">社会保険マスタ</a>
        <a href="<?= htmlspecialchars($base . '/help.php', ENT_QUOTES, 'UTF-8') ?>">チャット</a>

        <!-- ✅ 既存（tenant/storeあり版） -->
        <!-- <a href="<?= htmlspecialchars($base . '/help_kb.php', ENT_QUOTES, 'UTF-8') ?>">Help KB（表示用）</a> -->

        <?php if (!empty($_SESSION['impersonate_tenant_id'])): ?>
            <span style="padding:4px 8px;border:1px solid #111;border-radius:999px;">
                なりすまし中（tenant_id=<?= (int)$_SESSION['impersonate_tenant_id'] ?>）
            </span>
            <a href="<?= htmlspecialchars($base . '/stop_impersonate.php', ENT_QUOTES, 'UTF-8') ?>">なりすまし解除</a>
        <?php endif; ?>
    </div>

    <div style="display:flex;gap:10px;align-items:center;">
        <a href="<?= htmlspecialchars($base . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">ログアウト</a>
    </div>
</div>
<hr style="border:none;border-top:1px solid #eee;margin:0 0 14px;">
