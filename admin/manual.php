<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>取扱説明 | AzureSystems</title>
    <style>
    :root {
        --bg: #f5f2ee;
        --paper: #fff;
        --ink: #1f2937;
        --muted: rgba(31, 41, 55, .6);
        --line: rgba(31, 41, 55, .12);
        --accent: #1f6feb;
        --accent-soft: #e8f1ff;
        --warm: #f6efe6;
        --radius: 16px;
        --radius-sm: 12px;
        --shadow: 0 14px 40px rgba(17, 24, 39, .08);
        --mono: ui-monospace, "SFMono-Regular", "Menlo", "Consolas", "Liberation Mono", monospace;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        font-family: "Yu Mincho", "Hiragino Mincho ProN", "Hiragino Sans", "Yu Gothic", "Meiryo", serif;
        color: var(--ink);
        background: radial-gradient(circle at top, #fbfaf7 0%, #f3efe9 40%, #f5f2ee 100%);
    }

    .page {
        padding: 18px;
    }

    .hero {
        background: var(--paper);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 20px 22px;
        box-shadow: var(--shadow);
    }

    .heroTop {
        display: flex;
        gap: 18px;
        align-items: center;
        flex-wrap: wrap;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        border-radius: 999px;
        background: var(--accent-soft);
        color: var(--accent);
        font-weight: 900;
        font-size: 12px;
    }

    .title {
        font-size: 24px;
        margin: 0;
        font-weight: 900;
        letter-spacing: .02em;
    }

    .subtitle {
        margin: 6px 0 0;
        color: var(--muted);
        font-size: 13px;
        font-weight: 700;
    }

    .heroActions {
        margin-left: auto;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .search {
        display: flex;
        align-items: center;
        gap: 8px;
        border: 1px solid var(--line);
        background: #fff;
        padding: 10px 12px;
        border-radius: 999px;
        min-width: 260px;
        box-shadow: 0 6px 16px rgba(17, 24, 39, .08);
    }

    .search input {
        border: none;
        outline: none;
        width: 100%;
        font-size: 14px;
        font-weight: 700;
        color: var(--ink);
        background: transparent;
    }

    .stats {
        margin-top: 16px;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
    }

    .statCard {
        border: 1px solid var(--line);
        border-radius: var(--radius-sm);
        background: var(--warm);
        padding: 12px 14px;
    }

    .statLabel {
        font-size: 12px;
        color: var(--muted);
        font-weight: 800;
    }

    .statValue {
        font-size: 16px;
        font-weight: 900;
        margin-top: 4px;
    }

    .grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 280px;
        gap: 16px;
        margin-top: 18px;
    }

    @media (max-width: 1100px) {
        .grid {
            grid-template-columns: 1fr;
        }
    }

    .doc {
        display: grid;
        gap: 14px;
    }

    .section {
        background: var(--paper);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 18px 20px;
        box-shadow: var(--shadow);
    }

    .section h2 {
        margin: 0 0 10px;
        font-size: 18px;
        font-weight: 900;
    }

    .section h3 {
        margin: 16px 0 8px;
        font-size: 15px;
        font-weight: 900;
    }

    .section p {
        margin: 8px 0;
        line-height: 1.7;
        font-size: 14px;
    }

    .list {
        margin: 0;
        padding-left: 18px;
        line-height: 1.7;
        font-size: 14px;
    }

    .tagRow {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 8px;
    }

    .tag {
        padding: 6px 10px;
        border-radius: 999px;
        background: #f2f2f2;
        border: 1px solid var(--line);
        font-size: 12px;
        font-weight: 800;
    }

    .callout {
        border-left: 4px solid var(--accent);
        background: #f7faff;
        padding: 10px 12px;
        border-radius: 10px;
        margin-top: 10px;
        font-size: 13px;
        font-weight: 700;
    }

    .roleGrid {
        display: grid;
        gap: 10px;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }

    .roleCard {
        border: 1px solid var(--line);
        border-radius: var(--radius-sm);
        padding: 12px 14px;
        background: #fff;
    }

    .roleCard h4 {
        margin: 0 0 6px;
        font-size: 14px;
        font-weight: 900;
    }

    .roleCard p {
        margin: 0;
        font-size: 13px;
        color: var(--muted);
    }

    .pageCard {
        border: 1px solid var(--line);
        border-radius: var(--radius-sm);
        padding: 12px 14px;
        background: #fff;
        margin-top: 10px;
    }

    .pageCard .path {
        font-family: var(--mono);
        font-size: 12px;
        color: var(--muted);
    }

    .pageCard .title {
        font-size: 14px;
        margin: 6px 0 4px;
    }

    .pageCard .desc {
        font-size: 13px;
        color: var(--muted);
    }

    .toc {
        position: sticky;
        top: 12px;
        display: grid;
        gap: 12px;
    }

    .tocCard {
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 14px;
        background: #fff;
        box-shadow: var(--shadow);
    }

    .tocTitle {
        font-size: 13px;
        font-weight: 900;
        margin-bottom: 8px;
    }

    .toc a {
        display: block;
        color: var(--ink);
        text-decoration: none;
        padding: 6px 8px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 13px;
    }

    .toc a.active {
        background: var(--accent-soft);
        color: var(--accent);
    }

    .kbd {
        font-family: var(--mono);
        font-size: 12px;
        padding: 2px 6px;
        border-radius: 6px;
        background: #f3f4f6;
        border: 1px solid var(--line);
    }

    .footerNote {
        margin-top: 16px;
        font-size: 12px;
        color: var(--muted);
        text-align: center;
    }
    </style>
</head>

<body>
    <div class="page">
        <section class="hero">
            <div class="heroTop">
                <span class="badge">取扱説明書</span>
                <div>
                    <h1 class="title">シメナビ管理画面ガイド</h1>
                    <div class="subtitle">初心者でも迷わないように、画面ごとの目的と操作を整理しました。</div>
                </div>
                <div class="heroActions">
                    <label class="search">
                        🔍
                        <input id="searchInput" type="text" placeholder="機能名や画面名で検索">
                    </label>
                </div>
            </div>

            <div class="stats">
                <div class="statCard">
                    <div class="statLabel">対象ユーザー</div>
                    <div class="statValue">店舗管理者 / 勤怠担当 / 本部</div>
                </div>
                <div class="statCard">
                    <div class="statLabel">基本思想</div>
                    <div class="statValue">日々の勤怠・シフト・バック・給与を一元管理</div>
                </div>
                <div class="statCard">
                    <div class="statLabel">よく使う用語</div>
                    <div class="statValue">営業日 / 打刻調整 / バック / 明細</div>
                </div>
            </div>
        </section>

        <div class="grid">
            <main class="doc" id="docRoot">
                <section class="section" id="intro" data-keywords="はじめに 概要 基本">
                    <h2>はじめに</h2>
                    <p>このページは、管理画面の「どこで・何ができるか」を最短で理解するためのガイドです。</p>
                    <p>各ページの目的・操作の流れ・注意点をまとめています。</p>
                    <div class="callout">迷ったら <span class="kbd">画面名</span> を検索すると、該当ページの説明に飛べます。</div>
                </section>

                <section class="section" id="roles" data-keywords="役割 店舗管理者 勤怠担当 本部">
                    <h2>役割別の使い方</h2>
                    <div class="roleGrid">
                        <div class="roleCard">
                            <h4>店舗管理者</h4>
                            <p>店舗の設定変更、従業員管理、日々の確認を行います。</p>
                        </div>
                        <div class="roleCard">
                            <h4>勤怠担当</h4>
                            <p>打刻の修正、勤怠の確認、給与明細の確認を行います。</p>
                        </div>
                        <div class="roleCard">
                            <h4>本部</h4>
                            <p>各店舗の状況確認、設定の統一、数値の監督を行います。</p>
                        </div>
                    </div>
                </section>

                <section class="section" id="glossary" data-keywords="用語 営業日 打刻調整 バック 明細">
                    <h2>重要な用語</h2>
                    <ul class="list">
                        <li><strong>営業日</strong>：日付の区切り。設定された「営業日切替」時刻より前は前日扱い。</li>
                        <li><strong>打刻調整</strong>：出勤は切り上げ・退勤は切り捨てで丸める仕組み。</li>
                        <li><strong>バック</strong>：イベントに紐づく金額の集計（確定/未確定あり）。</li>
                        <li><strong>明細</strong>：期間内の勤務・手当・控除の集計結果。</li>
                    </ul>
                </section>

                <section class="section" id="navigation" data-keywords="ナビ ヘッダー 画面遷移">
                    <h2>画面の見方</h2>
                    <p>ヘッダーに主要な画面が並んでいます。店舗の切り替えは右上で行います。</p>
                    <div class="tagRow">
                        <span class="tag">🏠 ホーム</span>
                        <span class="tag">📅 日別勤怠</span>
                        <span class="tag">🗓️ シフト管理</span>
                        <span class="tag">💰 バック</span>
                        <span class="tag">⚙️ 設定</span>
                    </div>
                </section>

                <section class="section" id="dashboard" data-keywords="ダッシュボード ホーム 売上 グラフ">
                    <h2>ホーム（ダッシュボード）</h2>
                    <div class="pageCard">
                        <div class="path">/admin/index.php</div>
                        <div class="title">日次・月次の売上、客数、人件費率を確認</div>
                        <div class="desc">期間を選択してKPIとグラフの推移を見ます。</div>
                    </div>
                    <ul class="list">
                        <li>期間プルダウンで「過去30日」や「過去12ヶ月」を選択。</li>
                        <li>タブを切り替えて、一覧・グラフ・AI相談を確認。</li>
                        <li>AIタブは説明用で、期間プルダウンは非表示。</li>
                    </ul>
                </section>

                <section class="section" id="daily" data-keywords="日別勤怠 打刻 休憩 日給">
                    <h2>日別勤怠</h2>
                    <div class="pageCard">
                        <div class="path">/admin/time_punch_daily.php</div>
                        <div class="title">日ごとの打刻、勤務時間、日給を確認・修正</div>
                        <div class="desc">勤務の詳細を見て、必要なら修正画面へ移動します。</div>
                    </div>
                    <ul class="list">
                        <li>期間・従業員・在籍/退職を選んで一覧表示。</li>
                        <li>出勤/退勤の打刻調整後の時刻が表示されます。</li>
                        <li>日給は「実働 × その日の時給（履歴）」で計算。</li>
                    </ul>
                    <div class="callout">「給料明細（印刷）」は従業員を選択したときのみ有効です。</div>
                </section>

                <section class="section" id="punch-edit" data-keywords="打刻編集 修正 勤怠調整">
                    <h2>打刻編集</h2>
                    <div class="pageCard">
                        <div class="path">/admin/time_punch_edit.php</div>
                        <div class="title">個別の打刻を修正・削除</div>
                        <div class="desc">誤打刻の修正、休憩の調整を行います。</div>
                    </div>
                    <ul class="list">
                        <li>日別勤怠の「編集」から移動。</li>
                        <li>打刻の時刻・種別・休憩を修正できます。</li>
                    </ul>
                </section>

                <section class="section" id="shifts" data-keywords="シフト 予定 ドラッグ 編集">
                    <h2>シフト管理</h2>
                    <div class="pageCard">
                        <div class="path">/admin/shifts.php</div>
                        <div class="title">月間/週間のシフトを作成・管理</div>
                        <div class="desc">ドラッグ操作やテンプレートで配置できます。</div>
                    </div>
                    <ul class="list">
                        <li>従業員の予定・実績が一覧で見えます。</li>
                        <li>固定シフトの登録で繰り返し登録ができます。</li>
                    </ul>
                </section>

                <section class="section" id="back" data-keywords="バック イベント 確定 未確定">
                    <h2>バック管理</h2>
                    <div class="pageCard">
                        <div class="path">/admin/back_events.php</div>
                        <div class="title">バックイベントの入力・確定</div>
                        <div class="desc">日別合計や従業員別の明細を確認できます。</div>
                    </div>
                    <ul class="list">
                        <li>イベント種別を選んで金額を入力。</li>
                        <li>確定/未確定の区分を管理。</li>
                        <li>日別合計に従業員名も表示されます。</li>
                    </ul>
                </section>

                <section class="section" id="payslip" data-keywords="明細 給与 PDF 印刷">
                    <h2>給与明細</h2>
                    <div class="pageCard">
                        <div class="path">/admin/payslip_simple_view.php</div>
                        <div class="title">給与明細の一覧・表示</div>
                        <div class="desc">対象期間の明細を確認できます。</div>
                    </div>
                    <div class="pageCard">
                        <div class="path">/admin/payslip_simple_pdf.php</div>
                        <div class="title">明細PDFの出力</div>
                        <div class="desc">日別明細と合計がPDFに出力されます。</div>
                    </div>
                    <ul class="list">
                        <li>期間と従業員を選ぶと日別明細も表示されます。</li>
                        <li>日別明細は最大31日まで表示されます。</li>
                    </ul>
                </section>

                <section class="section" id="employees" data-keywords="従業員 設定 時給 PIN">
                    <h2>従業員・店舗設定</h2>
                    <div class="pageCard">
                        <div class="path">/admin/employees_new.php</div>
                        <div class="title">従業員の登録・並び替え・店舗設定</div>
                        <div class="desc">給与設定や営業日切替などをまとめて管理します。</div>
                    </div>
                    <ul class="list">
                        <li>従業員の追加・退職・復活・並び順を管理。</li>
                        <li>給与設定タブで「支払サイクル」「打刻調整」「営業日切替」を設定。</li>
                        <li>機能解説ポップアップのON/OFFもここで切り替え。</li>
                    </ul>
                </section>

                <section class="section" id="employee-edit" data-keywords="従業員編集 時給履歴">
                    <h2>従業員編集</h2>
                    <div class="pageCard">
                        <div class="path">/admin/employee_edit.php</div>
                        <div class="title">個別従業員の詳細設定</div>
                        <div class="desc">時給や適用開始日、基本情報を更新します。</div>
                    </div>
                    <ul class="list">
                        <li>時給の適用開始日は履歴として保存されます。</li>
                        <li>過去の時給は履歴で保持され、過去の計算は汚染されません。</li>
                    </ul>
                </section>

                <section class="section" id="devices" data-keywords="端末管理 デバイス">
                    <h2>端末管理</h2>
                    <div class="pageCard">
                        <div class="path">/admin/devices_manage.php</div>
                        <div class="title">打刻端末の登録・管理</div>
                        <div class="desc">端末が未登録だと勤怠登録に失敗する場合があります。</div>
                    </div>
                </section>

                <section class="section" id="faq" data-keywords="FAQ よくある質問">
                    <h2>よくある質問</h2>
                    <h3>Q. 日別勤怠と明細の金額が違う？</h3>
                    <p>打刻調整・時給履歴の適用・バックの扱いが影響します。明細は日別明細の合算です。</p>
                    <h3>Q. 日別明細に表示されない日がある？</h3>
                    <p>期間設定、営業日切替、31日上限を確認してください。</p>
                </section>

                <div class="footerNote">※ 本ガイドは一般的な操作説明です。実際の運用ルールは店舗ごとに調整してください。</div>
            </main>

            <aside class="toc">
                <div class="tocCard">
                    <div class="tocTitle">目次</div>
                    <nav id="tocLinks" class="toc">
                        <a href="#intro">はじめに</a>
                        <a href="#roles">役割別の使い方</a>
                        <a href="#glossary">重要な用語</a>
                        <a href="#navigation">画面の見方</a>
                        <a href="#dashboard">ホーム</a>
                        <a href="#daily">日別勤怠</a>
                        <a href="#punch-edit">打刻編集</a>
                        <a href="#shifts">シフト管理</a>
                        <a href="#back">バック管理</a>
                        <a href="#payslip">給与明細</a>
                        <a href="#employees">従業員・店舗設定</a>
                        <a href="#employee-edit">従業員編集</a>
                        <a href="#devices">端末管理</a>
                        <a href="#faq">よくある質問</a>
                    </nav>
                </div>
            </aside>
        </div>
    </div>

    <script>
    (function() {
        const search = document.getElementById('searchInput');
        const sections = Array.from(document.querySelectorAll('.section'));
        const tocLinks = Array.from(document.querySelectorAll('#tocLinks a'));

        const updateFilter = () => {
            const q = (search.value || '').trim().toLowerCase();
            sections.forEach(sec => {
                const text = (sec.textContent || '').toLowerCase();
                const keys = (sec.getAttribute('data-keywords') || '').toLowerCase();
                const hit = q === '' || text.includes(q) || keys.includes(q);
                sec.style.display = hit ? '' : 'none';
            });
        };

        if (search) {
            search.addEventListener('input', updateFilter);
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const id = entry.target.getAttribute('id');
                tocLinks.forEach(link => {
                    link.classList.toggle('active', link.getAttribute('href') === '#' + id);
                });
            });
        }, {
            rootMargin: '-30% 0px -60% 0px'
        });

        sections.forEach(sec => observer.observe(sec));
    })();
    </script>
</body>

</html>