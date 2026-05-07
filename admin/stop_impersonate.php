<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

admin_session_bootstrap();
$superSessId = (string)($_SESSION['admin_super_sessid'] ?? '');
$_SESSION = [];
session_regenerate_id(true);
session_write_close();

if ($superSessId !== '') {
    require_once __DIR__ . '/../super/_auth.php';
    session_id($superSessId);
    super_session_bootstrap();
    unset($_SESSION['impersonate_tenant_id']);
    session_write_close();
    header('Location: /super/tenants.php');
    exit;
}

header('Location: /super/login.php');
exit;
