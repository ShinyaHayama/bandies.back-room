<?php
// /admin/_auth.php

require_once dirname(__DIR__) . '/lib/billing_access.php';

function admin_session_bootstrap(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name('ADMINSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

function require_admin_login(): void
{
    admin_session_bootstrap();

    $script = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($script === 'login.php') return;

    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== 1) {
        $returnTo = $_SERVER['REQUEST_URI'] ?? '/admin/index.php';
        header('Location: /admin/login.php?return_to=' . rawurlencode($returnTo));
        exit;
    }

    // tenantは現状固定（将来マルチテナント化の土台）
    if (!isset($_SESSION['tenant_id'])) {
        $_SESSION['tenant_id'] = 1;
    }

    $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
    if ($tenantId > 0 && admin_is_tenant_inactive($tenantId)) {
        unset(
            $_SESSION['admin_auth'],
            $_SESSION['tenant_id'],
            $_SESSION['tenant_admin_user_id'],
            $_SESSION['tenant_admin_email'],
            $_SESSION['admin_impersonate'],
            $_SESSION['admin_impersonate_tenant_id'],
            $_SESSION['admin_super_sessid']
        );
        session_regenerate_id(true);
        $returnTo = $_SERVER['REQUEST_URI'] ?? '/admin/index.php';
        header('Location: /admin/login.php?locked=1&return_to=' . rawurlencode($returnTo));
        exit;
    }

    admin_load_acl();

    if ($tenantId > 0 && admin_is_trial_restricted($tenantId)) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/index.php', PHP_URL_PATH) ?: '/admin/index.php';
        $allowed = ['/admin/account.php', '/admin/logout.php'];
        if (!in_array($path, $allowed, true)) {
            header('Location: /admin/account.php?trial_expired=1');
            exit;
        }
    }
}

function admin_db_pdo(): ?PDO
{
    try {
        require_once __DIR__ . '/_db.php';
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }
        if (function_exists('db')) {
            $pdo = db();
            if ($pdo instanceof PDO) return $pdo;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

function admin_is_tenant_inactive(int $tenantId): bool
{
    try {
        $pdo = admin_db_pdo();
        if (!$pdo) return false;
        return !billing_access_should_allow($pdo, $tenantId);
    } catch (Throwable $e) {
        return false;
    }
}

function admin_is_trial_restricted(int $tenantId): bool
{
    try {
        $pdo = admin_db_pdo();
        if (!$pdo) return false;

        $manual = billing_access_month_status($pdo, $tenantId);
        if ($manual === 'paid') return false;
        if ($manual === 'unpaid' || $manual === 'stopped') return true;

        $cols = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('trial_ends_at', $cols, true)) return false;

        $st = $pdo->prepare("
            SELECT trial_ends_at,
                   stripe_subscription_status,
                   stripe_subscription_id,
                   billing_status,
                   (trial_ends_at IS NOT NULL AND trial_ends_at <= NOW()) AS trial_expired
            FROM tenants WHERE id = :id LIMIT 1
        ");
        $st->execute([':id' => $tenantId]);
        $row = $st->fetch();
        if (!$row) return false;

        if ((string)($row['billing_status'] ?? 'active') !== 'active') return false;

        $trialEndsAt = (string)($row['trial_ends_at'] ?? '');
        if ($trialEndsAt === '') return false;
        $trialExpired = (int)($row['trial_expired'] ?? 0) === 1;
        if (!$trialExpired) return false;

        $status = strtolower((string)($row['stripe_subscription_status'] ?? ''));
        $subId = (string)($row['stripe_subscription_id'] ?? '');
        $paidStatuses = ['active', 'trialing', 'past_due', 'unpaid'];
        $hasPaid = ($subId !== '' && in_array($status, $paidStatuses, true));
        return !$hasPaid;
    } catch (Throwable $e) {
        return false;
    }
}

function admin_load_acl(): void
{
    $adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);
    $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
    if ($adminUserId <= 0 || $tenantId <= 0) return;

    try {
        $pdo = admin_db_pdo();
        if (!$pdo) return;

        $cols = $pdo->query("SHOW COLUMNS FROM tenant_admin_users")->fetchAll(PDO::FETCH_COLUMN, 0);
        $colSet = array_flip($cols);
        if (!isset($colSet['role'])) {
            $pdo->exec("ALTER TABLE tenant_admin_users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'owner'");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tenant_admin_store_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                tenant_admin_user_id INT NOT NULL,
                store_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_admin_store (tenant_admin_user_id, store_id),
                KEY idx_tenant_admin (tenant_id, tenant_admin_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $st = $pdo->prepare("SELECT role FROM tenant_admin_users WHERE id=:id AND tenant_id=:tenant_id LIMIT 1");
        $st->execute([':id' => $adminUserId, ':tenant_id' => $tenantId]);
        $role = (string)($st->fetch()['role'] ?? 'owner');
        if (!in_array($role, ['owner', 'manager'], true)) $role = 'owner';
        $_SESSION['admin_role'] = $role;

        $allowed = [];
        if ($role === 'owner') {
            $st2 = $pdo->prepare("SELECT id FROM stores WHERE tenant_id=:t ORDER BY id ASC");
            $st2->execute([':t' => $tenantId]);
            $allowed = array_map(fn($r) => (int)$r['id'], $st2->fetchAll(PDO::FETCH_ASSOC));
        } else {
            $st2 = $pdo->prepare("
                SELECT store_id FROM tenant_admin_store_permissions
                WHERE tenant_id = :t AND tenant_admin_user_id = :u
                ORDER BY store_id ASC
            ");
            $st2->execute([':t' => $tenantId, ':u' => $adminUserId]);
            $allowed = array_map(fn($r) => (int)$r['store_id'], $st2->fetchAll(PDO::FETCH_ASSOC));
        }

        $_SESSION['allowed_store_ids'] = $allowed;

        if ($role === 'manager') {
            if (empty($allowed)) {
                http_response_code(403);
                echo '権限がありません（店舗が割り当てられていません）';
                exit;
            }
            $currentStoreId = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
            $defaultStoreId = $allowed[0];
            if ($currentStoreId <= 0) {
                $_GET['store_id'] = $defaultStoreId;
            } elseif (!in_array($currentStoreId, $allowed, true)) {
                $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/index.php', PHP_URL_PATH) ?: '/admin/index.php';
                $qs = $_GET;
                $qs['store_id'] = $defaultStoreId;
                $target = $path . '?' . http_build_query($qs);
                header('Location: ' . $target);
                exit;
            }
        }
    } catch (Throwable $e) {
        return;
    }
}
