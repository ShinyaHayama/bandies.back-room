<?php
// ✅ ファイル名: /trial.php
// ✅ 書き込み場所: このファイルを「丸ごと置き換え」
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Tokyo');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function base_path(): string
{
    $p = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($p === '/' ? '' : $p);
}
$basePath = base_path();

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

// flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// CSS探索
$cssHref = '/assets/style.css?v=3';
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot !== '' && !is_file($docRoot . '/assets/style.css')) {
    if (is_file($docRoot . '/admin/assets/style.css')) {
        $cssHref = '/admin/assets/style.css?v=3';
    } elseif ($basePath !== '' && is_file($docRoot . $basePath . '/assets/style.css')) {
        $cssHref = $basePath . '/assets/style.css?v=3';
    } else {
        $cssHref = './assets/style.css?v=3';
    }
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>シメナビ｜無料お試し（アカウント発行）</title>
    <meta name="description" content="シメナビの無料お試し。メールアドレスだけでアカウント発行のご案内を送ります。" />
    <link rel="stylesheet" href="<?= h($cssHref) ?>" />

    <style>
        body {
            font-family: system-ui, -apple-system, "Hiragino Sans", "Noto Sans JP", sans-serif;
            background: linear-gradient(180deg, #f3f7fd, #ffffff 60%);
        }

        .trialInlineWarn {
            display: none;
            margin-top: 8px;
            padding: 10px 12px;
            border: 1px solid #ffd18a;
            background: #fff7e6;
            color: #6b4a00;
            font-size: 13px;
            line-height: 1.4;
        }

        .trialInlineOk {
            display: none;
            margin-top: 8px;
            padding: 10px 12px;
            border: 1px solid #cfe9d6;
            background: #eef9f1;
            color: #0f5132;
            font-size: 13px;
            line-height: 1.4;
        }

        /* disabledでも見た目を大きく崩さない（既存CSSを邪魔しない最小） */
        button[disabled] {
            opacity: .6;
            cursor: not-allowed;
        }

        .trialLogo {
            height: 28px;
            width: auto;
            display: block;
        }

        .trialHeader {
            background: transparent;
            border-bottom: 1px solid rgba(15, 23, 42, .06);
        }

        .trialIntro,
        .trialCard {
            background: linear-gradient(135deg, #eef6fb, #f7fbff);
            border: 0;
            border-radius: 34px;
            box-shadow: none;
        }

        .trialIntro {
            padding: 26px;
        }

        .trialCard {
            padding: 26px;
        }

        .trialBtn {
            background: #2f5aa6;
            border: 0;
            border-radius: 999px;
            color: #fff;
            font-weight: 900;
        }

        .trialForm input,
        .trialForm select,
        .trialForm textarea {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, .12);
        }

        .trialForm input:focus,
        .trialForm textarea:focus {
            border-color: rgba(47, 90, 166, .45);
            box-shadow: 0 0 0 4px rgba(47, 90, 166, .12);
        }
    </style>
</head>

<body class="trialBody">

    <header class="trialHeader">
        <div class="container trialHeaderInner">
            <a href="<?= h($basePath) ?>/" class="trialBrand" aria-label="シメナビ トップへ">
                <img class="trialLogo" src="/images/logo_main.png" alt="SHIMENABI">
                <span class="trialBrandText"><strong>シメナビ</strong><span>AI自動改善 × 勤怠管理</span></span>
            </a>
        </div>
    </header>

    <main class="trialMain">
        <div class="container">
            <div class="trialWrap">
                <div class="trialIntro">
                    <h1>今すぐ無料でお試しいただけます</h1>
                    <ul class="trialList">
                        <li>勤怠管理に必要な機能はすべて使えます</li>
                        <li>お試し後、そのまま継続して利用可能</li>
                        <li>iPad打刻 / LINE出退勤 / 複数店舗にも対応</li>
                    </ul>
                    <p class="trialNote">
                        ※ アカウント発行のご案内メールをお送りします。<br>
                        ※ 料金は発生しません（無料デモ/トライアル）。
                    </p>
                </div>

                <section class="trialCard" aria-label="アカウント発行フォーム">
                    <h2>アカウント発行はこちら</h2>

                    <?php if ($flash): ?>
                        <div class="flash <?= h((string)($flash['type'] ?? 'info')) ?>">
                            <?= h((string)($flash['message'] ?? '')) ?>
                        </div>
                    <?php endif; ?>

                    <form action="<?= h($basePath) ?>/trial_submit.php" method="post" class="trialForm"
                        data-validate="trial" id="trialForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="text" name="website" value="" class="hp" tabindex="-1" autocomplete="off"
                            aria-hidden="true">

                        <label class="trialLabel">
                            <span>メールアドレス <b class="req">*</b></span>
                            <input type="email" name="email" id="trialEmail" placeholder="example@company.com" required
                                autocomplete="email">
                            <small>※所属企業のメールアドレスをご入力ください。</small>

                            <div class="trialInlineWarn" id="emailWarn"></div>
                            <div class="trialInlineOk" id="emailOk">このメールアドレスは利用できます。</div>
                        </label>

                        <label class="trialAgree">
                            <input type="checkbox" name="agree" value="1" required id="trialAgree">
                            <span><a href="/terms.php" target="_blank" rel="noopener">利用規約</a>に同意する</span>
                        </label>

                        <!-- ✅ 初期は押せない：チェックがOKになったら有効化 -->
                        <button class="btn primary w100 trialBtn" type="submit" id="trialSubmitBtn" disabled>
                            アカウント作成
                        </button>

                        <p class="trialFoot">
                            ご入力のメールアドレス宛に、アカウント発行メールを送付します。
                        </p>
                    </form>

                    <div class="trialLinks">
                        <a href="<?= h($basePath) ?>/#ai">AI自動改善を見る</a>
                        <span class="sep">・</span>
                        <a href="<?= h($basePath) ?>/#contact">お問い合わせ</a>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <script src="<?= h($basePath) ?>/assets/app.js?v=3"></script>

    <script>
        (function() {
            const basePath = "<?= h($basePath) ?>";
            const emailEl = document.getElementById('trialEmail');
            const agreeEl = document.getElementById('trialAgree');
            const warnEl = document.getElementById('emailWarn');
            const okEl = document.getElementById('emailOk');
            const btnEl = document.getElementById('trialSubmitBtn');
            const formEl = document.getElementById('trialForm');

            if (!emailEl || !agreeEl || !warnEl || !okEl || !btnEl || !formEl) return;

            // 状態: 'init' | 'checking' | 'ok' | 'blocked' | 'unknown' | 'invalid'
            let state = 'init';
            let timer = null;
            let submitting = false;
            let inflight = 0;

            function isEmailFormat(v) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
            }

            function render() {
                const canSubmit = (state === 'ok') && agreeEl.checked;
                btnEl.disabled = !canSubmit;

                warnEl.style.display = 'none';
                okEl.style.display = 'none';

                if (state === 'ok') {
                    okEl.style.display = 'block';
                    emailEl.setCustomValidity('');
                    return;
                }

                if (state === 'blocked') {
                    warnEl.textContent = 'このメールアドレスはすでに登録されています。別のメールアドレスをご利用ください。';
                    warnEl.style.display = 'block';
                    emailEl.setCustomValidity('このメールアドレスはすでに登録されています。');
                    return;
                }

                if (state === 'unknown') {
                    warnEl.textContent = 'メール確認に失敗しました。通信環境を確認してもう一度お試しください。';
                    warnEl.style.display = 'block';
                    emailEl.setCustomValidity('メール確認に失敗しました。');
                    return;
                }

                if (state === 'invalid') {
                    warnEl.textContent = 'メールアドレスを正しく入力してください。';
                    warnEl.style.display = 'block';
                    emailEl.setCustomValidity('メールアドレスを正しく入力してください。');
                    return;
                }

                emailEl.setCustomValidity('');
            }

            async function checkEmail(force) {
                const v = (emailEl.value || '').trim();

                if (!v) {
                    state = 'init';
                    render();
                    return;
                }

                if (!isEmailFormat(v)) {
                    state = 'invalid';
                    render();
                    return;
                }

                state = 'checking';
                render();

                const my = ++inflight;
                try {
                    const url = basePath + "/trial_email_check.php?email=" + encodeURIComponent(v) + "&_=" + Date
                        .now();
                    const res = await fetch(url, {
                        method: 'GET',
                        credentials: 'same-origin'
                    });
                    const text = await res.text();

                    if (my !== inflight) return;

                    let data = null;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        data = null;
                    }

                    if (!res.ok || !data || data.ok !== true) {
                        state = 'unknown';
                        render();
                        return;
                    }

                    // ✅ ここが重要：exists を最優先で blocked
                    if (data.exists === true) {
                        state = 'blocked';
                        render();
                        return;
                    }

                    // ✅ valid が true じゃないなら invalid（誤って ok にしない）
                    if (data.valid !== true) {
                        state = 'invalid';
                        render();
                        return;
                    }

                    state = 'ok';
                    render();
                } catch (e) {
                    if (my !== inflight) return;
                    state = 'unknown';
                    render();
                }
            }

            function debounceCheck() {
                clearTimeout(timer);
                timer = setTimeout(() => checkEmail(false), 250);
            }

            emailEl.addEventListener('input', debounceCheck);
            emailEl.addEventListener('blur', () => checkEmail(true));
            agreeEl.addEventListener('change', render);

            formEl.addEventListener('submit', function(ev) {
                if (submitting) return;

                ev.preventDefault();
                ev.stopPropagation();

                (async () => {
                    await checkEmail(true);

                    if (!agreeEl.checked) {
                        agreeEl.focus();
                        return;
                    }

                    if (state !== 'ok') {
                        return;
                    }

                    submitting = true;
                    formEl.submit();
                })();
            });

            render();
        })();
    </script>
</body>

</html>
