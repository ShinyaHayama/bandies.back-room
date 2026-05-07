<?php

declare(strict_types=1);

/**
 * /super/logout.php
 * Super管理 ログアウト
 */

require_once __DIR__ . '/_auth.php';

// super関連だけ消す（他のセッション用途が将来増えても安全）
unset($_SESSION['super_admin_ok'], $_SESSION['super_admin_login_at']);

// CSRFも作り直したいなら消す
unset($_SESSION['super_csrf_token']);

// セッションIDも切り替え（ログアウト後の固定化対策）
session_regenerate_id(true);

header('Location: /super/login.php');
exit;
