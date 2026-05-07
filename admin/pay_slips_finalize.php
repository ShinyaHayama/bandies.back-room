<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/pay_slips_finalize.php
 * ✅ 書き込み場所: このファイルを「新規作成」または「丸ごと置き換え」
 *
 * 目的:
 * - 「給与確定ボタン」用のエンドポイント
 * - taxable_pay / withholding_tax / net_pay を DB に確定保存する
 * - tax_overridden=1 の場合は絶対に上書きしない
 *
 * 使い方（POST想定）:
 * - slip_id: 確定対象の pay_slips.id
 * - version_label: 例 'v2026_01'（省略時は v2026_01）
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

$__logDir = __DIR__ . '/logs';
@mkdir($__logDir, 0777, true);
$__errLog = $__logDir . '/pay_slips_finalize_error.log';

function flog(string $msg): void
{
    global $__errLog;
    @file_put_contents($__errLog, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

set_exception_handler(static function (Throwable $e) {
    flog("UNCAUGHT_EXCEPTION " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
    exit;
});

// =========================
// 認証（必要なら）
// =========================
$auth = __DIR__ . '/_auth.php';
if (is_file($auth)) {
    require_once $auth;
    if (function_exists('require_admin_login')) {
        require_admin_login();
    }
}

// =========================
// テナントコンテキスト（あれば）
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
// DB（/admin/_db.php）
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
$versionLabel = trim((string)($_POST['version_label'] ?? 'v2026_01'));

if ($slipId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'slip_id_required'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($versionLabel === '') {
    $versionLabel = 'v2026_01';
}

$pdo->beginTransaction();

try {
    // 1) slip を取得（tenantId があるなら絞る）
    $sql = "SELECT ps.*, e.tax_type
            FROM pay_slips ps
            JOIN employees e ON e.id = ps.employee_id
            WHERE ps.id = :id";
    $params = [':id' => $slipId];

    if ($tenantId !== null) {
        $sql .= " AND ps.tenant_id = :tenant_id";
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

    // 2) 手動上書きなら触らない
    $taxOver = (int)($row['tax_overridden'] ?? 0);
    if ($taxOver === 1) {
        $pdo->commit();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'tax_overridden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3) taxable_pay を確定（gross - non_taxable）
    $gross = (int)($row['gross_pay'] ?? 0);
    $nonTax = (int)($row['non_taxable_total'] ?? 0);
    $taxable = max($gross - $nonTax, 0);

    // 4) 税額表から tax_yen を引く
    //    ※ 照合順序混在を避けるため、両辺を同一 COLLATE で比較
    $payCycle = (string)($row['pay_cycle'] ?? '');
    $taxType  = (string)($row['tax_type'] ?? 'otsu');

    $st2 = $pdo->prepare("
        SELECT tr.tax_yen
        FROM tax_withholding_tables tt
        JOIN tax_withholding_rows tr ON tr.table_id = tt.id
        WHERE (CONVERT(tt.pay_cycle USING utf8mb4) COLLATE utf8mb4_unicode_ci) = (CONVERT(:pay_cycle USING utf8mb4) COLLATE utf8mb4_unicode_ci)
          AND (CONVERT(tt.tax_type  USING utf8mb4) COLLATE utf8mb4_unicode_ci) = (CONVERT(:tax_type  USING utf8mb4) COLLATE utf8mb4_unicode_ci)
          AND (CONVERT(tt.version_label USING utf8mb4) COLLATE utf8mb4_unicode_ci) = (CONVERT(:version_label USING utf8mb4) COLLATE utf8mb4_unicode_ci)
          AND :taxable >= tr.lower_yen
          AND (tr.upper_yen IS NULL OR :taxable <= tr.upper_yen)
        ORDER BY tr.lower_yen DESC
        LIMIT 1
    ");
    $st2->execute([
        ':pay_cycle' => $payCycle,
        ':tax_type' => $taxType,
        ':version_label' => $versionLabel,
        ':taxable' => $taxable,
    ]);
    $taxYen = $st2->fetchColumn();
    $withholding = ($taxYen === false) ? 0 : (int)$taxYen;

    // 5) net_pay 確定（社保なし前提: gross - withholding）
    $net = max($gross - $withholding, 0);

    // 6) 保存（finalized_at があるなら入れる）
    //    ※ finalized_at / pdf_generated_at の列が無い環境でも落ちないように UPDATE を分ける
    $st3 = $pdo->prepare("
        UPDATE pay_slips
        SET taxable_pay = :taxable,
            withholding_tax = :withholding,
            net_pay = :net
        WHERE id = :id
        " . ($tenantId !== null ? " AND tenant_id = :tenant_id" : "") . "
        LIMIT 1
    ");
    $bind = [
        ':taxable' => $taxable,
        ':withholding' => $withholding,
        ':net' => $net,
        ':id' => $slipId,
    ];
    if ($tenantId !== null) {
        $bind[':tenant_id'] = $tenantId;
    }
    $st3->execute($bind);

    // finalized_at があるなら入れる（無いなら無視）
    try {
        $pdo->exec("UPDATE pay_slips SET finalized_at = NOW() WHERE id = " . (int)$slipId . " LIMIT 1");
    } catch (Throwable $e) {
        // カラム未存在などは無視（要件次第で後で整備）
        flog("finalized_at_update_skip " . $e->getMessage());
    }

    $pdo->commit();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'slip_id' => $slipId,
        'version_label' => $versionLabel,
        'taxable_pay' => $taxable,
        'withholding_tax' => $withholding,
        'net_pay' => $net,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}