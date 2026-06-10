<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/../lib/stripe.php';
require_once __DIR__ . '/../lib/app_url.php';

if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;
$adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);
if ($adminUserId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$sessionRole = (string)($_SESSION['admin_role'] ?? '');
$isOwner = ($sessionRole === 'owner');
if (!$isOwner) {
    try {
        $roleStmt = $pdo->prepare("SELECT role FROM tenant_admin_users WHERE id=:id AND tenant_id=:tenant_id LIMIT 1");
        $roleStmt->execute([':id' => $adminUserId, ':tenant_id' => $tenantId]);
        $dbRole = (string)($roleStmt->fetch()['role'] ?? '');
        if ($dbRole !== '') {
            $_SESSION['admin_role'] = $dbRole;
            $isOwner = ($dbRole === 'owner');
        }
    } catch (Throwable $e) {
    }
}

// ===== DB =====
$paths = [
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if ($dbFile === null) {
    http_response_code(500);
    exit('db.php not found');
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// stores（header用）
$storesStmt = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id = :t ORDER BY id ASC");
$storesStmt->execute([':t' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (!$stores) {
    http_response_code(400);
    exit('stores なし');
}
$storeId = (int)($_GET['store_id'] ?? 0);
$storeIds = array_map(fn($s) => (int)$s['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) {
    $storeId = (int)$stores[0]['id'];
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

$errors = [];
$success = '';
if (isset($_GET['trial_expired']) && (string)$_GET['trial_expired'] === '1') {
    $errors[] = 'トライアル期限が切れました。お支払いいただくことで通常通り使用できます。';
}

// 現在の情報
$curStmt = $pdo->prepare("
    SELECT email, password_hash
    FROM tenant_admin_users
    WHERE id = :id AND tenant_id = :tenant_id
    LIMIT 1
");
$curStmt->execute([':id' => $adminUserId, ':tenant_id' => $tenantId]);
$cur = $curStmt->fetch();
if (!$cur) {
    header('Location: /admin/login.php');
    exit;
}
$currentEmail = (string)($cur['email'] ?? '');
$currentHash = (string)($cur['password_hash'] ?? '');

$tenantName = '';
try {
    $tStmt = $pdo->prepare("SELECT name FROM tenants WHERE id = :id LIMIT 1");
    $tStmt->execute([':id' => $tenantId]);
    $tenantName = (string)($tStmt->fetch()['name'] ?? '');
} catch (Throwable $e) {
    $tenantName = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $errors[] = 'CSRFトークンが不正です';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'stripe_checkout') {
            if (!$isOwner) {
                $errors[] = '権限がありません（オーナーのみ操作できます）';
            } else {
            try {
                stripe_ensure_tenant_fields($pdo);
                $cfg = stripe_config();
                if ($cfg['secret_key'] === '') throw new RuntimeException('Stripe設定が未完了です（秘密鍵）');

                $tenantStmt = $pdo->prepare("
                    SELECT id, name, stripe_customer_id, stripe_subscription_id, stripe_subscription_status
                    FROM tenants WHERE id = :id LIMIT 1
                ");
                $tenantStmt->execute([':id' => $tenantId]);
                $tenantRow = $tenantStmt->fetch();
                if (!$tenantRow) throw new RuntimeException('テナントが見つかりません');

                $customerId = (string)($tenantRow['stripe_customer_id'] ?? '');
                if ($customerId === '') {
                    $err = '';
                    $customer = stripe_api_request('POST', '/v1/customers', [
                        'name' => $tenantRow['name'] ?? ('tenant-' . $tenantId),
                        'email' => $currentEmail,
                        'metadata[tenant_id]' => (string)$tenantId,
                    ], $err);
                    if (!$customer) throw new RuntimeException('Stripe顧客作成に失敗しました: ' . $err);
                    $customerId = (string)($customer['id'] ?? '');

                    $stmt = $pdo->prepare("UPDATE tenants SET stripe_customer_id = :cid WHERE id = :id LIMIT 1");
                    $stmt->execute([':cid' => $customerId, ':id' => $tenantId]);
                }

                $catalog = stripe_ensure_catalog($pdo, 3300, 400);
                $tz = new DateTimeZone(billing_tenant_timezone($pdo, $tenantId));
                $periodStart = new DateTimeImmutable('first day of this month 00:00:00', $tz);
                $periodEnd = $periodStart->modify('+1 month');
                $seatCount = billing_employee_count($pdo, $tenantId, $periodStart, $periodEnd);

                $err = '';
                $session = stripe_api_request('POST', '/v1/checkout/sessions', [
                    'mode' => 'subscription',
                    'customer' => $customerId,
                    'client_reference_id' => (string)$tenantId,
                    'success_url' => app_public_url('/admin/account.php?billing=success'),
                    'cancel_url' => app_public_url('/admin/account.php?billing=cancel'),
                    'line_items[0][price]' => $catalog['base_price_id'],
                    'line_items[0][quantity]' => 1,
                    'line_items[1][price]' => $catalog['seat_price_id'],
                    'line_items[1][quantity]' => $seatCount,
                    'subscription_data[metadata][tenant_id]' => (string)$tenantId,
                    'subscription_data[metadata][billing_kind]' => 'tenant_seat',
                    'metadata[tenant_id]' => (string)$tenantId,
                ], $err, 'checkout_' . $tenantId . '_' . time());
                if (!$session || empty($session['url'])) {
                    throw new RuntimeException('Stripeセッション作成に失敗しました: ' . $err);
                }

                $stmt = $pdo->prepare("UPDATE tenants SET stripe_checkout_session_id = :sid WHERE id = :id LIMIT 1");
                $stmt->execute([':sid' => (string)($session['id'] ?? ''), ':id' => $tenantId]);

                header('Location: ' . $session['url']);
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
            }
        } else {
            $newEmail = trim((string)($_POST['email'] ?? ''));
            $newPw = (string)($_POST['new_password'] ?? '');
            $newPw2 = (string)($_POST['new_password_confirm'] ?? '');
            $currentPw = (string)($_POST['current_password'] ?? '');

            if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'メールアドレスを正しく入力してください';
            }

            if ($newPw !== '' || $newPw2 !== '') {
                if ($currentPw === '' || !password_verify($currentPw, $currentHash)) {
                    $errors[] = '現在のパスワードが正しくありません';
                } elseif ($newPw === '' || $newPw2 === '') {
                    $errors[] = '新しいパスワードと確認を入力してください';
                } elseif ($newPw !== $newPw2) {
                    $errors[] = '新しいパスワードが一致しません';
                } elseif (mb_strlen($newPw) < 8) {
                    $errors[] = '新しいパスワードは8文字以上にしてください';
                }
            }

            if (!$errors) {
                $params = [
                    ':id' => $adminUserId,
                    ':tenant_id' => $tenantId,
                    ':email' => $newEmail,
                ];
                $setPassword = '';
                if ($newPw !== '' && $newPw2 !== '') {
                    $setPassword = ", password_hash = :password_hash";
                    $params[':password_hash'] = password_hash($newPw, PASSWORD_DEFAULT);
                }

                $stmt = $pdo->prepare("
                    UPDATE tenant_admin_users
                       SET email = :email
                       {$setPassword},
                           updated_at = NOW()
                     WHERE id = :id AND tenant_id = :tenant_id
                     LIMIT 1
                ");
                $stmt->execute($params);

                $_SESSION['tenant_admin_email'] = $newEmail;
                $currentEmail = $newEmail;
                $success = '更新しました';
            }
        }
    }
}

stripe_ensure_tenant_fields($pdo);
$stripeCfg = stripe_config();
$stripeReady = ($stripeCfg['secret_key'] !== '');
$tenantBilling = null;
try {
    $tb = $pdo->prepare("
        SELECT stripe_customer_id, stripe_subscription_id, stripe_subscription_status,
               stripe_subscription_item_id, stripe_trial_ends_at, stripe_current_period_end
        FROM tenants WHERE id = :id LIMIT 1
    ");
    $tb->execute([':id' => $tenantId]);
    $tenantBilling = $tb->fetch();
} catch (Throwable $e) {
    $tenantBilling = null;
}

$tz = new DateTimeZone(billing_tenant_timezone($pdo, $tenantId));
$periodStart = new DateTimeImmutable('first day of this month 00:00:00', $tz);
$periodEnd = $periodStart->modify('+1 month');
$seatCount = billing_employee_count($pdo, $tenantId, $periodStart, $periodEnd);
$baseFee = 3300;
$seatFee = 400;
$monthlyEstimate = $baseFee + ($seatCount * $seatFee);
$statusRaw = (string)($tenantBilling['stripe_subscription_status'] ?? '');
$statusLower = strtolower($statusRaw);
$isBillingActive = in_array($statusLower, ['active', 'trialing', 'past_due', 'unpaid'], true);
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>アカウント</title>
    <style>
        body {
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text)
        }

        .page {
            padding: 14px;
        }

        .card {
            max-width: 720px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 14px;
            box-shadow: none;
            box-sizing: border-box;
            width: 100%;
        }

        .topRow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .backLink {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 900;
            color: #111;
            text-decoration: none;
            padding: 6px 10px;
            border: 1px solid #e5e7eb;
            border-radius: 999px;
            background: #fff;
        }

        .sectionTitle {
            font-size: 16px;
            font-weight: 800;
            margin: 18px 0 8px;
        }

        .billingBox {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            background: #fafafa;
        }

        .billingGrid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            font-size: 13px;
        }

        .billingGrid .label {
            color: #667085;
        }

        .billingActions {
            margin-top: 12px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btnPrimary {
            border: none;
            background: #365EAB;
            color: #fff;
            padding: 10px 14px;
            border-radius: 999px;
            font-weight: 800;
            cursor: pointer;
        }

        .statusBadge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 13px;
        }

        .statusActive {
            background: #e6f7ed;
            color: #0f6a3b;
            border: 1px solid #b7ebc6;
        }

        .statusInactive {
            background: #fff7ed;
            color: #7a3e00;
            border: 1px solid #f2c39a;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .field {
            margin-bottom: 12px;
        }

        .label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 6px;
        }

        .input {
            width: 100%;
            height: 40px;
            border: 1px solid #d0d7de;
            border-radius: 12px;
            padding: 0 12px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .btn {
            height: 40px;
            padding: 0 14px;
            border-radius: 12px;
            border: 1px solid #0f172a;
            background: #0f172a;
            color: #fff;
            font-weight: 900;
            cursor: pointer;
        }

        .note {
            font-size: 12px;
            color: #64748b;
        }

        .err {
            background: #ffecec;
            border: 1px solid #ffb3b3;
            padding: 10px;
            border-radius: 10px;
            margin: 10px 0;
            font-size: 13px;
        }

        .ok {
            background: #ecfdf3;
            border: 1px solid #a7f3d0;
            padding: 10px;
            border-radius: 10px;
            margin: 10px 0;
            font-size: 13px;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="page">
        <div class="card">
            <div class="topRow">
                <div>
                    <h1 style="margin:0;">アカウント設定</h1>
                    <?php if ($tenantName !== ''): ?>
                        <div class="note">テナント：<?= h($tenantName) ?></div>
                    <?php endif; ?>
                </div>
                <a class="backLink" href="/admin/index.php?store_id=<?= (int)$storeId ?>">← 戻る</a>
            </div>

            <?php if ($errors): ?>
                <div class="err">
                    <?= h(implode(' / ', $errors)) ?>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="ok"><?= h($success) ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="update_account">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                <div class="field">
                    <label class="label">メールアドレス</label>
                    <input class="input" type="email" name="email" value="<?= h($currentEmail) ?>" required>
                </div>

                <div class="field">
                    <label class="label">現在のパスワード（パスワード変更時のみ）</label>
                    <input class="input" type="password" name="current_password" autocomplete="current-password">
                </div>

                <div class="field">
                    <label class="label">新しいパスワード</label>
                    <input class="input" type="password" name="new_password" autocomplete="new-password">
                </div>

                <div class="field">
                    <label class="label">新しいパスワード（確認）</label>
                    <input class="input" type="password" name="new_password_confirm" autocomplete="new-password">
                </div>

                <div class="note">※ パスワード変更は任意です。変更しない場合は空欄のまま保存してください。</div>

                <div style="margin-top:12px;">
                    <button class="btn" type="submit">保存</button>
                </div>
            </form>

            <?php if ($isOwner): ?>
                <div class="sectionTitle">お支払い</div>
                <div class="billingBox">
                    <div class="billingGrid">
                        <div class="label">料金</div>
                        <div>¥<?= number_format($baseFee) ?> / 月 + ¥<?= number_format($seatFee) ?> / 人</div>
                        <div class="label">今月の課金対象人数</div>
                        <div><?= number_format($seatCount) ?> 人</div>
                        <div class="label">今月の目安</div>
                        <div>¥<?= number_format($monthlyEstimate) ?></div>
                        <div class="label">ステータス</div>
                        <div>
                            <?php if ($isBillingActive): ?>
                                <span class="statusBadge statusActive">有効</span>
                            <?php else: ?>
                                <span class="statusBadge statusInactive"><?= h($statusRaw !== '' ? $statusRaw : '未設定') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="label">トライアル終了</div>
                        <div><?= h((string)($tenantBilling['stripe_trial_ends_at'] ?? '-')) ?></div>
                        <div class="label">次回更新日</div>
                        <div><?= h((string)($tenantBilling['stripe_current_period_end'] ?? '-')) ?></div>
                    </div>
                    <form method="post" class="billingActions">
                        <input type="hidden" name="action" value="stripe_checkout">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <button type="submit" class="btnPrimary" <?= ($stripeReady && !$isBillingActive) ? '' : 'disabled' ?>>支払い設定を開始</button>
                    </form>
                    <?php if (!$stripeReady): ?>
                        <div class="note">Stripeの設定が未完了のため、決済を開始できません。</div>
                    <?php endif; ?>
                    <div class="note">トライアル30日後に自動で課金が開始されます。</div>
                </div>
            <?php else: ?>
                <div class="sectionTitle">お支払い</div>
                <div class="billingBox">
                    <div class="note">お支払い情報はオーナーのみ確認できます。</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
