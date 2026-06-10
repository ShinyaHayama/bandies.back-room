<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/employees_new.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 今回の修正（FK 1451対策 / 既存UI・既存POSTフローは維持）
 * - delete_employee は employees を物理削除しない（FKで失敗するため）
 * - delete_employee は employment_status='inactive'（退職化）に更新する
 * - action名や画面上のボタン配置は変えない（既存機能を壊さない）
 *
 * ✅ 追加修正（時給履歴対応）
 * - 従業員一覧の「時給」は employee_wage_histories を優先して表示
 *   - effective_business_day <= 今日の営業日 の最新を採用
 *   - 履歴が無い / テーブルが無い環境は employees.hourly_wage_yen にフォールバック（壊さない）
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';

// ===== DB =====
require_once __DIR__ . '/../api/lib/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

date_default_timezone_set('Asia/Tokyo');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// tenant は _tenant_context.php が $tenantId を用意する想定
$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

// ===== tenant名 =====
$tenantName = '';
try {
    $tStmt = $pdo->prepare("SELECT name FROM tenants WHERE id = :id LIMIT 1");
    $tStmt->execute([':id' => $tenantId]);
    $tenantName = (string)($tStmt->fetchColumn() ?: '');
} catch (Throwable $e) {
    $tenantName = '';
}

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

function isValidCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}

