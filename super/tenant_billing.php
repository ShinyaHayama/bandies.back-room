<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_super_admin_login();
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/../lib/billing.php';
require_once __DIR__ . '/../lib/billing_access.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['super_csrf_token'])) {
    $_SESSION['super_csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['super_csrf_token'];

function billingStatusOptions(): array
{
    return [
        'paid' => '入金済',
        'unpaid' => '未入金',
        'stopped' => '停止中',
    ];
}

function billingStatusLabel(string $status): string
{
    $map = billingStatusOptions();
    return $map[$status] ?? $status;
}

function deriveDefaultBillingStatus(array $tenantRow): string
{
    $billingStatus = strtolower(trim((string)($tenantRow['billing_status'] ?? 'active')));
    if ($billingStatus !== '' && $billingStatus !== 'active') {
        return 'stopped';
    }

    $stripeStatus = strtolower(trim((string)($tenantRow['stripe_subscription_status'] ?? '')));
    if ($stripeStatus === 'active') {
        return 'paid';
    }
    if (in_array($stripeStatus, ['past_due', 'unpaid'], true)) {
        return 'unpaid';
    }
    if (in_array($stripeStatus, ['canceled', 'cancelled', 'incomplete', 'incomplete_expired', 'paused'], true)) {
        return 'stopped';
    }

    return 'unpaid';
}

$tenantId = (int)($_GET['tenant_id'] ?? 0);
$err = null;
$tenant = null;
$rows = [];

try {
    billing_access_ensure_status_table($pdo);
} catch (Throwable $e) {
    $err = '請求ステータステーブルの初期化に失敗しました: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === null) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $err = 'CSRFトークンが不正です';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'update_billing_status') {
            $tenantIdPost = (int)($_POST['tenant_id'] ?? 0);
            $billingMonth = trim((string)($_POST['billing_month'] ?? ''));
            $status = (string)($_POST['status'] ?? '');
            $allowedStatuses = billingStatusOptions();

            if ($tenantIdPost <= 0 || !preg_match('/^\d{4}-\d{2}$/', $billingMonth) || !isset($allowedStatuses[$status])) {
                $err = '請求ステータスの更新内容が不正です';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO tenant_billing_statuses (tenant_id, billing_month, status, updated_at)
                    VALUES (:tenant_id, :billing_month, :status, NOW())
                    ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()
                ");
                $stmt->execute([
                    ':tenant_id' => $tenantIdPost,
                    ':billing_month' => $billingMonth,
                    ':status' => $status,
                ]);
                billing_access_sync_tenant_status($pdo, $tenantIdPost, $billingMonth, $status);
                header('Location: /super/tenant_billing.php?tenant_id=' . $tenantIdPost);
                exit;
            }
        }
    }
}

if ($tenantId <= 0) {
    $err = $err ?? 'tenant_id が不正です';
} else {
    $stmt = $pdo->prepare("
        SELECT id, name, billing_status, stripe_subscription_id, stripe_subscription_status,
               trial_started_at, trial_ends_at, created_at
        FROM tenants
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $tenantId]);
    $tenant = $stmt->fetch();
    if (!$tenant) {
        $err = $err ?? 'テナントが見つかりません';
    }
}

