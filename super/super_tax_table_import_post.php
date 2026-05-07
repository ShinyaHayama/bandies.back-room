<?php

declare(strict_types=1);

/**
 * ✅ スーパー管理者専用：源泉徴収 税額表CSVインポート（POST）
 *
 * 目的（今回）:
 * - 「日額CSV（08-14）」を正しくパースして tax_withholding_rows に入るようにする
 *
 * 対応：
 * 1) シンプルCSV： lower_yen,upper_yen,tax_yen
 * 2) 国税庁CSV（先頭に日本語行が大量にある形式）
 *    - 月額(01-07): [No, lower, upper, 甲(扶養0..7), 乙]
 *    - 日額(08-14): [No, lower, upper, 甲(扶養0..7), 乙, 丙]  ← ★乙が「末尾-1」になる
 *
 * 重要：
 * - 乙(otsu)の列位置が pay_cycle により違うので、ここで分岐する（★今回の修正点）
 * - 末尾の空列も除去（あなたが入れた修正を確実に適用）
 */

require_once __DIR__ . '/_auth.php';
require_super_admin_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Tokyo');

function flash_and_redirect(bool $ok, string $msg): void
{
    $_SESSION['flash_super_tax'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: /super/super_tax_table_import.php');
    exit;
}

function normalize_int_or_null($v): ?int
{
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;

    // "105,000" "105,000円" などを数字へ
    $s = str_replace([',', '円', ' '], '', $s);

    if (!preg_match('/^-?\d+$/', $s)) return null;
    return (int)$s;
}

function is_numericish($v): bool
{
    return normalize_int_or_null($v) !== null;
}

/**
 * ✅ 文字コードをできるだけ安全にUTF-8へ寄せる（国税庁CSVは cp932 が多い）
 */
function read_csv_bytes_as_utf8_string(string $tmpPath): string
{
    $raw = (string)file_get_contents($tmpPath);
    if ($raw === '') return '';

    // BOM除去（UTF-8 BOM）
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

    // mb_detect_encoding があれば使う
    $enc = null;
    if (function_exists('mb_detect_encoding')) {
        $enc = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'CP932', 'SJIS', 'ISO-2022-JP', 'EUC-JP'], true);
    }

    // 推定できなければ cp932 を最後に試す（Windows系CSVが多い）
    $candidates = $enc ? [$enc, 'UTF-8', 'SJIS-win', 'CP932'] : ['UTF-8', 'SJIS-win', 'CP932'];

    foreach ($candidates as $e) {
        $s = @mb_convert_encoding($raw, 'UTF-8', $e);
        if ($s !== '' && $s !== false) {
            // 変換後に "給与所得の源泉徴収税額表" 等が読めるか軽くチェック（ダメなら次へ）
            if (strpos($s, '源泉') !== false || strpos($s, '税額表') !== false || preg_match('/\d+/', $s)) {
                return $s;
            }
        }
    }

    // どうしてもダメならそのまま返す（最悪でも数値行は拾えることがある）
    return $raw;
}

/**
 * ✅ 国税庁CSVのデータ行から、末尾空列を除去（あなたが求めていた修正点）
 */
function trim_trailing_empty_cols(array &$r): void
{
    while (!empty($r) && trim((string)$r[count($r) - 1]) === '') {
        array_pop($r);
    }
}

/**
 * ✅ 乙(otsu)の税額列インデックスを求める
 * - 月額: 乙が末尾
 * - 日額: 乙が「末尾-1」（末尾が丙）
 */
function otsu_index_by_paycycle(string $payCycle, array $r): int
{
    $n = count($r);
    if ($n <= 0) return 0;

    if ($payCycle === 'daily') {
        // 日額は末尾が「丙」になりがち → 乙は末尾-1
        if ($n >= 2) return $n - 2;
        return $n - 1;
    }

    // monthly / weekly は基本「乙が末尾」
    return $n - 1;
}

