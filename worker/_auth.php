<?php
// /worker/_auth.php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/billing_access.php';

function worker_session_bootstrap(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $lifetime = 90 * 24 * 60 * 60;
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_name('WORKERSESSID');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/worker',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();

    if (isset($_SESSION['worker_auth']) && $_SESSION['worker_auth'] === 1) {
        worker_refresh_session_cookie();
    }
}

function require_worker_login(): void
{
    worker_session_bootstrap();

    $script = basename(parse_url($_SERVER['REQUEST_URI'] ?? '/worker/login.php', PHP_URL_PATH));
    if ($script === 'login.php') return;

    if (!isset($_SESSION['worker_auth']) || $_SESSION['worker_auth'] !== 1) {
        $returnTo = $_SERVER['REQUEST_URI'] ?? '/worker/shifts.php';
        header('Location: /worker/login.php?return_to=' . rawurlencode($returnTo));
        exit;
    }

    $tenantId = (int)($_SESSION['worker_tenant_id'] ?? 0);
    if ($tenantId > 0 && worker_is_tenant_inactive($tenantId)) {
        unset(
            $_SESSION['worker_auth'],
            $_SESSION['worker_tenant_id'],
            $_SESSION['worker_store_id'],
            $_SESSION['worker_employee_id'],
            $_SESSION['worker_employee_name'],
            $_SESSION['worker_pin']
        );
        session_regenerate_id(true);
        $returnTo = $_SERVER['REQUEST_URI'] ?? '/worker/shifts.php';
        header('Location: /worker/login.php?locked=1&return_to=' . rawurlencode($returnTo));
        exit;
    }
}

function worker_is_tenant_inactive(int $tenantId): bool
{
    try {
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
        if ($dbFile === null) return false;
        require_once $dbFile;
        $pdo = db();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return !billing_access_should_allow($pdo, $tenantId);
    } catch (Throwable $e) {
        return false;
    }
}

function worker_logout(): void
{
    worker_session_bootstrap();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 3600,
                'path' => $params['path'] ?? '/worker',
                'domain' => $params['domain'] ?? '',
                'secure' => $params['secure'] ?? false,
                'httponly' => $params['httponly'] ?? true,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }
    session_destroy();
}

function worker_refresh_session_cookie(): void
{
    if (headers_sent()) return;
    $params = session_get_cookie_params();
    $lifetime = 90 * 24 * 60 * 60;
    setcookie(
        session_name(),
        session_id(),
        [
            'expires' => time() + $lifetime,
            'path' => $params['path'] ?? '/worker',
            'domain' => $params['domain'] ?? '',
            'secure' => $params['secure'] ?? false,
            'httponly' => $params['httponly'] ?? true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}
