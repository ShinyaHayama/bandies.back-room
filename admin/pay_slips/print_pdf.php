<?php

declare(strict_types=1);

/**
 * ✅ 給与明細PDF出力（源泉徴収 “確定保存” を必ず先に実行）
 *
 * 重要：
 * - PDFは「計算しない」
 * - ここで wh_finalize_pay_slip() を呼んでDB保存 → 保存値だけを印字する
 */

require_once __DIR__ . '/../_auth.php';
require_admin_login();

require_once __DIR__ . '/../lib/db.php';
$pdo = db(); // ←あなたのDB接続関数名に合わせてください

// ✅ 源泉徴収（最小）ユーティリティ
require_once __DIR__ . '/lib_withholding.php';

// ✅ 対象ID（パラメータ名はあなたの既存に合わせてOK）
$paySlipId = (int)($_GET['id'] ?? $_GET['pay_slip_id'] ?? 0);
if ($paySlipId <= 0) {
    http_response_code(400);
    echo 'pay_slip_id required';
    exit;
}

try {
    $pdo->beginTransaction();

    // ✅ ここが今回の追加：PDF出力前に必ず確定保存
    // tax_overridden=1 の場合は withholding_tax を上書きしません
    $final = wh_finalize_pay_slip($pdo, $paySlipId, 'v1');

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo 'withholding finalize error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

/* =========================================================
   ✅ ここから下は「既存のPDF生成処理」をそのまま維持してください
   - DBから pay_slips を読み直して、保存値（gross_pay/taxable_pay/withholding_tax/net_pay）を印字
   ========================================================= */

// ✅ 例：保存後の値を読み直す（あなたの既存SQLに合わせて置換OK）
$stmt = $pdo->prepare("SELECT * FROM pay_slips WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $paySlipId]);
$slip = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slip) {
    http_response_code(404);
    echo 'pay_slips not found';
    exit;
}

// --- ここにあなたの既存PDF生成ライブラリ処理 ---
// 例）$pdf->Cell(... $slip['gross_pay'] ...);
// 例）$pdf->Cell(... $slip['taxable_pay'] ...);
// 例）$pdf->Cell(... $slip['withholding_tax'] ...);
// 例）$pdf->Cell(... $slip['net_pay'] ...);

// ※このサンプルは「確定保存を先に必ず通す」ことが目的なので、PDF生成本体は既存を維持してください。