// ===== ユーザー設定（admin_user_prefs）=====
function ensureAdminUserPrefsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_user_prefs (
            tenant_admin_user_id INT NOT NULL,
            pref_key VARCHAR(64) NOT NULL,
            pref_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tenant_admin_user_id, pref_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
function getAdminUserPref(PDO $pdo, int $userId, string $key, string $default = '0'): string
{
    try {
        ensureAdminUserPrefsTable($pdo);
        $st = $pdo->prepare("
            SELECT pref_value
            FROM admin_user_prefs
            WHERE tenant_admin_user_id = :uid AND pref_key = :k
            LIMIT 1
        ");
        $st->execute([':uid' => $userId, ':k' => $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null) return $default;
        return (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}
function setAdminUserPref(PDO $pdo, int $userId, string $key, string $value): void
{
    ensureAdminUserPrefsTable($pdo);
    $st = $pdo->prepare("
        INSERT INTO admin_user_prefs (tenant_admin_user_id, pref_key, pref_value)
        VALUES (:uid, :k, :v)
        ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([':uid' => $userId, ':k' => $key, ':v' => $value]);
}

/** 1..31 */
function clampDay(int $d, int $default): int
{
    return ($d < 1 || $d > 31) ? $default : $d;
}
/** 0..6 */
function clampWday(int $w, int $default): int
{
    return ($w < 0 || $w > 6) ? $default : $w;
}
/** 0..60 */
function clampOffsetDays(int $n, int $default): int
{
    return ($n < 0 || $n > 60) ? $default : $n;
}
/** 0..2 */
function clampMonthOffset(int $m, int $default): int
{
    return ($m < 0 || $m > 2) ? $default : $m;
}
/** 0=OFF, 5..30 */
function clampRoundUnit(int $m, int $default = 15): int
{
    if ($m === 0) return 0;
    if ($m < 5 || $m > 30) return $default;
    return $m;
}
function sanitizeTz(string $tz, string $default = 'Asia/Tokyo'): string
{
    $tz = trim($tz);
    if ($tz === '') return $default;
    try {
        new DateTimeZone($tz);
        return $tz;
    } catch (Throwable $e) {
        return $default;
    }
}
function clampRate(float $v, float $default): float
{
    if (!is_finite($v)) return $default;
    if ($v < 0.0) return 0.0;
    if ($v > 100.0) return 100.0;
    return round($v, 2);
}

/**
 * ✅ テーブル列一覧（列差異に耐える）
 * - なぜ必要か：stores に business_day_cutoff_time が無い環境でも壊さないため
 */
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

function ensureStoreEnableSlotColumn(PDO $pdo): void
{
    $cols = tableColumns($pdo, 'stores');
    if (!in_array('enable_slot', $cols, true)) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN enable_slot TINYINT(1) NOT NULL DEFAULT 1");
    }
}

function ensureStoreRequireSalesOnClockOutColumn(PDO $pdo): void
{
    $cols = tableColumns($pdo, 'stores');
    if (!in_array('require_sales_on_clockout', $cols, true)) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN require_sales_on_clockout TINYINT(1) NOT NULL DEFAULT 1");
    }
}

function ensureStoreLeaveRequestNotificationEmailColumn(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `stores` LIKE 'leave_request_notification_email'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN leave_request_notification_email VARCHAR(255) NULL");
        }
    } catch (Throwable $e) {
        throw $e;
    }
}

/** ✅ DB内に employee_wage_histories があるか（無い環境でも壊さないため） */
function hasEmployeeWageHistoriesTable(PDO $pdo): bool
{
    try {
        $db = (string)($pdo->query("SELECT DATABASE()")->fetchColumn() ?: '');
        if ($db === '') return false;
        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'employee_wage_histories'
            LIMIT 1
        ");
        $st->execute([':db' => $db]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * ✅ business_day_cutoff_time を秒に変換（"05:00" / "05:00:00" どちらもOK）
 */
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

/**
 * ✅ 営業日切替時刻の正規化
 * - 入力: "05:00" / "05:00:00"
 * - 出力: "05:00:00"
 */
function normalizeCutoffTime(string $cutoff, string $default = '00:00:00'): string
{
    $cutoff = trim($cutoff);
    if ($cutoff === '') return $default;

    if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $cutoff)) return $default;

    $parts = explode(':', $cutoff);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    $s = (int)($parts[2] ?? 0);

    if ($h < 0 || $h > 23) return $default;
    if ($m < 0 || $m > 59) return $default;
    if ($s < 0 || $s > 59) return $default;

    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

/**
 * ✅ 現在時刻(ts)から「営業日(business_date)」を算出
 * - cutoff より前（例: 00:00〜04:59）は前日を営業日として返す
 */
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

/**
 * ✅ 重複しない4桁PIN
 */
function generateUniquePinCode(PDO $pdo, int $tenantId, int $storeId): string
{
    $badPins = [
        '0000',
        '1111',
        '2222',
        '3333',
        '4444',
        '5555',
        '6666',
        '7777',
        '8888',
        '9999',
        '1234',
        '2345',
        '3456',
        '4567',
        '5678',
        '6789',
        '0123',
        '0987',
        '9876',
        '8765',
        '7654',
        '6543',
        '5432',
        '4321',
    ];

    $stmt = $pdo->prepare("
        SELECT 1
        FROM employees
        WHERE tenant_id = :tenant_id
          AND store_id  = :store_id
          AND auth_pin_code = :pin
        LIMIT 1
    ");

    for ($i = 0; $i < 100; $i++) {
        $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        if (in_array($pin, $badPins, true)) continue;

        $stmt->execute([':tenant_id' => $tenantId, ':store_id' => $storeId, ':pin' => $pin]);
        if (!(bool)$stmt->fetchColumn()) return $pin;
    }
    throw new RuntimeException('PIN生成に失敗しました（試行回数超過）');
}

function makePinHashPair(string $pin): array
{
    $salt = random_bytes(16);
    $hash = hash('sha256', $salt . $pin, true);
    return [$salt, $hash];
}

/**
 * ✅ 紐付けコード発行（line_bind_tokens）
 * - 期限: 24時間
 */
function issueLineBindToken(PDO $pdo, int $tenantId, int $storeId, int $employeeId): string
{
    $token = bin2hex(random_bytes(6)); // 12 hex

    $stmt = $pdo->prepare("
        INSERT INTO line_bind_tokens (tenant_id, store_id, employee_id, token, expires_at, used_at)
        VALUES (:t, :s, :e, :token, DATE_ADD(NOW(), INTERVAL 24 HOUR), NULL)
    ");
    $stmt->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
        ':token' => $token,
    ]);

    return $token;
}

/**
 * ✅ LINE紐付け解除
 */
function unlinkLineUser(PDO $pdo, int $tenantId, int $storeId, int $employeeId): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("
            UPDATE employees
               SET line_user_id = NULL,
                   updated_at = NOW()
             WHERE tenant_id = :t
               AND store_id  = :s
               AND id        = :id
             LIMIT 1
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId, ':id' => $employeeId]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('解除対象が見つかりません（対象なし）');
        }

        // 未使用トークンも無効化（任意）
        $st2 = $pdo->prepare("
            UPDATE line_bind_tokens
               SET expires_at = NOW()
             WHERE tenant_id = :t
               AND store_id  = :s
               AND employee_id = :id
               AND used_at IS NULL
               AND expires_at > NOW()
        ");
        $st2->execute([':t' => $tenantId, ':s' => $storeId, ':id' => $employeeId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ✅ 時給履歴テーブル有無（一覧の“見え方”だけに使う）
$hasWageHistoryTable = hasEmployeeWageHistoriesTable($pdo);

try {
    ensureStoreEnableSlotColumn($pdo);
    ensureStoreRequireSalesOnClockOutColumn($pdo);
    ensureStoreLeaveRequestNotificationEmailColumn($pdo);
} catch (Throwable $e) {
    $errors[] = '店舗設定列作成に失敗しました: ' . $e->getMessage();
}

// ===== tenantの店舗一覧（給与設定も取得）=====
// ✅ business_day_cutoff_time が無い環境でも壊さないため列存在チェックして SELECT を組み立てる
$storeCols = tableColumns($pdo, 'stores');
if (!in_array('leave_request_notification_email', $storeCols, true)) {
    $storeCols[] = 'leave_request_notification_email';
}
$selectCutoff = in_array('business_day_cutoff_time', $storeCols, true)
    ? "COALESCE(business_day_cutoff_time,'00:00:00') AS business_day_cutoff_time"
    : "'00:00:00' AS business_day_cutoff_time";
$selectEnableSlot = in_array('enable_slot', $storeCols, true)
    ? "COALESCE(enable_slot,1) AS enable_slot"
    : "1 AS enable_slot";
$selectRequireSalesOnClockOut = in_array('require_sales_on_clockout', $storeCols, true)
    ? "COALESCE(require_sales_on_clockout,1) AS require_sales_on_clockout"
    : "1 AS require_sales_on_clockout";
$selectLeaveRequestNotificationEmail = in_array('leave_request_notification_email', $storeCols, true)
    ? "COALESCE(leave_request_notification_email,'') AS leave_request_notification_email"
    : "'' AS leave_request_notification_email";

$storesStmt = $pdo->prepare("
    SELECT
        id, name, payslip_issuer_name,
        {$selectCutoff},
        COALESCE(payroll_tz, 'Asia/Tokyo') AS payroll_tz,
        COALESCE(payroll_pay_month_offset, 1) AS payroll_pay_month_offset,

        COALESCE(payroll_cycle_type, 'monthly') AS payroll_cycle_type,
        COALESCE(payroll_close_day, 31) AS payroll_close_day,
        COALESCE(payroll_pay_day, 25)   AS payroll_pay_day,

        COALESCE(payroll_week_close_wday, 0) AS payroll_week_close_wday,
        COALESCE(payroll_week_pay_offset_days, 0) AS payroll_week_pay_offset_days,

        COALESCE(payroll_round_unit_minutes, 15) AS payroll_round_unit_minutes,

        COALESCE(labor_green_max_rate, 30.00)  AS labor_green_max_rate,
        COALESCE(labor_yellow_max_rate, 35.00) AS labor_yellow_max_rate,
        {$selectEnableSlot},
        {$selectRequireSalesOnClockOut},
        {$selectLeaveRequestNotificationEmail}
    FROM stores
    WHERE tenant_id = :tenant_id
    ORDER BY id ASC
");
$storesStmt->execute([':tenant_id' => $tenantId]);
$stores = $storesStmt->fetchAll();

if (empty($stores)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "stores がありません。tenant_id={$tenantId}";
    exit;
}

// ===== store 選択（GET）=====
$storeId = (int)($_GET['store_id'] ?? 0);
$validStoreIds = array_map(fn($s) => (int)$s['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $validStoreIds, true)) {
    $storeId = (int)$stores[0]['id'];
}

// store情報（表示用）
$storeName = '';
$storeTz = 'Asia/Tokyo';
$storePayMonthOffset = 1;

$storeCycleType = 'monthly';
$storeCloseDay = 31;
$storePayDay = 25;
$storeWeekCloseWday = 0;
$storeWeekPayOffset = 0;
$storeRoundUnit = 15;
$storePayslipIssuerName = '';
$storeLaborGreenMax  = 30.0;
$storeLaborYellowMax = 35.0;
$storeEnableSlot = 1;
$storeRequireSalesOnClockOut = 1;
$storeLeaveRequestNotificationEmail = '';

// ✅ 今日の営業日（時給履歴参照の基準日）
$storeCutoff = '00:00:00';
$storeCutoffInput = '00:00';
$storeBusinessDay = date('Y-m-d');

foreach ($stores as $st) {
    if ((int)$st['id'] === $storeId) {
        $storeName = (string)$st['name'];
        $storePayslipIssuerName = (string)($st['payslip_issuer_name'] ?? '');
        $storeTz = sanitizeTz((string)($st['payroll_tz'] ?? 'Asia/Tokyo'), 'Asia/Tokyo');
        $storePayMonthOffset = clampMonthOffset((int)($st['payroll_pay_month_offset'] ?? 1), 1);

        $storeCycleType = (string)($st['payroll_cycle_type'] ?? 'monthly');
        $storeCloseDay = (int)($st['payroll_close_day'] ?? 31);
        $storePayDay   = (int)($st['payroll_pay_day'] ?? 25);
        $storeWeekCloseWday = (int)($st['payroll_week_close_wday'] ?? 0);
        $storeWeekPayOffset = (int)($st['payroll_week_pay_offset_days'] ?? 0);

        $storeRoundUnit = clampRoundUnit((int)($st['payroll_round_unit_minutes'] ?? 15), 15);
        $storeLaborGreenMax  = (float)($st['labor_green_max_rate'] ?? 30.0);
        $storeLaborYellowMax = (float)($st['labor_yellow_max_rate'] ?? 35.0);
        $storeEnableSlot = (int)($st['enable_slot'] ?? 1);
        $storeRequireSalesOnClockOut = (int)($st['require_sales_on_clockout'] ?? 1);
        $storeLeaveRequestNotificationEmail = (string)($st['leave_request_notification_email'] ?? '');

        $storeCutoff = normalizeCutoffTime((string)($st['business_day_cutoff_time'] ?? '00:00:00'), '00:00:00');
        $storeCutoffInput = substr($storeCutoff, 0, 5);
        $storeBusinessDay = businessDateFromTs(time(), cutoffToSeconds($storeCutoff));
        break;
    }
}
if (!in_array($storeCycleType, ['monthly', 'weekly'], true)) $storeCycleType = 'monthly';

// ===== 従業員系 =====
function fetchEmployees(PDO $pdo, int $tenantId, int $storeId, bool $hasWageHistoryTable, string $businessDayYmd): array
{
    if ($hasWageHistoryTable) {
        // ✅ 履歴優先の時給（<=営業日の最新）。無ければ employees にフォールバック。
        $stmt = $pdo->prepare("
            SELECT
                e.id,
                e.display_name,
                e.employment_status,
                e.sort_order,
                e.auth_pin_set_at,
                e.auth_pin_code,
                e.line_user_id,

                COALESCE(
                    (
                        SELECT wh.hourly_wage_yen
                        FROM employee_wage_histories wh
                        WHERE wh.tenant_id = e.tenant_id
                          AND wh.store_id  = e.store_id
                          AND wh.employee_id = e.id
                          AND wh.effective_business_day <= :biz
                        ORDER BY wh.effective_business_day DESC, wh.id DESC
                        LIMIT 1
                    ),
                    e.hourly_wage_yen,
                    0
                ) AS hourly_wage_yen,

                (
                  SELECT lbt.token
                  FROM line_bind_tokens lbt
                  WHERE lbt.tenant_id = e.tenant_id
                    AND lbt.store_id  = e.store_id
                    AND lbt.employee_id = e.id
                    AND lbt.used_at IS NULL
                    AND lbt.expires_at > NOW()
                  ORDER BY lbt.id DESC
                  LIMIT 1
                ) AS latest_bind_token,

                (
                  SELECT lbt.expires_at
                  FROM line_bind_tokens lbt
                  WHERE lbt.tenant_id = e.tenant_id
                    AND lbt.store_id  = e.store_id
                    AND lbt.employee_id = e.id
                    AND lbt.used_at IS NULL
                    AND lbt.expires_at > NOW()
                  ORDER BY lbt.id DESC
                  LIMIT 1
                ) AS latest_bind_expires_at

            FROM employees e
            WHERE e.tenant_id = :tenant_id
              AND e.store_id  = :store_id
            ORDER BY e.sort_order ASC, e.id ASC
        ");
        $stmt->execute([':tenant_id' => $tenantId, ':store_id' => $storeId, ':biz' => $businessDayYmd]);
        return $stmt->fetchAll();
    }

    // ✅ 従来のまま（壊さない）
    $stmt = $pdo->prepare("
        SELECT
            e.id, e.display_name, e.hourly_wage_yen, e.employment_status, e.sort_order,
            e.auth_pin_set_at, e.auth_pin_code,
            e.line_user_id,

            (
              SELECT lbt.token
              FROM line_bind_tokens lbt
              WHERE lbt.tenant_id = e.tenant_id
                AND lbt.store_id  = e.store_id
                AND lbt.employee_id = e.id
                AND lbt.used_at IS NULL
                AND lbt.expires_at > NOW()
              ORDER BY lbt.id DESC
              LIMIT 1
            ) AS latest_bind_token,

            (
              SELECT lbt.expires_at
              FROM line_bind_tokens lbt
              WHERE lbt.tenant_id = e.tenant_id
                AND lbt.store_id  = e.store_id
                AND lbt.employee_id = e.id
                AND lbt.used_at IS NULL
                AND lbt.expires_at > NOW()
              ORDER BY lbt.id DESC
              LIMIT 1
            ) AS latest_bind_expires_at

        FROM employees e
        WHERE e.tenant_id = :tenant_id
          AND e.store_id  = :store_id
        ORDER BY e.sort_order ASC, e.id ASC
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':store_id' => $storeId]);
    return $stmt->fetchAll();
}

function nextSortOrder(PDO $pdo, int $tenantId, int $storeId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) AS mx FROM employees WHERE tenant_id=:t AND store_id=:s");
    $stmt->execute([':t' => $tenantId, ':s' => $storeId]);
    return (int)($stmt->fetch()['mx'] ?? 0) + 1;
}

/**
 * ✅ FK 1451 対策：物理DELETEはしない
 * - pay_slips が employees を参照している従業員は削除できずエラーになる
 * - 退職 = employment_status='inactive' にすることで、給与明細を保持したまま管理できる
 * - action名 delete_employee は既存フロー維持のためそのまま使う
 */
function deleteEmployee(PDO $pdo, int $tenantId, int $storeId, int $employeeId): void
{
    $stmt = $pdo->prepare("
        UPDATE employees
           SET employment_status='inactive',
               updated_at = NOW()
         WHERE tenant_id=:t AND store_id=:s AND id=:id
         LIMIT 1
    ");
    $stmt->execute([':t' => $tenantId, ':s' => $storeId, ':id' => $employeeId]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('退職に変更できませんでした（対象なし）');
    }
}

function restoreEmployee(PDO $pdo, int $tenantId, int $storeId, int $employeeId): void
{
    $stmt = $pdo->prepare("UPDATE employees SET employment_status='active', updated_at=NOW() WHERE tenant_id=:t AND store_id=:s AND id=:id LIMIT 1");
    $stmt->execute([':t' => $tenantId, ':s' => $storeId, ':id' => $employeeId]);
    if ($stmt->rowCount() === 0) throw new RuntimeException('復活できませんでした（対象なし）');
}

function reorderEmployees(PDO $pdo, int $tenantId, int $storeId, array $orderedIds, bool $hasWageHistoryTable, string $businessDayYmd): void
{
    // ✅ 並び替えの対象確認は従来通り employees.id を基準（時給の見え方とは独立）
    $current = fetchEmployees($pdo, $tenantId, $storeId, $hasWageHistoryTable, $businessDayYmd);
    $validIds = array_map(fn($r) => (int)$r['id'], $current);
    $validSet = array_flip($validIds);

    foreach ($orderedIds as $id) {
        if (!isset($validSet[(int)$id])) throw new RuntimeException('不正な従業員IDが含まれています');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE employees
               SET sort_order = :sort_order,
                   updated_at = NOW()
             WHERE tenant_id = :tenant_id
               AND store_id  = :store_id
               AND id        = :id
             LIMIT 1
        ");
        $i = 1;
        foreach ($orderedIds as $id) {
            $stmt->execute([
                ':sort_order' => $i,
                ':tenant_id'  => $tenantId,
                ':store_id'   => $storeId,
                ':id'         => (int)$id,
            ]);
            $i++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ===== アクション処理 =====
$errors = [];
$success = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['store_added']) && $storeId > 0) {
        $success = "店舗を追加しました（ID: {$storeId}）";
    } elseif (isset($_GET['store_updated']) && $storeId > 0) {
        $success = "店舗名を更新しました（ID: {$storeId}）";
    } elseif (isset($_GET['store_deleted'])) {
        $success = '店舗を削除しました';
    }
}

// ✅ 紐付けコード発行ポップアップ用
$bindPopup = null; // ['employee_id'=>int, 'code'=>string, 'message'=>string]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // AJAX reorder
    if ($action === 'reorder_employees') {
        header('Content-Type: application/json; charset=utf-8');
        $token = (string)($_POST['csrf_token'] ?? '');
        if (!isValidCsrf($token)) {
            echo json_encode(['ok' => false, 'message' => 'CSRF不正'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $idsRaw = (string)($_POST['ordered_ids'] ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
        if (count($ids) === 0) {
            echo json_encode(['ok' => false, 'message' => '並び順データが空です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            reorderEmployees($pdo, $tenantId, $storeId, $ids, $hasWageHistoryTable, $storeBusinessDay);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    $token = (string)($_POST['csrf_token'] ?? '');
    if (!isValidCsrf($token)) {
        $errors[] = 'CSRFトークンが不正です（再読み込みしてください）';
    } else {
        try {
            if ($action === 'add_employee') {
                $displayName = trim((string)($_POST['display_name'] ?? ''));
                $wageRaw = trim((string)($_POST['hourly_wage_yen'] ?? ''));
                $hourlyWage = null;

                if ($wageRaw !== '') {
                    if (!ctype_digit($wageRaw)) $errors[] = '時給は0以上の整数（円）で入力してください';
                    else $hourlyWage = (int)$wageRaw;
                }

                $status = (string)($_POST['employment_status'] ?? 'active');
                if ($displayName === '') $errors[] = '名前を入力してください';
                if (!in_array($status, ['active', 'inactive'], true)) $errors[] = 'ステータスが不正です';

                if (!$errors) {
                    $sortOrder = nextSortOrder($pdo, $tenantId, $storeId);

                    $pinCode = generateUniquePinCode($pdo, $tenantId, $storeId);
                    [$pinSalt, $pinHash] = makePinHashPair($pinCode);

                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO employees (
                                tenant_id, store_id, display_name, hourly_wage_yen,
                                employment_status, sort_order,
                                auth_pin_code, auth_pin_hash, auth_pin_salt, auth_pin_set_at,
                                created_at, updated_at
                            )
                            VALUES (
                                :tenant_id, :store_id, :display_name, :hourly_wage_yen,
                                :employment_status, :sort_order,
                                :auth_pin_code, :auth_pin_hash, :auth_pin_salt, NOW(),
                                NOW(), NOW()
                            )
                        ");
                        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                        $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT);
                        $stmt->bindValue(':display_name', $displayName, PDO::PARAM_STR);
                        if ($hourlyWage === null) $stmt->bindValue(':hourly_wage_yen', null, PDO::PARAM_NULL);
                        else $stmt->bindValue(':hourly_wage_yen', $hourlyWage, PDO::PARAM_INT);
                        $stmt->bindValue(':employment_status', $status, PDO::PARAM_STR);
                        $stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);

                        $stmt->bindValue(':auth_pin_code', $pinCode, PDO::PARAM_STR);
                        $stmt->bindValue(':auth_pin_hash', $pinHash, PDO::PARAM_LOB);
                        $stmt->bindValue(':auth_pin_salt', $pinSalt, PDO::PARAM_LOB);

                        $stmt->execute();
                        $newId = (int)$pdo->lastInsertId();
                        $pdo->commit();

                        $success = "登録しました（employees.id = {$newId}） / PIN: {$pinCode}（※従業員に共有してください）";
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            } elseif ($action === 'add_store') {
                $newStoreName = trim((string)($_POST['store_name'] ?? ''));
                if ($newStoreName === '') {
                    $errors[] = '店舗名を入力してください';
                }
                if (!$errors) {
                    $now = date('Y-m-d H:i:s');
                    $storeColsLocal = tableColumns($pdo, 'stores');

                    $cols = ['tenant_id', 'name'];
                    $vals = [':tenant_id', ':name'];
                    $params = [
                        ':tenant_id' => $tenantId,
                        ':name' => $newStoreName,
                    ];

                    if (in_array('status', $storeColsLocal, true)) {
                        $cols[] = 'status';
                        $vals[] = ':status';
                        $params[':status'] = 'active';
                    }
                    if (in_array('created_at', $storeColsLocal, true)) {
                        $cols[] = 'created_at';
                        $vals[] = ':created_at';
                        $params[':created_at'] = $now;
                    }
                    if (in_array('updated_at', $storeColsLocal, true)) {
                        $cols[] = 'updated_at';
                        $vals[] = ':updated_at';
                        $params[':updated_at'] = $now;
                    }

                    $sql = "INSERT INTO stores (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $newStoreId = (int)$pdo->lastInsertId();

                    header('Location: /admin/employees_new.php?store_id=' . $newStoreId . '&store_added=1#store');
                    exit;
                }
            } elseif ($action === 'update_store_name') {
                $storeIdPost = (int)($_POST['store_id'] ?? 0);
                $newName = trim((string)($_POST['store_name'] ?? ''));
                if ($storeIdPost <= 0) {
                    $errors[] = 'store_id が不正です';
                } elseif ($newName === '') {
                    $errors[] = '店舗名を入力してください';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE stores
                           SET name = :name,
                               updated_at = NOW()
                         WHERE tenant_id = :tenant_id
                           AND id        = :store_id
                         LIMIT 1
                    ");
                    $stmt->execute([
                        ':name' => $newName,
                        ':tenant_id' => $tenantId,
                        ':store_id' => $storeIdPost,
                    ]);
                    if ($stmt->rowCount() === 0) {
                        throw new RuntimeException('店舗名を更新できませんでした（対象店舗なし）');
                    }

                    header('Location: /admin/employees_new.php?store_id=' . $storeIdPost . '&store_updated=1#store');
                    exit;
                }
            } elseif ($action === 'delete_store') {
                $storeIdPost = (int)($_POST['store_id'] ?? 0);
                if ($storeIdPost <= 0) {
                    $errors[] = 'store_id が不正です';
                } else {
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM stores WHERE tenant_id = :tenant_id");
                    $countStmt->execute([':tenant_id' => $tenantId]);
                    $storeCount = (int)$countStmt->fetchColumn();
                    if ($storeCount <= 1) {
                        $errors[] = '最後の店舗は削除できません';
                    } else {
                        $stmt = $pdo->prepare("
                            DELETE FROM stores
                             WHERE tenant_id = :tenant_id
                               AND id        = :store_id
                             LIMIT 1
                        ");
                        $stmt->execute([
                            ':tenant_id' => $tenantId,
                            ':store_id' => $storeIdPost,
                        ]);
                        if ($stmt->rowCount() === 0) {
                            throw new RuntimeException('店舗を削除できませんでした（対象店舗なし）');
                        }

                        $nextStmt = $pdo->prepare("
                            SELECT id
                            FROM stores
                            WHERE tenant_id = :tenant_id
                            ORDER BY id ASC
                            LIMIT 1
                        ");
                        $nextStmt->execute([':tenant_id' => $tenantId]);
                        $nextStoreId = (int)($nextStmt->fetchColumn() ?: 0);

                        $redirectId = ($nextStoreId > 0) ? $nextStoreId : $storeId;
                        header('Location: /admin/employees_new.php?store_id=' . $redirectId . '&store_deleted=1#store');
                        exit;
                    }
                }
            } elseif ($action === 'delete_employee') {
                $employeeIdPost = (int)($_POST['employee_id'] ?? 0);
                if ($employeeIdPost <= 0) $errors[] = 'employee_id が不正です';
                else {
                    // ✅ 物理削除ではなく退職(inactive)化
                    deleteEmployee($pdo, $tenantId, $storeId, $employeeIdPost);
                    $success = "退職に変更しました（ID: {$employeeIdPost}）";
                }
            } elseif ($action === 'restore_employee') {
                $employeeIdPost = (int)($_POST['employee_id'] ?? 0);
                if ($employeeIdPost <= 0) $errors[] = 'employee_id が不正です';
                else {
                    restoreEmployee($pdo, $tenantId, $storeId, $employeeIdPost);
                    $success = "復活しました（ID: {$employeeIdPost}）";
                }
            } elseif ($action === 'issue_line_token') {
                $employeeIdPost = (int)($_POST['employee_id'] ?? 0);
                if ($employeeIdPost <= 0) $errors[] = 'employee_id が不正です';
                else {
                    $employeeName = '';
                    $pinCode = '';
                    $employmentStatus = '';
                    try {
                        $stEmp = $pdo->prepare("
                            SELECT display_name, auth_pin_code, employment_status
                            FROM employees
                            WHERE tenant_id=? AND store_id=? AND id=?
                            LIMIT 1
                        ");
                        $stEmp->execute([$tenantId, $storeId, $employeeIdPost]);
                        $empRow = $stEmp->fetch();
                        if (!$empRow) {
                            $errors[] = '従業員が見つかりません';
                        } else {
                            $employeeName = (string)($empRow['display_name'] ?? '');
                            $pinCode = (string)($empRow['auth_pin_code'] ?? '');
                            $employmentStatus = (string)($empRow['employment_status'] ?? '');
                        }
                    } catch (Throwable $e) {
                        $errors[] = '従業員情報を取得できませんでした';
                    }

                    if (!$errors && $employmentStatus !== 'active') {
                        $errors[] = '非在籍の従業員にはマイページ情報を発行できません';
                    }

                    if (!$errors && $pinCode === '') {
                        $pinCode = generateUniquePinCode($pdo, $tenantId, $storeId);
                        [$pinSalt, $pinHash] = makePinHashPair($pinCode);

                        $stPin = $pdo->prepare("
                            UPDATE employees
                            SET auth_pin_code = :pin,
                                auth_pin_salt = :salt,
                                auth_pin_hash = :hash,
                                auth_pin_set_at = NOW(),
                                updated_at = NOW()
                            WHERE tenant_id = :tenant_id
                              AND store_id = :store_id
                              AND id = :employee_id
                            LIMIT 1
                        ");
                        $stPin->bindValue(':pin', $pinCode, PDO::PARAM_STR);
                        $stPin->bindValue(':salt', $pinSalt, PDO::PARAM_LOB);
                        $stPin->bindValue(':hash', $pinHash, PDO::PARAM_LOB);
                        $stPin->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                        $stPin->bindValue(':store_id', $storeId, PDO::PARAM_INT);
                        $stPin->bindValue(':employee_id', $employeeIdPost, PDO::PARAM_INT);
                        $stPin->execute();
                    }

                    if (!$errors) {
                        $nameLabel = $employeeName !== '' ? $employeeName . 'さん' : '従業員さん';
                        $success = "項目をコピーして{$nameLabel}へ共有してください。";

                        $bindPopup = [
                            'employee_id' => $employeeIdPost,
                            'code'        => $pinCode,
                            'message'     => "項目をコピーして{$nameLabel}へ共有してください。",
                            'mode'        => 'mypage',
                        ];
                    }
                }
            } elseif ($action === 'issue_line_token_line') {
                $employeeIdPost = (int)($_POST['employee_id'] ?? 0);
                if ($employeeIdPost <= 0) $errors[] = 'employee_id が不正です';
                else {
                    $tokenIssued = issueLineBindToken($pdo, $tenantId, $storeId, $employeeIdPost);
                    $employeeName = '';
                    try {
                        $stEmp = $pdo->prepare("
                            SELECT display_name
                            FROM employees
                            WHERE tenant_id=? AND store_id=? AND id=?
                            LIMIT 1
                        ");
                        $stEmp->execute([$tenantId, $storeId, $employeeIdPost]);
                        $employeeName = (string)($stEmp->fetch()['display_name'] ?? '');
                    } catch (Throwable $e) {
                        $employeeName = '';
                    }
                    $nameLabel = $employeeName !== '' ? $employeeName . 'さん' : '従業員さん';
                    $success = "項目をコピーして{$nameLabel}へLINEで共有してください。";

                    $bindPopup = [
                        'employee_id' => $employeeIdPost,
                        'code'        => $tokenIssued,
                        'message'     => "項目をコピーして{$nameLabel}へLINEで共有してください。",
                        'mode'        => 'line',
                    ];
                }
            } elseif ($action === 'unlink_line') {
                $employeeIdPost = (int)($_POST['employee_id'] ?? 0);
                if ($employeeIdPost <= 0) $errors[] = 'employee_id が不正です';
                else {
                    unlinkLineUser($pdo, $tenantId, $storeId, $employeeIdPost);
                    $success = "LINE紐付けを解除しました（ID: {$employeeIdPost}）";
                }
            } elseif ($action === 'update_store_payroll') {
                $cycleType = (string)($_POST['payroll_cycle_type'] ?? 'monthly');
                if (!in_array($cycleType, ['monthly', 'weekly'], true)) $cycleType = 'monthly';

                $tz = sanitizeTz((string)($_POST['payroll_tz'] ?? 'Asia/Tokyo'), 'Asia/Tokyo');
                $payMonthOffset = clampMonthOffset((int)($_POST['payroll_pay_month_offset'] ?? 1), 1);
                $roundUnit = clampRoundUnit((int)($_POST['payroll_round_unit_minutes'] ?? 15), 15);
                $cutoffTime = normalizeCutoffTime((string)($_POST['business_day_cutoff_time'] ?? ''), $storeCutoff);

                $closeDay = clampDay((int)($_POST['payroll_close_day'] ?? 31), 31);
                $payDay   = clampDay((int)($_POST['payroll_pay_day'] ?? 25), 25);

                $weekCloseWday = clampWday((int)($_POST['payroll_week_close_wday'] ?? 0), 0);
                $weekPayOffset = clampOffsetDays((int)($_POST['payroll_week_pay_offset_days'] ?? 0), 0);

                $payslipIssuerName = trim((string)($_POST['payslip_issuer_name'] ?? ''));
                if (mb_strlen($payslipIssuerName) > 255) $payslipIssuerName = mb_substr($payslipIssuerName, 0, 255);
                $leaveRequestNotificationEmail = trim((string)($_POST['leave_request_notification_email'] ?? ''));
                if ($leaveRequestNotificationEmail === '') {
                    throw new RuntimeException('休み申請通知のメインアドレスは必須です');
                }
                if (!filter_var($leaveRequestNotificationEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('休み申請通知のメインアドレスを正しく入力してください');
                }

                $setSql = "
                    payroll_tz = :payroll_tz,
                    payroll_pay_month_offset = :pay_month_offset,
                    payslip_issuer_name = :payslip_issuer_name,
                    leave_request_notification_email = :leave_request_notification_email,
                    payroll_cycle_type = :cycle_type,
                    payroll_close_day = :close_day,
                    payroll_pay_day   = :pay_day,
                    payroll_week_close_wday = :week_close_wday,
                    payroll_week_pay_offset_days = :week_pay_offset,
                    payroll_round_unit_minutes = :round_unit
                ";
                if (in_array('business_day_cutoff_time', $storeCols, true)) {
                    $setSql .= ",
                    business_day_cutoff_time = :cutoff_time";
                }

                $stmt = $pdo->prepare("
                    UPDATE stores
                       SET {$setSql},
                           updated_at = NOW()
                     WHERE tenant_id = :tenant_id
                       AND id        = :store_id
                     LIMIT 1
                ");
                $params = [
                    ':payroll_tz'          => $tz,
                    ':pay_month_offset'    => $payMonthOffset,
                    ':payslip_issuer_name' => ($payslipIssuerName !== '' ? $payslipIssuerName : null),
                    ':leave_request_notification_email' => ($leaveRequestNotificationEmail !== '' ? $leaveRequestNotificationEmail : null),
                    ':cycle_type'          => $cycleType,
                    ':close_day'           => $closeDay,
                    ':pay_day'             => $payDay,
                    ':week_close_wday'     => $weekCloseWday,
                    ':week_pay_offset'     => $weekPayOffset,
                    ':round_unit'          => $roundUnit,
                    ':tenant_id'           => $tenantId,
                    ':store_id'            => $storeId,
                ];
                if (in_array('business_day_cutoff_time', $storeCols, true)) {
                    $params[':cutoff_time'] = $cutoffTime;
                }
                $stmt->execute($params);
                if ($stmt->rowCount() === 0) throw new RuntimeException('給与設定を更新できませんでした（対象店舗なし）');

                $storeTz = $tz;
                $storePayMonthOffset = $payMonthOffset;
                $storePayslipIssuerName = $payslipIssuerName;
                $storeLeaveRequestNotificationEmail = $leaveRequestNotificationEmail;
                $storeCycleType = $cycleType;
                $storeCloseDay = $closeDay;
                $storePayDay = $payDay;
                $storeWeekCloseWday = $weekCloseWday;
                $storeWeekPayOffset = $weekPayOffset;
                $storeRoundUnit = $roundUnit;
                $storeCutoff = $cutoffTime;
                $storeCutoffInput = substr($storeCutoff, 0, 5);
                $storeBusinessDay = businessDateFromTs(time(), cutoffToSeconds($storeCutoff));

                $offsetJa = ['当月', '翌月', '翌々月'];
                $roundJa = ($roundUnit === 0) ? 'OFF' : ($roundUnit . '分');

                if ($cycleType === 'monthly') {
                    $success = "給与設定を保存しました（月払い：締日 {$closeDay}日 / 支払日 {$payDay}日 / 支払月 {$offsetJa[$payMonthOffset]} / 打刻調整 {$roundJa} / TZ {$tz}）";
                } else {
                    $wdayJaLocal = ['日', '月', '火', '水', '木', '金', '土'];
                    $success = "給与設定を保存しました（週払い：締め {$wdayJaLocal[$weekCloseWday]}曜 / 支払 +{$weekPayOffset}日 / 支払月 {$offsetJa[$payMonthOffset]} / 打刻調整 {$roundJa} / TZ {$tz}）";
                }
            } elseif ($action === 'update_slot_setting') {
                $enableSlot = ((int)($_POST['enable_slot'] ?? 1) === 1) ? 1 : 0;

                $stmt = $pdo->prepare("
                    UPDATE stores
                       SET enable_slot = :enable_slot,
                           updated_at = NOW()
                     WHERE tenant_id = :tenant_id
                       AND id        = :store_id
                     LIMIT 1
                ");
                $stmt->execute([
                    ':enable_slot' => $enableSlot,
                    ':tenant_id'   => $tenantId,
                    ':store_id'    => $storeId,
                ]);
                if ($stmt->rowCount() === 0) throw new RuntimeException('スロット設定を更新できませんでした（対象店舗なし）');

                $storeEnableSlot = $enableSlot;
                $success = $enableSlot ? 'スロット機能を有効にしました' : 'スロット機能を無効にしました';
            } elseif ($action === 'update_sales_prompt_setting') {
                $requireSales = ((int)($_POST['require_sales_on_clockout'] ?? 1) === 1) ? 1 : 0;

                $stmt = $pdo->prepare("
                    UPDATE stores
                       SET require_sales_on_clockout = :require_sales_on_clockout,
                           updated_at = NOW()
                     WHERE tenant_id = :tenant_id
                       AND id        = :store_id
                     LIMIT 1
                ");
                $stmt->execute([
                    ':require_sales_on_clockout' => $requireSales,
                    ':tenant_id' => $tenantId,
                    ':store_id'  => $storeId,
                ]);
                if ($stmt->rowCount() === 0) throw new RuntimeException('退勤時売上入力設定を更新できませんでした（対象店舗なし）');

                $storeRequireSalesOnClockOut = $requireSales;
                $success = $requireSales ? '退勤時に売上入力を表示します' : '退勤時の売上入力を表示しません';

            } elseif ($action === 'update_store_labor_thresholds') {
                $greenMax  = clampRate((float)($_POST['labor_green_max_rate'] ?? 30.0), 30.0);
                $yellowMax = clampRate((float)($_POST['labor_yellow_max_rate'] ?? 35.0), 35.0);
                if ($yellowMax < $greenMax) $yellowMax = $greenMax;

                $stmt = $pdo->prepare("
                    UPDATE stores
                       SET labor_green_max_rate  = :g,
                           labor_yellow_max_rate = :y,
                           updated_at = NOW()
                     WHERE tenant_id = :tenant_id
                       AND id        = :store_id
                     LIMIT 1
                ");
                $stmt->execute([
                    ':g' => $greenMax,
                    ':y' => $yellowMax,
                    ':tenant_id' => $tenantId,
                    ':store_id'  => $storeId,
                ]);
                if ($stmt->rowCount() === 0) throw new RuntimeException('人件費率設定を更新できませんでした（対象店舗なし）');

                $storeLaborGreenMax  = $greenMax;
                $storeLaborYellowMax = $yellowMax;

                $success = "人件費率の色判定を保存しました（GREEN ≤ {$greenMax}% / YELLOW ≤ {$yellowMax}% / RED > {$yellowMax}%）";
            } elseif ($action === 'update_help_popup_pref') {
                $adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);
                if ($adminUserId <= 0) {
                    $errors[] = 'ユーザーIDが取得できませんでした';
                } else {
                    $enabled = ((int)($_POST['help_popup_enabled'] ?? 0) === 1) ? '1' : '0';
                    setAdminUserPref($pdo, $adminUserId, 'help_popup_back_events', $enabled);
                    $success = '機能解説ポップアップの設定を更新しました';
                }
            } else {
                $errors[] = 'action が不正です';
            }
        } catch (Throwable $e) {
            $errors[] = 'エラー: ' . $e->getMessage();
        }
    }
}

// ===== 最新一覧 =====
$employees = fetchEmployees($pdo, $tenantId, $storeId, $hasWageHistoryTable, $storeBusinessDay);
$wdayJa = ['日', '月', '火', '水', '木', '金', '土'];

// ===== ユーザー設定値 =====
$adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);
$helpPopupEnabled = ($adminUserId > 0)
    ? getAdminUserPref($pdo, $adminUserId, 'help_popup_back_events', '0')
    : '0';

$tzOptions = [
    'Asia/Tokyo',
    'Asia/Seoul',
    'Asia/Shanghai',
    'Asia/Singapore',
    'Asia/Bangkok',
    'Australia/Sydney',
    'Europe/London',
    'Europe/Paris',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Toronto',
    'Pacific/Auckland',
];

// ✅ 表示は「テナント名だけ」にする（要求どおり）
// ただし tenantName が取れない場合は、画面が無表示になるよりマシなので tenant_id を出す（機能影響なし）
$tenantLabel = ($tenantName !== '')
    ? ('テナント：' . $tenantName)
    : ('テナント：tenant_id=' . (string)$tenantId);
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>従業員管理</title>
    <style>
    html,
    body {
        height: 100%;
    }

    body {
        margin: 0;
        font-family: system-ui, -apple-system, sans-serif;
        background: #fff;
        color: #1f2937;
    }

    .page {
        padding: 14px;
        padding-bottom: 64px;
    }

    .page * {
        box-sizing: border-box;
    }

    .wrap {
        max-width: none;
        margin: 0;
    }

    .card {
        border: 1px solid #e5e7eb;
        border-radius: 0;
        padding: 14px;
        background: #fff;
        box-shadow: none;
    }

    h3 {
        margin: 0;
        font-size: 16px;
        color: #0f172a;
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    .label {
        color: #475569;
        font-size: 12px;
        font-weight: 700;
    }

    .pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 0px;
        background: #fff;
        border: 1px solid #e5e7eb;
        font-size: 12px;
        color: #0f172a;
        font-weight: 900;
        white-space: nowrap;
    }

    /* ✅ 添付の雰囲気に寄せた「タブ」 */
    .tabsBar {
        display: flex;
        align-items: flex-end;
        gap: 10px;
        padding: 10px 10px 0;
        background: #e9eef1;
        border: 1px solid #e5e7eb;
        border-bottom: none;
    }

    .tabBtn {
        appearance: none;
        border: 1px solid #d0d7de;
        border-bottom: none;
        border-radius: 0;
        padding: 14px 28px;
        font-size: 14px;
        font-weight: 900;
        line-height: 1;
        background: #6f899b;
        color: #fff;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-width: 150px;
    }

    .tabBtn.isActive {
        background: #fff;
        color: #0f172a;
        border-color: #d0d7de;
    }

    .tabBtn:focus {
        outline: 2px solid rgba(111, 137, 155, .35);
        outline-offset: 2px;
    }

    .tabWrap {
        border: 1px solid #e5e7eb;
        background: #fff;
        padding: 14px;
    }

    .tabPanel {
        display: none;
    }

    .tabPanel.isActive {
        display: block;
    }

    /* ✅ ボタンを添付の雰囲気に寄せる（角なし / 薄い枠 / 高さ） */
    .btn {
        padding: 0 10px;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        background: #fff;
        font-size: 12px;
        line-height: 1;
        color: #0f172a;
        text-decoration: none;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        white-space: nowrap;
        height: 30px;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
    }

    .btn:hover {
        border-color: #6f899b;
    }

    .btn.primary {
        background: #0f172a;
        color: #fff;
        border-color: rgba(0, 0, 0, .14);
        cursor: pointer;
    }

    .btn.danger {
        background: #fff;
        border-color: #f0b4b4;
        color: #9b1c1c;
        cursor: pointer;
    }

    .btn.icon {
        width: 30px;
        height: 30px;
        padding: 0;
        font-size: 16px;
        line-height: 1;
        font-weight: 900;
        border: none;
        box-shadow: none;
    }

    .notice {
        width: 100%;
        margin-top: 10px;
        padding: 10px;
        border-radius: 0px;
        font-weight: 900;
        border: 1px solid #e5e7eb;
        background: #fff;
        color: #0f172a;
    }

    .notice.err {
        border: 1px solid #f0b4b4;
        background: #fff5f5;
        color: #9b1c1c;
    }

    .muted {
        color: #64748b;
        font-size: 12px;
    }

    .hint {
        color: #64748b;
        font-size: 12px;
        margin-top: 6px;
    }

    .fieldGrid {
        display: grid;
        gap: 10px;
        grid-template-columns: 1fr;
        margin-top: 10px;
    }

    .input {
        width: 100%;
        padding: 0 10px;
        border-radius: 12px;
        border: 1px solid #ddd;
        background: #fff;
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
        height: 30px;
        line-height: 1;
    }

    textarea.input {
        height: auto;
        min-height: 70px;
        padding: 8px 10px;
        line-height: 1.4;
    }

    .inputShort {
        max-width: 320px;
    }

    .box {
        margin-top: 10px;
        padding: 12px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
    }

    /* 従業員リスト */
    .listWrap {
        margin-top: 10px;
        border: none;
        border-radius: 0;
        background: transparent;
        padding: 0;
    }

    ul.list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 8px;
    }

    li.item {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 10px;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        background: #fff;
    }

    li.item[draggable="true"] {
        cursor: grab;
    }

    li.item.dragging {
        opacity: .65;
    }

    li.item.inactive {
        opacity: .55;
        background: #fafafa;
    }

    .left {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        min-width: 0;
        flex: 1;
    }

    .handle {
        width: 40px;
        height: 40px;
        border-radius: 0px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #0f172a;
        background: #fff;
        user-select: none;
        font-weight: 900;
        flex: 0 0 auto;
    }

    .nameLine {
        font-weight: 900;
        color: #0f172a;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 520px;
    }

    .tagLine {
        margin-top: 4px;
        color: #64748b;
        font-size: 12px;
        line-height: 1.35;
    }

    /* ✅ 右側：常に右寄せで“詰めて並べる” */
    .right {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        justify-content: flex-end;
    }

    .actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 10px;
        max-width: 720px;
    }

    .actions form {
        margin: 0;
    }

    /* ✅ PIN pill も添付寄せ */
    .pinPill {
        padding: 0 12px;
        gap: 10px;
        height: 40px;
    }

    .pinMasked {
        font-weight: 900;
        letter-spacing: .5px;
        min-width: 44px;
        display: inline-block;
        text-align: center;
    }

    .payrollRow > div {
        flex: 0 1 260px;
        min-width: 0;
    }

    .payrollRow {
        justify-content: flex-start;
    }

    @media (max-width: 900px) {
        li.item {
            flex-wrap: wrap;
        }

        .right {
            width: 100%;
        }

        .actions {
            width: 100%;
            justify-content: flex-start;
        }
    }

    /* 固定フッター */
    .footerCopy {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        text-align: center;
        padding: 8px 0;
        font-size: 12px;
        color: #64748b;
        background: rgba(255, 255, 255, .85);
        border-top: 1px solid #e5e7eb;
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
    }

    @media print {
        .footerCopy {
            display: none;
        }
    }

    /* LINE未連携を赤表示 */
    .lineStatus.unlinked {
        color: #dc2626;
        font-weight: 900;
    }

    .btn.isFilterActive {
        background: #0f172a;
        color: #fff;
        border-color: #0f172a;
    }
    </style>
</head>

<body data-mode="settings">
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="page">
        <div class="wrap">

            <?php if ($success): ?>
            <div class="notice"><?= h($success) ?></div>
            <?php endif; ?>

            <?php if ($errors): ?>
            <div class="notice err">
                <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ✅ タブ -->
            <div class="tabsBar" role="tablist" aria-label="従業員管理タブ">
                <button type="button" class="tabBtn" data-tab="list" role="tab"
                    aria-selected="false">従業員設定</button>
                <button type="button" class="tabBtn" data-tab="add" role="tab" aria-selected="false">従業員追加</button>
                <button type="button" class="tabBtn" data-tab="payroll" role="tab" aria-selected="false">店舗設定</button>
                <button type="button" class="tabBtn" data-tab="store" role="tab" aria-selected="false">店舗追加</button>
                <button type="button" class="tabBtn" data-tab="labor" role="tab"
                    aria-selected="false">人件費率設定</button>
                <a class="tabBtn" href="/admin/expenses.php?store_id=<?= (int)$storeId ?>" role="tab"
                    aria-selected="false">
                    経費
                </a>
                <a class="tabBtn" href="/admin/devices_manage.php?store_id=<?= (int)$storeId ?>" role="tab"
                    aria-selected="false">
                    端末管理
                </a>
            </div>

            <div class="tabWrap">
                <!-- =========================
                     ✅ TAB: 従業員一覧
                     ========================= -->
                <div class="tabPanel" id="tab_list" data-panel="list">
                    <div class="card" style="border:none; padding:0;">
                        <div class="row" style="justify-content:space-between;">
                            <div class="row" style="gap:8px;">
                                <button type="button" class="btn isFilterActive" data-filter="active">在籍</button>
                                <button type="button" class="btn" data-filter="inactive">退職</button>
                                <button type="button" class="btn" data-filter="all">全員</button>
                            </div>
                        </div>

                        <div class="listWrap">
                            <ul id="empList" class="list">
                                <?php if (empty($employees)): ?>
                                <li class="muted">従業員がいません</li>
                                <?php else: ?>
                                <?php foreach ($employees as $e): ?>
                                <?php
                                        $id = (int)$e['id'];
                                        $name = (string)$e['display_name'];
                                        $st = (string)$e['employment_status'];
                                        $isInactive = ($st === 'inactive');

                                        $pinSetAt = (string)($e['auth_pin_set_at'] ?? '');
                                        $lineUserId = (string)($e['line_user_id'] ?? '');
                                        $isLinked = ($lineUserId !== '');

                                        $latestToken = (string)($e['latest_bind_token'] ?? '');
                                        $latestExp   = (string)($e['latest_bind_expires_at'] ?? '');

                                        // ✅ fetchEmployees が「履歴優先の時給」を hourly_wage_yen として返す
                                        $wage = isset($e['hourly_wage_yen']) ? (int)$e['hourly_wage_yen'] : 0;

                                        $pinCode = (string)($e['auth_pin_code'] ?? '');
                                        ?>
                                <li class="item <?= $isInactive ? 'inactive' : '' ?>" draggable="true"
                                    data-id="<?= $id ?>" data-status="<?= h($st) ?>">
                                    <div class="left">
                                        <div class="handle">≡</div>
                                        <div style="min-width:0;">
                                            <div class="nameLine">
                                                <?= h($name) ?>
                                            </div>

                                            <?php
                                                    $stJa = ($st === 'active') ? '在籍' : (($st === 'inactive') ? '非在籍' : $st);
                                                    ?>
                                            <div class="tagLine">
                                                状態: <strong><?= h($stJa) ?></strong>
                                                / 時給: <?= $wage ?>円
                                            </div>

                                            <div class="tagLine">
                                                追加日: <?= $pinSetAt !== '' ? h($pinSetAt) : '未設定' ?>
                                            </div>

                                            <div class="tagLine">
                                                <span class="lineStatus <?= $isLinked ? '' : 'unlinked' ?>">
                                                    LINE: <?= $isLinked ? '紐付け済み' : '未紐付け' ?>
                                                </span>
                                                <?= $isLinked ? ' / line_user_id: ' . h($lineUserId) : '' ?>
                                            </div>

                                            <div class="tagLine">
                                                LINE紐付けPIN:
                                                <?= $latestToken !== '' ? h($latestToken) . '（期限 ' . h($latestExp) . '）' : 'なし（未発行 or 期限切れ）' ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="right">
                                        <div class="actions">

                                            <form method="post" onsubmit="return confirm('紐付けコードを発行しますか？（24時間有効）');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="action" value="issue_line_token_line">
                                                <input type="hidden" name="employee_id" value="<?= $id ?>">
                                                <button class="btn" type="submit" title="LINE紐付けコード発行"
                                                    aria-label="LINE紐付けコード発行"
                                                    style="background:#4BC251; border-color:#4BC251; color:#fff;">LINE紐付けコード発行</button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('マイページ情報を発行しますか？');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="action" value="issue_line_token">
                                                <input type="hidden" name="employee_id" value="<?= $id ?>">
                                                <button class="btn" type="submit" title="マイページ情報発行"
                                                    aria-label="マイページ情報発行"
                                                    style="background:#92A8D0; border-color:#92A8D0; color:#fff;">マイページ情報発行</button>
                                            </form>

                                            <form method="post" onsubmit="return confirm('LINE紐付けを解除しますか？（退職者など）');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="action" value="unlink_line">
                                                <input type="hidden" name="employee_id" value="<?= $id ?>">
                                                <button class="btn" type="submit" title="紐付け解除" aria-label="紐付け解除">🔓
                                                    解除</button>
                                            </form>

                                            <a class="btn" title="編集" aria-label="編集"
                                                href="/admin/employee_edit.php?store_id=<?= (int)$storeId ?>&employee_id=<?= (int)$id ?>">✏️
                                                編集</a>

                                            <?php if ($isInactive): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="action" value="restore_employee">
                                                <input type="hidden" name="employee_id" value="<?= $id ?>">
                                                <button class="btn" type="submit"
                                                    style="border-color:#0f172a;">復活</button>
                                            </form>
                                            <?php else: ?>
                                            <form method="post"
                                                onsubmit="return confirm('退職に変更しますか？（給与明細がある従業員は削除できないため退職化します）');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="action" value="delete_employee">
                                                <input type="hidden" name="employee_id" value="<?= $id ?>">
                                                <button class="btn danger icon" type="submit" title="退職"
                                                    aria-label="退職">🗑️</button>
                                            </form>
                                            <?php endif; ?>

                                        </div>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- =========================
                     ✅ TAB: 従業員追加
                     ========================= -->
                <div class="tabPanel" id="tab_add" data-panel="add">
                    <div class="card" style="border:none; padding:0;">
                        <form method="post" style="margin-top:10px;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="add_employee">

                            <div class="fieldGrid">
                                <div>
                                    <div class="label">名前（表示名）</div>
                                    <input class="input inputShort" name="display_name" placeholder="例：松原" required>
                                </div>

                                <div>
                                    <div class="label">時給（円）</div>
                                    <input class="input inputShort" type="number" name="hourly_wage_yen"
                                        placeholder="例：1200" min="0" step="1">
                                </div>

                                <div>
                                    <div class="label">ステータス</div>
                                    <select name="employment_status" class="input inputShort">
                                        <option value="active" selected>在籍</option>
                                        <option value="inactive">非在籍</option>
                                    </select>
                                </div>
                            </div>

                            <button class="btn primary" type="submit" style="margin-top:12px;">登録</button>
                        </form>
                    </div>
                </div>

                <!-- =========================
                     ✅ TAB: 給与設定
                     ========================= -->
                <div class="tabPanel" id="tab_payroll" data-panel="payroll">
                    <div class="card" style="border:none; padding:0;">
                        <form method="post" style="margin-top:10px;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="update_store_payroll">

                            <div class="box">
                                <div class="label">タイムゾーン（店舗）</div>
                                <select class="input inputShort" name="payroll_tz">
                                    <?php foreach ($tzOptions as $tz): ?>
                                    <option value="<?= h($tz) ?>" <?= $storeTz === $tz ? 'selected' : '' ?>>
                                        <?= h($tz) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <div class="label" style="margin-top:12px;">明細の会社名（発行者名）</div>
                                <input class="input inputShort" name="payslip_issuer_name" maxlength="255"
                                    value="<?= h($storePayslipIssuerName) ?>" placeholder="例：株式会社〇〇 / 〇〇店">

                                <div class="label" style="margin-top:12px;">休み申請通知のメインアドレス</div>
                                <input class="input inputShort" type="email" name="leave_request_notification_email"
                                    maxlength="255" value="<?= h($storeLeaveRequestNotificationEmail) ?>"
                                    placeholder="例：admin@example.com" required>
                                <div class="muted" style="margin-top:6px;">メンバーページから休み申請が送信された時、このメールアドレスへ通知します。</div>

                                <div class="label" style="margin-top:12px;">支払月（オフセット）</div>
                                <select class="input inputShort" name="payroll_pay_month_offset">
                                    <?php foreach ([0 => '当月', 1 => '翌月', 2 => '翌々月'] as $k => $label): ?>
                                    <option value="<?= (int)$k ?>"
                                        <?= $storePayMonthOffset === (int)$k ? 'selected' : '' ?>><?= h($label) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>

                                <div class="label" style="margin-top:12px;">打刻時刻の調整（分）</div>
                                <select class="input inputShort" name="payroll_round_unit_minutes">
                                    <option value="0" <?= $storeRoundUnit === 0 ? 'selected' : '' ?>>OFF（調整なし）</option>
                                    <?php for ($m = 5; $m <= 30; $m++): ?>
                                    <option value="<?= $m ?>" <?= ($storeRoundUnit === $m) ? 'selected' : '' ?>>
                                        <?= $m ?>分</option>
                                    <?php endfor; ?>
                                </select>

                                <div class="label" style="margin-top:12px;">営業日切替時刻</div>
                                <input class="input inputShort" type="time" name="business_day_cutoff_time"
                                    value="<?= h($storeCutoffInput) ?>" step="60">

                                <div class="label" style="margin-top:12px;">支払いサイクル</div>
                                <select class="input inputShort" name="payroll_cycle_type" id="payroll_cycle_type">
                                    <option value="monthly" <?= $storeCycleType === 'monthly' ? 'selected' : '' ?>>月払い
                                    </option>
                                    <option value="weekly" <?= $storeCycleType === 'weekly'  ? 'selected' : '' ?>>週払い
                                    </option>
                                </select>

                                <div id="monthlyBox"
                                    style="margin-top:10px; <?= $storeCycleType === 'monthly' ? '' : 'display:none;' ?>">
                                    <div class="row payrollRow" style="gap:10px; justify-content:flex-start;">
                                        <div style="flex:0 0 260px; min-width:210px; margin-left:0;">
                                            <div class="label">締日</div>
                                            <select class="input inputShort" name="payroll_close_day">
                                                <?php for ($d = 1; $d <= 31; $d++): ?>
                                                <option value="<?= $d ?>"
                                                    <?= ($storeCloseDay === $d) ? 'selected' : '' ?>>
                                                    <?= $d ?>日<?= ($d === 31) ? '（月末扱い）' : '' ?>
                                                </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div style="flex:0 0 260px; min-width:210px;">
                                            <div class="label">支払日</div>
                                            <select class="input inputShort" name="payroll_pay_day">
                                                <?php for ($d = 1; $d <= 31; $d++): ?>
                                                <option value="<?= $d ?>"
                                                    <?= ($storePayDay === $d) ? 'selected' : '' ?>>
                                                    <?= $d ?>日</option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div id="weeklyBox"
                                    style="margin-top:10px; <?= $storeCycleType === 'weekly' ? '' : 'display:none;' ?>">
                                    <div class="row payrollRow" style="gap:10px; justify-content:flex-start;">
                                        <div style="flex:1; min-width:210px;">
                                            <div class="label">締め曜日（週払い）</div>
                                            <select class="input inputShort" name="payroll_week_close_wday">
                                                <?php for ($w = 0; $w <= 6; $w++): ?>
                                                <option value="<?= $w ?>"
                                                    <?= ($storeWeekCloseWday === $w) ? 'selected' : '' ?>>
                                                    <?= $wdayJa[$w] ?>曜</option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div style="flex:1; min-width:210px;">
                                            <div class="label">支払（締め日 + N日）</div>
                                            <select class="input inputShort" name="payroll_week_pay_offset_days">
                                                <?php for ($n = 0; $n <= 30; $n++): ?>
                                                <option value="<?= $n ?>"
                                                    <?= ($storeWeekPayOffset === $n) ? 'selected' : '' ?>>
                                                    +<?= $n ?>日</option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <button class="btn primary" type="submit" style="margin-top:12px;">保存</button>
                            </div>
                        </form>

                        <form method="post" style="margin-top:14px;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="update_slot_setting">
                            <div class="box">
                                <div class="label">出勤後のスロット演出(iPadのみ)</div>
                                <select class="input inputShort" name="enable_slot">
                                    <option value="1" <?= ((int)$storeEnableSlot === 1) ? 'selected' : '' ?>>ON</option>
                                    <option value="0" <?= ((int)$storeEnableSlot === 0) ? 'selected' : '' ?>>OFF</option>
                                </select>
                                <button class="btn primary" type="submit" style="margin-top:12px;">保存</button>
                            </div>
                        </form>

                        <form method="post" style="margin-top:14px;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="update_sales_prompt_setting">
                            <div class="box">
                                <div class="label">退勤時の売上入力アラート</div>
                                <select class="input inputShort" name="require_sales_on_clockout">
                                    <option value="1" <?= ((int)$storeRequireSalesOnClockOut === 1) ? 'selected' : '' ?>>ON</option>
                                    <option value="0" <?= ((int)$storeRequireSalesOnClockOut === 0) ? 'selected' : '' ?>>OFF</option>
                                </select>
                                <button class="btn primary" type="submit" style="margin-top:12px;">保存</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- =========================
                     ✅ TAB: 店舗追加
                     ========================= -->
                <div class="tabPanel" id="tab_store" data-panel="store">
                    <div class="card" style="border:none; padding:0;">
                        <form method="post" style="margin-top:10px;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="add_store">

                            <div class="row" style="gap:10px; align-items:flex-end;">
                                <div>
                                    <div class="label">店舗名</div>
                                    <input class="input inputShort" name="store_name" placeholder="例：新宿店" required>
                                </div>
                                <button class="btn primary" type="submit">店舗を追加</button>
                            </div>
                        </form>

                        <div class="box" style="margin-top:14px;">
                            <div class="label">登録済み店舗</div>
                            <div class="listWrap" style="margin-top:10px;">
                                <ul class="list">
                                    <?php foreach ($stores as $st): ?>
                                    <li class="item">
                                        <div class="left" style="align-items:center;">
                                            <div style="min-width:0;">
                                                <div class="nameLine">
                                                    <?= h((string)$st['name']) ?>
                                                </div>
                                                <div class="tagLine">店舗ID: <?= (int)$st['id'] ?></div>
                                            </div>
                                        </div>
                                        <div class="right">
                                            <div class="actions">
                                                <form method="post" style="display:flex; gap:8px; align-items:center;">
                                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                    <input type="hidden" name="action" value="update_store_name">
                                                    <input type="hidden" name="store_id" value="<?= (int)$st['id'] ?>">
                                                    <input class="input inputShort" name="store_name"
                                                        value="<?= h((string)$st['name']) ?>" required>
                                                    <button class="btn" type="submit">名前更新</button>
                                                </form>
                                                <form method="post" onsubmit="return confirm('この店舗を削除しますか？');">
                                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                    <input type="hidden" name="action" value="delete_store">
                                                    <input type="hidden" name="store_id" value="<?= (int)$st['id'] ?>">
                                                    <button class="btn danger" type="submit">削除</button>
                                                </form>
                                            </div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- =========================
                     ✅ TAB: 人件費率
                     ========================= -->
                <div class="tabPanel" id="tab_labor" data-panel="labor">
                    <div class="card" style="border:none; padding:0;">
                        <form method="post" style="margin-top:10px;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="update_store_labor_thresholds">

                            <div class="box">
                                <div class="row" style="gap:10px; justify-content:flex-start;">
                                    <div style="min-width:220px;">
                                        <div class="label">正常上限（例：30.0）</div>
                                        <input class="input inputShort" type="number" step="0.01" min="0" max="100"
                                            name="labor_green_max_rate" value="<?= h((string)$storeLaborGreenMax) ?>">
                                        <div class="hint">※この値以下が正常値</div>
                                    </div>

                                    <div style="min-width:220px;">
                                        <div class="label">警告上限（例：35.0）</div>
                                        <input class="input inputShort" type="number" step="0.01" min="0" max="100"
                                            name="labor_yellow_max_rate" value="<?= h((string)$storeLaborYellowMax) ?>">
                                        <div class="hint">※超えると要注意</div>
                                    </div>
                                </div>

                                <button class="btn primary" type="submit" style="margin-top:12px;">保存</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <!-- ✅ モーダル（発行時のみ） -->
    <?php if (is_array($bindPopup) && !empty($bindPopup['code'])): ?>
    <div id="bindModalBack"
        style="position:fixed; inset:0; background:rgba(0,0,0,.35); display:none; align-items:center; justify-content:center; padding:16px; z-index:9999;">
        <div
            style="width:min(520px, 100%); background:#fff; border:1px solid #d0d7de; border-radius:0; box-shadow:0 20px 60px rgba(0,0,0,.2); overflow:hidden;">
            <div
                style="padding:12px 14px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                <div style="font-weight:900; color:#0f172a; font-size:14px;">マイページ情報発行</div>
                <button type="button" id="bindModalClose" class="btn" style="height:40px;">閉じる</button>
            </div>

            <div style="padding:14px;">
                <div style="font-weight:900; color:#0f172a;"><?= h((string)$bindPopup['message']) ?></div>
                <div class="muted" style="margin-top:6px;">※ 24時間有効</div>

                <div style="margin-top:12px; padding:12px; border:1px solid #d0d7de; border-radius:0; background:#fff;">
                    <div class="muted" style="font-weight:900;">CODE</div>

                    <div style="display:flex; gap:8px; align-items:flex-start; margin-top:8px; flex-wrap:wrap;">
                        <textarea id="bindCodeInput" readonly class="input"
                            style="flex:1; min-width:220px; font-weight:900; letter-spacing:.5px; height:120px; resize:vertical;"><?=
                            h(
                                (($bindPopup['mode'] ?? 'mypage') === 'line')
                                    ? "① 公式LINE友達登録してください。\nhttps://lin.ee/fknM0Dw\n\n② 以下を送信してください。\n" . (string)$bindPopup['code']
                                    : "下記のマイページにアクセスしてください。\nhttp://bandies.back-room.me/worker/login.php\n\n下記のコードを入力するとログインできます。\n" . $tenantId . "A" . $storeId . "A" . (string)$bindPopup['code']
                            )
                            ?></textarea>
                        <button type="button" id="bindCopyBtn" class="btn primary" style="height:40px;">コピー</button>
                    </div>

                    <div id="bindCopyMsg" class="muted" style="margin-top:8px; display:none;">コピーしました</div>
                </div>
            </div>

            <div style="padding:12px 14px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end;">
                <button type="button" id="bindModalOk" class="btn">OK</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // =========================
        // ✅ タブ切り替え（表示だけ）
        // - 既存機能（POST/保存/並び替え）は一切変更しない
        // - hash(#list など) で状態保持
        // =========================
        const tabBtns = Array.from(document.querySelectorAll('.tabBtn[data-tab]'));
        const panels = Array.from(document.querySelectorAll('.tabPanel[data-panel]'));

        function activateTab(name) {
            tabBtns.forEach(b => {
                const isOn = (b.dataset.tab === name);
                b.classList.toggle('isActive', isOn);
                b.setAttribute('aria-selected', isOn ? 'true' : 'false');
            });
            panels.forEach(p => {
                const isOn = (p.dataset.panel === name);
                p.classList.toggle('isActive', isOn);
            });
        }

        function getInitialTab() {
            const h = (location.hash || '').replace('#', '').trim();
            if (h === 'add' || h === 'payroll' || h === 'store' || h === 'labor' || h === 'list') return h;
            return 'list';
        }

        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const t = btn.dataset.tab;
                if (!t) return;
                activateTab(t);
                location.hash = '#' + t;
            });
        });

        activateTab(getInitialTab());

        // =========================
        // ✅ 支払いサイクルUI（既存の見た目切り替えのみ）
        // =========================
        const cycleSel = document.getElementById('payroll_cycle_type');
        const monthlyBox = document.getElementById('monthlyBox');
        const weeklyBox = document.getElementById('weeklyBox');

        function syncCycleUI() {
            if (!cycleSel || !monthlyBox || !weeklyBox) return;
            const v = cycleSel.value;
            if (v === 'weekly') {
                weeklyBox.style.display = '';
                monthlyBox.style.display = 'none';
            } else {
                monthlyBox.style.display = '';
                weeklyBox.style.display = 'none';
            }
        }
        if (cycleSel) {
            cycleSel.addEventListener('change', syncCycleUI);
            syncCycleUI();
        }

        // =========================
        // ✅ ドラッグ並び替え保存（既存機能そのまま）
        // =========================
        const list = document.getElementById('empList');
        const csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
        let saveTimer = null;
        let saving = false;

        let dragging = null;

        function getItems() {
            if (!list) return [];
            return Array.from(list.querySelectorAll('.item[data-id]'));
        }

        function onDragStart(e) {
            const li = e.currentTarget;
            dragging = li;
            li.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', li.dataset.id || '');
        }

        function saveOrder() {
            if (saving) return;
            const ids = getItems().map(li => li.dataset.id).filter(Boolean).join(',');
            if (!ids) return;
            saving = true;
            const form = new FormData();
            form.append('csrf_token', csrf);
            form.append('action', 'reorder_employees');
            form.append('ordered_ids', ids);
            fetch(location.href, {
                method: 'POST',
                body: form
            }).then(res => res.json()).then(json => {
                if (!json || json.ok !== true) {
                    throw new Error(json && json.message ? json.message : '保存に失敗しました');
                }
            }).catch(err => {
                alert('エラー: ' + (err && err.message ? err.message : String(err)));
            }).finally(() => {
                saving = false;
            });
        }

        function onDragEnd(e) {
            e.currentTarget.classList.remove('dragging');
            dragging = null;
            if (saveTimer) clearTimeout(saveTimer);
            saveTimer = setTimeout(saveOrder, 400);
        }

        function getDragAfterElement(container, y) {
            const els = getItems().filter(el => el !== dragging);
            let closest = {
                offset: Number.NEGATIVE_INFINITY,
                element: null
            };
            for (const el of els) {
                const box = el.getBoundingClientRect();
                const offset = y - (box.top + box.height / 2);
                if (offset < 0 && offset > closest.offset) {
                    closest = {
                        offset,
                        element: el
                    };
                }
            }
            return closest.element;
        }

        function onDragOver(e) {
            e.preventDefault();
            const after = getDragAfterElement(list, e.clientY);
            if (!dragging) return;
            if (after == null) list.appendChild(dragging);
            else list.insertBefore(dragging, after);
        }

        if (list) {
            getItems().forEach(li => {
                li.addEventListener('dragstart', onDragStart);
                li.addEventListener('dragend', onDragEnd);
            });
            list.addEventListener('dragover', onDragOver);
        }

        // =========================
        // ✅ PIN show (masked -> visible)（既存機能そのまま）
        // =========================
        document.querySelectorAll('.pinShowBtn').forEach(btn => {
            btn.addEventListener('click', () => {
                const pill = btn.closest('.pinPill');
                const span = pill ? pill.querySelector('.pinMasked') : null;
                if (!span) return;

                const pin = span.dataset.pin || '';
                if (!pin) {
                    alert('PINが未設定です（再発行してください）');
                    return;
                }

                const ok = confirm('作業員識別IDを表示しますか？（30秒で非表示になります）');
                if (!ok) return;

                span.textContent = pin;
                setTimeout(() => {
                    span.textContent = '****';
                }, 30000);
            });
        });

        // =========================
        // ✅ LINE 紐付けコード Popup + Copy（既存機能そのまま）
        // =========================
        const hasPopup =
            <?= json_encode(is_array($bindPopup) && !empty($bindPopup['code']), JSON_UNESCAPED_UNICODE) ?>;
        if (!hasPopup) return;

        const back = document.getElementById('bindModalBack');
        const closeBtn = document.getElementById('bindModalClose');
        const okBtn = document.getElementById('bindModalOk');
        const copyBtn = document.getElementById('bindCopyBtn');
        const input = document.getElementById('bindCodeInput');
        const msg = document.getElementById('bindCopyMsg');
        if (!back || !copyBtn || !input) return;

        function openModal() {
            back.style.display = 'flex';
            setTimeout(() => input.select(), 0);
        }

        function closeModal() {
            back.style.display = 'none';
        }

        async function copyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }
            input.focus();
            input.select();
            return document.execCommand('copy');
        }

        copyBtn.addEventListener('click', async () => {
            if (!input.value) return;
            const text = input.value;

            try {
                const ok = await copyText(text);
                if (!ok) throw new Error('copy failed');

                if (msg) {
                    msg.style.display = 'block';
                    msg.textContent = 'コピーしました';
                    setTimeout(() => {
                        msg.style.display = 'none';
                    }, 1500);
                }
            } catch (e) {
                alert('コピーに失敗しました。CODEを選択して手動でコピーしてください。');
            }
        });

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (okBtn) okBtn.addEventListener('click', closeModal);

        back.addEventListener('click', (e) => {
            if (e.target === back) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && back.style.display === 'flex') closeModal();
        });

        openModal();

        if (!location.hash) location.hash = '#list';
    });

    // =========================
    // ✅ 在籍 / 退職 / 全員 フィルタ（表示のみ）
    // =========================
    const filterBtns = document.querySelectorAll('[data-filter]');
    const empItems = document.querySelectorAll('#empList .item');

    function applyFilter(mode) {
        empItems.forEach(li => {
            const st = li.dataset.status; // active / inactive
            if (mode === 'all') {
                li.style.display = '';
            } else if (mode === 'active') {
                li.style.display = (st === 'active') ? '' : 'none';
            } else if (mode === 'inactive') {
                li.style.display = (st === 'inactive') ? '' : 'none';
            }
        });
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const mode = btn.dataset.filter;
            filterBtns.forEach(b => b.classList.remove('isFilterActive'));
            btn.classList.add('isFilterActive');
            applyFilter(mode);
        });
    });

    const initialBtn = Array.from(filterBtns).find(b => b.classList.contains('isFilterActive'));
    applyFilter((initialBtn?.dataset.filter) || 'active');
    </script>

    <footer class="footerCopy">
        &copy; AzureSystems by Fader
    </footer>
</body>

</html>
