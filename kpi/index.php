<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>〆ナビAI by A/ZURE｜営業マニュアル（見やすい版）</title>
    <style>
        /* =========================================
       見やすさ最優先（高コントラスト・大文字・大余白）
       ※今回は「余白を少し増やす」だけ（文言・構成は変更しない）
       ========================================= */

        :root {
            --bg: #ffffff;
            --text: #111111;
            --muted: #333333;
            --line: #d9d9d9;

            --blue: #0b66c3;
            /* 強めの青（強調） */
            --blue-bg: #e9f2ff;

            --green: #0b7a3d;
            /* OK */
            --green-bg: #e9fff1;

            --yellow: #8a6a00;
            /* 注意 */
            --yellow-bg: #fff7d6;

            --red: #b00020;
            /* NG */
            --red-bg: #ffe9ee;

            --card: #fafafa;

            --radius: 18px;
            /* 少しだけ丸みUP（視認性） */
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
                "Hiragino Sans", "Hiragino Kaku Gothic ProN",
                "Yu Gothic", "Noto Sans JP", Meiryo, sans-serif;
            line-height: 2.0;
            /* 行間を少し広げる */
            font-size: 18px;
        }

        /* 画面を“全体的に”使う */
        .wrap {
            max-width: 1480px;
            /* 少しだけ広げる */
            margin: 0 auto;
            padding: 22px 18px 72px;
            /* 余白UP */
        }

        /* 上部固定ナビ：大きく、押しやすく */
        header {
            position: sticky;
            top: 0;
            z-index: 10;
            background: var(--bg);
            border-bottom: 2px solid var(--line);
            padding: 14px 0;
            /* 余白UP */
        }

        .top {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            /* 少しUP */
            align-items: center;
            justify-content: space-between;
        }

        .title h1 {
            margin: 0;
            font-size: 26px;
            line-height: 1.35;
            letter-spacing: .2px;
        }

        .title .sub {
            margin-top: 8px;
            /* 少しUP */
            color: var(--muted);
            font-size: 16px;
        }

        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            /* 少しUP */
            align-items: center;
        }

        .btn {
            display: inline-block;
            padding: 14px 16px;
            /* 余白UP（押しやすさ） */
            border: 2px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: var(--text);
            text-decoration: none;
            font-size: 16px;
            font-weight: 700;
        }

        .btn:focus,
        .btn:hover {
            border-color: var(--blue);
            outline: none;
        }

        /* セクション：大きく、余白を広く */
        main {
            display: flex;
            flex-direction: column;
            gap: 22px;
            /* セクション間の余白UP */
            margin-top: 22px;
            /* 余白UP */
        }

        .card {
            background: var(--card);
            border: 2px solid var(--line);
            border-radius: var(--radius);
            padding: 22px;
            /* 余白UP */
        }

        .card h2 {
            margin: 0 0 14px;
            font-size: 22px;
            line-height: 1.4;
        }

        .small {
            font-size: 16px;
            color: var(--muted);
        }

        /* 大きい強調ボックス */
        .callout {
            border-radius: var(--radius);
            padding: 18px 16px;
            /* 余白UP */
            border: 2px solid var(--line);
            background: #fff;
            margin: 16px 0;
            /* 余白UP */
        }

        .callout strong {
            font-size: 19px;
        }

        .callout.blue {
            border-color: var(--blue);
            background: var(--blue-bg);
        }

        .callout.ok {
            border-color: var(--green);
            background: var(--green-bg);
        }

        .callout.warn {
            border-color: var(--yellow);
            background: var(--yellow-bg);
        }

        .callout.ng {
            border-color: var(--red);
            background: var(--red-bg);
        }

        /* 大きいリスト */
        ul,
        ol {
            margin: 14px 0 0 26px;
            /* 余白UP */
            padding: 0;
        }

        li {
            margin: 12px 0;
            /* 余白UP */
        }

        /* 数字の箱（KPI） */
        .kpi-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            /* 余白UP */
            margin-top: 16px;
            /* 余白UP */
        }

        @media(min-width: 900px) {
            .kpi-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .kpi-box {
            background: #fff;
            border: 2px solid var(--line);
            border-radius: 16px;
            padding: 16px;
            /* 余白UP */
        }

        .kpi-label {
            font-size: 16px;
            color: var(--muted);
            margin-bottom: 8px;
            /* 余白UP */
            font-weight: 700;
        }

        .kpi-value {
            font-size: 28px;
            font-weight: 900;
            line-height: 1.2;
        }

        .kpi-desc {
            margin-top: 10px;
            /* 余白UP */
            font-size: 16px;
            color: var(--muted);
        }

        /* 重要：コードは大きく読みやすく */
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 16px;
            font-weight: 700;
            background: #fff;
            border: 2px solid var(--line);
            padding: 3px 8px;
            /* 余白UP */
            border-radius: 10px;
            white-space: nowrap;
        }

        .big-quote {
            font-size: 20px;
            font-weight: 900;
            padding: 18px;
            /* 余白UP */
            background: #fff;
            border: 2px solid var(--line);
            border-radius: var(--radius);
            margin-top: 12px;
            /* 余白UP */
        }

        /* 2カラム（広い画面のときだけ） */
        .two-col {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
            /* 余白UP */
        }

        @media(min-width: 1050px) {
            .two-col {
                grid-template-columns: 1.2fr .8fr;
            }
        }

        /* フッター */
        .footer {
            text-align: center;
            color: var(--muted);
            font-size: 14px;
            margin-top: 22px;
            /* 余白UP */
        }

        /* 印刷にも強い */
        @media print {
            header {
                position: static;
                border-bottom: 2px solid #000;
            }

            .btn {
                display: none;
            }

            body {
                font-size: 16px;
            }

            .wrap {
                max-width: 100%;
                padding: 0;
            }

            .card {
                border: 1px solid #000;
                background: #fff;
            }

            .callout {
                border: 1px solid #000;
            }

            code {
                border: 1px solid #000;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header>
            <div class="top">
                <div class="title">
                    <h1>〆ナビAI by A/ZURE｜営業マニュアル（見やすい版）</h1>
                    <div class="sub">飲食店／夜店舗向け｜「売り込まない」相談型営業｜再現性を最優先</div>
                </div>
                <nav class="nav" aria-label="ページ内ナビ">
                    <a class="btn" href="#goal">ゴール</a>
                    <a class="btn" href="#time">時間</a>
                    <a class="btn" href="#kpi">KPI</a>
                    <a class="btn" href="#hearing">5つの質問</a>
                    <a class="btn" href="#trial">トライアル</a>
                    <a class="btn" href="#rules">ルール</a>
                    <a class="btn" href="#fee">料金</a>
                </nav>
            </div>
        </header>

        <main>

            <!-- ゴール -->
            <section class="card" id="goal">
                <h2>1. ゴール（何を目指す？）</h2>

                <div class="callout blue">
                    <strong>黒字（くろじ）になるライン</strong><br>
                    固定コスト：<code>262,000円/月</code><br>
                    1店舗の平均売上：<code>4,900円/月</code><br>
                    <code>262,000 ÷ 4,900 ≒ 53.5</code> → <strong>累積54店舗で単月黒字</strong>
                </div>

                <div class="callout warn">
                    <strong>大事：初月は売上ゼロ（種まき）でOK</strong><br>
                    その代わり、<strong>トライアルの質</strong>を作る。<br>
                    解約率は <code>3%</code> 前提（SaaSは蓄積で勝つ）。
                </div>

                <ul>
                    <li>この営業は、<strong>売り込みではありません</strong>。</li>
                    <li><strong>店舗の困りごと</strong>を整理して、<strong>仕組みで解決</strong>します。</li>
                    <li>説明を長くしない。<strong>体感（たいかん）</strong>させる。</li>
                </ul>
            </section>

            <!-- 料金・報酬 -->
            <section class="card two-col" id="fee">
                <div>
                    <h2>2. 料金（お客様）</h2>
                    <div class="callout blue">
                        <strong>月額（げつがく）</strong><br>
                        <code>3,300円 + 従業員1人につき +400円</code><br><br>
                        平均従業員数：4人 → <strong><code>4,900円/月</code></strong>
                    </div>
                    <div class="callout ok">
                        <strong>ルール：料金は先に言わない</strong><br>
                        聞かれたら答える。<br>
                        先に「締めがラク」「人件費が見える」を体感してもらう。
                    </div>
                </div>

                <div>
                    <h2>3. 報酬（営業：業務委託）</h2>
                    <div class="callout">
                        <ul>
                            <li>基本：<code>200,000円/月</code></li>
                            <li>トライアル：<code>+1,000円/件</code></li>
                            <li>本登録：<code>+4,000円/件</code></li>
                        </ul>
                    </div>
                    <div class="callout ng">
                        <strong>注意：数だけ追うと失敗</strong><br>
                        「質の低いトライアル」が増えると、本登録率が落ちます。<br>
                        見るのは <strong>3つだけ</strong>（下にあります）。
                    </div>
                </div>
            </section>

            <!-- 稼働時間 -->
            <section class="card" id="time">
                <h2>4. 稼働時間（いつ動く？）</h2>

                <div class="callout blue">
                    <strong>おすすめ（最優先）</strong><br>
                    <code>平日 13:00〜17:00</code>（最大18:00）<br>
                    <strong>1日4〜5時間</strong>でOK（これが一番成果が出る）
                </div>

                <div class="callout warn">
                    <strong>避ける</strong><br>
                    夜の営業中（18:00以降）／金曜夜／土曜夜<br>
                    → 忙しくて話にならない。印象も悪くなる。
                </div>

                <ul>
                    <li>ポイントは「長く働く」ではなく、<strong>良い時間に集中</strong>すること。</li>
                    <li>業務委託は「だらだら長時間」より「短時間で成果」が正解。</li>
                </ul>
            </section>

            <!-- KPI -->
            <section class="card" id="kpi">
                <h2>5. KPI（毎月これを守る）</h2>

                <div class="callout blue">
                    <strong>半年以内に黒字化を狙うための目標（本登録：月11件）</strong><br>
                    現実ラインの成約率で逆算しています。
                </div>

                <div class="kpi-grid">
                    <div class="kpi-box">
                        <div class="kpi-label">① 接触（会話）</div>
                        <div class="kpi-value">月375件</div>
                        <div class="kpi-desc">1日 約19件（20日稼働）</div>
                    </div>
                    <div class="kpi-box">
                        <div class="kpi-label">② ヒアリング</div>
                        <div class="kpi-value">月150件</div>
                        <div class="kpi-desc">1日 7〜8件</div>
                    </div>
                    <div class="kpi-box">
                        <div class="kpi-label">③ トライアル</div>
                        <div class="kpi-value">月44件</div>
                        <div class="kpi-desc">1日 約2件</div>
                    </div>
                    <div class="kpi-box">
                        <div class="kpi-label">④ 本登録（目標）</div>
                        <div class="kpi-value">月11件</div>
                        <div class="kpi-desc">2日に1件ペース</div>
                    </div>
                </div>

                <div class="callout ok">
                    <strong>営業が見るのは「3つだけ」</strong>
                    <ul>
                        <li><strong>ヒアリング数</strong></li>
                        <li><strong>トライアル数</strong></li>
                        <li><strong>本登録率</strong></li>
                    </ul>
                </div>

                <div class="callout ng">
                    <strong>赤信号（すぐ直す）</strong>
                    <ul>
                        <li>ヒアリング：<strong>月120件未満</strong></li>
                        <li>トライアル：<strong>月30件未満</strong></li>
                        <li>本登録率：<strong>20%未満</strong></li>
                    </ul>
                </div>
            </section>

            <!-- 5問 -->
            <section class="card" id="hearing">
                <h2>6. ヒアリング（この5つだけ聞く）</h2>

                <div class="callout ok">
                    <strong>ルール</strong><br>
                    <strong>説明しない。</strong> まず聞く。<br>
                    5つを<strong>順番どおり</strong>に聞くだけ。
                </div>

                <ol>
                    <li><strong>Q1</strong>：月末の勤怠締めって、<strong>どなたが・どれくらい時間</strong>かけてやってますか？</li>
                    <li><strong>Q2</strong>：人件費って、<strong>月の途中で把握</strong>できてます？ それとも締めてからですか？</li>
                    <li><strong>Q3</strong>：その勤怠や人件費の状況って、<strong>店長以外も分かります？</strong></li>
                    <li><strong>Q4</strong>：今のやり方で、<strong>スタッフが増えたら</strong>回りそうですか？</li>
                    <li><strong>Q5</strong>：もし「月の途中で人件費が見えて」「締め作業がほぼ無くなったら」<strong>一度試してみたいですか？</strong></li>
                </ol>

                <div class="callout warn">
                    <strong>よくある失敗（やらない）</strong>
                    <ul>
                        <li>途中で機能説明を始める</li>
                        <li>料金の話を先に出す</li>
                        <li>「便利ですよ」と言う</li>
                    </ul>
                </div>
            </section>

            <!-- トライアル -->
            <section class="card" id="trial">
                <h2>7. トライアルで“必ずやる3つ”</h2>

                <div class="callout ok">
                    <strong>これを3つやると本登録率が上がる</strong><br>
                    逆に、1つでも抜けると本登録率が落ちます。
                </div>

                <ol>
                    <li>
                        <strong>① 従業員を「実人数＋2名」登録</strong><br>
                        言い方：<span class="big-quote">「実際の人数に、予備2名を足して登録してください」</span>
                    </li>
                    <li>
                        <strong>② 今日から1週間分のシフトを入れる</strong><br>
                        言い方：<span class="big-quote">「今日から1週間分だけシフトを入れてみてください」</span>
                    </li>
                    <li>
                        <strong>③ 管理画面で「人件費アラート」を1回見る</strong><br>
                        言い方：<span class="big-quote">「この表示、今までどこで見てました？」</span>
                    </li>
                </ol>

                <div class="callout ng">
                    <strong>順番を変えない</strong><br>
                    <code>①→②→③</code> の順番を守る。<br>
                    変えると「体感」が弱くなります。
                </div>

                <div class="callout blue">
                    <strong>トライアル終了の“最後の一言”（これだけ）</strong>
                    <div class="big-quote">「正直、これが無い状態に戻るのって、どう思います？」</div>
                    <div class="small">※ YES/NOで聞かない。感想を言わせる。</div>
                </div>
            </section>

            <!-- ルール -->
            <section class="card" id="rules">
                <h2>8. ルール（守ると数字が安定する）</h2>

                <div class="callout ok">
                    <strong>やること</strong>
                    <ul>
                        <li>説明しない（聞かれたら答える）</li>
                        <li>売り込まない（相談に乗る）</li>
                        <li>5つの質問を順番に聞く</li>
                        <li>トライアルは3つを必ずやってもらう</li>
                    </ul>
                </div>

                <div class="callout ng">
                    <strong>言ってはいけない言葉</strong>
                    <ul>
                        <li>「便利ですよ」</li>
                        <li>「楽になります」</li>
                        <li>「他社も使ってます」</li>
                    </ul>
                </div>

                <div class="callout blue">
                    <strong>おすすめの言い方</strong>
                    <ul>
                        <li>「どう感じました？」</li>
                        <li>「今までと何が違います？」</li>
                        <li>「それ、誰が困ってます？」</li>
                    </ul>
                </div>

                <div class="callout warn">
                    <strong>1日の動き（4〜5時間の型）</strong>
                    <ul>
                        <li>13:00〜14:00　接触（架電／飛び込み／再訪）</li>
                        <li>14:00〜15:30　ヒアリング（4〜5件）</li>
                        <li>15:30〜16:30　ヒアリング（2〜3件）</li>
                        <li>16:30〜17:00　トライアル設定／入力フォロー</li>
                    </ul>
                </div>
            </section>

            <div class="footer">
                最終更新：会話内容のみを基に作成｜HTML単体で閲覧・印刷OK
            </div>
        </main>
    </div>
</body>

</html>