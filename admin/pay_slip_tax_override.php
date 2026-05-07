<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/pay_slip_tax_override.php
 * ✅ 書き込み場所: このファイルを「新規作成」または「丸ごと置き換え」
 *
 * 目的:
 * - tax_overridden=1 にして「自動再計算禁止」を確実に運用する
 * - 源泉徴収税額(withholding_tax)を手入力で確定させる
 *
 * 使い方（POST想定）:
 * - slip_id
 * - withholding_tax（手入力したい税額）
 * - reason（任意）
 *
 * 動作:
 * - tax_overridden=1
 * - tax_override_reason を保存（列があれば）
 * - net_pay を gross_pay - withholding_tax で再計算（社保なし前提）
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

$__logDir = __DIR__ . '/logs';
@mkdir($__logDir, 0777, true);
$__errLog = $__logDir . '/pay_slip_tax_override_error.log';

function olog(string $msg): void
{
    global $__errLog;
    @file_put_contents($__errLog, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

set_exception_handler(static function (Throwable $e) {
    olog("UNCAUGHT_EXCEPTION " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
    exit;
});

// =========================
// 認証
// =========================
$auth = __DIR__ . '/_auth.php';
if (is_file($auth)) {
    require_once $auth;
    if (function_exists('require_admin_login')) {
        require_admin_login();
    }
}

// =========================
// テナントコンテキスト
// =========================
$tenantId = null;
$tenantCtx = __DIR__ . '/_tenant_context.php';
if (is_file($tenantCtx)) {
    require_once $tenantCtx;
    if (isset($tenantId) && (int)$tenantId > 0) {
        $tenantId = (int)$tenantId;
    } else {
        $tenantId = null;
    }
}

// =========================
// DB
// =========================
$dbFile = __DIR__ . '/_db.php';
if (!is_file($dbFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '_db.php_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $dbFile;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'pdo_not_available'], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================
// 入力
// =========================
$slipId = (int)($_POST['slip_id'] ?? 0);
$withholding = (int)($_POST['withholding_tax'] ?? -1);
$reason = trim((string)($_POST['reason'] ?? ''));

if ($slipId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'slip_id_required'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($withholding < 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'withholding_tax_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->beginTransaction();

try {
    // 1) slip を取得（gross_pay を取る）
    $sql = "SELECT id, tenant_id, gross_pay
            FROM pay_slips
            WHERE id = :id";
    $params = [':id' => $slipId];
    if ($tenantId !== null) {
        $sql .= " AND tenant_id = :tenant_id";
        $params[':tenant_id'] = $tenantId;
    }
    $sql .= " LIMIT 1";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$row) {
        $pdo->rollBack();
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'pay_slip_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $gross = (int)($row['gross_pay'] ?? 0);
    $net = max($gross - $withholding, 0);

    // 2) 基本更新（列が存在する前提で安全に）
    $st2 = $pdo->prepare("
        UPDATE pay_slips
        SET withholding_tax = :withholding,
            net_pay = :net,
            tax_overridden = 1
        WHERE id = :id
        " . ($tenantId !== null ? " AND tenant_id = :tenant_id" : "") . "
        LIMIT 1
    ");
    $bind = [
        ':withholding' => $withholding,
        ':net' => $net,
        ':id' => $slipId,
    ];
    if ($tenantId !== null) {
        $bind[':tenant_id'] = $tenantId;
    }
    $st2->execute($bind);

    // 3) reason が保存できるなら保存（列が無いなら無視）
    if ($reason !== '') {
        try {
            $st3 = $pdo->prepare("
                UPDATE pay_slips
                SET tax_override_reason = :reason
                WHERE id = :id
                LIMIT 1
            ");
            $st3->execute([':reason' => $reason, ':id' => $slipId]);
        } catch (Throwable $e) {
            olog("tax_override_reason_update_skip " . $e->getMessage());
        }
    }

    $pdo->commit();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'slip_id' => $slipId,
        'withholding_tax' => $withholding,
        'net_pay' => $net,
        'tax_overridden' => 1,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}