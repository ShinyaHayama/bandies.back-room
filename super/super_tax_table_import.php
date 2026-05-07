<?php

declare(strict_types=1);

/**
 * ✅ スーパー管理者専用：源泉徴収 税額表CSVインポート画面
 *
 * ✅ 改良点（今回）
 * - 1つの国税庁CSVから「甲(ko)」「乙(otsu)」を同時に登録できるモードを追加
 * - 甲(ko)は扶養人数(0〜7)を画面で選択（該当列を抽出）
 * - 乙(otsu)は扶養人数なし（乙列を抽出）
 *
 * ✅ 重要（今回のバグ修正）
 * - POSTに tax_type が含まれず「NG: tax_type が不正です（受信値: ）」が出るケースがあるため
 *   この画面で hidden の tax_type を必ず送る（import_mode から自動反映）
 */

require_once __DIR__ . '/_auth.php';
require_super_admin_login();

// 二重session_start防止（/super/_auth.php が session_start 済み想定）
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ===== CSRF ===== */
if (!isset($_SESSION['_csrf_super_tax'])) {
    $_SESSION['_csrf_super_tax'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['_csrf_super_tax'];

$flash = $_SESSION['flash_super_tax'] ?? null;
unset($_SESSION['flash_super_tax']);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>税額表インポート（スーパー管理者）</title>
    <style>
    body {
        font-family: sans-serif;
        margin: 24px
    }

    .card {
        border: 1px solid #ccc;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 16px
    }

    .row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap
    }

    label {
        font-weight: 700
    }

    input,
    select {
        padding: 8px
    }

    button {
        padding: 10px 16px;
        font-weight: 700
    }

    .ok {
        background: #e6ffed;
        padding: 10px;
        white-space: pre-wrap
    }

    .ng {
        background: #ffecec;
        padding: 10px;
        white-space: pre-wrap
    }

    .hint {
        color: #333;
        font-size: 13px;
        line-height: 1.6
    }

    pre {
        background: #f6f6f6;
        padding: 10px;
        border-radius: 6px;
        overflow: auto
    }

    .warn {
        color: #b00000;
        font-weight: 800;
    }
    </style>
</head>

<body>

    <h2>源泉徴収 税額表インポート（スーパー管理者）</h2>

    <?php if ($flash): ?>
    <div class="<?= !empty($flash['ok']) ? 'ok' : 'ng' ?>">
        <?= h((string)($flash['msg'] ?? '')) ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <form method="post" action="/super/super_tax_table_import_post.php" enctype="multipart/form-data"
            id="taxImportForm">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

            <!-- ✅ tax_type を必ず送る（import_mode から自動反映） -->
            <!-- ※ import_mode=both の時は、後段POST側で import_mode を見て両方作る想定。
                 このhiddenは「空で落ちる」事故防止として ko を送る。 -->
            <input type="hidden" name="tax_type" id="tax_type_hidden" value="ko">

            <div class="row">
                <div>
                    <label>pay_cycle</label><br>
                    <select name="pay_cycle" required>
                        <option value="daily">daily</option>
                        <option value="weekly">weekly</option>
                        <option value="monthly" selected>monthly</option>
                    </select>
                </div>

                <div>
                    <label>version_label</label><br>
                    <input name="version_label" value="v2026_01" required>
                </div>

                <div>
                    <label>インポート種別</label><br>
                    <select name="import_mode" required id="import_mode">
                        <option value="both" selected>甲(ko) + 乙(otsu) を同時登録（おすすめ）</option>
                        <option value="ko">甲(ko)のみ</option>
                        <option value="otsu">乙(otsu)のみ</option>
                    </select>
                </div>

                <div>
                    <label>扶養人数（甲のみ）</label><br>
                    <select name="dependent_count" id="dependent_count">
                        <option value="0" selected>0人</option>
                        <option value="1">1人</option>
                        <option value="2">2人</option>
                        <option value="3">3人</option>
                        <option value="4">4人</option>
                        <option value="5">5人</option>
                        <option value="6">6人</option>
                        <option value="7">7人</option>
                    </select>
                </div>

                <div>
                    <label>CSV</label><br>
                    <input type="file" name="csv" accept=".csv" required>
                </div>
            </div>

            <p class="hint">
                ✅ 国税庁の「給与所得の源泉徴収税額表（令和◯年分）」CSV（先頭に日本語タイトル行が大量にある形式）でもOK。<br>
                ✅ <b>「甲+乙 同時登録」</b>を選ぶと、<b>1回のインポートで両方の rows を作れます</b>（今回の目的）。<br>
                ✅ 甲(ko)は扶養人数列があるため、上の「扶養人数」を選んでください。乙(otsu)は扶養人数なし。<br>
                <span class="warn">※ 既存の rows は同じ table_id（pay_cycle/tax_type/version）で全削除して入れ直します（年1回運用向け）。</span>
            </p>

            <button type="submit">インポート実行</button>
        </form>
    </div>

    <div class="card">
        <h3>対応CSV形式</h3>

        <h4>① シンプルCSV（lower/upper/tax のみ）</h4>
        <p class="hint">
            この形式は <b>甲か乙の片方</b>しか表現できません。<br>
            （甲+乙 同時登録を選ぶと、POST側でエラーにしてください）
        </p>
        <pre>lower_yen,upper_yen,tax_yen
0,87999,0
88000,88999,120
89000,89999,130
90000,,140</pre>

        <h4>② 国税庁の「給与所得の源泉徴収税額表」CSV（先頭に日本語タイトル行が大量にある形式）</h4>
        <p class="hint">→ 自動判定して、DB用（lower/upper/tax）に変換して投入します。</p>
    </div>

    <script>
    /**
     * ✅ import_mode に応じて hidden(tax_type) を必ず送る
     * - ko / otsu モードはそのまま
     * - both モードは「空送信防止」として ko を送る（実処理は import_mode を優先）
     *
     * ✅ 扶養人数UI制御
     * - otsu の時は扶養人数は無関係なので選択は残すが、見た目は無効化する
     */
    (function() {
        const modeEl = document.getElementById('import_mode');
        const taxHidden = document.getElementById('tax_type_hidden');
        const depEl = document.getElementById('dependent_count');

        if (!modeEl || !taxHidden) return;

        const apply = () => {
            const mode = (modeEl.value || '').trim();

            if (mode === 'otsu') {
                taxHidden.value = 'otsu';
                if (depEl) depEl.disabled = true; // disabledでも送信される項目ではないのでOK
            } else {
                // ko / both は ko を送る（bothはPOST側で import_mode を見て両方登録）
                taxHidden.value = 'ko';
                if (depEl) depEl.disabled = false;
            }
        };

        apply();
        modeEl.addEventListener('change', apply);
    })();
    </script>

</body>

</html>