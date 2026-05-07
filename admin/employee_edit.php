<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/employee_edit.php
 * ✅ 書き込み場所: この内容で「丸ごと置き換え」
 *
 * 目的:
 * - employee_profiles に profile/traits/memo を保存（既存）
 * - employees に「年齢/電話/メール/SNS(複数)/住所/緊急連絡先/雇用情報/振込情報/指名料(任意・末尾表示)」を保存
 * - ✅ employees に「tax_type（ko/otsu）」を保存（既存）
 * - ✅ employees に「withholding_pay_cycle（daily/weekly/monthly）」を保存（既存）
 * - ✅ 時給を変更したとき、過去計算が変わらないように「営業日基準の時給履歴」を保存できるようにする
 *   - employee_wage_histories(effective_business_day, hourly_wage_yen) にUPSERT
 *   - ✅ 初回のみ baseline（2000-01-01）を旧時給で自動INSERTして「履歴が無い過去」を固定（既存）
 *
 * ✅ 今回のUI修正（重要）
 * - 「時給の適用開始営業日（YYYY-MM-DD）」を手入力からプルダウン選択へ変更（入力ミスで不正にならない）
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location:/admin/login.php');
    exit;
}

require_once __DIR__ . '/../api/lib/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];
function isValidCsrf(string $t): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)($_SESSION['csrf_token']), $t);
}

$storeId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);

$st = $pdo->prepare("SELECT name FROM stores WHERE tenant_id=:t AND id=:s LIMIT 1");
$st->execute([':t' => $tenantId, ':s' => $storeId]);
$storeName = (string)($st->fetchColumn() ?: '');

$st = $pdo->prepare("SELECT display_name FROM employees WHERE tenant_id=:t AND store_id=:s AND id=:e LIMIT 1");
$st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
$employeeName = (string)($st->fetchColumn() ?: '');

if ($storeName === '' || $employeeName === '') {
    http_response_code(400);
    echo "store/employee not found";
    exit;
}

$errors = [];
$success = null;

function yen(?int $v): string
{
    return $v === null ? '—' : number_format($v) . '円';
}
function textOrDash(?string $v): string
{
    $v = trim((string)$v);
    return $v === '' ? '—' : $v;
}

/** ✅ SNS 1行=1件（絶対に分割しない） */
function parseSnsLines(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_map('trim', explode("\n", $raw));
    $out = [];
    foreach ($lines as $ln) {
        if ($ln === '') continue;
        $out[] = $ln;
    }
    $uniq = [];
    foreach ($out as $v) {
        if (!in_array($v, $uniq, true)) $uniq[] = $v;
    }
    return $uniq;
}
function snsToDbString(array $snsList): ?string
{
    $snsList = array_values(array_filter(array_map('trim', $snsList), fn($v) => $v !== ''));
    return $snsList ? implode("\n", $snsList) : null;
}
function snsFromDb(?string $dbValue): array
{
    $dbValue = (string)($dbValue ?? '');
    return trim($dbValue) === '' ? [] : parseSnsLines($dbValue);
}
/** ✅ SNSは「必ずリンク」：URLっぽい→直リンク / それ以外→検索リンク */
function snsLinkUrlAlways(string $label): string
{
    $s = trim($label);
    if ($s === '') return 'https://www.google.com/';
    if (preg_match('~^https?://~i', $s)) return $s;
    if (preg_match('~^[a-z0-9.-]+\.[a-z]{2,}~i', $s)) return 'https://' . $s;
    return 'https://www.google.com/search?q=' . rawurlencode($s);
}

function normalizeDigits(string $raw): string
{
    return preg_replace('/\D+/', '', $raw) ?? '';
}
function toIntOrNullDigits(string $v): ?int
{
    if ($v === '') return null;
    if (!preg_match('/^\d+$/', $v)) return null;
    return (int)$v;
}

/** ✅ YYYY-MM-DD 判定 */
function isValidYmd(string $v): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y);
}

/** ✅ employee_wage_histories があるか */
function hasEmployeeWageHistoriesTable(PDO $pdo): bool
{
    try {
        $cols = tableColumns($pdo, 'employee_wage_histories');
        $need = ['tenant_id', 'store_id', 'employee_id', 'effective_business_day', 'hourly_wage_yen'];
        foreach ($need as $col) {
            if (!in_array($col, $cols, true)) {
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** ✅ stores 列チェック */
function tableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    try {
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[] = (string)$r['Field'];
        }
        $cache[$table] = $cols;
        return $cols;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }
}

function ensureEmployeeInsuranceColumns(PDO $pdo): void
{
    $cols = tableColumns($pdo, 'employees');
    $changes = [];
    if (!in_array('standard_monthly_remuneration', $cols, true)) {
        $changes[] = "ADD COLUMN standard_monthly_remuneration INT NOT NULL DEFAULT 0";
    }
    if (!in_array('health_ins_enrolled', $cols, true)) {
        $changes[] = "ADD COLUMN health_ins_enrolled TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!in_array('pension_enrolled', $cols, true)) {
        $changes[] = "ADD COLUMN pension_enrolled TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!in_array('birth_date', $cols, true)) {
        $changes[] = "ADD COLUMN birth_date DATE NULL";
    }
    if ($changes) {
        $pdo->exec("ALTER TABLE employees " . implode(', ', $changes));
    }
}

function ensureEmployeeNightPremiumColumns(PDO $pdo): void
{
    $cols = tableColumns($pdo, 'employees');
    $changes = [];
    if (!in_array('night_premium_enabled', $cols, true)) {
        $changes[] = "ADD COLUMN night_premium_enabled TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!in_array('night_premium_rate_percent', $cols, true)) {
        $changes[] = "ADD COLUMN night_premium_rate_percent INT NOT NULL DEFAULT 25";
    }
    if ($changes) {
        $pdo->exec("ALTER TABLE employees " . implode(', ', $changes));
    }
}

/** ✅ cutoff "05:00" / "05:00:00" -> seconds */
function cutoffToSeconds(string $cutoff): int
{
    $cutoff = trim($cutoff);
    if ($cutoff === '') return 0;
    if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $cutoff)) return 0;

    $parts = explode(':', $cutoff);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    $s = (int)($parts[2] ?? 0);

    if ($h < 0 || $h > 23) return 0;
    if ($m < 0 || $m > 59) return 0;
    if ($s < 0 || $s > 59) return 0;

    return $h * 3600 + $m * 60 + $s;
}

/** ✅ now(ts) -> business day */
function businessDateFromTs(int $ts, int $cutoffSeconds): string
{
    $dayStart = strtotime(date('Y-m-d 00:00:00', $ts));
    if ($dayStart === false) return date('Y-m-d', $ts);

    if ($cutoffSeconds <= 0) return date('Y-m-d', $ts);

    $cutoffTs = (int)$dayStart + $cutoffSeconds;
    if ($ts < $cutoffTs) {
        return date('Y-m-d', strtotime('-1 day', (int)$dayStart));
    }
    return date('Y-m-d', $ts);
}

/** ✅ 店舗cutoff考慮の「今日の営業日」 */
function getDefaultBusinessDayYmd(PDO $pdo, int $tenantId, int $storeId): string
{
    $today = date('Y-m-d');

    $cols = tableColumns($pdo, 'stores');
    if (!in_array('business_day_cutoff_time', $cols, true)) {
        return $today;
    }

    try {
        $st = $pdo->prepare("
            SELECT COALESCE(business_day_cutoff_time,'00:00:00') AS cutoff
            FROM stores
            WHERE tenant_id=:t AND id=:s
            LIMIT 1
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId]);
        $cutoff = (string)($st->fetchColumn() ?: '00:00:00');
        $sec = cutoffToSeconds($cutoff);
        return businessDateFromTs(time(), $sec);
    } catch (Throwable $e) {
        return $today;
    }
}

/**
 * ✅ 適用開始営業日プルダウン（前3650〜後365）
 * @return array<int, array{value:string,label:string}>
 */
