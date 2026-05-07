<?php

declare(strict_types=1);

session_name('ADMINSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

// セッション破棄
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'] ?? '/admin', $p['domain'] ?? '', (bool)($p['secure'] ?? false), (bool)($p['httponly'] ?? true));
}

session_destroy();

header('Location: /admin/login.php');
exit;