if (!$err) {
    $baseFee = 3300;
    $perEmployee = 400;
    $tenantTz = new DateTimeZone(billing_tenant_timezone($pdo, $tenantId));
    $now = new DateTimeImmutable('first day of this month 00:00:00', $tenantTz);
    $statusStmt = $pdo->prepare("
        SELECT billing_month, status
        FROM tenant_billing_statuses
        WHERE tenant_id = :tenant_id
    ");
    $statusStmt->execute([':tenant_id' => $tenantId]);
    $statusMap = [];
    foreach ($statusStmt->fetchAll() as $statusRow) {
        $statusMap[(string)$statusRow['billing_month']] = (string)$statusRow['status'];
    }
    $defaultStatus = deriveDefaultBillingStatus($tenant);

    for ($i = 0; $i < 12; $i++) {
        $monthStart = $now->modify("-{$i} months");
        $nextStart = $monthStart->modify('+1 month');
        $label = $monthStart->format('Y-m');

        $count = billing_employee_count($pdo, $tenantId, $monthStart, $nextStart);
        $usageFee = $count * $perEmployee;
        $total = $baseFee + $usageFee;

        $rows[] = [
            'label' => $label,
            'count' => $count,
            'base_fee' => $baseFee,
            'usage_fee' => $usageFee,
            'total' => $total,
            'status' => $statusMap[$label] ?? $defaultStatus,
        ];
    }
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>テナント請求</title>
    <style>
        body {
            font-family: system-ui;
            padding: 18px;
            color: #111;
        }

        .card {
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 14px;
            max-width: 1200px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            border-bottom: 1px solid #eee;
            padding: 8px;
            font-size: 13px;
            text-align: left;
        }

        th {
            background: #fafafa;
        }

        .row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        a {
            color: #111;
        }

        .err {
            background: #ffecec;
            border: 1px solid #ffb3b3;
            padding: 10px;
            border-radius: 10px;
            margin: 10px 0;
        }

        .statusCell {
            white-space: nowrap;
        }

        .statusBadge {
            display: inline-block;
            min-width: 72px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-align: center;
        }

        .status-paid {
            background: #e8f7ee;
            color: #17663a;
        }

        .status-unpaid {
            background: #fff3cd;
            color: #8a5b00;
        }

        .status-stopped {
            background: #ffe3e3;
            color: #a11d1d;
        }

        .statusForm {
            display: inline-flex;
            gap: 6px;
            align-items: center;
        }

        .statusSelect,
        .statusBtn {
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 12px;
            background: #fff;
        }
    </style>
</head>

<body>
    <?php require __DIR__ . '/_top.php'; ?>

    <div class="card">
        <div class="row" style="justify-content:space-between;">
            <h2 style="margin:0;">テナント請求（月次）</h2>
            <a href="/super/tenants.php">← テナント一覧</a>
        </div>

        <?php if ($err): ?>
            <div class="err"><?= h($err) ?></div>
        <?php else: ?>
            <div style="margin-top:6px;font-size:13px;color:#666;">
                テナント: <?= h((string)$tenant['name']) ?>（ID: <?= (int)$tenant['id'] ?>）
            </div>
            <div style="margin-top:6px;font-size:12px;color:#666;">
                基本料金: ¥3,300 / 月 + 従業員 ¥400 / 人 / 月（税別）
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:120px;">対象月</th>
                        <th style="width:120px;">従業員数</th>
                        <th style="width:160px;">基本料金</th>
                        <th style="width:160px;">人数課金</th>
                        <th style="width:180px;">合計</th>
                        <th style="width:260px;">ステータス</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $status = (string)$r['status']; ?>
                        <tr>
                            <td><?= h($r['label']) ?></td>
                            <td><?= number_format((int)$r['count']) ?></td>
                            <td>¥<?= number_format((int)$r['base_fee']) ?></td>
                            <td>¥<?= number_format((int)$r['usage_fee']) ?></td>
                            <td>¥<?= number_format((int)$r['total']) ?></td>
                            <td class="statusCell">
                                <span class="statusBadge status-<?= h($status) ?>"><?= h(billingStatusLabel($status)) ?></span>
                                <form method="post" class="statusForm">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="update_billing_status">
                                    <input type="hidden" name="tenant_id" value="<?= (int)$tenant['id'] ?>">
                                    <input type="hidden" name="billing_month" value="<?= h($r['label']) ?>">
                                    <select name="status" class="statusSelect">
                                        <?php foreach (billingStatusOptions() as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= $value === $status ? 'selected' : '' ?>>
                                                <?= h($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="statusBtn">更新</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</body>

</html>
