<?php

declare(strict_types=1);

/**
 * ✅ 目的：
 * PDF出力「直前」に必ず源泉を確定保存する（PDFは保存値を印字するだけ）
 *
 * ✅ 使い方（既存のPDF生成処理の直前に、この関数を呼ぶ）
 *   finalize_withholding_for_slip($pdo, $slipId, 'v1');
 *
 * ⚠️ 注意：
 * - tax_overridden=1 の slip はSP側で触らない設計
 * - ここでは「保存を呼ぶだけ」。税額表の中身（CSV）は別途投入
 */

function finalize_withholding_for_slip(PDO $pdo, int $slipId, string $versionLabel = 'v1'): void
{
    // slipId が不正なら何もしない（落とさない）
    if ($slipId <= 0) {
        return;
    }

    // SP を呼ぶだけ（計算→保存）
    $stmt = $pdo->prepare("CALL sp_finalize_withholding_for_slip(:slip_id, :ver)");
    $stmt->execute([
        ':slip_id' => $slipId,
        ':ver'     => $versionLabel,
    ]);

    // CALL の後は、PDOによっては次結果セットを消費しないと次クエリで詰まることがあるため保険
    while ($stmt->nextRowset()) {
        // 何もしない（結果セットを捨てる）
    }
}

/* ----------------------------------------------------------
   ✅ ここから下は「あなたの既存PDF処理に組み込む例」
   ※ 既存コードに合わせて読み替えてください
---------------------------------------------------------- */

// 例：$pdo, $slipId が取れている前提
// finalize_withholding_for_slip($pdo, $slipId, 'v1');

// 例：この後に pay_slips を SELECT して「保存値」をPDFに印字する
// SELECT gross_pay, non_taxable_total, taxable_pay, withholding_tax, net_pay FROM pay_slips WHERE id = :slipId