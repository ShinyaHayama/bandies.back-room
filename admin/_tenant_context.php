<?php

declare(strict_types=1);

/**
 * /admin/_tenant_context.php
 *
 * 役割:
 * - _auth.php が開始した ADMINSESSID セッションから tenant_id を確定する
 * - ここでは session_start() しない（_auth.php が担当）
 * - ここでは require_admin_login() を呼ばない（呼び出し側が _auth.php を require 済み前提）
 * - ここでは header() で飛ばさない（呼び出し側が扱う）
 *
 * 重要:
 * - tenant_id は「必ず明示的に扱う」方針
 * - super のなりすまし値は admin セッションに “受け渡し” されたもののみ扱う
 *
 * 優先順位:
 * 1) adminセッション内で明示された tenant_id（= なりすましを含む最終決定値）
 * 2) admin_impersonate_tenant_id（過去互換のため残す）
 *
 * 出力:
 * - $tenantId (int) をこのファイルのスコープに用意する
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    // ここに来るのは設計逸脱（_auth.php が session_start する前提）
    // ただし「真っ白」を避けるため例外ではなく 500 表示
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error: session is not active. Load /admin/_auth.php before _tenant_context.php";
    exit;
}

$tenantId = 0;

/**
 * ① 推奨：tenant_id を正として使う（なりすましもここに入れる）
 *   - /admin/login.php 側で tenant_id をセットしているならここで拾える
 */
if (isset($_SESSION['tenant_id']) && (int)$_SESSION['tenant_id'] > 0) {
    $tenantId = (int)$_SESSION['tenant_id'];
}

/**
 * ② 互換：昔のキーが残ってる場合だけ拾う（あれば tenant_id に同期）
 */
if ($tenantId <= 0 && isset($_SESSION['admin_impersonate_tenant_id']) && (int)$_SESSION['admin_impersonate_tenant_id'] > 0) {
    $tenantId = (int)$_SESSION['admin_impersonate_tenant_id'];

    // 以後のブレを防ぐため tenant_id に統一
    $_SESSION['tenant_id'] = $tenantId;
}

/**
 * tenantId を呼び出し側で使う（このファイルではリダイレクトしない）
 * 呼び出し側で必ず
 *   if ($tenantId <= 0) { header('Location: /admin/login.php'); exit; }
 * のように扱うこと
 */