// ===== DB =====
$paths = [
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
    __DIR__ . '/../../api/lib/db.php',
    __DIR__ . '/../../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if (!$dbFile) {
    flash_and_redirect(false, "NG: db.php not found");
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ===== 入力 =====
$csrf = (string)($_POST['_csrf'] ?? '');
if ($csrf === '' || !hash_equals((string)($_SESSION['_csrf_super_tax'] ?? ''), $csrf)) {
    flash_and_redirect(false, "NG: CSRF が不正です（再読込してやり直してください）");
}

$payCycle   = (string)($_POST['pay_cycle'] ?? '');
$version    = trim((string)($_POST['version_label'] ?? ''));
$importMode = (string)($_POST['import_mode'] ?? ''); // both / ko / otsu
$depCount   = (int)($_POST['dependent_count'] ?? 0);

$allowedCycle = ['daily', 'weekly', 'monthly'];
$allowedMode  = ['both', 'ko', 'otsu'];

if (!in_array($payCycle, $allowedCycle, true)) flash_and_redirect(false, "NG: pay_cycle が不正です");
if (!in_array($importMode, $allowedMode, true)) flash_and_redirect(false, "NG: import_mode が不正です");
if ($version === '') flash_and_redirect(false, "NG: version_label が空です");
if ($depCount < 0 || $depCount > 7) $depCount = 0;

if (empty($_FILES['csv']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
    flash_and_redirect(false, "NG: CSVファイルがアップロードされていません");
}

// tax_withholding_tables / rows のカラム存在チェック（created_at等が無い環境でも動く）
$tblCols = [];
$rowCols = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM tax_withholding_tables")->fetchAll() as $c) {
        $tblCols[] = (string)$c['Field'];
    }
    foreach ($pdo->query("SHOW COLUMNS FROM tax_withholding_rows")->fetchAll() as $c) {
        $rowCols[] = (string)$c['Field'];
    }
} catch (Throwable $e) {
    flash_and_redirect(false, "NG: 税額表テーブルが存在しません。\n" . $e->getMessage());
}

$hasTblCreated = in_array('created_at', $tblCols, true);
$hasTblUpdated = in_array('updated_at', $tblCols, true);
$hasRowCreated = in_array('created_at', $rowCols, true);
$hasRowUpdated = in_array('updated_at', $rowCols, true);

/**
 * ✅ tax_withholding_tables の table_id を確保（無ければ作る）
 * - 同じ (pay_cycle, tax_type, version_label) は既存を使う
 */
