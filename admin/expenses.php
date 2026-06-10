<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../lib/store_expenses.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
store_expenses_ensure_schema($pdo);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

$storesStmt = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:t ORDER BY id ASC");
$storesStmt->execute([':t' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (!$stores) exit('storesなし');
$storeIds = array_map(fn($s) => (int)$s['id'], $stores);
$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) $storeId = (int)$stores[0]['id'];
$storeName = '';
foreach ($stores as $s) if ((int)$s['id'] === $storeId) $storeName = (string)$s['name'];

$month = (string)($_GET['month'] ?? date('Y-m'));
if (!store_expenses_valid_month($month)) $month = date('Y-m');
$monthDt = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: new DateTimeImmutable('first day of this month');
$prevMonth = $monthDt->modify('-1 month')->format('Y-m');
$nextMonth = $monthDt->modify('+1 month')->format('Y-m');
$message = '';
$error = '';

function redirect_expenses(int $storeId, string $month): void
{
    header('Location: /admin/expenses.php?store_id=' . $storeId . '&month=' . rawurlencode($month));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        $error = 'CSRFトークンが不正です。';
    } else {
        try {
            if ($action === 'add_fixed') {
                $name = trim((string)($_POST['name'] ?? ''));
                $amount = max(0, (int)($_POST['amount_yen'] ?? 0));
                if ($name === '') throw new RuntimeException('項目名を入力してください。');
                $st = $pdo->prepare("
                    INSERT INTO store_fixed_expenses
                      (tenant_id, store_id, name, amount_yen, is_active, sort_order, created_at, updated_at)
                    VALUES
                      (:t, :s, :name, :amount, 1, 0, NOW(), NOW())
                ");
                $st->execute([':t' => $tenantId, ':s' => $storeId, ':name' => mb_substr($name, 0, 120), ':amount' => $amount]);
                redirect_expenses($storeId, $month);
            } elseif ($action === 'update_fixed') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $amount = max(0, (int)($_POST['amount_yen'] ?? 0));
                $active = ((int)($_POST['is_active'] ?? 0) === 1) ? 1 : 0;
                if ($id <= 0 || $name === '') throw new RuntimeException('入力内容が不正です。');
                $st = $pdo->prepare("
                    UPDATE store_fixed_expenses
                    SET name=:name, amount_yen=:amount, is_active=:active, updated_at=NOW()
                    WHERE id=:id AND tenant_id=:t AND store_id=:s
                    LIMIT 1
                ");
                $st->execute([':name' => mb_substr($name, 0, 120), ':amount' => $amount, ':active' => $active, ':id' => $id, ':t' => $tenantId, ':s' => $storeId]);
                redirect_expenses($storeId, $month);
            } elseif ($action === 'add_monthly') {
                $name = trim((string)($_POST['name'] ?? ''));
                $amount = max(0, (int)($_POST['amount_yen'] ?? 0));
                $memo = trim((string)($_POST['memo'] ?? ''));
                if ($name === '') throw new RuntimeException('項目名を入力してください。');
                $st = $pdo->prepare("
                    INSERT INTO store_monthly_expenses
                      (tenant_id, store_id, expense_month, name, amount_yen, memo, created_at, updated_at)
                    VALUES
                      (:t, :s, :m, :name, :amount, :memo, NOW(), NOW())
                ");
                $st->execute([
                    ':t' => $tenantId,
                    ':s' => $storeId,
                    ':m' => $month,
                    ':name' => mb_substr($name, 0, 120),
                    ':amount' => $amount,
                    ':memo' => mb_substr($memo, 0, 255),
                ]);
                redirect_expenses($storeId, $month);
            } elseif ($action === 'update_monthly') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $amount = max(0, (int)($_POST['amount_yen'] ?? 0));
                $memo = trim((string)($_POST['memo'] ?? ''));
                if ($id <= 0 || $name === '') throw new RuntimeException('入力内容が不正です。');
                $st = $pdo->prepare("
                    UPDATE store_monthly_expenses
                    SET name=:name, amount_yen=:amount, memo=:memo, updated_at=NOW()
                    WHERE id=:id AND tenant_id=:t AND store_id=:s AND expense_month=:m
                    LIMIT 1
                ");
                $st->execute([':name' => mb_substr($name, 0, 120), ':amount' => $amount, ':memo' => mb_substr($memo, 0, 255), ':id' => $id, ':t' => $tenantId, ':s' => $storeId, ':m' => $month]);
                redirect_expenses($storeId, $month);
            } elseif ($action === 'delete_monthly') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $st = $pdo->prepare("DELETE FROM store_monthly_expenses WHERE id=:id AND tenant_id=:t AND store_id=:s AND expense_month=:m LIMIT 1");
                    $st->execute([':id' => $id, ':t' => $tenantId, ':s' => $storeId, ':m' => $month]);
                }
                redirect_expenses($storeId, $month);
            } elseif ($action === 'copy_prev_month') {
                $exists = $pdo->prepare("
                    SELECT COUNT(*) FROM store_monthly_expenses
                    WHERE tenant_id=:t AND store_id=:s AND expense_month=:m
                ");
                $exists->execute([':t' => $tenantId, ':s' => $storeId, ':m' => $month]);
                if ((int)$exists->fetchColumn() === 0) {
                    $st = $pdo->prepare("
                        INSERT INTO store_monthly_expenses
                          (tenant_id, store_id, expense_month, name, amount_yen, memo, created_at, updated_at)
                        SELECT tenant_id, store_id, :to_month, name, amount_yen, memo, NOW(), NOW()
                        FROM store_monthly_expenses
                        WHERE tenant_id=:t AND store_id=:s AND expense_month=:from_month
                    ");
                    $st->execute([':to_month' => $month, ':t' => $tenantId, ':s' => $storeId, ':from_month' => $prevMonth]);
                }
                redirect_expenses($storeId, $month);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$fixedStmt = $pdo->prepare("
    SELECT *
    FROM store_fixed_expenses
    WHERE tenant_id=:t AND store_id=:s
    ORDER BY is_active DESC, sort_order ASC, id ASC
");
$fixedStmt->execute([':t' => $tenantId, ':s' => $storeId]);
$fixedRows = $fixedStmt->fetchAll();

$monthlyStmt = $pdo->prepare("
    SELECT *
    FROM store_monthly_expenses
    WHERE tenant_id=:t AND store_id=:s AND expense_month=:m
    ORDER BY id ASC
");
$monthlyStmt->execute([':t' => $tenantId, ':s' => $storeId, ':m' => $month]);
$monthlyRows = $monthlyStmt->fetchAll();

$fixedTotal = 0;
foreach ($fixedRows as $r) if ((int)$r['is_active'] === 1) $fixedTotal += (int)$r['amount_yen'];
$monthlyTotal = array_sum(array_map(fn($r) => (int)$r['amount_yen'], $monthlyRows));
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>経費設定</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Noto Sans JP", sans-serif; background: #fff; color: #111827; }
        .page { padding: 14px; padding-bottom: 64px; }
        .wrap { max-width: none; margin: 0; }
        .tabsBar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:0; margin-bottom:16px; background:transparent; border:none; overflow:visible; }
        .tabBtn { appearance:none; min-height:44px; border:1px solid #d0d7de; border-radius:999px; padding:0 18px; min-width:132px; background:#fff; color:#0f172a; font-family:system-ui,-apple-system,sans-serif; font-size:13px; font-weight:900; line-height:1; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; white-space:nowrap; cursor:pointer; transition:background .18s ease, color .18s ease, border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
        .tabBtn.isActive { background:linear-gradient(135deg, #365EAB, #4b74c2); color:#fff; border-color:rgba(54, 94, 171, .32); box-shadow:0 10px 24px rgba(54, 94, 171, .18); }
        .tabBtn:focus { outline:2px solid rgba(111, 137, 155, .35); outline-offset:2px; }
        .tabWrap { border:1px solid #e5e7eb; padding:14px; border-radius:16px; }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
        .box { border:1px solid #e5e7eb; background:#fff; padding:14px; border-radius:12px; }
        .boxHead { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; }
        h1, h2 { margin:0; font-size:18px; }
        .muted { color:#6b7280; font-size:12px; font-weight:700; line-height:1.5; }
        .notice { margin-bottom:12px; padding:10px 12px; border-radius:10px; background:#ecfdf5; color:#166534; font-weight:800; }
        .notice.err { background:#fef2f2; color:#991b1b; }
        .summary { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
        .pill { display:inline-flex; align-items:center; padding:7px 10px; border:1px solid #e5e7eb; border-radius:999px; font-size:12px; font-weight:900; color:#374151; background:#f9fafb; }
        .rowForm { display:grid; grid-template-columns: 1.2fr 130px auto auto; gap:8px; align-items:center; padding:8px 0; border-top:1px solid #f1f5f9; }
        .monthlyRow { grid-template-columns: 1fr 120px 1fr auto auto; }
        input, select { width:100%; height:34px; border:1px solid #d1d5db; border-radius:10px; padding:0 10px; font-weight:700; }
        .btn { height:34px; border:1px solid #d1d5db; border-radius:999px; padding:0 12px; background:#fff; color:#111827; font-weight:900; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; white-space:nowrap; }
        .btn.primary { background:#111827; color:#fff; border-color:#111827; }
        .btn.danger { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
        .monthNav { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .empty { padding:16px; border:1px dashed #d1d5db; border-radius:12px; color:#6b7280; font-weight:800; text-align:center; }
        .expenseTools { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:8px 0 10px; }
        .compactAction { height:28px; padding:0 10px; font-size:11px; }
        .receiptBox { margin:0; }
        .receiptBox summary { width:fit-content; list-style:none; cursor:pointer; }
        .receiptBox summary::-webkit-details-marker { display:none; }
        .receiptPanel { display:none; margin:0 0 12px; padding:10px; border:1px solid #e5e7eb; border-radius:12px; background:#f9fafb; }
        .receiptPanel.isOpen { display:block; }
        .receiptHelp { font-size:10px; line-height:1.45; }
        .receiptControls { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px; }
        .receiptControls input[type="file"] { width:min(360px, 100%); height:auto; padding:8px; background:#fff; }
        .receiptResult { display:none; margin-top:10px; padding:10px; border:1px solid #d1fae5; border-radius:10px; background:#ecfdf5; color:#065f46; font-size:12px; font-weight:800; line-height:1.6; }
        .receiptResult.isError { border-color:#fecdd3; background:#fff1f2; color:#be123c; }
        @media (max-width: 860px) {
            .grid { grid-template-columns:1fr; }
            .rowForm, .monthlyRow { grid-template-columns:1fr; align-items:stretch; }
            .tabBtn { min-width:120px; min-height:40px; padding:0 14px; }
        }
    </style>
</head>
<body data-mode="settings">
    <?php require_once __DIR__ . '/_header.php'; ?>
    <div class="page">
        <div class="wrap">
            <?php if ($message): ?><div class="notice"><?= h($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice err"><?= h($error) ?></div><?php endif; ?>

            <div class="tabsBar" role="tablist" aria-label="設定">
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#list">従業員設定</a>
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#add">従業員追加</a>
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#payroll">店舗設定</a>
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#store">店舗追加</a>
                <a class="tabBtn" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>#labor">人件費設定</a>
                <a class="tabBtn isActive" href="/admin/expenses.php?store_id=<?= (int)$storeId ?>&month=<?= h($month) ?>">経費</a>
                <a class="tabBtn" href="/admin/devices_manage.php?store_id=<?= (int)$storeId ?>">端末管理</a>
                <a class="tabBtn" href="/admin/color_settings.php?store_id=<?= (int)$storeId ?>">テーマ変更</a>
            </div>

            <div class="tabWrap">
                <div class="boxHead">
                    <div>
                        <h1>経費設定</h1>
                        <div class="muted">店舗：<?= h($storeName) ?> / 固定経費と月別経費を登録すると、ホームで推定利益を表示できます。</div>
                    </div>
                    <a class="btn" href="/admin/index.php?store_id=<?= (int)$storeId ?>">ホームへ</a>
                </div>

                <div class="summary">
                    <span class="pill">固定経費：<?= number_format($fixedTotal) ?>円/月</span>
                    <span class="pill"><?= h($month) ?> 月別経費：<?= number_format($monthlyTotal) ?>円</span>
                    <span class="pill">合計：<?= number_format($fixedTotal + $monthlyTotal) ?>円</span>
                </div>

                <div class="grid">
                    <section class="box">
                        <div class="boxHead">
                            <div>
                                <h2>固定経費</h2>
                                <div class="muted">家賃・通信費・サブスクなど、毎月ほぼ変わらない費用。</div>
                            </div>
                        </div>

                        <form class="rowForm" method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="add_fixed">
                            <input name="name" placeholder="例：家賃" required>
                            <input name="amount_yen" type="number" min="0" step="1" placeholder="80000" required>
                            <span></span>
                            <button class="btn primary" type="submit">追加</button>
                        </form>

                        <?php if (!$fixedRows): ?>
                            <div class="empty">固定経費が未設定です。家賃・通信費などを追加してください。</div>
                        <?php endif; ?>
                        <?php foreach ($fixedRows as $r): ?>
                        <form class="rowForm" method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="update_fixed">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input name="name" value="<?= h((string)$r['name']) ?>" required>
                            <input name="amount_yen" type="number" min="0" step="1" value="<?= (int)$r['amount_yen'] ?>" required>
                            <select name="is_active">
                                <option value="1" <?= (int)$r['is_active'] === 1 ? 'selected' : '' ?>>ON</option>
                                <option value="0" <?= (int)$r['is_active'] === 0 ? 'selected' : '' ?>>OFF</option>
                            </select>
                            <button class="btn" type="submit">保存</button>
                        </form>
                        <?php endforeach; ?>
                    </section>

                    <section class="box">
                        <div class="boxHead">
                            <div>
                                <h2>月別経費</h2>
                                <div class="muted">広告費・消耗品・水道光熱費など、月ごとに変わる費用。</div>
                                <div class="expenseTools">
                                    <a class="btn compactAction" href="/admin/expenses.php?store_id=<?= (int)$storeId ?>&month=<?= h($prevMonth) ?>">前月</a>
                                    <span class="pill"><?= h($monthDt->format('Y年n月')) ?></span>
                                    <a class="btn compactAction" href="/admin/expenses.php?store_id=<?= (int)$storeId ?>&month=<?= h($nextMonth) ?>">翌月</a>
                                    <form method="post" style="margin:0;" onsubmit="return confirm('前月の月別経費をコピーしますか？既に入力済みの月にはコピーしません。');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="copy_prev_month">
                                        <button class="btn compactAction" type="submit">前月からコピー</button>
                                    </form>
                                    <button class="btn compactAction" type="button" id="receiptToggleBtn">レシートから入力</button>
                                </div>
                            </div>
                        </div>

                        <div class="receiptPanel" id="receiptPanel">
                            <div class="muted receiptHelp">JPG / PNG / WebP、8MB以内。読み取り後にフォームへ反映し、内容を確認してから追加してください。</div>
                            <div class="receiptControls">
                                <input type="file" id="receiptImage" accept="image/jpeg,image/png,image/webp">
                                <button class="btn compactAction" type="button" id="receiptReadBtn">読み取る</button>
                            </div>
                            <div class="receiptResult" id="receiptResult"></div>
                        </div>

                        <form class="rowForm monthlyRow" method="post" id="monthlyAddForm">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="add_monthly">
                            <input name="name" id="monthlyName" placeholder="例：広告費" required>
                            <input name="amount_yen" id="monthlyAmount" type="number" min="0" step="1" placeholder="30000" required>
                            <input name="memo" id="monthlyMemo" placeholder="メモ">
                            <span></span>
                            <button class="btn primary" type="submit">追加</button>
                        </form>

                        <?php if (!$monthlyRows): ?>
                            <div class="empty"><?= h($monthDt->format('Y年n月')) ?>の月別経費は未入力です。</div>
                        <?php endif; ?>
                        <?php foreach ($monthlyRows as $r): ?>
                        <form class="rowForm monthlyRow" method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="update_monthly">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input name="name" value="<?= h((string)$r['name']) ?>" required>
                            <input name="amount_yen" type="number" min="0" step="1" value="<?= (int)$r['amount_yen'] ?>" required>
                            <input name="memo" value="<?= h((string)($r['memo'] ?? '')) ?>">
                            <button class="btn" type="submit">保存</button>
                            <button class="btn danger" type="submit" name="action" value="delete_monthly" onclick="return confirm('削除しますか？');">削除</button>
                        </form>
                        <?php endforeach; ?>
                    </section>
                </div>
            </div>
        </div>
    </div>
    <script>
        (() => {
            const fileInput = document.getElementById('receiptImage');
            const toggleBtn = document.getElementById('receiptToggleBtn');
            const panel = document.getElementById('receiptPanel');
            const readBtn = document.getElementById('receiptReadBtn');
            const result = document.getElementById('receiptResult');
            const nameInput = document.getElementById('monthlyName');
            const amountInput = document.getElementById('monthlyAmount');
            const memoInput = document.getElementById('monthlyMemo');

            const showResult = (message, isError = false) => {
                result.style.display = 'block';
                result.classList.toggle('isError', isError);
                result.textContent = message;
            };

            toggleBtn?.addEventListener('click', () => {
                panel?.classList.toggle('isOpen');
            });

            readBtn?.addEventListener('click', async () => {
                const file = fileInput?.files?.[0];
                if (!file) {
                    showResult('画像を選択してください。', true);
                    return;
                }
                if (file.size > 8 * 1024 * 1024) {
                    showResult('画像サイズは8MB以内にしてください。', true);
                    return;
                }

                const fd = new FormData();
                fd.append('csrf_token', <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>);
                fd.append('store_id', <?= (int)$storeId ?>);
                fd.append('receipt_image', file);

                readBtn.disabled = true;
                showResult('読み取り中です...');
                try {
                    const res = await fetch('/admin/expense_receipt_ocr.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                    });
                    const json = await res.json();
                    if (!json.ok) throw new Error(json.error || '読み取りに失敗しました。');

                    nameInput.value = json.name || json.vendor || 'レシート経費';
                    amountInput.value = String(json.total_yen || '');
                    memoInput.value = json.memo || (json.date ? ('レシート ' + json.date) : 'レシート');
                    showResult(
                        `読み取り結果をフォームに反映しました。\n店名: ${json.vendor || '-'}\n日付: ${json.date || '-'}\n合計金額: ${(Number(json.total_yen || 0)).toLocaleString()}円`
                    );
                    nameInput.focus();
                } catch (e) {
                    showResult(String(e.message || e), true);
                } finally {
                    readBtn.disabled = false;
                }
            });
        })();
    </script>
</body>
</html>
