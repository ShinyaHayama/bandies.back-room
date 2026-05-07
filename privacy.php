<?php
declare(strict_types=1);
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SHIMENABI 個人情報の取り扱い</title>
    <style>
        :root {
            --bg: #f8fafc;
            --text: #111827;
            --muted: #667085;
            --line: #e5e7eb;
            --accent: #365EAB;
            --card: #ffffff;
        }

        body {
            margin: 0;
            font-family: system-ui, "Noto Sans JP", sans-serif;
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
            font-weight: 700;
        }

        .logo img {
            width: 36px;
            height: auto;
            display: block;
        }

        .title {
            font-size: 28px;
            font-weight: 700;
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
            color: #27447f;
            background: #e6edfb;
            border: 1px solid #c7d4f2;
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
            <span class="badge">個人情報の取り扱い</span>
        </div>

        <h1 class="title">個人情報の取り扱い</h1>
        <div class="sub">最終更新日: <?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?></div>

        <div class="card">
            <div class="section">
                <h2>1. 取得する情報</h2>
                <p>当社はサービス提供に必要な範囲で、氏名、メールアドレス、連絡先、勤務情報などの個人情報を取得します。</p>
            </div>

            <div class="section">
                <h2>2. 利用目的</h2>
                <ul>
                    <li>本サービスの提供・運営・サポート</li>
                    <li>本人確認、問い合わせ対応</li>
                    <li>サービス改善のための分析</li>
                </ul>
            </div>

            <div class="section">
                <h2>3. 第三者提供</h2>
                <p>法令に基づく場合を除き、本人の同意なく第三者に個人情報を提供しません。</p>
            </div>

            <div class="section">
                <h2>4. 委託</h2>
                <p>業務の一部を外部へ委託する場合は、適切な管理を行います。</p>
            </div>

            <div class="section">
                <h2>5. 開示・訂正・削除</h2>
                <p>本人からの請求に基づき、法令の範囲で開示・訂正・削除に対応します。</p>
            </div>

            <div class="section">
                <h2>6. お問い合わせ</h2>
                <p>個人情報の取り扱いに関するお問い合わせは <a class="link" href="/#contact">お問い合わせフォーム</a> よりご連絡ください。</p>
            </div>
        </div>
    </div>
</body>

</html>