function buildBusinessDayOptions(string $centerYmd, int $pastDays = 3650, int $futureDays = 365): array
{
    if (!isValidYmd($centerYmd)) $centerYmd = date('Y-m-d');

    $tz = new DateTimeZone('Asia/Tokyo');
    $center = DateTimeImmutable::createFromFormat('Y-m-d', $centerYmd, $tz);
    if (!$center) $center = new DateTimeImmutable('today', $tz);

    $start = $center->modify('-' . max(0, $pastDays) . ' day');
    $end   = $center->modify('+' . max(0, $futureDays) . ' day');

    $wd = ['日', '月', '火', '水', '木', '金', '土'];

    $out = [];
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $v = $d->format('Y-m-d');
        $w = (int)$d->format('w');
        $out[] = ['value' => $v, 'label' => $v . '（' . ($wd[$w] ?? '') . '）'];
        if (count($out) > 5000) break;
    }
    return $out;
}

/** ✅ 履歴書アップロード（既存） */
function handleResumeUpload(array $file, int $tenantId, int $storeId, int $employeeId, array &$errors): ?array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = '履歴書アップロードに失敗しました（error=' . (int)$file['error'] . '）';
        return null;
    }

    $maxBytes = 10 * 1024 * 1024;
    if ((int)$file['size'] > $maxBytes) {
        $errors[] = '履歴書ファイルが大きすぎます（最大10MB）';
        return null;
    }

    $tmp = (string)$file['tmp_name'];
    if (!is_file($tmp)) {
        $errors[] = '履歴書ファイルが見つかりません';
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);

    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        $errors[] = '履歴書は PDF/JPG/PNG/WEBP のみ対応です（検出MIME: ' . $mime . '）';
        return null;
    }
    $ext = $allowed[$mime];

    $baseRoot = __DIR__ . '/../_private/resumes';
    $baseDir  = $baseRoot . '/t' . $tenantId . '/s' . $storeId . '/e' . $employeeId;

    if (!is_dir($baseDir)) {
        if (!@mkdir($baseDir, 0770, true) && !is_dir($baseDir)) {
            $errors[] = '履歴書保存フォルダを作成できません: ' . $baseDir;
            return null;
        }
    }

    $rand = bin2hex(random_bytes(8));
    $filename = $rand . '.' . $ext;
    $absPath = $baseDir . '/' . $filename;

    if (!@move_uploaded_file($tmp, $absPath)) {
        $errors[] = '履歴書ファイルを保存できませんでした';
        return null;
    }

    $dbPath = '_private/resumes'
        . '/t' . $tenantId
        . '/s' . $storeId
        . '/e' . $employeeId
        . '/' . $filename;

    $orig = (string)($file['name'] ?? ('resume.' . $ext));

    return ['path' => $dbPath, 'original' => $orig, 'abs' => $absPath];
}

/** ✅ 履歴書削除（既存） */
function deleteResumeFileByDbPath(string $dbPath): void
{
    $dbPath = trim($dbPath);
    if ($dbPath === '') return;

    $baseDir = realpath(__DIR__ . '/../_private/resumes');
    if ($baseDir === false) return;

    $prefix = '_private/resumes/';
    $rel = $dbPath;
    if (strpos($rel, $prefix) === 0) {
        $rel = substr($rel, strlen($prefix));
    }

    $rel = str_replace(['..', '\\'], ['', '/'], $rel);
    $abs = $baseDir . DIRECTORY_SEPARATOR . $rel;

    $real = realpath($abs);
    if ($real === false) return;

    if (strpos($real, $baseDir . DIRECTORY_SEPARATOR) !== 0) return;

    if (is_file($real)) @unlink($real);

    $dir = dirname($real);
    for ($i = 0; $i < 3; $i++) {
        if (!is_dir($dir)) break;
        $files = @scandir($dir);
        if (!$files) break;
        if (count($files) <= 2) {
            @rmdir($dir);
            $dir = dirname($dir);
            continue;
        }
        break;
    }
}

function taxTypeLabel(?string $v): string
{
    $v = trim((string)$v);
    if ($v === 'ko') return '甲（ko）';
    if ($v === 'otsu') return '乙（otsu）';
    return '—';
}
function whCycleLabel(?string $v): string
{
    $v = trim((string)$v);
    if ($v === 'daily') return '日払い（daily）';
    if ($v === 'weekly') return '週払い（weekly）';
    if ($v === 'monthly') return '月払い（monthly）';
    return '（未設定：店舗設定に従う）';
}

function wageHistoryNeedsUpsert(PDO $pdo, int $tenantId, int $storeId, int $employeeId, string $effectiveDay, int $hourlyWageYen): bool
{
    $st = $pdo->prepare("
        SELECT hourly_wage_yen
        FROM employee_wage_histories
        WHERE tenant_id = :t
          AND store_id  = :s
          AND employee_id = :e
          AND effective_business_day = :d
        LIMIT 1
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId, ':d' => $effectiveDay]);
    $v = $st->fetchColumn();
    if ($v === false) return true;
    return ((int)$v !== (int)$hourlyWageYen);
}
function wageHistoryExists(PDO $pdo, int $tenantId, int $storeId, int $employeeId): bool
{
    $st = $pdo->prepare("
        SELECT 1
        FROM employee_wage_histories
        WHERE tenant_id = :t
          AND store_id  = :s
          AND employee_id = :e
        LIMIT 1
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
    return (bool)$st->fetchColumn();
}
function insertWageBaselineIfNeeded(PDO $pdo, int $tenantId, int $storeId, int $employeeId, int $oldHourlyWage): void
{
    $baselineDay = '2000-01-01';

    $st = $pdo->prepare("
        SELECT 1
        FROM employee_wage_histories
        WHERE tenant_id = :t
          AND store_id  = :s
          AND employee_id = :e
          AND effective_business_day = :d
        LIMIT 1
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId, ':d' => $baselineDay]);
    if ((bool)$st->fetchColumn()) return;

    $ins = $pdo->prepare("
        INSERT INTO employee_wage_histories
          (tenant_id, store_id, employee_id, effective_business_day, hourly_wage_yen, created_at, updated_at)
        VALUES (:t,:s,:e,:d,:w,NOW(),NOW())
    ");
    $ins->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId, ':d' => $baselineDay, ':w' => $oldHourlyWage]);
}

// ✅ 履歴テーブル有無
$hasWageHistoryTable = hasEmployeeWageHistoriesTable($pdo);

try {
    ensureEmployeeInsuranceColumns($pdo);
    ensureEmployeeNightPremiumColumns($pdo);
} catch (Throwable $e) {
    $errors[] = '社保/深夜割増項目の列作成に失敗しました: ' . $e->getMessage();
}

