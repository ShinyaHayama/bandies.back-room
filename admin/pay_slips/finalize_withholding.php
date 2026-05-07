<?php

declare(strict_types=1);

/**
 * ✅ 「PDF出力前に確定保存」を実行するAPI（最小）
 * - GET/POST: pay_slip_id を受け取り、源泉計算→pay_slips更新
 * - 返り値 JSON
 *
 * 使い方（例）：
 * - /admin/pay_slips/finalize_withholding.php?pay_slip_id=123
 */

require_once __DIR__ . '/../_auth.php';
require_admin_login();

require_once __DIR__ . '/../lib/db.php';
$pdo = db(); // ←あなたのDB接続関数名に合わせてください

require_once __DIR__ . '/lib_withholding.php';

$paySlipId = (int)($_GET['pay_slip_id'] ?? $_POST['pay_slip_id'] ?? 0);
if ($paySlipId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'pay_slip_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    // ✅ 確定保存（PDFは保存値だけを見る）
    $result = wh_finalize_pay_slip($pdo, $paySlipId, 'v1');

    $pdo->commit();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'pay_slip_id' => $paySlipId, 'result' => $result], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}