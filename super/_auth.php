<?php

declare(strict_types=1);

function super_session_bootstrap(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name('SUPERSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/super',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

super_session_bootstrap();

/**
 * super 管理ログイン必須
 */
function require_super_admin_login(): void
{
    if (empty($_SESSION['super_admin_ok'])) {
        header('Location: /super/login.php');
        exit;
    }
}

/**
 * ログイン済みか？
 */
function is_super_admin_logged_in(): bool
{
    return !empty($_SESSION['super_admin_ok']);
}