// ===== DB読み込み =====
$st = $pdo->prepare("
    SELECT
      display_name,
      hourly_wage_yen, nomination_fee_yen, age,
      phone, email, sns,
      address,
      emergency_name, emergency_relation, emergency_phone,
      employment_type, hire_date, leave_date,
      commute_allowance_note,
      bank_name, bank_branch, bank_account_type, bank_account_number, bank_account_holder,
      resume_path, resume_original_name, resume_uploaded_at,
      tax_type,
      withholding_pay_cycle,
      night_premium_enabled,
      night_premium_rate_percent,
      standard_monthly_remuneration,
      health_ins_enrolled,
      pension_enrolled,
      birth_date
    FROM employees
    WHERE tenant_id=:t AND store_id=:s AND id=:e
    LIMIT 1
");
$st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
$empDb = $st->fetch();
if (!$empDb) {
    http_response_code(400);
    echo "employee not found";
    exit;
}

$st = $pdo->prepare("SELECT profile_text, traits_text, memo_text FROM employee_profiles WHERE tenant_id=:t AND store_id=:s AND employee_id=:e LIMIT 1");
$st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
$pDb = $st->fetch() ?: ['profile_text' => '', 'traits_text' => '', 'memo_text' => ''];

$empForm = $empDb;
$pForm = $pDb;
$empForm['sns_lines'] = implode("\n", snsFromDb($empDb['sns'] ?? null));

$wageHistoryRows = [];
if ($hasWageHistoryTable) {
    try {
        $st = $pdo->prepare("
            SELECT effective_business_day, hourly_wage_yen, updated_at
            FROM employee_wage_histories
            WHERE tenant_id = :t AND store_id = :s AND employee_id = :e
            ORDER BY effective_business_day DESC, id DESC
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
        $wageHistoryRows = $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        $wageHistoryRows = [];
    }
}

// ✅ プルダウン候補（前3650〜後365）
$defaultBusinessDayYmd = getDefaultBusinessDayYmd($pdo, $tenantId, $storeId);
$businessDayOptions = buildBusinessDayOptions($defaultBusinessDayYmd, 3650, 365);
$businessDaySet = [];
foreach ($businessDayOptions as $opt) $businessDaySet[(string)$opt['value']] = true;

// ✅ 初期選択（POST優先）
$empForm['wage_effective_business_day'] = (string)($_POST['wage_effective_business_day'] ?? $defaultBusinessDayYmd);
if (!isset($businessDaySet[$empForm['wage_effective_business_day']])) {
    $empForm['wage_effective_business_day'] = $defaultBusinessDayYmd;
}

// ✅ tax_type 初期値
if (trim((string)($empForm['tax_type'] ?? '')) === '') {
    $empForm['tax_type'] = 'ko';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (
        [
            'display_name',
            'hourly_wage_yen',
            'age',
            'phone',
            'email',
            'sns_lines',
            'nomination_fee_yen',
            'address',
            'emergency_name',
            'emergency_relation',
            'emergency_phone',
            'employment_type',
            'hire_date',
            'leave_date',
            'commute_allowance_note',
            'bank_name',
            'bank_branch',
            'bank_account_type',
            'bank_account_number',
            'bank_account_holder',
            'tax_type',
            'withholding_pay_cycle',
            'night_premium_rate_percent',
            'standard_monthly_remuneration',
            'health_ins_enrolled',
            'pension_enrolled',
            'birth_date',
            'wage_effective_business_day',
        ] as $k
    ) {
        $empForm[$k] = (string)($_POST[$k] ?? '');
    }
    $empForm['health_ins_enrolled'] = isset($_POST['health_ins_enrolled']) ? '1' : '0';
    $empForm['pension_enrolled'] = isset($_POST['pension_enrolled']) ? '1' : '0';
    $empForm['night_premium_enabled'] = isset($_POST['night_premium_enabled']) ? '1' : '0';
    foreach (['profile_text', 'traits_text', 'memo_text'] as $k) {
        $pForm[$k] = (string)($_POST[$k] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!isValidCsrf($token)) {
        $errors[] = 'CSRF不正';
    } else {
        $wageRaw = trim((string)($_POST['hourly_wage_yen'] ?? ''));
        $wageInt = toIntOrNullDigits($wageRaw);
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        if ($displayName === '') $errors[] = '名前を入力してください';

        $ageInt  = toIntOrNullDigits(trim((string)($_POST['age'] ?? '')));
        $nomInt  = toIntOrNullDigits(trim((string)($_POST['nomination_fee_yen'] ?? '')));
        $stdRaw = trim((string)($_POST['standard_monthly_remuneration'] ?? ''));
        $stdInt = toIntOrNullDigits($stdRaw);
        $healthEnrolled = isset($_POST['health_ins_enrolled']) ? 1 : 0;
        $pensionEnrolled = isset($_POST['pension_enrolled']) ? 1 : 0;
        $birthDate = trim((string)($_POST['birth_date'] ?? ''));

        $phoneDigits = normalizeDigits((string)($_POST['phone'] ?? ''));
        $emgDigits   = normalizeDigits((string)($_POST['emergency_phone'] ?? ''));

        // ✅ tax_type
        $taxType = trim((string)($_POST['tax_type'] ?? ''));
        if ($taxType === '甲') $taxType = 'ko';
        if ($taxType === '乙') $taxType = 'otsu';
        if (!in_array($taxType, ['ko', 'otsu'], true)) {
            $errors[] = '源泉 tax_type は 甲(ko) / 乙(otsu) のどちらかを選んでください';
        }

        // ✅ withholding_pay_cycle
        $whCycle = trim((string)($_POST['withholding_pay_cycle'] ?? ''));
        if ($whCycle !== '' && !in_array($whCycle, ['daily', 'weekly', 'monthly'], true)) {
            $errors[] = '源泉表の参照サイクルは daily / weekly / monthly のいずれかを選んでください';
        }

        $nightPremiumEnabled = isset($_POST['night_premium_enabled']) ? 1 : 0;
        $nightPremiumRateRaw = trim((string)($_POST['night_premium_rate_percent'] ?? ''));
        if ($nightPremiumRateRaw === '') $nightPremiumRateRaw = '25';
        $nightPremiumRate = (int)$nightPremiumRateRaw;
        $nightPremiumOptions = [25, 30, 35, 40, 45, 50];
        if (!in_array($nightPremiumRate, $nightPremiumOptions, true)) {
            $errors[] = '深夜割増率は 25%〜50%（5%刻み）から選択してください';
        }

        if ($wageRaw !== '' && $wageInt === null) $errors[] = '時給は数字のみ';
        if ($wageInt !== null && $wageInt < 0) $errors[] = '時給は0以上';

        if ((string)($_POST['age'] ?? '') !== '' && $ageInt === null) $errors[] = '年齢は数字のみ';
        if ($ageInt !== null && ($ageInt < 0 || $ageInt > 120)) $errors[] = '年齢は0〜120';

        if ((string)($_POST['nomination_fee_yen'] ?? '') !== '' && $nomInt === null) $errors[] = '指名料は数字のみ';
        if ($nomInt !== null && $nomInt < 0) $errors[] = '指名料は0以上';

        if ($stdRaw !== '' && $stdInt === null) $errors[] = '標準報酬月額は数字のみ';
        if ($stdInt !== null && $stdInt < 0) $errors[] = '標準報酬月額は0以上';
        if (($healthEnrolled === 1 || $pensionEnrolled === 1) && (int)$stdInt <= 0) {
            $errors[] = '社保加入者は標準報酬月額を入力してください';
        }

        if ($birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            $errors[] = '生年月日は YYYY-MM-DD 形式で入力してください';
        } elseif ($birthDate !== '') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $birthDate);
            if (!$dt) $errors[] = '生年月日が不正です';
        }

        if (trim((string)($_POST['email'] ?? '')) !== '' && !filter_var((string)$_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メール形式が不正';
        }

        if (trim((string)($_POST['phone'] ?? '')) !== '' && $phoneDigits === '') $errors[] = '電話番号は数字のみ';
        if ($phoneDigits !== '' && mb_strlen($phoneDigits) > 20) $errors[] = '電話番号が長すぎます';

        if (trim((string)($_POST['emergency_phone'] ?? '')) !== '' && $emgDigits === '') $errors[] = '緊急電話は数字のみ';
        if ($emgDigits !== '' && mb_strlen($emgDigits) > 20) $errors[] = '緊急電話が長すぎます';

        $snsList = parseSnsLines((string)($_POST['sns_lines'] ?? ''));
        $snsDb = snsToDbString($snsList);

        // ✅ 適用開始営業日（プルダウンのみ許可）
        $wageEffectiveBusinessDay = trim((string)($_POST['wage_effective_business_day'] ?? ''));
        if ($wageEffectiveBusinessDay === '' || !isset($businessDaySet[$wageEffectiveBusinessDay])) {
            $errors[] = '時給の適用開始営業日（YYYY-MM-DD）が不正です';
            $wageEffectiveBusinessDay = $defaultBusinessDayYmd; // 表示崩れ防止
        }

        // ✅ 新ファイル（あれば）
        $resumeInfo = null;
        if (isset($_FILES['resume_file'])) {
            $resumeInfo = handleResumeUpload($_FILES['resume_file'], $tenantId, $storeId, $employeeId, $errors);
        }

        // ✅ baseline用：現在DBの時給
        $dbWage = $empDb['hourly_wage_yen'];
        $dbWageInt = ($dbWage === null ? null : (int)$dbWage);
        $wageChanged = ($wageRaw !== '' && $wageInt !== null && $dbWageInt !== null)
            ? ((int)$wageInt !== (int)$dbWageInt)
            : ($wageRaw !== '' && $wageInt !== null && $dbWageInt === null);
        $historyDateExplicitlyChanged = ($wageEffectiveBusinessDay !== $defaultBusinessDayYmd);
        $shouldTouchWageHistory = $wageChanged || $historyDateExplicitlyChanged;

        $shouldUpsertHistory = false;
        if ($hasWageHistoryTable && $shouldTouchWageHistory && $wageRaw !== '' && $wageInt !== null && isset($businessDaySet[$wageEffectiveBusinessDay])) {
            $shouldUpsertHistory = wageHistoryNeedsUpsert($pdo, $tenantId, $storeId, $employeeId, $wageEffectiveBusinessDay, $wageInt);
        }

        if (!$errors) {
            $oldResumePath = (string)($empDb['resume_path'] ?? '');

            try {
                $pdo->beginTransaction();

                // employee_profiles upsert
                $stmt = $pdo->prepare("
                    INSERT INTO employee_profiles
                      (tenant_id, store_id, employee_id, profile_text, traits_text, memo_text, created_at, updated_at)
                    VALUES (:t,:s,:e,:p,:tr,:m,NOW(),NOW())
                    ON DUPLICATE KEY UPDATE
                      profile_text=VALUES(profile_text),
                      traits_text=VALUES(traits_text),
                      memo_text=VALUES(memo_text),
                      updated_at=NOW()
                ");
                $stmt->execute([
                    ':t' => $tenantId,
                    ':s' => $storeId,
                    ':e' => $employeeId,
                    ':p' => trim((string)($_POST['profile_text'] ?? '')) ?: null,
                    ':tr' => trim((string)($_POST['traits_text'] ?? '')) ?: null,
                    ':m' => trim((string)($_POST['memo_text'] ?? '')) ?: null,
                ]);

                // ✅ baseline + 履歴
                if ($hasWageHistoryTable && $shouldTouchWageHistory && $wageRaw !== '' && $wageInt !== null && isset($businessDaySet[$wageEffectiveBusinessDay])) {
                    $hasAnyHistory = wageHistoryExists($pdo, $tenantId, $storeId, $employeeId);
                    if (!$hasAnyHistory && $dbWageInt !== null) {
                        if ((int)$dbWageInt !== (int)$wageInt) {
                            insertWageBaselineIfNeeded($pdo, $tenantId, $storeId, $employeeId, (int)$dbWageInt);
                        }
                    }

                    if ($shouldUpsertHistory) {
                        $stmt = $pdo->prepare("
                            INSERT INTO employee_wage_histories
                              (tenant_id, store_id, employee_id, effective_business_day, hourly_wage_yen, created_at, updated_at)
                            VALUES (:t,:s,:e,:d,:w,NOW(),NOW())
                            ON DUPLICATE KEY UPDATE
                              hourly_wage_yen = VALUES(hourly_wage_yen),
                              updated_at = NOW()
                        ");
                        $stmt->execute([
                            ':t' => $tenantId,
                            ':s' => $storeId,
                            ':e' => $employeeId,
                            ':d' => $wageEffectiveBusinessDay,
                            ':w' => $wageInt,
                        ]);
                    }
                }

                // employees update
                $sql = "
                    UPDATE employees SET
                      display_name=:display_name,
                      hourly_wage_yen=:w,
                      age=:age,
                      phone=:ph,
                      email=:em,
                      sns=:sns,

                      address=:addr,
                      emergency_name=:en,
                      emergency_relation=:er,
                      emergency_phone=:ep,

                      employment_type=:etype,
                      hire_date=:hdate,
                      leave_date=:ldate,
                      commute_allowance_note=:canote,

                      bank_name=:bname,
                      bank_branch=:bbranch,
                      bank_account_type=:btype,
                      bank_account_number=:bnum,
                      bank_account_holder=:bholder,

                      nomination_fee_yen=:nom,
                      tax_type=:tax_type,
                      withholding_pay_cycle=:wh_cycle,
                      night_premium_enabled=:night_premium_enabled,
                      night_premium_rate_percent=:night_premium_rate_percent,
                      standard_monthly_remuneration=:std_rem,
                      health_ins_enrolled=:health_enrolled,
                      pension_enrolled=:pension_enrolled,
                      birth_date=:birth_date,
                      updated_at=CURRENT_TIMESTAMP
                      " . ($resumeInfo ? ", resume_path=:rpath, resume_original_name=:rname, resume_uploaded_at=NOW() " : "") . "
                    WHERE tenant_id=:t AND store_id=:s AND id=:e
                    LIMIT 1
                ";
                $stmt = $pdo->prepare($sql);

                $params = [
                    ':display_name' => $displayName,
                    ':w' => ($wageRaw === '' ? null : $wageInt),
                    ':age' => ((string)($_POST['age'] ?? '') === '' ? null : $ageInt),
                    ':ph' => ($phoneDigits === '' ? null : $phoneDigits),
                    ':em' => (trim((string)($_POST['email'] ?? '')) === '' ? null : trim((string)$_POST['email'])),
                    ':sns' => $snsDb,

                    ':addr' => (trim((string)($_POST['address'] ?? '')) === '' ? null : trim((string)($_POST['address']))),
                    ':en' => (trim((string)($_POST['emergency_name'] ?? '')) === '' ? null : trim((string)($_POST['emergency_name']))),
                    ':er' => (trim((string)($_POST['emergency_relation'] ?? '')) === '' ? null : trim((string)($_POST['emergency_relation']))),
                    ':ep' => ($emgDigits === '' ? null : $emgDigits),

                    ':etype' => (trim((string)($_POST['employment_type'] ?? '')) === '' ? null : trim((string)($_POST['employment_type']))),
                    ':hdate' => (trim((string)($_POST['hire_date'] ?? '')) === '' ? null : trim((string)($_POST['hire_date']))),
                    ':ldate' => (trim((string)($_POST['leave_date'] ?? '')) === '' ? null : trim((string)($_POST['leave_date']))),
                    ':canote' => (trim((string)($_POST['commute_allowance_note'] ?? '')) === '' ? null : trim((string)($_POST['commute_allowance_note']))),

                    ':bname' => (trim((string)($_POST['bank_name'] ?? '')) === '' ? null : trim((string)($_POST['bank_name']))),
                    ':bbranch' => (trim((string)($_POST['bank_branch'] ?? '')) === '' ? null : trim((string)($_POST['bank_branch']))),
                    ':btype' => (trim((string)($_POST['bank_account_type'] ?? '')) === '' ? null : trim((string)($_POST['bank_account_type']))),
                    ':bnum' => (trim((string)($_POST['bank_account_number'] ?? '')) === '' ? null : trim((string)($_POST['bank_account_number']))),
                    ':bholder' => (trim((string)($_POST['bank_account_holder'] ?? '')) === '' ? null : trim((string)($_POST['bank_account_holder']))),

                    ':nom' => ((string)($_POST['nomination_fee_yen'] ?? '') === '' ? null : $nomInt),

                    ':tax_type' => $taxType,
                    ':wh_cycle' => ($whCycle === '' ? null : $whCycle),
                    ':night_premium_enabled' => $nightPremiumEnabled,
                    ':night_premium_rate_percent' => $nightPremiumRate,
                    ':std_rem' => ($stdRaw === '' ? 0 : (int)$stdInt),
                    ':health_enrolled' => $healthEnrolled,
                    ':pension_enrolled' => $pensionEnrolled,
                    ':birth_date' => ($birthDate === '' ? null : $birthDate),

                    ':t' => $tenantId,
                    ':s' => $storeId,
                    ':e' => $employeeId,
                ];

                if ($resumeInfo) {
                    $params[':rpath'] = (string)$resumeInfo['path'];
                    $params[':rname'] = (string)$resumeInfo['original'];
                }

                $stmt->execute($params);

                $pdo->commit();
                $success = '保存しました';

                if ($resumeInfo && isset($resumeInfo['path'])) {
                    $newResumePath = (string)$resumeInfo['path'];
                    if ($oldResumePath !== '' && $oldResumePath !== $newResumePath) {
                        deleteResumeFileByDbPath($oldResumePath);
                    }
                }

                // 再読込
                $st = $pdo->prepare("
                    SELECT
                      display_name,
                      hourly_wage_yen, nomination_fee_yen, age,
                      phone, email, sns,
                      address,
                      emergency_name, emergency_relation, emergency_phone,
                      employment_type, hire_date, leave_date,
                      commute_allowance_note,
                      bank_name, bank_branch, bank_account_type, bank_account_number, bank_account_holder,
                      resume_path, resume_original_name, resume_uploaded_at,
                      tax_type,
                      withholding_pay_cycle,
                      night_premium_enabled,
                      night_premium_rate_percent,
                      standard_monthly_remuneration,
                      health_ins_enrolled,
                      pension_enrolled,
                      birth_date
                    FROM employees
                    WHERE tenant_id=:t AND store_id=:s AND id=:e
                    LIMIT 1
                ");
                $st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
                $empDb = $st->fetch() ?: $empDb;

                $st = $pdo->prepare("SELECT profile_text, traits_text, memo_text FROM employee_profiles WHERE tenant_id=:t AND store_id=:s AND employee_id=:e LIMIT 1");
                $st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
                $pDb = $st->fetch() ?: $pDb;

                $empForm = $empDb;
                $empForm['sns_lines'] = implode("\n", snsFromDb($empDb['sns'] ?? null));
                $pForm = $pDb;

                $empForm['wage_effective_business_day'] = $wageEffectiveBusinessDay;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();

                if (!empty($resumeInfo['abs']) && is_file((string)$resumeInfo['abs'])) {
                    @unlink((string)$resumeInfo['abs']);
                }

                $errors[] = 'エラー: ' . $e->getMessage();
            }
        } else {
            if (!empty($resumeInfo['abs']) && is_file((string)$resumeInfo['abs'])) {
                @unlink((string)$resumeInfo['abs']);
            }
        }
    }
}

$snsListForView = snsFromDb($empDb['sns'] ?? null);
$resumeDlUrl = '/admin/resume_download.php?store_id=' . (int)$storeId . '&employee_id=' . (int)$employeeId;

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>従業員プロフィール編集</title>
    <style>
    * {
        box-sizing: border-box
    }

    body {
        margin: 0;
        font-family: system-ui, -apple-system, sans-serif;
        background: #f7f7f7;
        color: #111
    }

    .page {
        padding: 24px;
        padding-bottom: 64px;
        max-width: 1100px;
        margin: 0 auto
    }

    .card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 14px;
        padding: 16px
    }

    .muted {
        color: #666;
        font-size: 12px
    }

    .err {
        background: #ffecec;
        border: 1px solid #ffb3b3;
        padding: 10px;
        border-radius: 10px;
        margin-bottom: 12px
    }

    .ok {
        background: #eaffea;
        border: 1px solid #9be59b;
        padding: 10px;
        border-radius: 10px;
        margin-bottom: 12px
    }

    .warn {
        background: #fff7e6;
        border: 1px solid #ffd28a;
        padding: 10px;
        border-radius: 10px;
        margin-bottom: 12px
    }

    .headerRow {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 12px 12px;
        border-radius: 12px;
        border: 1px solid #111;
        background: #111;
        color: #fff;
        font-weight: 900;
        cursor: pointer;
        width: 100%
    }

    .btn2 {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid #111;
        background: #fff;
        color: #111;
        font-weight: 900;
        text-decoration: none
    }

    .summaryCard {
        background: #f3f6fb;
        border: 1px solid #cfd7e3;
        border-radius: 14px;
        padding: 12px;
        margin-top: 0
    }

    .contentGrid {
        display: grid;
        grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
        gap: 14px;
        margin-top: 12px;
        align-items: start;
    }

    @media (max-width: 900px) {
        .contentGrid {
            grid-template-columns: 1fr;
        }
    }

    .sectionFold {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 8px 12px 12px;
        background: #fff;
        margin-bottom: 12px;
    }

    .sectionFoldSummary {
        cursor: pointer;
        list-style: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        font-weight: 900;
        padding: 6px 0;
    }

    .sectionFoldSummary::-webkit-details-marker {
        display: none;
    }

    .sectionFoldSummary .foldHint {
        color: #64748b;
        font-size: 12px;
        font-weight: 700;
    }

    .sectionFoldBody {
        padding-top: 6px;
    }

    .summaryTitle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px
    }

    .summaryTitle h3 {
        margin: 0;
        font-size: 12px;
        font-weight: 900
    }

    .summaryGrid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        margin-top: 8px
    }

    @media(max-width:900px) {
        .summaryGrid {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }
    }

    @media(max-width:560px) {
        .summaryGrid {
            grid-template-columns: 1fr
        }
    }

    .sItem {
        border: 1px solid #e4e8f0;
        border-radius: 12px;
        padding: 8px;
        background: #fff;
        min-height: 52px
    }

    .sLabel {
        font-size: 11px;
        color: #666;
        font-weight: 900;
        margin-bottom: 4px
    }

    .sValue {
        font-size: 13px;
        font-weight: 900;
        word-break: break-word
    }

    .summaryCard .hint {
        font-size: 10px;
        line-height: 1.4;
    }

    .mono {
        font-variant-numeric: tabular-nums
    }

    .full {
        grid-column: 1 / -1
    }

    .section {
        margin-top: 16px;
        padding: 16px;
        border: 1px solid #eee;
        border-radius: 12px;
        background: #fbfbfb
    }

    .section h3 {
        margin: 0;
        font-size: 14px;
        font-weight: 900
    }

    .sectionHeader {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px
    }

    .sectionHint {
        font-size: 12px;
        color: #666
    }

    .grid2 {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-top: 12px
    }

    @media(max-width:720px) {
        .grid2 {
            grid-template-columns: 1fr
        }
    }

    label {
        display: block;
        margin-top: 10px;
        font-weight: 900
    }

    .sectionFold label {
        font-size: 11px;
        margin-top: 8px;
    }

    textarea {
        width: 100%;
        min-height: 120px;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 12px;
        font-size: 14px;
        line-height: 1.55
    }

    input[type="text"],
    input[type="email"],
    input[type="date"],
    select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 12px;
        font-size: 14px
    }

    .hint {
        margin-top: 6px;
        font-size: 12px;
        color: #666
    }

    .links {
        display: flex;
        flex-wrap: wrap;
        gap: 8px
    }

    .chip {
        display: inline-flex;
        align-items: center;
        padding: 7px 10px;
        border-radius: 999px;
        border: 1px solid #e5e5e5;
        background: #fff;
        font-size: 12px;
        font-weight: 900;
        text-decoration: none;
        color: #111;
        max-width: 100%
    }

    .chip:hover {
        background: #f6f6f6
    }

    .smallBtn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 8px;
        border-radius: 10px;
        border: 1px solid #111;
        background: #fff;
        color: #111;
        font-weight: 900;
        font-size: 11px;
        text-decoration: none
    }

    hr.sep {
        border: none;
        border-top: 1px solid #eee;
        margin: 14px 0
    }

    .inlineRow {
        display: flex;
        gap: 10px;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .inlineRow.alignTop {
        align-items: flex-start;
    }

    .inlineRow>div {
        flex: 1;
        min-width: 220px;
    }

    .quickNav {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px
    }

    .quickNav a {
        display: inline-flex;
        align-items: center;
        padding: 10px 14px;
        border-radius: 999px;
        border: 1px solid #e5e5e5;
        background: #fff;
        color: #111;
        font-size: 14px;
        font-weight: 900;
        text-decoration: none
    }

    .quickNav a:hover {
        background: #f3f3f3
    }

    .fieldGroup {
        margin-top: 8px
    }

    .fieldGroup>label {
        margin-top: 0
    }

    .helpWrap {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 6px
    }

    .helpIcon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 14px;
        height: 14px;
        border-radius: 999px;
        border: 1px solid #cfe1ff;
        background: #eef4ff;
        color: #3b82f6;
        font-size: 9px;
        font-weight: 900;
        cursor: pointer;
        line-height: 1
    }

    .helpPopover {
        position: absolute;
        top: 26px;
        left: 0;
        z-index: 5;
        width: min(520px, 90vw);
        background: #fff;
        border: 1px solid #d9dde6;
        border-radius: 12px;
        padding: 12px;
        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12);
        font-size: 12px;
        line-height: 1.6;
        color: #111;
        display: none
    }

    .helpPopover.is-open {
        display: block
    }

    .helpPopover h4 {
        margin: 0 0 6px 0;
        font-size: 12px;
        font-weight: 900
    }

    .helpPopover p {
        margin: 0 0 8px 0
    }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="page">

        <div class="card">
            <div class="headerRow">
                <div>
                    <div style="font-weight:900;font-size:18px;">従業員プロフィール編集</div>
                    <div class="muted"><?= h($storeName) ?> / <?= h($employeeName) ?></div>
                </div>
                <a class="btn2" href="/admin/employees_new.php?store_id=<?= (int)$storeId ?>">戻る</a>
            </div>
            <?php if (!$hasWageHistoryTable): ?>
            <div class="warn" style="margin-top:12px;">
                <div style="font-weight:900;">⚠ 時給履歴テーブルが見つかりません</div>
                <div class="muted" style="margin-top:6px;">
                    時給の履歴を保存する機能が使えません。<br>
                    「適用開始営業日」を選んでも履歴保存ができない状態です。
                </div>
            </div>
            <?php endif; ?>

            <?php if ($success): ?><div class="ok" style="margin-top:12px;"><?= h($success) ?></div><?php endif; ?>
            <?php if ($errors): ?><div class="err" style="margin-top:12px;"><?php foreach ($errors as $e): ?><div>
                    <?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
        </div>

        <div class="contentGrid">
            <div class="leftCol">
                <div class="card">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                        <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">

                        <details class="sectionFold" id="sec-basic">
                            <summary class="sectionFoldSummary">
                                <span>基本情報</span>
                                <span class="foldHint">源泉・社保・時給</span>
                            </summary>
                            <div class="sectionFoldBody">

                    <div class="grid2">
                        <div>
                            <label>名前（表示名）</label>
                            <input type="text" name="display_name" value="<?= h((string)($empForm['display_name'] ?? '')) ?>" required>
                        </div>
                        <div>
                            <label>
                                <span class="helpWrap">
                                    源泉区分
                                    <button class="helpIcon" type="button" data-help-target="help-tax-type">?</button>
                                    <span class="helpPopover" id="help-tax-type">
                                        <h4>源泉区分とは</h4>
                                        <p>甲は「主たる給与の扶養控除等申告書あり」、乙は「申告書なし（副業扱いになりやすい）」です。</p>
                                    </span>
                                </span>
                            </label>
                            <select name="tax_type" required>
                                <option value="ko"
                                    <?= ((string)($empForm['tax_type'] ?? '') === 'ko') ? 'selected' : '' ?>>甲（ko）
                                </option>
                                <option value="otsu"
                                    <?= ((string)($empForm['tax_type'] ?? '') === 'otsu') ? 'selected' : '' ?>>乙（otsu）
                                </option>
                            </select>
                        </div>

                        <div>
                            <label>
                                <span class="helpWrap">
                                    源泉表の参照サイクル
                                    <button class="helpIcon" type="button" data-help-target="help-withholding-cycle">?</button>
                                    <span class="helpPopover" id="help-withholding-cycle">
                                        <h4>参照サイクルとは</h4>
                                        <p>源泉税を計算するときの支払いサイクルを選びます。</p>
                                        <p>未設定の場合は、店舗の給与サイクルに合わせます。</p>
                                    </span>
                                </span>
                            </label>
                            <select name="withholding_pay_cycle">
                                <option value=""
                                    <?= (trim((string)($empForm['withholding_pay_cycle'] ?? '')) === '') ? 'selected' : '' ?>>
                                    （未設定：店舗の給与サイクルに合わせる）</option>
                                <option value="daily"
                                    <?= ((string)($empForm['withholding_pay_cycle'] ?? '') === 'daily') ? 'selected' : '' ?>>
                                    日払い（daily）</option>
                                <option value="weekly"
                                    <?= ((string)($empForm['withholding_pay_cycle'] ?? '') === 'weekly') ? 'selected' : '' ?>>
                                    週払い（weekly）</option>
                                <option value="monthly"
                                    <?= ((string)($empForm['withholding_pay_cycle'] ?? '') === 'monthly') ? 'selected' : '' ?>>
                                    月払い（monthly）</option>
                            </select>
                        </div>

                        <div>
                            <label>
                                <span class="helpWrap">
                                    標準報酬月額（円）
                                    <button class="helpIcon" type="button" data-help-target="help-standard-rem">?</button>
                                    <span class="helpPopover" id="help-standard-rem">
                                        <h4>通知書はどこからもらえる？</h4>
                                        <p>通知書（標準報酬月額の決定・改定の結果）は、原則「日本年金機構 → 事業主（会社・店舗）」に届きます。事業主には従業員へ通知する義務があります。</p>
                                        <p>タイミングは、資格取得時決定・定時決定（算定基礎届）・随時改定（月変）です。算定基礎届は事業所に送付され、事業所が提出します。</p>
                                        <p>現場での最短ルートは、店舗（事業主）の社保担当に「標準報酬月額決定通知書の標準報酬月額を教えてください」でOKです。</p>
                                        <h4>等級表はどこで手に入る？</h4>
                                        <p>A) 協会けんぽの保険料額表（都道府県別）</p>
                                        <p>B) 日本年金機構の厚生年金保険料額表</p>
                                    </span>
                                </span>
                            </label>
                            <input type="text" name="standard_monthly_remuneration" inputmode="numeric" pattern="\d*"
                                value="<?= h((string)($empForm['standard_monthly_remuneration'] ?? '')) ?>"
                                oninput="this.value=this.value.replace(/[^\d]/g,'')">
                        </div>

                        <div>
                            <label>
                                <span class="helpWrap">
                                    社保加入
                                    <button class="helpIcon" type="button" data-help-target="help-ins-enroll">?</button>
                                    <span class="helpPopover" id="help-ins-enroll">
                                        <h4>社保加入について</h4>
                                        <p>加入しているものにチェックしてください。加入者は標準報酬月額が必要です。</p>
                                    </span>
                                </span>
                            </label>
                            <div class="inlineRow alignTop">
                                <label style="margin:0;">
                                    <input type="checkbox" name="health_ins_enrolled" value="1"
                                        <?= ((int)($empForm['health_ins_enrolled'] ?? 0) === 1) ? 'checked' : '' ?>>
                                    健康保険
                                </label>
                                <label style="margin:0;">
                                    <input type="checkbox" name="pension_enrolled" value="1"
                                        <?= ((int)($empForm['pension_enrolled'] ?? 0) === 1) ? 'checked' : '' ?>>
                                    厚生年金
                                </label>
                            </div>
                        </div>

                        <div>
                            <label>
                                <span class="helpWrap">
                                    生年月日
                                    <button class="helpIcon" type="button" data-help-target="help-birth-date">?</button>
                                    <span class="helpPopover" id="help-birth-date">
                                        <h4>生年月日について</h4>
                                        <p>介護保険の対象判定に使います。</p>
                                    </span>
                                </span>
                            </label>
                            <input type="date" name="birth_date" value="<?= h((string)($empForm['birth_date'] ?? '')) ?>">
                        </div>

                        <div class="fieldGroup" style="grid-column:1/-1;">
                            <label>
                                <span class="helpWrap">
                                    時給（円）
                                    <button class="helpIcon" type="button" data-help-target="help-hourly-wage">?</button>
                                    <span class="helpPopover" id="help-hourly-wage">
                                        <h4>時給について</h4>
                                        <p>ここを変更すると「現在の時給」が変わります。過去の計算は履歴を使って守ります。</p>
                                    </span>
                                </span>
                            </label>
                            <div class="inlineRow">
                                <div>
                                    <input type="text" name="hourly_wage_yen" inputmode="numeric" pattern="\d*"
                                        value="<?= h((string)($empForm['hourly_wage_yen'] ?? '')) ?>"
                                        oninput="this.value=this.value.replace(/[^\d]/g,'')">
                                </div>

                                <div>
                                    <label style="margin-top:0;">
                                        <span class="helpWrap">
                                            時給の適用開始（営業日）
                                            <button class="helpIcon" type="button" data-help-target="help-wage-start">?</button>
                                            <span class="helpPopover" id="help-wage-start">
                                                <h4>適用開始について</h4>
                                                <p>変更した時給がいつから有効になるかを選びます。</p>
                                                <p>同じ日付で保存すると、その日の履歴が上書きされます。</p>
                                                <p>時給を変えずにこの日付だけ変えて保存すると、過去の時給適用日を修正できます。</p>
                                            </span>
                                        </span>
                                    </label>
                                    <select name="wage_effective_business_day" required>
                                        <?php
                                        $selected = (string)($empForm['wage_effective_business_day'] ?? $defaultBusinessDayYmd);
                                        foreach ($businessDayOptions as $opt):
                                            $v = (string)$opt['value'];
                                            $lbl = (string)$opt['label'];
                                        ?>
                                        <option value="<?= h($v) ?>" <?= ($v === $selected) ? 'selected' : '' ?>>
                                            <?= h($lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label>深夜割増</label>
                            <div class="inlineRow">
                                <label style="margin:0;">
                                    <input type="checkbox" name="night_premium_enabled" value="1"
                                        <?= ((int)($empForm['night_premium_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
                                    有効
                                </label>
                            </div>
                        </div>

                        <div>
                            <label>深夜割増率（%）</label>
                            <select name="night_premium_rate_percent">
                                <?php
                                $npRate = (int)($empForm['night_premium_rate_percent'] ?? 25);
                                foreach ([25, 30, 35, 40, 45, 50] as $r):
                                ?>
                                <option value="<?= (int)$r ?>" <?= ($npRate === (int)$r) ? 'selected' : '' ?>>
                                    <?= (int)$r ?>%</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label>年齢</label>
                            <input type="text" name="age" inputmode="numeric" pattern="\d*"
                                value="<?= h((string)($empForm['age'] ?? '')) ?>"
                                oninput="this.value=this.value.replace(/[^\d]/g,'')">
                        </div>

                        <div>
                            <label>電話番号（数字のみ）</label>
                            <input type="text" name="phone" inputmode="numeric" pattern="\d*"
                                value="<?= h((string)($empForm['phone'] ?? '')) ?>"
                                oninput="this.value=this.value.replace(/[^\d]/g,'')">
                        </div>

                        <div>
                            <label>メール</label>
                            <input type="email" name="email" value="<?= h((string)($empForm['email'] ?? '')) ?>">
                        </div>

                        <div style="grid-column:1/-1;">
                            <label>
                                <span class="helpWrap">
                                    SNS（1行=1リンク）
                                    <button class="helpIcon" type="button" data-help-target="help-sns">?</button>
                                    <span class="helpPopover" id="help-sns">
                                        <h4>SNS入力</h4>
                                        <p>1行に1つのURLまたは文字列を入力してください。</p>
                                    </span>
                                </span>
                            </label>
                            <textarea name="sns_lines"
                                style="min-height:140px;"><?= h((string)($empForm['sns_lines'] ?? '')) ?></textarea>
                        </div>

                    </div>
                            </div>
                        </details>

                        <details class="sectionFold" id="sec-contact">
                            <summary class="sectionFoldSummary">
                                <span>連絡先</span>
                                <span class="foldHint">住所・緊急連絡</span>
                            </summary>
                            <div class="sectionFoldBody">

                    <label>住所</label>
                    <input type="text" name="address" value="<?= h((string)($empForm['address'] ?? '')) ?>">

                    <div class="grid2">
                        <div>
                            <label>緊急連絡先 氏名</label>
                            <input type="text" name="emergency_name"
                                value="<?= h((string)($empForm['emergency_name'] ?? '')) ?>">
                        </div>
                        <div>
                            <label>続柄</label>
                            <input type="text" name="emergency_relation" placeholder="例：配偶者 / 母 / 兄"
                                value="<?= h((string)($empForm['emergency_relation'] ?? '')) ?>">
                        </div>
                        <div style="grid-column:1/-1;">
                            <label>緊急連絡先 電話（数字のみ）</label>
                            <input type="text" name="emergency_phone" inputmode="numeric" pattern="\d*"
                                value="<?= h((string)($empForm['emergency_phone'] ?? '')) ?>"
                                oninput="this.value=this.value.replace(/[^\d]/g,'')">
                        </div>
                    </div>
                            </div>
                        </details>

                        <details class="sectionFold" id="sec-employment">
                            <summary class="sectionFoldSummary">
                                <span>雇用情報</span>
                                <span class="foldHint">雇用形態・入退社・交通費</span>
                            </summary>
                            <div class="sectionFoldBody">
                    <div class="grid2">
                        <div>
                            <label>雇用形態</label>
                            <input type="text" name="employment_type" placeholder="例：アルバイト"
                                value="<?= h((string)($empForm['employment_type'] ?? '')) ?>">
                        </div>
                        <div>
                            <label>入社日</label>
                            <input type="date" name="hire_date" value="<?= h((string)($empForm['hire_date'] ?? '')) ?>">
                        </div>
                        <div>
                            <label>退職日</label>
                            <input type="date" name="leave_date"
                                value="<?= h((string)($empForm['leave_date'] ?? '')) ?>">
                        </div>
                        <div>
                            <label>交通費メモ</label>
                            <input type="text" name="commute_allowance_note" placeholder="例：上限1日500円"
                                value="<?= h((string)($empForm['commute_allowance_note'] ?? '')) ?>">
                        </div>
                    </div>
                            </div>
                        </details>

                        <details class="sectionFold" id="sec-bank">
                            <summary class="sectionFoldSummary">
                                <span>振込（任意）</span>
                                <span class="foldHint">口座情報</span>
                            </summary>
                            <div class="sectionFoldBody">
                    <div class="grid2">
                        <div>
                            <label>銀行名</label>
                            <input type="text" name="bank_name" value="<?= h((string)($empForm['bank_name'] ?? '')) ?>">
                        </div>
                        <div>
                            <label>支店名</label>
                            <input type="text" name="bank_branch"
                                value="<?= h((string)($empForm['bank_branch'] ?? '')) ?>">
                        </div>
                        <div>
                            <label>
                                <span class="helpWrap">
                                    口座種別
                                    <button class="helpIcon" type="button" data-help-target="help-bank-type">?</button>
                                    <span class="helpPopover" id="help-bank-type">
                                        <h4>口座種別の例</h4>
                                        <p>普通 / 当座 / 貯蓄 などを入力してください。</p>
                                    </span>
                                </span>
                            </label>
                            <input type="text" name="bank_account_type" placeholder="ordinary/current/savings"
                                value="<?= h((string)($empForm['bank_account_type'] ?? '')) ?>">
                        </div>
                        <div>
                            <label>口座番号</label>
                            <input type="text" name="bank_account_number" inputmode="numeric" pattern="\d*"
                                oninput="this.value=this.value.replace(/[^\d]/g,'')"
                                value="<?= h((string)($empForm['bank_account_number'] ?? '')) ?>">
                        </div>
                        <div style="grid-column:1/-1;">
                            <label>口座名義</label>
                            <input type="text" name="bank_account_holder"
                                value="<?= h((string)($empForm['bank_account_holder'] ?? '')) ?>">
                        </div>
                    </div>
                            </div>
                        </details>

                        <details class="sectionFold" id="sec-resume">
                            <summary class="sectionFoldSummary">
                                <span>履歴書</span>
                                <span class="foldHint">ファイル管理</span>
                            </summary>
                            <div class="sectionFoldBody">
                    <label>
                        <span class="helpWrap">
                            履歴書ファイル（PDF/JPG/PNG/WEBP、最大10MB）
                            <button class="helpIcon" type="button" data-help-target="help-resume">?</button>
                            <span class="helpPopover" id="help-resume">
                                <h4>履歴書について</h4>
                                <p>保存先は非公開です。表示は「認可リンク」経由になります。</p>
                                <p>差し替え時は古いファイルを自動で削除します。</p>
                            </span>
                        </span>
                    </label>
                    <input type="file" name="resume_file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
                            </div>
                        </details>

                        <details class="sectionFold" id="sec-profile">
                            <summary class="sectionFoldSummary">
                                <span>プロフィール</span>
                                <span class="foldHint">店内共有メモ</span>
                            </summary>
                            <div class="sectionFoldBody">

                    <label>概要</label>
                    <textarea name="profile_text"><?= h((string)($pForm['profile_text'] ?? '')) ?></textarea>

                    <label>特徴</label>
                    <textarea name="traits_text"><?= h((string)($pForm['traits_text'] ?? '')) ?></textarea>

                    <label>メモ</label>
                    <textarea name="memo_text"><?= h((string)($pForm['memo_text'] ?? '')) ?></textarea>
                            </div>
                        </details>

                        <button class="btn" type="submit" style="margin-top:12px;">保存</button>
                    </form>
                </div>
            </div>

            <div class="rightCol">
                <div class="summaryCard">
                    <div class="summaryTitle">
                        <h3>現在のデータ</h3>
                    </div>

                    <div class="summaryGrid">
                        <div class="sItem">
                            <div class="sLabel">時給</div>
                            <div class="sValue mono">
                                <?= h(yen($empDb['hourly_wage_yen'] !== null ? (int)$empDb['hourly_wage_yen'] : null)) ?></div>
                        </div>

                        <div class="sItem full">
                            <div class="sLabel">時給履歴</div>
                            <?php if (!$hasWageHistoryTable): ?>
                            <div class="sValue">履歴テーブル未検出</div>
                            <?php elseif (!$wageHistoryRows): ?>
                            <div class="sValue">履歴なし</div>
                            <div class="hint">現在の時給だけが使われます。</div>
                            <?php else: ?>
                            <div class="hint">この日付から時給が切り替わります。同じ時給でも過去日で保存すると遡って反映できます。</div>
                            <div style="margin-top:8px;border:1px solid #eee;border-radius:10px;overflow:hidden;">
                                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                                    <thead>
                                        <tr style="background:#f7f7f7;">
                                            <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #eee;">適用開始営業日</th>
                                            <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #eee;">時給</th>
                                            <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #eee;">更新日時</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($wageHistoryRows as $row): ?>
                                        <tr>
                                            <td style="padding:8px 10px;border-bottom:1px solid #f1f1f1;" class="mono">
                                                <?= h((string)($row['effective_business_day'] ?? '')) ?>
                                            </td>
                                            <td style="padding:8px 10px;border-bottom:1px solid #f1f1f1;text-align:right;" class="mono">
                                                <?= h(yen(isset($row['hourly_wage_yen']) ? (int)$row['hourly_wage_yen'] : null)) ?>
                                            </td>
                                            <td style="padding:8px 10px;border-bottom:1px solid #f1f1f1;" class="mono">
                                                <?= h((string)($row['updated_at'] ?? '')) ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">深夜割増</div>
                            <div class="sValue"><?= ((int)($empDb['night_premium_enabled'] ?? 0) === 1) ? 'ON' : 'OFF' ?></div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">深夜割増率</div>
                            <div class="sValue mono">
                                <?= (int)($empDb['night_premium_rate_percent'] ?? 25) ?>%
                            </div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">源泉区分</div>
                            <div class="sValue"><?= h(taxTypeLabel((string)($empDb['tax_type'] ?? ''))) ?></div>
                            <div class="hint">※給与明細の源泉計算で使用</div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">源泉表参照サイクル</div>
                            <div class="sValue"><?= h(whCycleLabel((string)($empDb['withholding_pay_cycle'] ?? ''))) ?></div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">標準報酬月額</div>
                            <div class="sValue mono">
                                <?= h(yen($empDb['standard_monthly_remuneration'] !== null ? (int)$empDb['standard_monthly_remuneration'] : null)) ?>
                            </div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">健康保険加入</div>
                            <div class="sValue"><?= ((int)($empDb['health_ins_enrolled'] ?? 0) === 1) ? '加入' : '未加入' ?></div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">厚生年金加入</div>
                            <div class="sValue"><?= ((int)($empDb['pension_enrolled'] ?? 0) === 1) ? '加入' : '未加入' ?></div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">生年月日</div>
                            <div class="sValue mono"><?= h(textOrDash($empDb['birth_date'] ?? null)) ?></div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">年齢</div>
                            <div class="sValue mono"><?= h($empDb['age'] !== null ? ((int)$empDb['age'] . '歳') : '—') ?></div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">電話</div>
                            <div class="sValue mono"><?= h(textOrDash($empDb['phone'] ?? null)) ?></div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">メール</div>
                            <div class="sValue"><?= h(textOrDash($empDb['email'] ?? null)) ?></div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">住所</div>
                            <div class="sValue"><?= h(textOrDash($empDb['address'] ?? null)) ?></div>
                        </div>

                        <div class="sItem">
                            <div class="sLabel">雇用形態</div>
                            <div class="sValue"><?= h(textOrDash($empDb['employment_type'] ?? null)) ?></div>
                        </div>

                        <div class="sItem full">
                            <div class="sLabel">SNS（1行=1リンク）</div>
                            <?php if (!$snsListForView): ?>
                            <div class="sValue">—</div>
                            <?php else: ?>
                            <div class="links" style="margin-top:6px;">
                                <?php foreach ($snsListForView as $snsItem): ?>
                                <a class="chip" href="<?= h(snsLinkUrlAlways($snsItem)) ?>" target="_blank"
                                    rel="noopener noreferrer"><?= h($snsItem) ?></a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="sItem full">
                            <div class="sLabel">履歴書（認可リンク）</div>
                            <?php
                            $resumeDlUrl = '/admin/resume_download.php?store_id=' . (int)$storeId . '&employee_id=' . (int)$employeeId;
                            ?>
                            <?php if (trim((string)($empDb['resume_path'] ?? '')) === ''): ?>
                            <div class="sValue">—</div>
                            <?php else: ?>
                            <a class="smallBtn" href="<?= h($resumeDlUrl) ?>" target="_blank" rel="noopener noreferrer">
                                履歴書を見る（<?= h((string)($empDb['resume_original_name'] ?? 'file')) ?>）
                            </a>
                            <div class="hint">アップロード: <?= h((string)($empDb['resume_uploaded_at'] ?? '')) ?></div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-help-target]');
            const popovers = document.querySelectorAll('.helpPopover');

            if (btn) {
                const id = btn.getAttribute('data-help-target');
                const pop = document.getElementById(id);
                popovers.forEach((el) => {
                    if (el !== pop) el.classList.remove('is-open');
                });
                if (pop) pop.classList.toggle('is-open');
                return;
            }

            popovers.forEach((el) => el.classList.remove('is-open'));
        });
    </script>

</body>

</html>
