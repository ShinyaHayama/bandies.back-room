<?php

declare(strict_types=1);

/**
 * ✅ 税額表CSVの取り込み処理（最小・安全寄り）
 * - 対象：tax_withholding_tables / tax_withholding_rows
 * - 同じ table_id に対して「一旦全削除→全投入」する（事故を防ぐため）
 *
 * 注意：
 * - 本番運用では「version_label を上げる」運用が安全（v2, v3...）
 */

require_once __DIR__ . '/../_auth.php';
require_admin_login();

require_once __DIR__ . '/../lib/db.php';
$pdo = db(); // ←あなたのDB接続関数名に合わせてください

function bad(string $msg): void
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$payCycle = strtolower(trim((string)($_POST['pay_cycle'] ?? '')));
$taxType  = strtolower(trim((string)($_POST['tax_type'] ?? '')));
$version  = trim((string)($_POST['version_label'] ?? 'v1'));

if (!in_array($payCycle, ['monthly', 'weekly', 'daily'], true)) bad('pay_cycle invalid');
if (!in_array($taxType, ['ko', 'otsu'], true)) bad('tax_type invalid');
if ($version === '') bad('version_label missing');

if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
    bad('csv_file missing');
}

$tmp = $_FILES['csv_file']['tmp_name'];
$fp = fopen($tmp, 'rb');
if (!$fp) bad('failed to open csv');

$pdo->beginTransaction();

try {
    // 1) 対象の器（tax_withholding_tables.id）を取得（無ければ作る）
    $stmt = $pdo->prepare("
        SELECT id
        FROM tax_withholding_tables
        WHERE pay_cycle=:c AND tax_type=:t AND version_label=:v
        LIMIT 1
    ");
    $stmt->execute([':c' => $payCycle, ':t' => $taxType, ':v' => $version]);
    $tableId = (int)($stmt->fetchColumn() ?: 0);

    if ($tableId <= 0) {
        $stmt = $pdo->prepare("
            INSERT INTO tax_withholding_tables (pay_cycle, tax_type, version_label)
            VALUES (:c,:t,:v)
        ");
        $stmt->execute([':c' => $payCycle, ':t' => $taxType, ':v' => $version]);
        $tableId = (int)$pdo->lastInsertId();
    }

    // 2) 既存レンジを全削除（同一 table_id）
    $stmt = $pdo->prepare("DELETE FROM tax_withholding_rows WHERE table_id=:tid");
    $stmt->execute([':tid' => $tableId]);

    // 3) CSVを読む
    $rows = [];
    $line = 0;

    while (($cols = fgetcsv($fp)) !== false) {
        $line++;

        // 空行スキップ
        if (!$cols || count($cols) === 0) continue;

        // BOM/空白除去
        $cols = array_map(static function ($v) {
            $v = (string)$v;
            $v = preg_replace('/^\xEF\xBB\xBF/', '', $v); // BOM
            return trim($v);
        }, $cols);

        // ヘッダっぽい行はスキップ（lower_yen 等）
        $joined = strtolower(implode(',', $cols));
        if (str_contains($joined, 'lower') && str_contains($joined, 'tax')) {
            continue;
        }

        // 期待：3列
        if (count($cols) < 3) {
            throw new RuntimeException("CSV format error at line {$line}: need 3 columns (lower, upper, tax)");
        }

        $lower = $cols[0];
        $upper = $cols[1];
        $tax   = $cols[2];

        if ($lower === '' || $tax === '') {
            throw new RuntimeException("CSV value missing at line {$line}: lower/tax required");
        }

        if (!ctype_digit($lower)) {
            throw new RuntimeException("CSV invalid lower_yen at line {$line}");
        }
        if ($upper !== '' && !ctype_digit($upper)) {
            throw new RuntimeException("CSV invalid upper_yen at line {$line}");
        }
        if (!ctype_digit($tax)) {
            throw new RuntimeException("CSV invalid tax_yen at line {$line}");
        }

        $lowerI = (int)$lower;
        $upperI = ($upper === '') ? null : (int)$upper;
        $taxI   = (int)$tax;

        if ($upperI !== null && $upperI < $lowerI) {
            throw new RuntimeException("CSV range invalid at line {$line}: upper < lower");
        }

        $rows[] = [$lowerI, $upperI, $taxI];
    }

    fclose($fp);

    if (count($rows) === 0) {
        throw new RuntimeException("CSV rows empty (no data)");
    }

    // 4) 一括INSERT（安全のため分割）
    $stmt = $pdo->prepare("
        INSERT INTO tax_withholding_rows (table_id, lower_yen, upper_yen, tax_yen)
        VALUES (:tid, :l, :u, :tax)
    ");

    foreach ($rows as [$l, $u, $tax]) {
        $stmt->execute([
            ':tid' => $tableId,
            ':l'   => $l,
            ':u'   => $u,
            ':tax' => $tax,
        ]);
    }

    $pdo->commit();

    header('Content-Type: text/plain; charset=utf-8');
    echo "OK\n";
    echo "table_id={$tableId}\n";
    echo "inserted_rows=" . count($rows) . "\n";
    echo "pay_cycle={$payCycle}\n";
    echo "tax_type={$taxType}\n";
    echo "version_label={$version}\n";
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: " . $e->getMessage();
    exit;
}