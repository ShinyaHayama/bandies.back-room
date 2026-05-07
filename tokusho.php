<?php
declare(strict_types=1);
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SHIMENABI 特定商取引法に基づく表記</title>
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

        .row {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid var(--line);
        }

        .row:last-child {
            border-bottom: 0;
        }

        .label {
            font-weight: 700;
            font-size: 14px;
        }

        .value {
            font-size: 14px;
            line-height: 1.7;
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

        @media (max-width: 720px) {
            .row {
                grid-template-columns: 1fr;
            }
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
            <span class="badge">特定商取引法に基づく表記</span>
        </div>

        <h1 class="title">特定商取引法に基づく表記</h1>
        <div class="sub">最終更新日: <?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?></div>

        <div class="card">
            <div class="row">
                <div class="label">販売事業者</div>
                <div class="value">株式会社 Fader</div>
            </div>
            <div class="row">
                <div class="label">運営統括責任者</div>
                <div class="value">代表取締役　佐藤 伸一</div>
            </div>
            <div class="row">
                <div class="label">所在地</div>
                <div class="value">東京都港区浜松町2丁目2番15号浜松町ダイヤビル2F</div>
            </div>
            <div class="row">
                <div class="label">電話番号</div>
                <div class="value">03-5727-8339</div>
            </div>
            <div class="row">
                <div class="label">メールアドレス</div>
                <div class="value"><a class="link" href="mailto:info@fader.group">info@fader.group</a></div>
            </div>
            <div class="row">
                <div class="label">URL</div>
                <div class="value"><a class="link" href="https://fader.group" target="_blank" rel="noopener">https://fader.group</a></div>
            </div>
            <div class="row">
                <div class="label">販売価格</div>
                <div class="value">サービスページに記載のとおり（税別）</div>
            </div>
            <div class="row">
                <div class="label">商品代金以外の必要料金</div>
                <div class="value">インターネット接続にかかる通信料等はお客様のご負担となります。</div>
            </div>
            <div class="row">
                <div class="label">支払方法</div>
                <div class="value">クレジットカード</div>
            </div>
            <div class="row">
                <div class="label">支払時期</div>
                <div class="value">月に1回</div>
            </div>
            <div class="row">
                <div class="label">役務提供時期</div>
                <div class="value">登録完了後、直ちにご利用いただけます。</div>
            </div>
            <div class="row">
                <div class="label">トライアル</div>
                <div class="value">トライアル1ヶ月は無料です。</div>
            </div>
            <div class="row">
                <div class="label">キャンセル・解約</div>
                <div class="value">サービスの性質上、提供開始後の返金はできません。解約は翌月1日までにお手続きください。</div>
            </div>
        </div>
    </div>
</body>

</html>
