<?php
$logoMain = '/images/logo_main.png?v=' . date('Ymd');
$trialUrl = 'http://bandies.back-room.me/trial.php';
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>AI改善案 体験デモ | SHIMENABI</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
    :root {
        --primary: #365EAB;
        --primary-dark: #2f4f93;
        --bg: #f6f8fd;
        --text: #0f172a;
        --muted: #6b7a96;
        --border: #d9e3f2;
        --card: #ffffff;
        --shadow: 0 12px 34px rgba(15, 23, 42, 0.08);
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Kaku Gothic ProN", "Yu Gothic",
            sans-serif;
        color: var(--text);
        background: var(--bg);
    }

    a {
        color: inherit;
        text-decoration: none;
    }

    header {
        position: sticky;
        top: 0;
        background: #fff;
        border-bottom: 1px solid var(--border);
        z-index: 10;
    }

    .wrap {
        width: min(1100px, 92vw);
        margin: 0 auto;
    }

    .headerInner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 0;
        gap: 12px;
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 800;
    }

    .brand img {
        height: 36px;
        width: auto;
        display: block;
    }

    .nav {
        display: flex;
        align-items: center;
        gap: 16px;
        font-weight: 700;
        color: var(--muted);
    }

    .btn {
        border: 1px solid var(--primary);
        background: var(--primary);
        color: #fff;
        padding: 10px 16px;
        border-radius: 999px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
    }

    .btnGhost {
        background: #fff;
        color: var(--primary);
    }

    .hero {
        padding: 34px 0 18px;
    }

    h1 {
        margin: 0 0 8px;
        font-size: 32px;
        font-weight: 900;
    }

    .lead {
        margin: 0;
        color: var(--muted);
        font-size: 14px;
        font-weight: 700;
    }

    .demoShell {
        margin: 24px 0 40px;
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 24px;
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .demoTop {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 18px;
        background: #f2f6ff;
        border-bottom: 1px solid var(--border);
        gap: 10px;
        flex-wrap: wrap;
    }

    .demoTitle {
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .demoTitle .pill {
        background: var(--primary);
        color: #fff;
        font-size: 12px;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: 700;
    }

    .demoMeta {
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
    }

    .demoBody {
        padding: 18px;
        background: #f6f8fd;
        min-height: 360px;
    }

    .chatWindow {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .msg {
        max-width: 88%;
        padding: 14px 16px;
        border-radius: 16px;
        font-size: 13px;
        line-height: 1.7;
        white-space: pre-line;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
    }

    .msg.ai {
        background: #fff;
        border: 1px solid #e6edf9;
        align-self: flex-start;
    }

    .msg.user {
        background: var(--primary);
        color: #fff;
        align-self: flex-end;
        border: 1px solid var(--primary);
    }

    .aiTitle {
        font-weight: 800;
        font-size: 13px;
        margin-bottom: 6px;
    }

    .aiLabel {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--primary);
        color: #fff;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .thinking {
        color: var(--muted);
        font-weight: 700;
    }

    .dots::after {
        content: "";
        display: inline-block;
        width: 18px;
        animation: dots 1.4s infinite;
    }

    @keyframes dots {
        0% {
            content: "";
        }
        33% {
            content: ".";
        }
        66% {
            content: "..";
        }
        100% {
            content: "...";
        }
    }

    .demoInput {
        border-top: 1px solid var(--border);
        padding: 16px 18px 18px;
        background: #fff;
    }

    .chips {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 10px;
    }

    .chip {
        border: 1px solid #d9e3f2;
        background: #f7f9ff;
        color: #2f4f93;
        border-radius: 999px;
        padding: 6px 12px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }

    .chip.active {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }

    .inputRow {
        display: flex;
        gap: 10px;
    }

    .inputRow input {
        flex: 1;
        padding: 12px 14px;
        border-radius: 12px;
        border: 1px solid #d9e3f2;
        font-size: 14px;
        font-weight: 700;
        outline: none;
    }

    .inputRow input:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(54, 94, 171, 0.12);
    }

    .note {
        margin-top: 10px;
        font-size: 12px;
        color: var(--muted);
        font-weight: 700;
    }

    @media (max-width: 900px) {
        .nav {
            display: none;
        }

        .inputRow {
            flex-direction: column;
        }

        .btn,
        .btnGhost {
            width: 100%;
        }
    }
    </style>
</head>

<body>
    <header>
        <div class="wrap headerInner">
            <a class="brand" href="/index.php">
                <img src="<?= $logoMain ?>" alt="SHIMENABI">
                <span>SHIMENABI</span>
            </a>
            <nav class="nav">
                <a href="/index.php">ホーム</a>
                <a href="/index.php#pricing">料金</a>
                <a href="/index.php#contact">お問い合わせ</a>
            </nav>
            <a class="btn" href="<?= $trialUrl ?>">無料で試す</a>
        </div>
    </header>

    <main class="wrap">
        <section class="hero">
            <h1>AI改善案 体験デモ</h1>
            <p class="lead">実データの代わりに、サンプルでAIの出力イメージを体験できます。</p>
        </section>

        <section class="demoShell" aria-live="polite">
            <div class="demoTop">
                <div class="demoTitle">
                    <span class="pill">AI</span>
                    AIに相談（改善案）
                </div>
                <div class="demoMeta">※サンプルデモ / API未接続</div>
            </div>
            <div class="demoBody">
                <div class="chatWindow" id="chatWindow"></div>
            </div>
            <div class="demoInput">
                <div class="chips" id="chips"></div>
                <div class="inputRow">
                    <input id="userInput" type="text" placeholder="例：人件費率が高い原因は？">
                    <button class="btn" id="sendBtn" type="button">AIに相談する</button>
                </div>
                <div class="note">※これはサンプル表示です。実データの内容により結果は変わります。</div>
            </div>
        </section>
    </main>

    <script>
    const samples = [
        {
            question: "人件費率が高い原因と対策を教えて",
            title: "AI改善案（2025-12-18〜2026-01-16 / 平均人件費率 35%）",
            body: `1) 結論
人件費が高い日が多く、売上が少ない日とのバランスが悪い。出退勤記録の漏れもある。

2) 今の問題
- 5日も人件費率が50%以上で効率が悪い。
- 売上0で人件費が発生している日が2日ある（記録漏れの疑い）。
- 出勤・退勤のボタンを押し忘れが多く、実働時間が正確に把握できていない。

3) すぐやる改善
- 人件費率が高い日を中心にスタッフの予定を見直す。
- 売上0でも人件費が発生している日はシフト調整や記録の確認をする。
- 出退勤ボタンの押し忘れ防止を全員で意識し、打刻漏れを減らす。

4) 従業員評価
- 山田さんは11日勤務で残業多い（97時間30分）も遅刻なし。
- 花子さんは5日勤務で残業24時間1分、打刻漏れが25回と多い。
- 高橋さんは1日勤務、残業1時間12分、打刻漏れが18回と多い。
- 3人とも遅刻はなし、打刻漏れの対策が必要。
- 松原さんが勤務日数多いが残業も多く負担大。
- 花子さんの予定出退勤記録が多く抜けている。
- 佐藤さんは勤務少ないが記録漏れが多く手順の確認が必要。
- シフトの使い方は、全員で打刻漏れ防止と勤務時間記録の見直しを推奨。

5) 明日やること
- 売上0で人件費が出ている日について、出退勤ボタンの記録漏れをすぐチェックする。
- 人件費率が高い日のシフトを確認し、無駄な勤務を減らす。
- 全スタッフに出退勤ボタンの押し忘れ防止を声かけして意識を高める。`
        },
        {
            question: "週末の人件費率が高い理由を知りたい",
            title: "AI改善案（2026-02-01〜2026-02-29 / 平均人件費率 32%）",
            body: `1) 結論
週末に人員が厚く配置されている一方、売上が伸び切らず人件費率が上がっている。

2) 今の問題
- 金土の人件費率が45%を超える日が4日ある。
- 休日の休憩時間が未入力の日が3日あり、実働が過大に見える。
- 予定シフトと実績の乖離が多く、調整が遅れている。

3) すぐやる改善
- 週末のピーク時間だけ人員を厚くし、前後の時間は削る。
- 休憩の入力漏れをその日のうちに確認する。
- 予定と実績の差分が大きい日を優先的に見直す。

4) 従業員評価
- 佐藤さんは10日勤務で遅刻なし、休憩漏れ0。
- 松原さんは8日勤務、休憩漏れが多く確認が必要。
- 高橋さんは週末出勤が多く、過集中で負担が大きい。

5) 明日やること
- 直近の金土シフトを再配置し、開始・終了を15分調整する。
- 休憩入力の確認ルールを共有し、漏れを防ぐ。`
        },
        {
            question: "売上が伸びない日の原因を教えて",
            title: "AI改善案（2025-11-01〜2025-11-30 / 平均人件費率 28%）",
            body: `1) 結論
売上が低い日にスタッフ配置が厚く、時間帯別の調整が不足している。

2) 今の問題
- 売上が低い日でもスタッフ数が変わらず、比率が上がっている。
- 早番の終了が遅れやすく、引き継ぎが重なっている。
- 打刻の抜けがあり、実働時間の精度が低い。

3) すぐやる改善
- 売上が低い日のピーク前後を30分ずらして調整する。
- 早番と遅番の引き継ぎ時間を短縮する。
- 打刻漏れが多い人には個別に確認する。

4) 従業員評価
- 田中さんは遅刻なしだが打刻漏れが3回。
- 佐々木さんは勤務日数が少なく実績が不安定。
- 山田さんは勤務が多く負担が偏っている。

5) 明日やること
- 低売上日のシフトを見直し、1名分を短縮する。
- 打刻漏れの原因を担当者に確認する。`
        },
        {
            question: "打刻漏れが多いので対策を知りたい",
            title: "AI改善案（2026-03-01〜2026-03-31 / 平均人件費率 41%）",
            body: `1) 結論
打刻漏れが多く、実働時間の把握が不正確。人件費率が高く見える要因になっている。

2) 今の問題
- 打刻漏れが発生している日が7日ある。
- 退勤ボタンの押し忘れが特定メンバーに集中。
- 修正が月末に集中して負担が増えている。

3) すぐやる改善
- 退勤前の声かけルールを作る。
- 打刻漏れの多いメンバーに通知を強化する。
- 1日1回、管理者が当日分を確認する。

4) 従業員評価
- 花子さんは打刻漏れが多く、手順の再確認が必要。
- 松原さんは勤務が多く、修正量が多い。
- 佐藤さんは打刻は正確だが残業が多い。

5) 明日やること
- 今日の打刻を閉店前に確認する。
- 漏れの多いメンバーへ手順を再共有する。`
        },
        {
            question: "人件費と売上のバランスを改善したい",
            title: "AI改善案（2025-10-01〜2025-10-31 / 平均人件費率 30%）",
            body: `1) 結論
売上に対して人員が厚い日があり、バランスの調整が必要。

2) 今の問題
- 人件費率が40%を超える日が3日ある。
- 売上入力が遅れて比較ができない日がある。
- シフトの偏りで特定メンバーの負担が大きい。

3) すぐやる改善
- 人件費率が高い日のシフトを1時間だけ短縮する。
- 売上入力を当日中に確認する。
- 偏りが大きい週は担当を入れ替える。

4) 従業員評価
- 高橋さんは出勤が少なく売上貢献が低い。
- 山田さんは勤務が多く負担が集中。
- 佐藤さんはシフトの変更が多く調整が必要。

5) 明日やること
- 高人件費日のシフトをチェックして調整する。
- 売上入力の確認ルールを共有する。`
        },
        {
            question: "売上0の日が出るのはなぜ？",
            title: "AI改善案（2026-04-01〜2026-04-30 / 平均人件費率 38%）",
            body: `1) 結論
売上入力漏れと閉店時間帯の記録不足が重なり、売上0日が発生している可能性が高い。

2) 今の問題
- 売上0の日が3日連続で発生している。
- 同日に勤怠は記録されており、入力漏れの疑いが濃い。
- 閉店間際の打刻が抜けている日がある。

3) すぐやる改善
- 売上入力の締め確認を毎日固定時間で実施する。
- 0円の日は翌朝に自動通知でチェックする。
- 閉店前の打刻確認を担当に固定する。

4) 従業員評価
- 佐藤さんは閉店対応が多く、入力確認の担当が適任。
- 花子さんは入力忘れが多い傾向がある。

5) 明日やること
- 直近の売上0日を確認し、未入力なら修正する。
- 売上入力の担当を明確にする。`
        },
        {
            question: "スタッフの配置が偏っている気がする",
            title: "AI改善案（2026-02-10〜2026-03-09 / 平均人件費率 33%）",
            body: `1) 結論
特定スタッフに勤務が集中しており、シフトの偏りが発生している。

2) 今の問題
- 上位3名が全体勤務の48%を占めている。
- 新人メンバーの勤務が少なく、スキルが定着しにくい。
- 繁忙日と閑散日の担当が固定化している。

3) すぐやる改善
- 勤務数が多いメンバーを週1日減らす。
- 新人の勤務を週1日追加してローテーション化する。
- 繁忙日担当の入れ替えを試す。

4) 従業員評価
- 山田さんは勤務が多く負担が集中。
- 高橋さんは勤務が少なく経験不足になりやすい。

5) 明日やること
- 来週のシフトで偏りが出ないよう担当を再配置する。`
        },
        {
            question: "休憩時間の入力漏れが多い",
            title: "AI改善案（2026-01-01〜2026-01-31 / 平均人件費率 31%）",
            body: `1) 結論
休憩入力の漏れが多く、実働時間が過大に計上されている。

2) 今の問題
- 休憩未入力の日が6日ある。
- 入力漏れが特定スタッフに偏っている。
- 修正が月末に集中している。

3) すぐやる改善
- 休憩開始・終了の入力ルールを紙で掲示する。
- 休憩未入力は当日中に管理者が確認する。

4) 従業員評価
- 松原さんは休憩入力が正確。
- 花子さんは休憩入力漏れが多い。

5) 明日やること
- 直近の休憩未入力日を洗い出して修正する。`
        },
        {
            question: "人件費率の改善目標を決めたい",
            title: "AI改善案（2025-09-01〜2025-09-30 / 平均人件費率 29%）",
            body: `1) 結論
現状29%の人件費率を26〜27%に下げると、売上に対して安定した運営ができる。

2) 今の問題
- 28%以下の日と35%超の日の差が大きい。
- 調整は週末に偏っている。

3) すぐやる改善
- 週あたりの人件費率目標を27%で固定する。
- 高い日を1日ずつ削る調整で全体を下げる。

4) 従業員評価
- 佐藤さんは売上が低い日に勤務が多い。
- 山田さんはピーク時間の配置が適切。

5) 明日やること
- 直近2週の人件費率を見て、1日分の調整を行う。`
        },
        {
            question: "遅刻が増えている原因を知りたい",
            title: "AI改善案（2026-03-15〜2026-04-14 / 平均人件費率 34%）",
            body: `1) 結論
遅刻が特定の曜日に集中しており、シフト開始の調整で改善できる可能性が高い。

2) 今の問題
- 月曜と金曜に遅刻が集中している。
- 朝番のシフト開始が急で準備が追いついていない。

3) すぐやる改善
- 朝番の開始を15分後ろ倒しする。
- 遅刻が多いメンバーに前日リマインドを送る。

4) 従業員評価
- 高橋さんは遅刻が多く、時間調整が必要。
- 松原さんは遅刻なしで安定。

5) 明日やること
- 来週の朝番開始を15分調整して様子を見る。`
        }
    ];

    const chatWindow = document.getElementById("chatWindow");
    const userInput = document.getElementById("userInput");
    const sendBtn = document.getElementById("sendBtn");
    const chips = document.getElementById("chips");
    let activeIndex = 0;

    function createChip(text, index) {
        const chip = document.createElement("button");
        chip.type = "button";
        chip.className = "chip" + (index === 0 ? " active" : "");
        chip.textContent = text;
        chip.addEventListener("click", () => {
            activeIndex = index;
            userInput.value = samples[index].question;
            document.querySelectorAll(".chip").forEach(c => c.classList.remove("active"));
            chip.classList.add("active");
        });
        return chip;
    }

    samples.forEach((s, i) => chips.appendChild(createChip(s.question, i)));
    userInput.value = samples[0].question;

    function addMessage(text, type, title = "") {
        const msg = document.createElement("div");
        msg.className = `msg ${type}`;
        if (type === "ai") {
            const label = document.createElement("div");
            label.className = "aiLabel";
            label.textContent = "AI";
            msg.appendChild(label);
            if (title) {
                const t = document.createElement("div");
                t.className = "aiTitle";
                t.textContent = title;
                msg.appendChild(t);
            }
        }
        const body = document.createElement("div");
        body.textContent = text;
        msg.appendChild(body);
        chatWindow.appendChild(msg);
        chatWindow.scrollTop = chatWindow.scrollHeight;
        return msg;
    }

    function addThinking() {
        const msg = document.createElement("div");
        msg.className = "msg ai thinking";
        msg.innerHTML = "<span class='dots'>AIが分析中</span>";
        chatWindow.appendChild(msg);
        chatWindow.scrollTop = chatWindow.scrollHeight;
        return msg;
    }

    function sendSample() {
        const sample = samples[activeIndex];
        const question = userInput.value.trim() || sample.question;
        addMessage(question, "user");
        userInput.value = "";
        const thinking = addThinking();

        setTimeout(() => {
            thinking.remove();
            addMessage(sample.body, "ai", sample.title);
        }, 900);
    }

    sendBtn.addEventListener("click", sendSample);
    userInput.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
            e.preventDefault();
            sendSample();
        }
    });

    // 初回表示
    addMessage(samples[0].body, "ai", samples[0].title);
    </script>
</body>

</html>
