<?php

/**
 * ✅ ファイル名: /kintai/index.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 今回の修正（あなたの要望どおり）
 * - 「AZUREと出ている部分（brandText strong）」をロゴ画像に置き換え
 * - 左の丸枠（mark / markFallback）は完全に消す（枠も影も出さない）
 * - 画像が読めない場合は strong のテキスト（シメナビ）が見える（フォールバック）
 *
 * ✅ 重要
 * - HTML構造は極力そのまま（機能変更なし）
 * - CSSで確実に差し替えが効くように、strong に background-image を設定
 */

declare(strict_types=1);
session_start();

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

// flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** ✅ 申込リンク（無料お試しページ） */
$trialUrl = 'http://bandies.back-room.me/trial.php';
$aiDemoUrl = 'http://bandies.back-room.me/night/ai_demo.php';

// images（night配下のみ参照）
$heroImg = '/night/assets/img/hero.png';
$feature1 = '/night/assets/img/feature1.png';
$feature2 = '/night/assets/img/feature2.png';
$feature3 = '/night/assets/img/feature3.png';

// ✅ ロゴ（night配下）
$logoMain = '/night/assets/img/logo_main_night.png';

// ✅ CSSで background-image に差し込む（キャッシュ回避に ?v= を付与）
$logoMainCssUrl = $logoMain . '?v=' . date('YmdHis');
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>シメナビ｜ナイト店舗のバック管理・締め作業を見える化</title>
    <meta name="description" content="シメナビは、ナイト店舗のバック計算・締め日/日払い・金額共有まで一元化。勤怠/シフトと連携し、ミスや認識ズレを減らす業態向けSaaSです。" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@500;700;800&family=Noto+Sans+JP:wght@400;600;700;800&display=swap"
        rel="stylesheet">

    <style>
    :root {
        /* ===== Theme Gold ===== */
        --navy-0: #b88a1b;
        --navy-1: #d4af37;
        --navy-2: #e1c15a;

        --glow: #d4af37;
        --glow-2: #cfa52d;
        --glow-soft: #fff6dc;
        --glow-strong: rgba(212, 175, 55, .35);
        --glow-ring: rgba(212, 175, 55, .12);
        --glow-ring-strong: rgba(212, 175, 55, .18);
        --glow-outer: rgba(212, 175, 55, .22);
        --glow-outer-strong: rgba(212, 175, 55, .30);

        /* UI */
        --bg: #fffdf6;
        --soft: #fff7e6;
        --ink: #111827;
        --muted: #4b5563;
        --line: #efe2c2;

        --r: 18px;
        --r2: 26px;

        --shadow: 0 22px 60px rgba(20, 40, 80, .10);
        --shadow2: 0 14px 34px rgba(20, 40, 80, .08);
    }

    * {
        box-sizing: border-box;
    }

    html,
    body {
        height: 100%;
    }

    body {
        margin: 0;
        font-family: "Noto Sans JP", system-ui, -apple-system, "Segoe UI", sans-serif;
        color: var(--ink);
        background: linear-gradient(180deg, #fff9ee 0%, #fffdf7 30%, #ffffff 70%);
        -webkit-font-smoothing: antialiased;
        text-rendering: geometricPrecision;
        padding-top: 84px;
    }

    a {
        color: inherit;
        text-decoration: none;
    }

    .wrap {
        max-width: 1140px;
        margin: 0 auto;
        padding: 0 22px;
    }

    /* ===== Header ===== */
    header {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 50;
        background: rgba(255, 255, 255, .92);
        backdrop-filter: blur(16px);
        border-bottom: 1px solid var(--line);
    }

    .headerInner {
        height: 84px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 220px;
    }

    /* ✅ 左の丸枠は完全に消す（枠も影も出さない） */
    .mark,
    .markFallback {
        display: none !important;
    }

    /* ✅ 「シメナビ」ロゴを img で表示 */
    .brandText strong {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 900;
        letter-spacing: .2px;
        font-size: 16px;
        line-height: 1;
    }

    .brandLogoImg {
        display: block;
        height: 38px;
        width: auto;
    }

    .brandName {
        display: none;
    }

    @media (prefers-reduced-data: reduce) {
        .brandLogoImg {
            display: none;
        }
        .brandName {
            display: inline;
            color: #0f172a;
        }
    }

    .brandText span {
        display: block;
        margin-top: 2px;
        font-size: 12px;
        color: var(--muted);
        font-weight: 900;
    }

    .nav {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .nav a {
        font-size: 14px;
        font-weight: 900;
        color: #111827;
        padding: 10px 12px;
        border-radius: 14px;
    }

    .nav a:hover {
        background: rgba(0, 0, 0, .04);
    }

    /* ===== Buttons（大きく・分かりやすく） ===== */
    .btn {
        height: 56px;
        padding: 0 22px;
        border-radius: 999px;
        border: 1px solid rgba(0, 0, 0, .12);
        background: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 16px;
        letter-spacing: .2px;
        cursor: pointer;
        transition: transform .12s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease;
        user-select: none;
        white-space: nowrap;
    }

    .btn:hover {
        transform: translateY(-1px);
    }

    .btnPrimary {
        background: #d4af37;
        color: #fff;
        border: 0;
        box-shadow: 0 12px 26px rgba(212, 175, 55, .30);
    }

    .btnPrimary:hover {
        box-shadow: 0 16px 32px rgba(212, 175, 55, .36);
    }

    .btnGhost {
        background: #fff;
        color: #111827;
        border: 1px solid rgba(0, 0, 0, .14);
    }

    .btnGhost:hover {
        border-color: rgba(212, 175, 55, .45);
        box-shadow: 0 12px 26px rgba(6, 10, 20, .08);
    }

    .btnSm {
        height: 46px;
        padding: 0 16px;
        font-size: 14px;
    }

    .ctaRow {
        display: flex;
        justify-content: center;
        gap: 14px;
        flex-wrap: wrap;
    }

    .heroCta {
        justify-content: center;
    }

    /* ===== Hero（横分割を使わない＝縦積み） ===== */
    .hero {
        padding: 110px 0 84px;
        background:
            radial-gradient(760px 360px at 85% 20%, rgba(212, 175, 55, .12), transparent 62%),
            radial-gradient(700px 300px at 15% 0%, rgba(212, 175, 55, .08), transparent 60%),
            linear-gradient(180deg, #fff7e6 0%, #fffdf7 70%, #ffffff 100%);
        border-bottom: 1px solid var(--line);
    }

    .heroInner {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 20px;
        align-items: center;
        text-align: center;
    }

    .heroLeft {
        max-width: 1040px;
        margin: 0 auto;
        text-align: center;
    }

    .heroNotice {
        grid-column: 1 / -1;
        margin-bottom: 16px;
        background: #ffffff;
        border-radius: 18px;
        padding: 12px 14px;
        border: 1px solid rgba(15, 23, 42, .08);
        font-weight: 900;
    }

    .pillRow {
        display: flex;
        justify-content: flex-start;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .pill {
        padding: 10px 14px;
        border-radius: 999px;
        background: #fff;
        border: 1px solid rgba(212, 175, 55, .24);
        box-shadow: 0 10px 24px rgba(6, 10, 20, .06);
        font-weight: 900;
        font-size: 13px;
        color: #111827;
    }

    h1 {
        margin: 0;
        font-size: 64px;
        line-height: 1.2;
        letter-spacing: -0.6px;
        font-weight: 900;
    }

    .heroLine {
        display: block;
        white-space: nowrap;
    }

    .accent {
        color: var(--glow);
        text-shadow: 0 0 14px rgba(212, 175, 55, .22);
    }

    .lead {
        margin: 16px auto 0;
        max-width: 100%;
        color: var(--muted);
        font-size: 22px;
        line-height: 1.9;
        font-weight: 800;
    }

    .neonLine {
        height: 2px;
        width: 140px;
        margin: 18px 0 0;
        background: linear-gradient(90deg, transparent, var(--glow), transparent);
        filter: drop-shadow(0 0 10px rgba(212, 175, 55, .35));
    }

    .heroCta {
        margin-top: 26px;
        flex-direction: column;
        align-items: stretch;
    }

    .heroCta .btn {
        width: 100%;
    }

    .statsRow {
        margin: 28px 0 0;
        max-width: 980px;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }

    .statCard {
        background: linear-gradient(135deg, #fff7e6, #fffdf7);
        border: 0;
        border-radius: 28px;
        padding: 18px 18px;
        box-shadow: none;
        text-align: left;
    }

    .statNum {
        font-size: 34px;
        font-weight: 900;
        letter-spacing: -0.5px;
        color: #111827;
    }

    .statNum .glow {
        color: var(--glow);
        text-shadow: 0 0 12px rgba(212, 175, 55, .22);
    }

    .statCap {
        margin-top: 6px;
        font-size: 13px;
        font-weight: 900;
        color: var(--muted);
    }

    .heroNote {
        margin-top: 10px;
        color: var(--muted);
        font-weight: 800;
        font-size: 16px;
        text-align: center;
    }

    .imgFallback {
        display: none;
        padding: 18px;
        border-radius: 26px;
        border: 1px dashed rgba(0, 0, 0, .18);
        background: rgba(0, 0, 0, .02);
        color: #6b7280;
        font-weight: 900;
        text-align: center;
    }

    .logosRow {
        margin: 26px auto 0;
        max-width: 980px;
        text-align: left;
    }

    .logosLabel {
        color: var(--muted);
        font-weight: 900;
        font-size: 13px;
        margin-bottom: 10px;
    }

    .logos {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .logoChip {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 44px;
        padding: 10px 14px;
        border-radius: 16px;
        border: 1px solid #ead8a8;
        background: #fff7e6;
    }

    .logoChip img {
        max-height: 22px;
        max-width: 110px;
        width: auto;
        height: auto;
        display: block;
        object-fit: contain;
        filter: grayscale(100%);
        opacity: .85;
    }

    /* ===== Section ===== */
    .section {
        padding: 72px 0;
    }

    .section.soft {
        background: #fff7e6;
        border-top: 1px solid var(--line);
        border-bottom: 1px solid var(--line);
    }

    .kicker {
        display: inline-flex;
        padding: 10px 14px;
        border-radius: 999px;
        font-weight: 900;
        font-size: 13px;
        background: #fff1cc;
        color: #6b5207;
        border: 1px solid #e7cd8c;
    }

    .h2 {
        margin: 14px 0 10px;
        font-size: 34px;
        font-weight: 900;
        letter-spacing: -0.6px;
        line-height: 1.25;
    }

    .p {
        margin: 0;
        color: var(--muted);
        font-weight: 800;
        line-height: 1.9;
        font-size: 16px;
    }

    /* ===== Common Problem ===== */
    .problemBox {
        margin-top: 22px;
        background: linear-gradient(135deg, #fff7e6, #fffdf7);
        border: 0;
        border-radius: 44px;
        box-shadow: none;
        padding: 26px;
    }

    .problemRow {
        display: flex;
        gap: 18px;
        align-items: center;
        justify-content: space-between;
    }

    .bubbles {
        margin-top: 18px;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        flex: 1 1 auto;
    }

    .problemBubbles {
        display: none;
    }

    .bubble {
        background: linear-gradient(135deg, #e4c566, #d4af37);
        border: 1px solid rgba(212, 175, 55, .35);
        color: #111827;
        padding: 16px 16px;
        border-radius: 18px;
        font-weight: 900;
        line-height: 1.45;
        box-shadow:
            0 16px 34px rgba(6, 10, 20, .14),
            0 0 18px rgba(212, 175, 55, .12);
    }

    .problemImg {
        max-width: 420px;
        flex: 0 0 auto;
    }

    .problemImg img {
        width: 100%;
        display: block;
        border-radius: 22px;
    }

    .arrowDown {
        text-align: center;
        margin: 18px 0 8px;
        font-size: 26px;
        font-weight: 900;
        color: var(--glow);
        text-shadow: 0 0 18px rgba(212, 175, 55, .28);
    }

    .triCards {
        margin-top: 12px;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }

    .card {
        background: #fffdf7;
        border-radius: 26px;
        border: 0;
        box-shadow: none;
        overflow: hidden;
    }

    .cardHead {
        padding: 16px 14px;
        background: #d4af37;
        border-bottom: 0;
        color: #111827;
        font-weight: 900;
        font-size: 20px;
        text-align: center;
        text-shadow: none;
    }

    .cardBody {
        padding: 18px;
        text-align: left;
    }

    .cardIcon {
        height: 140px;
        border-radius: 18px;
        background: #fff;
        border: 2px solid #e7cd8c;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        color: #64748b;
        margin-bottom: 12px;
    }

    .cardIcon img {
        max-height: 122px;
        width: auto;
        display: block;
    }

    .cardBody p {
        margin: 0;
        color: #475569;
        font-weight: 800;
        line-height: 1.9;
        font-size: 14px;
    }

    /* ===== Feature ===== */
    .features {
        margin-top: 22px;
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .feature {
        background: linear-gradient(135deg, #fff7e6, #fffdf7);
        border-radius: 44px;
        padding: 28px 30px;
    }

    .featureRow {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 520px;
        gap: 20px;
        align-items: center;
    }

    .featureRow.is-right {
        grid-template-columns: 520px minmax(0, 1fr);
    }

    .featureMedia {
        width: 520px;
        justify-self: end;
    }

    .featureRow.is-right .featureMedia {
        justify-self: start;
    }

    .featureTag {
        display: inline-flex;
        padding: 8px 14px;
        border-radius: 999px;
        background: #d4af37;
        border: 0;
        font-weight: 900;
        font-size: 12px;
        color: #111827;
    }

    .featureTitle {
        margin: 12px 0 10px;
        font-size: 24px;
        font-weight: 900;
        letter-spacing: -0.4px;
        line-height: 1.28;
    }

    .featureDesc {
        margin: 0;
        color: #44546a;
        font-weight: 800;
        line-height: 1.9;
        font-size: 14px;
    }

    .shot {
        background: #ffffff;
        border-radius: 24px;
        padding: 14px;
        width: 100%;
    }

    .shot img {
        width: 100%;
        display: block;
        border-radius: 18px;
    }

    /* remove image-side borders/lines */
    .problemImg,
    .shot {
        border: 0;
        background: transparent;
        box-shadow: none;
    }

    .problemImg img,
    .shot img {
        border: 0;
        box-shadow: none;
        outline: none;
    }

    @media (max-width: 900px) {
        .heroInner {
            grid-template-columns: minmax(0, 1fr);
        }
        .problemRow {
            flex-direction: column;
            align-items: stretch;
        }
        .problemImg {
            max-width: 100%;
        }
        .problemBubbles {
            display: grid;
        }
        .problemRow .problemImg {
            display: none;
        }
        .problemRow .problemImg {
            position: relative;
            left: 50%;
            right: 50%;
            margin-left: -50vw;
            margin-right: -50vw;
            width: 100vw;
            max-width: none;
            overflow: visible;
        }
        .problemImg img {
            border-radius: 0;
            width: 100vw;
            max-width: none;
            margin-left: 0;
        }
        .featureRow,
        .featureRow.is-right {
            grid-template-columns: minmax(0, 1fr);
        }
        .featureMedia {
            width: 100%;
            justify-self: stretch;
        }
        .shot {
            max-width: 100%;
        }
    }

    /* ===== Pricing ===== */
    .pricingGrid {
        margin-top: 22px;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .priceCard {
        background: linear-gradient(135deg, #fff7e6, #fffdf7);
        border-radius: 32px;
        border: 1px solid #ead8a8;
        box-shadow: none;
        padding: 22px;
    }

    .priceTop {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .plan {
        font-weight: 900;
        font-size: 18px;
    }

    .badge {
        padding: 8px 12px;
        border-radius: 999px;
        font-weight: 900;
        font-size: 12px;
        background: #d4af37;
        color: #111827;
        border: 0;
    }

    .price {
        margin-top: 14px;
        font-weight: 900;
        font-size: 40px;
        letter-spacing: -0.8px;
    }

    .price span {
        font-size: 13px;
        color: var(--muted);
        font-weight: 900;
    }

    .ul {
        margin: 14px 0 18px;
        padding-left: 18px;
        color: #0f172a;
        font-weight: 800;
        line-height: 1.9;
    }

    .ul li {
        margin: 8px 0;
    }

    .diff-light {
        color: #0f766e;
    }

    .diff-standard {
        color: #2563eb;
    }

    .diff-pro {
        color: #16a34a;
    }

    .diff-back {
        color: #b45309;
    }

    .diff-head {
        font-size: 16px;
        font-weight: 900;
    }


    /* ===== Contact form ===== */
    .form {
        margin-top: 22px;
        background: linear-gradient(135deg, #fff7e6, #fffdf7);
        border-radius: 32px;
        border: 0;
        box-shadow: none;
        padding: 22px;
    }

    .formGrid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .form label {
        display: block;
        margin: 0 0 8px;
        font-weight: 900;
        font-size: 13px;
        color: #0f172a;
    }

    .form input,
    .form textarea {
        width: 100%;
        border: 1px solid rgba(0, 0, 0, .14);
        border-radius: 18px;
        padding: 14px 14px;
        font-size: 15px;
        font-weight: 700;
        outline: none;
    }

    .form input:focus,
    .form textarea:focus {
        border-color: rgba(212, 175, 55, .55);
        box-shadow: 0 0 0 5px rgba(212, 175, 55, .18);
    }

    .hp {
        position: absolute;
        left: -9999px;
        width: 1px;
        height: 1px;
        opacity: 0;
    }

    .tiny {
        margin-top: 10px;
        color: var(--muted);
        font-weight: 800;
        font-size: 13px;
        line-height: 1.8;
    }

    /* ===== Footer ===== */
    footer {
        padding: 34px 0 22px;
        border-top: 1px solid var(--line);
        background: #fff;
    }

    .footerInner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
    }

    .footerLinks {
        margin-top: 12px;
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        font-weight: 800;
        color: var(--muted);
    }

    .footerLinks a {
        color: var(--muted);
        text-decoration: none;
    }

    .footerLinks a:hover {
        text-decoration: underline;
    }

    .copy {
        margin-top: 10px;
        color: var(--muted);
        font-weight: 800;
        font-size: 12px;
    }

    /* ===== Responsive ===== */
    @media (max-width: 1200px) {
        .pricingGrid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 980px) {
        .nav {
            display: none;
        }

        .heroInner {
            grid-template-columns: 1fr;
            text-align: center;
        }

        .heroLine {
            white-space: normal;
        }

        h1 {
            font-size: 40px;
            line-height: 1.25;
        }

        .statsRow {
            grid-template-columns: 1fr;
        }

        .triCards {
            grid-template-columns: 1fr;
        }

        .pricingGrid {
            grid-template-columns: 1fr;
        }

        .bubbles {
            grid-template-columns: 1fr;
        }

        .formGrid {
            grid-template-columns: 1fr;
        }

        .btn {
            width: 100%;
        }

        .btnSm {
            width: auto;
        }
    }
    </style>
</head>

<body>
    <header>
        <div class="wrap headerInner">
            <a class="brand" href="#top" aria-label="シメナビ">
                <!-- ✅ 左の丸枠は残すがCSSで完全非表示（DOMは触らず機能影響ゼロ） -->
                <span class="mark" aria-hidden="true">
                    <img src="<?= h($logoMain) ?>" alt="シメナビ ロゴ"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" />
                    <span class="markFallback" style="display:none;"></span>
                </span>

                <span class="brandText">
                    <strong>
                        <img class="brandLogoImg" src="<?= h($logoMain) ?>" alt="SHIMENABI">
                        <span class="brandName">SHIMENABI</span>
                    </strong>
                    <span>バック管理 × 締め作業 × 勤怠</span>
                </span>
            </a>

            <nav class="nav" aria-label="サイト内メニュー">
                <a href="#top">ホーム</a>
                <a href="#features">機能</a>
                <a href="<?= h($aiDemoUrl) ?>">AI体験</a>
                <a href="#pricing">料金</a>
                <a href="#contact">お問い合わせ</a>
                <a href="http://bandies.back-room.me/admin/login.php">管理者ログイン</a>
            </nav>

            <a class="btn btnPrimary btnSm" href="<?= h($trialUrl) ?>">無料で試してみる</a>
        </div>
    </header>

    <main id="top">
        <!-- ===== HERO ===== -->
        <section class="hero">
            <div class="wrap heroInner">

                <?php if ($flash): ?>
                <div class="heroNotice">
                    <?= h($flash['message'] ?? '') ?>
                </div>
                <?php endif; ?>

                <div class="heroLeft">
                    <h1>
                        <span class="heroLine">ナイト店舗の<span class="accent">締め作業</span>を</span>
                        <span class="heroLine"><span class="accent">ミスなく、速く</span>する</span>
                    </h1>

                    <div class="neonLine"></div>

                    <p class="lead">
                        バック計算・日払い・金額共有まで<br>
                        これひとつで一括管理。
                    </p>

                    <div class="heroCta ctaRow">
                        <a class="btn btnPrimary" href="<?= h($trialUrl) ?>">無料で始めてみる</a>
                        <a class="btn btnGhost" href="<?= h($aiDemoUrl) ?>">AI体験する</a>
                    </div>
                    <div class="heroNote">無料トライアル実施中・クレジット不要</div>
                </div>

            </div>
        </section>

        <!-- ===== COMMON PROBLEM ===== -->
        <section class="section soft" id="problem">
            <div class="wrap" style="text-align:center;">
                <span class="kicker">Common Problem</span>
                <div class="h2">ナイト店舗のよくある課題を解決</div>
                <p class="p">締め・バック・金額共有のズレを減らします。</p>
                <div class="problemBox" style="text-align:left;">
                    <div class="problemRow">
                        <div class="bubbles problemBubbles">
                            <div class="bubble">バック計算が複雑で毎回ミスが出る</div>
                            <div class="bubble">締め日/日払い対応で集計が崩れる</div>
                            <div class="bubble">キャストとの金額認識ズレで揉める</div>
                            <div class="bubble">役職者によって計算ルールがブレる</div>
                        </div>
                        <div class="problemImg" style="max-width: 100%;">
                            <img src="/night/assets/img/kaiketsu.png" alt="解決イメージ"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" />
                            <div class="imgFallback" style="display:none;">
                                /night/assets/img/kaiketsu.png を配置すると表示されます
                            </div>
                        </div>
                    </div>

                    <div class="arrowDown">↓</div>

                    <div class="triCards">
                        <div class="card">
                            <div class="cardHead">締め作業を解決</div>
                            <div class="cardBody">
                                <div class="cardIcon">
                                    <img src="/night/assets/img/icons/daily.png" alt="日次アイコン" onerror="this.style.display='none'; this.parentNode.textContent='日次アイコン';">
                                </div>
                                <p>締め対象と集計を自動化し、作業の手戻りを減らします。</p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="cardHead">金額共有を解決</div>
                            <div class="cardBody">
                                <div class="cardIcon">
                                    <img src="/night/assets/img/icons/monthly.png" alt="月次アイコン" onerror="this.style.display='none'; this.parentNode.textContent='月次アイコン';">
                                </div>
                                <p>金額の確定/共有を一本化し、認識ズレを防止。</p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="cardHead">ルール統一を解決</div>
                            <div class="cardBody">
                                <div class="cardIcon">
                                    <img src="/night/assets/img/icons/manage.png" alt="管理アイコン" onerror="this.style.display='none'; this.parentNode.textContent='管理アイコン';">
                                </div>
                                <p>役職者ごとの計算差を抑え、現場の混乱を抑制。</p>
                            </div>
                        </div>
                    </div>

                    <div class="ctaRow" style="margin-top:18px;">
                        <a class="btn btnPrimary" href="<?= h($trialUrl) ?>">無料で試す（1分）</a>
                        <a class="btn btnGhost" href="#contact">相談する</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===== FEATURES ===== -->
        <section class="section" id="features">
            <div class="wrap">
                <span class="kicker">Function</span>
                <div class="h2">ナイト業態の締めに効く機能</div>
                <p class="p">現場の締め作業を先に整え、AIで改善を加速します。</p>

                <div class="features">
                    <div class="feature">
                        <div class="featureRow">
                            <div>
                                <span class="featureTag">Feature 1</span>
                                <div class="featureTitle">バック計算・日払いを自動集計</div>
                                <p class="featureDesc">複雑な計算を自動化し、締め作業のミスを減らします。</p>
                            </div>
                            <div class="shot featureMedia">
                                <img src="<?= h($feature1) ?>" alt="機能スクショ 1"
                                    onerror="this.style.display='none'; this.parentNode.style.padding='16px'; this.parentNode.innerHTML='<?= h($feature1) ?> を置くと表示されます（任意）';" />
                            </div>
                        </div>
                    </div>

                    <div class="feature">
                        <div class="featureRow is-right">
                            <div>
                                <span class="featureTag">Feature 2</span>
                                <div class="featureTitle">金額の確定と共有を一元化</div>
                                <p class="featureDesc">キャスト/スタッフへの金額共有を整え、トラブルを抑えます。</p>
                            </div>
                            <div class="shot featureMedia">
                                <img src="<?= h($feature2) ?>" alt="機能スクショ 2"
                                    onerror="this.style.display='none'; this.parentNode.style.padding='16px'; this.parentNode.innerHTML='<?= h($feature2) ?> を置くと表示されます（任意）';" />
                            </div>
                        </div>
                    </div>

                    <div class="feature">
                        <div class="featureRow">
                            <div>
                                <span class="featureTag">Feature 3</span>
                                <div class="featureTitle">AIがミスの原因と対策を提示</div>
                                <p class="featureDesc">締めのズレやルール差を可視化し、改善が速い。</p>
                                <div class="featureCta" style="margin-top:12px;">
                                    <div style="font-size:12px;color:var(--muted);font-weight:800;margin-bottom:6px;">
                                        AIの回答サンプルを体験できます
                                    </div>
                                    <a class="btn btnGhost" href="<?= h($aiDemoUrl) ?>">AI体験を見る</a>
                                </div>
                            </div>
                            <div class="shot featureMedia">
                                <img src="<?= h($feature3) ?>" alt="機能スクショ 3"
                                    onerror="this.style.display='none'; this.parentNode.style.padding='16px'; this.parentNode.innerHTML='<?= h($feature3) ?> を置くと表示されます（任意）';" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ctaRow" style="margin-top:18px;">
                    <a class="btn btnPrimary" href="<?= h($trialUrl) ?>">無料で試す</a>
                    <a class="btn btnGhost" href="#pricing">料金を見る</a>
                </div>
            </div>
        </section>

        <!-- ===== PRICING ===== -->
        <section class="section soft" id="pricing">
            <div class="wrap">
                <span class="kicker">Price</span>
                <div class="h2">料金体系（ナイト対応）</div>
                <p class="p">基本料金 + 従業員数のシンプルな人数課金です。</p>

                <div class="pricingGrid">
                    <div class="priceCard">
                        <div class="priceTop">
                            <div class="plan">基本プラン</div>
                            <div class="badge">人数課金</div>
                        </div>
                        <div class="price">¥3,300 <span>+ ¥400 / 従業員 / 月</span></div>
                        <ul class="ul">
                            <li>日別勤怠（閲覧/修正）</li>
                            <li>打刻編集</li>
                            <li>シフト管理（管理者/作業員閲覧）</li>
                            <li>従業員管理・時給履歴</li>
                            <li>給与明細（PDF出力含む）</li>
                            <li>iPad端末アクティベーション・LINE連携（打刻）</li>
                            <li>ダッシュボードの月/週切替</li>
                            <li>人件費率の色判定（閾値設定）</li>
                            <li>AI改善提案（フル）</li>
                            <li>期間比較や詳細レポート</li>
                        </ul>
                        <a class="btn btnGhost" style="width:100%;" href="<?= h($trialUrl) ?>">無料で試す</a>
                    </div>

                    <div class="priceCard">
                        <div class="priceTop">
                            <div class="plan">バック機能</div>
                            <div class="badge">業態オプション</div>
                        </div>
                        <div class="price">+¥5,500 <span>/ 店舗 / 月</span></div>
                        <ul class="ul">
                            <li class="diff-back">イベント種別の追加・編集（バック項目管理）</li>
                            <li class="diff-back">店舗/日付ごとのバック入力と当日一覧</li>
                            <li class="diff-back">保存（仮）による未確定管理</li>
                            <li class="diff-back">確定/未確定の分離表示と確定処理</li>
                            <li class="diff-back">ボーナス/調整の登録・編集・削除</li>
                            <li class="diff-back">スタッフ用URLの発行（24時間有効）</li>
                            <li class="diff-back">履歴（直近30日/過去1年の月別）</li>
                            <li class="diff-back">確定/未確定の合計・日別内訳を可視化</li>
                            <li class="diff-back">キャストランキング（確定/未確定）</li>
                        </ul>
                        <a class="btn btnGhost" style="width:100%;" href="#contact">相談する</a>
                    </div>
                </div>

                <div class="ctaRow" style="margin-top:18px;">
                    <a class="btn btnPrimary" href="<?= h($trialUrl) ?>">無料で試す</a>
                    <a class="btn btnGhost" href="#contact">導入相談</a>
                </div>
            </div>
        </section>

        <!-- ===== CONTACT ===== -->
        <section class="section" id="contact">
            <div class="wrap">
                <span class="kicker">Contact</span>
                <div class="h2">お問い合わせ</div>
                <p class="p">導入相談・機能質問はこちら。</p>

                <form class="form" action="/send_contact.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="text" name="website" value="" class="hp" tabindex="-1" autocomplete="off"
                        aria-hidden="true">

                    <div class="formGrid">
                        <div>
                            <label>会社名 / 店舗名</label>
                            <input type="text" name="company" placeholder="例：株式会社◯◯ / ◯◯店" required>
                        </div>
                        <div>
                            <label>お名前</label>
                            <input type="text" name="name" placeholder="例：山田 太郎" required>
                        </div>
                        <div>
                            <label>メール</label>
                            <input type="email" name="email" placeholder="example@company.com" required>
                        </div>
                        <div>
                            <label>電話（任意）</label>
                            <input type="tel" name="tel" placeholder="090-xxxx-xxxx">
                        </div>
                    </div>

                    <div style="margin-top:14px;">
                        <label>お問い合わせ内容</label>
                        <textarea name="message" rows="6" placeholder="導入店舗数、iPad/LINE運用、AI改善で見たい内容など"
                            required></textarea>
                    </div>

                    <div class="ctaRow" style="margin-top:16px;justify-content:flex-start;">
                        <button class="btn btnPrimary" type="submit">送信する</button>
                        <a class="btn btnGhost" href="<?= h($trialUrl) ?>">無料で試す</a>
                    </div>

                    <div class="tiny">※ 送信後、運営からご連絡します。</div>
                </form>
            </div>
        </section>
    </main>

    <footer>
        <div class="wrap footerInner">
            <a class="brand" href="#top" aria-label="シメナビ">
                <span class="mark" aria-hidden="true">
                    <img src="<?= h($logoMain) ?>" alt="シメナビ ロゴ"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" />
                    <span class="markFallback" style="display:none;"></span>
                </span>
                <span class="brandText">
                    <strong>シメナビ</strong>
                    <span>ナイト業態向け 勤怠/バック管理SaaS</span>
                </span>
            </a>

            <div class="ctaRow" style="justify-content:flex-end;">
                <a class="btn btnGhost btnSm" href="#top">TOP</a>
                <a class="btn btnPrimary btnSm" href="<?= h($trialUrl) ?>">無料で試す</a>
            </div>
        </div>
        <div class="wrap footerLinks">
            <a href="https://fader.group/" target="_blank" rel="noopener">運営会社</a>
            <a href="http://bandies.back-room.me/terms.php">利用規約</a>
            <a href="http://bandies.back-room.me/privacy.php">個人情報の取り扱い</a>
            <a href="http://bandies.back-room.me/tokusho.php">特定商取引法に基づく表記</a>
        </div>
        <div class="wrap copy">© <?= date('Y') ?> シメナビ. All rights reserved.</div>
    </footer>
</body>

</html>