function ensure_table_id(PDO $pdo, string $payCycle, string $taxType, string $version, bool $hasTblCreated, bool $hasTblUpdated): int
{
    $st = $pdo->prepare("
        SELECT id
        FROM tax_withholding_tables
        WHERE pay_cycle = :pc AND tax_type = :tt AND version_label = :v
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([':pc' => $payCycle, ':tt' => $taxType, ':v' => $version]);
    $id = (int)($st->fetchColumn() ?: 0);

    if ($id > 0) return $id;

    $cols = ['pay_cycle', 'tax_type', 'version_label'];
    $vals = [':pc', ':tt', ':v'];

    if ($hasTblCreated) {
        $cols[] = 'created_at';
        $vals[] = 'NOW()';
    }
    if ($hasTblUpdated) {
        $cols[] = 'updated_at';
        $vals[] = 'NOW()';
    }

    $sql = "INSERT INTO tax_withholding_tables (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $ins = $pdo->prepare($sql);
    $ins->execute([':pc' => $payCycle, ':tt' => $taxType, ':v' => $version]);

    return (int)$pdo->lastInsertId();
}

/**
 * ✅ rows を全削除（年1回運用想定）
 */
function wipe_rows(PDO $pdo, int $tableId): void
{
    $del = $pdo->prepare("DELETE FROM tax_withholding_rows WHERE table_id = :tid");
    $del->execute([':tid' => $tableId]);
}

/**
 * ✅ rows を bulk insert（ループでも十分だが、失敗時rollbackできるようTXでまとめる）
 */
function insert_rows(PDO $pdo, int $tableId, array $rowsToInsert, bool $hasRowCreated, bool $hasRowUpdated): int
{
    $cols = ['table_id', 'lower_yen', 'upper_yen', 'tax_yen'];
    if ($hasRowCreated) $cols[] = 'created_at';
    if ($hasRowUpdated) $cols[] = 'updated_at';

    $place = [];
    $place[] = ':tid';
    $place[] = ':lower';
    $place[] = ':upper';
    $place[] = ':tax';
    if ($hasRowCreated) $place[] = 'NOW()';
    if ($hasRowUpdated) $place[] = 'NOW()';

    $sql = "INSERT INTO tax_withholding_rows (" . implode(',', $cols) . ") VALUES (" . implode(',', $place) . ")";
    $ins = $pdo->prepare($sql);

    $cnt = 0;
    foreach ($rowsToInsert as [$lower, $upper, $tax]) {
        $ins->execute([
            ':tid'   => $tableId,
            ':lower' => (int)$lower,
            ':upper' => ($upper === null ? null : (int)$upper),
            ':tax'   => (int)$tax,
        ]);
        $cnt++;
    }
    return $cnt;
}

// ===== CSV を UTF-8 化して php://temp で fgetcsv =====
$tmpPath = (string)$_FILES['csv']['tmp_name'];
$csvText = read_csv_bytes_as_utf8_string($tmpPath);
if ($csvText === '') {
    flash_and_redirect(false, "NG: CSVが空です");
}

$mem = fopen('php://temp', 'wb+');
fwrite($mem, $csvText);
rewind($mem);

// ===== 判定：シンプルCSVか、国税庁CSVか =====
$peek = [];
for ($i = 0; $i < 40; $i++) {
    $r = fgetcsv($mem);
    if ($r === false) break;
    $peek[] = $r;
}
rewind($mem);

$foundSimpleHeader = false;
foreach ($peek as $r) {
    $hdr = array_map(fn($x) => trim((string)$x), $r);
    if (count($hdr) >= 3 && $hdr[0] === 'lower_yen' && $hdr[1] === 'upper_yen' && $hdr[2] === 'tax_yen') {
        $foundSimpleHeader = true;
        break;
    }
}

// ===== 取り込み先（ko/otsu）を決める =====
$wantKo   = ($importMode === 'both' || $importMode === 'ko');
$wantOtsu = ($importMode === 'both' || $importMode === 'otsu');

// シンプルCSVは「片方」しか表現できないので both を禁止
if ($foundSimpleHeader && $importMode === 'both') {
    fclose($mem);
    flash_and_redirect(false, "NG: シンプルCSVは甲+乙を同時登録できません（import_mode=both は不可）");
}

$rowsKo = [];
$rowsOtsu = [];

if ($foundSimpleHeader) {
    // ===== シンプルCSV =====
    $headerFound = false;
    while (($r = fgetcsv($mem)) !== false) {
        $r = array_map(fn($x) => trim((string)$x), $r);

        if (!$headerFound) {
            if (count($r) >= 3 && $r[0] === 'lower_yen' && $r[1] === 'upper_yen' && $r[2] === 'tax_yen') {
                $headerFound = true;
            }
            continue;
        }

        $lower = normalize_int_or_null($r[0] ?? null);
        $upper = normalize_int_or_null($r[1] ?? null);
        $tax   = normalize_int_or_null($r[2] ?? null);

        if ($lower === null) continue;
        if ($tax === null) $tax = 0;

        if ($importMode === 'ko') {
            $rowsKo[] = [$lower, $upper, $tax];
        } else { // otsu
            $rowsOtsu[] = [$lower, $upper, $tax];
        }
    }
} else {
    // ===== 国税庁CSV（01-07 / 08-14）=====
    $specialUnderDone = false;

    while (($r = fgetcsv($mem)) !== false) {
        if (!$r || (count($r) === 1 && trim((string)$r[0]) === '')) continue;

        // ★末尾の空列を除去（otsu=0連発の原因になり得る）
        trim_trailing_empty_cols($r);

        // 例：下限が "105000" 等、上限が "円未満" の特殊行（No列が空）
        if (!$specialUnderDone) {
            $c1 = trim((string)($r[1] ?? ''));
            $c2 = trim((string)($r[2] ?? ''));
            if (is_numericish($c1) && (strpos($c2, '未満') !== false)) {
                $upperBase = normalize_int_or_null($c1);
                if ($upperBase !== null && $upperBase > 0) {
                    $lower = 0;
                    $upper = $upperBase - 1;

                    // 甲
                    if ($wantKo) {
                        $idxKo = 3 + $depCount; // 0人=3
                        $taxKo = normalize_int_or_null($r[$idxKo] ?? null) ?? 0;
                        $rowsKo[] = [$lower, $upper, $taxKo];
                    }

                    // 乙（★日額は index が違う）
                    if ($wantOtsu) {
                        $idxO = otsu_index_by_paycycle($payCycle, $r);
                        $taxO = normalize_int_or_null($r[$idxO] ?? null) ?? 0; // "（3.063%）" 等は 0 になる
                        $rowsOtsu[] = [$lower, $upper, $taxO];
                    }

                    $specialUnderDone = true;
                }
                continue;
            }
        }

        // データ行（Noが数字）
        $noRaw = trim((string)($r[0] ?? ''));
        $noDigits = preg_replace('/\D+/', '', $noRaw) ?? '';
        if ($noDigits === '') {
            continue; // タイトル行など
        }

        $lower = normalize_int_or_null($r[1] ?? null);
        $upper = normalize_int_or_null($r[2] ?? null);
        if ($lower === null) continue;

        // 甲（扶養列）
        if ($wantKo) {
            $idxKo = 3 + $depCount; // 0人=3, 7人=10
            $taxKo = normalize_int_or_null($r[$idxKo] ?? null) ?? 0;
            $rowsKo[] = [$lower, $upper, $taxKo];
        }

        // 乙（★月額は末尾、日額は末尾-1）
        if ($wantOtsu) {
            $idxO = otsu_index_by_paycycle($payCycle, $r);
            $taxO = normalize_int_or_null($r[$idxO] ?? null) ?? 0;
            $rowsOtsu[] = [$lower, $upper, $taxO];
        }
    }
}

fclose($mem);

// ===== 入れる行が無いならNG =====
if ($wantKo && !$rowsKo) {
    flash_and_redirect(false, "NG: 甲(ko) の投入行を検出できませんでした（CSV形式/扶養人数/文字コードを確認）");
}
if ($wantOtsu && !$rowsOtsu) {
    flash_and_redirect(false, "NG: 乙(otsu) の投入行を検出できませんでした（CSV形式/文字コード/列位置を確認）");
}

// lower順に整列（念のため）
$sortFn = fn($a, $b) => ($a[0] <=> $b[0]);
if ($rowsKo) usort($rowsKo, $sortFn);
if ($rowsOtsu) usort($rowsOtsu, $sortFn);

// ===== TX：table確保 → rows全削除 → insert =====
$pdo->beginTransaction();
try {
    $resultLines = [];

    if ($wantKo) {
        $tidKo = ensure_table_id($pdo, $payCycle, 'ko', $version, $hasTblCreated, $hasTblUpdated);
        wipe_rows($pdo, $tidKo);
        $cntKo = insert_rows($pdo, $tidKo, $rowsKo, $hasRowCreated, $hasRowUpdated);
        $resultLines[] = "ko: table_id={$tidKo} rows={$cntKo}（扶養{$depCount}人）";
    }

    if ($wantOtsu) {
        $tidO = ensure_table_id($pdo, $payCycle, 'otsu', $version, $hasTblCreated, $hasTblUpdated);
        wipe_rows($pdo, $tidO);
        $cntO = insert_rows($pdo, $tidO, $rowsOtsu, $hasRowCreated, $hasRowUpdated);
        $resultLines[] = "otsu: table_id={$tidO} rows={$cntO}";
    }

    $pdo->commit();

    $msg = "OK: インポート成功\n";
    $msg .= "pay_cycle={$payCycle}\n";
    $msg .= "version={$version}\n";
    $msg .= "import_mode={$importMode}\n";
    $msg .= implode("\n", $resultLines);

    flash_and_redirect(true, $msg);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_and_redirect(false, "NG: インポート失敗\n" . $e->getMessage());
}