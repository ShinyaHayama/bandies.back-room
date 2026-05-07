<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/pay_slip_pdf.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * 目的:
 * - /admin/pay_slip_pdf.php?slip_id=5 で 500 にならないようにする
 * - slip_id が取れたら「診断でexit」せず、PDF/HTML出力まで必ず進む
 * - DBは /admin/_db.php（$pdo）を必ず使う（az_db()/fl_db()は使わない）
 * - dompdf が無い/壊れていても HTML にフォールバックして 500 を回避
 *
 * 追加:
 * - 例外は画面に出さずログへ（/admin/logs/pay_slip_pdf_error.log）
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

$__logDir = __DIR__ . '/logs';
@mkdir($__logDir, 0777, true);
$__errLog = $__logDir . '/pay_slip_pdf_error.log';

function pslog(string $msg): void
{
    global $__errLog;
    @file_put_contents($__errLog, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

set_error_handler(static function ($severity, $message, $file, $line) {
    pslog("PHP_ERROR severity={$severity} msg={$message} file={$file} line={$line}");
    // PHP標準のハンドラにも渡す
    return false;
});
set_exception_handler(static function (Throwable $e) {
    pslog("UNCAUGHT_EXCEPTION " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n";
    echo "原因ログ: /admin/logs/pay_slip_pdf_error.log\n";
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
    require_once $tenantCtx; // $tenantId を期待
    if (isset($tenantId) && (int)$tenantId > 0) {
        $tenantId = (int)$tenantId;
    } else {
        $tenantId = null;
    }
}

// =========================
// DB（/admin/_db.php から $pdo を取得）
// =========================
$dbFile = __DIR__ . '/_db.php';
if (!is_file($dbFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "_db.php not found";
    exit;
}
require_once $dbFile;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PDO handle not available (_db.php did not set \$pdo)";
    exit;
}

// =========================
// 入力: slip_id（互換）
// =========================
$slipId = (int)($_GET['slip_id'] ?? $_GET['id'] ?? $_GET['pay_slip_id'] ?? 0);
if ($slipId <= 0) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "slip_id is required. Example: /admin/pay_slip_pdf.php?slip_id=5\n";
    exit;
}

// =========================
// pay_slips 取得（tenantId が取れるなら絞る）
// =========================
$sql = "SELECT * FROM pay_slips WHERE id = :id";
$params = [':id' => $slipId];
if ($tenantId !== null) {
    $sql .= " AND tenant_id = :tenant_id";
    $params[':tenant_id'] = $tenantId;
}
$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$slip = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$slip) {
    // テナント不一致 or 存在しない を切り分け（診断は 500 にしない）
    $stmt2 = $pdo->prepare("SELECT id, tenant_id, employee_id, pay_period_id FROM pay_slips WHERE id=:id LIMIT 1");
    $stmt2->execute([':id' => $slipId]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;

    header('Content-Type: text/plain; charset=utf-8');
    echo "pay_slips が見つかりません\n";
    echo "slip_id={$slipId}\n";
    if (!$row) {
        echo "結論: id={$slipId} は pay_slips に存在しません\n";
        exit;
    }
    echo "pay_slips側 tenant_id={$row['tenant_id']}\n";
    echo "ログイン側 tenant_id=" . ($tenantId === null ? 'NULL' : (string)$tenantId) . "\n";
    echo "結論: tenant 不一致のため取得できません\n";
    exit;
}

// =========================
// 表示値（保存値をそのまま）
// =========================
$grossPay = (int)($slip['gross_pay'] ?? 0);
$nonTax   = (int)($slip['non_taxable_total'] ?? 0);
$taxable  = (int)($slip['taxable_pay'] ?? 0);
$withhold = (int)($slip['withholding_tax'] ?? 0);
$netPay   = (int)($slip['net_pay'] ?? 0);
$nightPremium = (int)($slip['night_premium_yen'] ?? 0);

$payCycle = (string)($slip['pay_cycle'] ?? '');
$payDate  = (string)($slip['payment_date'] ?? '');

// =========================
// HTML（PDF化する元）
// =========================
$html = '
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>給与明細</title>
  <style>
    body { font-family: sans-serif; font-size: 12px; }
    .box { border: 1px solid #333; padding: 12px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; }
    th, td { border: 1px solid #999; padding: 6px; text-align: left; }
  </style>
</head>
<body>
  <div class="box">
    <div><b>Pay Slip ID:</b> ' . (int)$slip['id'] . '</div>
    <div><b>Pay Cycle:</b> ' . htmlspecialchars($payCycle, ENT_QUOTES, "UTF-8") . '</div>
    <div><b>Payment Date:</b> ' . htmlspecialchars($payDate, ENT_QUOTES, "UTF-8") . '</div>

    <table>
      <tr><th>総支給</th><td>' . number_format($grossPay) . ' 円</td></tr>
      <tr><th>深夜割増</th><td>' . number_format($nightPremium) . ' 円</td></tr>
      <tr><th>非課税合計</th><td>' . number_format($nonTax) . ' 円</td></tr>
      <tr><th>課税対象</th><td>' . number_format($taxable) . ' 円</td></tr>
      <tr><th>源泉徴収</th><td>' . number_format($withhold) . ' 円</td></tr>
      <tr><th>差引支給</th><td>' . number_format($netPay) . ' 円</td></tr>
    </table>
  </div>
</body>
</html>
';

// =========================
// PDF出力（dompdf があればPDF / 無ければHTML）
// =========================
try {
    $autoloads = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];
    foreach ($autoloads as $a) {
        if (is_file($a)) {
            require_once $a;
            break;
        }
    }

    if (class_exists(\Dompdf\Dompdf::class)) {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="pay_slip_' . (int)$slip['id'] . '.pdf"');
        echo $dompdf->output();
        exit;
    }
} catch (Throwable $e) {
    // dompdf 周りで落ちても 500 にしない（HTMLへ退避）
    pslog("DOMPDF_FAIL " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
}

// dompdf が無い/失敗 → HTML表示
header('Content-Type: text/html; charset=utf-8');
echo $html;
exit;
