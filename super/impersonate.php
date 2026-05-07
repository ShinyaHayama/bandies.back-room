<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_super_admin_login();

$tenantId = (int)($_GET['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(400);
    echo "bad tenant_id";
    exit;
}

$_SESSION['impersonate_tenant_id'] = $tenantId;
$superSessionId = session_id();

session_write_close();

session_name('ADMINSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

$_SESSION = [];
$_SESSION['admin_auth'] = 1;
$_SESSION['tenant_id'] = $tenantId;
$_SESSION['tenant_admin_user_id'] = 0;
$_SESSION['tenant_admin_email'] = 'super@impersonate';
$_SESSION['admin_impersonate'] = 1;
$_SESSION['admin_impersonate_tenant_id'] = $tenantId;
$_SESSION['admin_super_sessid'] = $superSessionId;
session_regenerate_id(true);
session_write_close();

header('Location: /admin/index.php');

exit;
