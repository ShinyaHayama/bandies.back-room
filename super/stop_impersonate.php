<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

unset($_SESSION['impersonate_tenant_id']);
header('Location: /super/dashboard.php');
exit;