<?php
declare(strict_types=1);
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SHIMENABI 利用規約</title>
    <style>
        :root {
            --bg: #fafafe;
            --text: #111;
            --muted: #667085;
            --line: #e5e7eb;
            --accent: #ee950b;
            --card: #ffffff;
        }

        body {
            margin: 0;
            font-family: system-ui;
            background: var(--bg);
            color: var(--text);
        }

        .wrap {
            max-width: 920px;
            margin: 0 auto;
            padding: 28px 20px 56px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text);
            font-weight: 600;
        }

        .logo img {
            width: 36px;
            height: auto;
            display: block;
        }

        .title {
            font-size: 28px;
            font-weight: 650;
            margin: 0 0 6px;
        }

        .sub {
            color: var(--muted);
            font-size: 13px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 6px 16px rgba(17, 24, 39, 0.06);
        }

        .section {
            margin-top: 18px;
        }

        .section h2 {
            font-size: 16px;
            margin: 0 0 8px;
        }

        .section p,
        .section ul {
            margin: 0;
            color: var(--text);
            line-height: 1.7;
            font-size: 14px;
        }

        .section ul {
            padding-left: 18px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #6b4c00;
            background: #fff4dd;
            border: 1px solid #f3d39a;
            border-radius: 999px;
            padding: 6px 10px;
        }

        .link {
            color: var(--accent);
            text-decoration: none;
        }

        .link:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="topbar">
            <a class="logo" href="/">
                <img src="/images/logo_main.png" alt="SHIMENABI">
                <span>SHIMENABI</span>
            </a>
            <span class="badge">利用規約</span>
        </div>

        <h1 class="title">SHIMENABI 利用規約</h1>
        <div class="sub">最終更新日: <?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?></div>

        <div class="card">
            <div class="section">
                <h2>1. 適用</h2>
                <p>本規約は、SHIMENABI（以下「本サービス」）の利用条件を定めるものです。本サービスの利用者（以下「利用者」）は、本規約に同意の上で本サービスを利用します。</p>
            </div>

            <div class="section">
                <h2>2. サービス内容</h2>
                <p>本サービスは、勤怠管理、シフト管理、給与関連の出力、バック機能など、店舗運営に必要な管理機能を提供します。提供する機能や仕様は予告なく変更される場合があります。</p>
            </div>

            <div class="section">
                <h2>3. 利用料金</h2>
                <p>本サービスの利用料金は、基本料金3,000円 + 従業員1人あたり300円 / 月（税別）を基準とします。詳細は管理画面の案内に従います。</p>
                <p>バック機能は業態オプションとして、1店舗あたり月額5,000円（税別）で追加できます。</p>
                <p>トライアルは1ヶ月無料です。</p>
            </div>

            <div class="section">
                <h2>4. 支払方法</h2>
                <p>支払方法はクレジットカード、請求サイクルは月に1回です。支払遅延が発生した場合、サービスの一部または全部の利用を制限することがあります。</p>
            </div>

            <div class="section">
                <h2>5. 禁止事項</h2>
                <ul>
                    <li>法令または公序良俗に反する行為</li>
                    <li>第三者の権利を侵害する行為</li>
                    <li>本サービスの運営を妨害する行為</li>
                    <li>不正アクセスや脆弱性の悪用</li>
                </ul>
            </div>

            <div class="section">
                <h2>6. 免責</h2>
                <p>本サービスは現状有姿で提供されます。不可抗力、通信障害、第三者サービスの障害などに起因する損害について、当社は責任を負いません。</p>
            </div>

            <div class="section">
                <h2>7. 変更・停止</h2>
                <p>当社は、必要に応じて本サービスの内容を変更・停止することがあります。重要な変更がある場合は、合理的な方法で通知します。</p>
            </div>

            <div class="section">
                <h2>8. 退会・契約解除</h2>
                <p>利用者が本規約に違反した場合、当社は利用停止または契約解除を行うことがあります。</p>
            </div>

            <div class="section">
                <h2>9. 個人情報</h2>
                <p>当社は、利用者情報を適切に取り扱い、本サービス提供に必要な範囲で利用します。</p>
            </div>

            <div class="section">
                <h2>10. お問い合わせ</h2>
                <p>本規約に関するお問い合わせは、<a class="link" href="/#contact">お問い合わせフォーム</a>よりご連絡ください。</p>
            </div>
        </div>
    </div>
</body>

</html>
