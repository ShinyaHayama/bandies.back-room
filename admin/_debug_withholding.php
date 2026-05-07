<?php

declare(strict_types=1);

/**
 * ✅ 修正点
 * - employees が tenant_id/store_id で見つからないときに「なぜ見つからないか」を分解表示
 * - employees テーブルが tenant_id / store_id を持たない構成でも動くように段階検索
 *
 * ✅ 使い方
 * /admin/_debug_withholding.php?store_id=1&employee_id=123&taxable=112500
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

// ===== DB =====
$paths = [
    __DIR__ . '/../../api/lib/db.php',
    __DIR__ . '/../../lib/db.php',
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if (!$dbFile) {
    http_response_code(500);
    echo "db.php not found";
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$storeId = (int)($_GET['store_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);
$taxable = (int)($_GET['taxable'] ?? 0);

header('Content-Type: text/plain; charset=utf-8');

if ($storeId <= 0 || $employeeId <= 0 || $taxable <= 0) {
    echo "NG: store_id / employee_id / taxable を指定してください\n";
    echo "例) /admin/_debug_withholding.php?store_id=1&employee_id=123&taxable=112500\n";
    exit;
}

echo "tenant_id={$tenantId}\nstore_id={$storeId}\nemployee_id={$employeeId}\ntaxable={$taxable}\n\n";

// 1) 税額表テーブル存在確認
try {
    $pdo->query("SELECT 1 FROM tax_withholding_tables LIMIT 1");
    $pdo->query("SELECT 1 FROM tax_withholding_rows LIMIT 1");
    echo "OK: tax_withholding_tables / tax_withholding_rows は存在\n\n";
} catch (Throwable $e) {
    echo "NG: 税額表テーブルが存在しません（これなら源泉は必ず0になります）\n";
    echo $e->getMessage() . "\n";
    exit;
}

// 2) employees テーブルのカラム構成を確認（tenant_id/store_id を持たない可能性があるため）
$empCols = [];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll();
    foreach ($cols as $c) {
        $empCols[] = (string)$c['Field'];
    }
} catch (Throwable $e) {
    echo "NG: employees テーブルが存在しない/参照不可\n";
    echo $e->getMessage() . "\n";
    exit;
}
echo "employees.columns=" . implode(',', $empCols) . "\n\n";

$hasTenant = in_array('tenant_id', $empCols, true);
$hasStore  = in_array('store_id', $empCols, true);

// 3) 従業員検索（段階的に絞り込み）
// 3-1) idだけで存在するか
$emp = null;
try {
    $st = $pdo->prepare("SELECT * FROM employees WHERE id=:id LIMIT 1");
    $st->execute([':id' => $employeeId]);
    $emp = $st->fetch() ?: null;
} catch (Throwable $e) {
    echo "NG: employees id検索で失敗\n";
    echo $e->getMessage() . "\n";
    exit;
}

if (!$emp) {
    echo "NG: employees が id={$employeeId} で存在しません\n";
    echo "=> まず employee_id が正しいか確認してください（一覧画面のURLやDBのidと一致しているか）\n";
    exit;
}

echo "OK: employees は id={$employeeId} で存在\n";

// 3-2) tenant_id/store_id があるなら整合を確認（※ここで “見つからない原因” が確定する）
if ($hasTenant) {
    $empTenant = (int)($emp['tenant_id'] ?? 0);
    echo "employees.tenant_id={$empTenant}\n";
    if ($empTenant !== $tenantId) {
        echo "NG: tenant_id が一致しません（画面のtenantと従業員のtenantが違う）\n";
        echo "=> 今の tenant_id={$tenantId} ではこの従業員を参照できないので、源泉計算以前に対象がズレています\n";
        exit;
    }
} else {
    echo "INFO: employees に tenant_id カラムが無い構成\n";
}

if ($hasStore) {
    $empStore = (int)($emp['store_id'] ?? 0);
    echo "employees.store_id={$empStore}\n";
    if ($empStore !== $storeId) {
        echo "NG: store_id が一致しません（指定storeと従業員のstoreが違う）\n";
        echo "=> store_id パラメータか、従業員の所属店が違っています\n";
        exit;
    }
} else {
    echo "INFO: employees に store_id カラムが無い構成\n";
}

$employeeName = (string)($emp['display_name'] ?? ($emp['name'] ?? ''));
$taxType = (string)($emp['tax_type'] ?? '');
echo "employee.name=" . ($employeeName !== '' ? $employeeName : '(empty)') . "\n";
echo "employee.tax_type=" . ($taxType !== '' ? $taxType : '(empty)') . "\n\n";

// 4) tax_withholding_tables の登録値の一覧
echo "---- tax_withholding_tables distinct values ----\n";
$rows = $pdo->query("SELECT pay_cycle, COALESCE(tax_type,'') AS tax_type, COUNT(*) AS cnt FROM tax_withholding_tables GROUP BY pay_cycle, COALESCE(tax_type,'') ORDER BY pay_cycle, tax_type")->fetchAll();
foreach ($rows as $r) {
    echo "pay_cycle={$r['pay_cycle']} tax_type=" . ($r['tax_type'] !== '' ? $r['tax_type'] : '(empty)') . " cnt={$r['cnt']}\n";
}
echo "\n";

// 5) taxable が rows 範囲に入るか（最新テーブルから探索）
echo "---- try find matching row (latest table each combo) ----\n";

$combos = $pdo->query("SELECT pay_cycle, COALESCE(tax_type,'') AS tax_type, MAX(id) AS max_id FROM tax_withholding_tables GROUP BY pay_cycle, COALESCE(tax_type,'') ORDER BY max_id DESC")->fetchAll();

$hit = false;

foreach ($combos as $c) {
    $pc = (string)$c['pay_cycle'];
    $tt = (string)$c['tax_type'];
    $tid = (int)$c['max_id'];

    $rSt = $pdo->prepare("
        SELECT tax_yen, lower_yen, upper_yen
        FROM tax_withholding_rows
        WHERE table_id = :tid
          AND :x >= lower_yen
          AND (upper_yen IS NULL OR :x <= upper_yen)
        LIMIT 1
    ");
    $rSt->execute([':tid' => $tid, ':x' => $taxable]);
    $r = $rSt->fetch();
    if ($r) {
        $hit = true;
        echo "HIT: table_id={$tid} pay_cycle={$pc} tax_type=" . ($tt !== '' ? $tt : '(empty)') . " => tax_yen={$r['tax_yen']} (range {$r['lower_yen']} - " . ($r['upper_yen'] === null ? 'NULL' : $r['upper_yen']) . ")\n";
    }
}

if (!$hit) {
    echo "NO HIT: taxable={$taxable} が rows 範囲に入りません。\n";
    echo "=> rows が未投入/範囲欠け/単位違い/紐付け不整合 のどれかです。\n";
}

echo "\nDONE\n";