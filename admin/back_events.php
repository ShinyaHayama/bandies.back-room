<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/back_events.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 今回の修正（機能は一切いじらず、UIだけ変更）
 * - 左右2カラム表示 → 「タブ切り替え」で表示
 * - タブを1つ追加して「キャストランキング」を別タブに分離
 * - 「30日キャッシュバック履歴」タブに 月プルダウン（過去1年分）を追加し、過去月を閲覧可能に
 * - 履歴上で「確定/未確定」を分離表示し、確定忘れを一目で把握できるように（表示のみ）
 *
 * ✅ 既存のSQL/集計/保存/確定/削除/共有URL/モーダル/下書き一覧などロジックは現状維持
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php'; // $tenantId
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

date_default_timezone_set('Asia/Tokyo');

// ===== 強制切替（最優先）=====
$forcePc = (string)($_GET['pc'] ?? '') === '1';
$forceSp = (string)($_GET['sp'] ?? '') === '1';

if ($forceSp) {
    $qs = $_GET;
    unset($qs['pc'], $qs['sp']);
    $to = '/admin/back_events_sp.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $to);
    exit;
}

// CookieでSP固定なら「スマホ端末だけ」SPへ（PCでは飛ばさない）
$cookieMode = (string)($_COOKIE['view_mode'] ?? ''); // 'pc' or 'sp'
function isMobileUa(): bool
{
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return false;
    return (bool)preg_match('/iphone|ipod|android.*mobile|windows phone|blackberry|iemobile|opera mini/', $ua);
}
if (!$forcePc && $cookieMode === 'sp' && isMobileUa()) {
    $qs = $_GET;
    $to = '/admin/back_events_sp.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $to);
    exit;
}

// DB
$paths = [
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
    echo 'db.php not found';
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// CSRF
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

function mustPostCsrf(string $csrf): void
{
    $t = (string)($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals($csrf, $t)) {
        http_response_code(400);
        echo 'CSRF invalid';
        exit;
    }
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

$adminUserId = (int)($_SESSION['tenant_admin_user_id'] ?? 0);
$helpPopupEnabled = ($adminUserId > 0)
    ? getAdminUserPref($pdo, $adminUserId, 'help_popup_back_events', '0')
    : '0';

// URL戻り先
$returnUrl = (string)($_SERVER['REQUEST_URI'] ?? '/admin/back_events.php');

// GET
$storeId = (int)($_GET['store_id'] ?? 0);
$businessDate = (string)($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) $businessDate = date('Y-m-d');

// stores（この tenant のみ）
$stStores = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=? ORDER BY id");
$stStores->execute([$tenantId]);
$stores = $stStores->fetchAll();

if ($storeId <= 0 && !empty($stores)) $storeId = (int)$stores[0]['id'];

// storeId バリデーション（念のため）
$storeIds = array_map('intval', array_column($stores, 'id'));
if ($storeId > 0 && !in_array($storeId, $storeIds, true) && !empty($stores)) {
    $storeId = (int)$stores[0]['id'];
}

// ===== ヘルパ: テーブル列の存在チェック =====
function tableColumns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll();
        foreach ($rows as $r) {
            if (isset($r['Field'])) $cols[(string)$r['Field']] = true;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $cols;
}
function tableExists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * ✅ 重要：eventTypes のキー揺れを吸収する正規化
 * - DBから取ると key/label 形式
 * - 既存ロジックが type_key/type_label を参照している箇所もある
 * → どっちで来ても、両方のキーが必ず揃うようにする（ロジック変更なし）
 */
function normalizeEventTypes(array &$eventTypes): void
{
    foreach ($eventTypes as &$t) {
        $k = (string)($t['type_key'] ?? $t['key'] ?? '');
        $l = (string)($t['type_label'] ?? $t['label'] ?? $k);
        $t['type_key'] = $k;
        $t['type_label'] = $l;
        $t['key'] = $k;
        $t['label'] = $l;
    }
    unset($t);
}

$noticeWarning = '';
$strictWorkFilter = false; // ✅ 出勤者に限定できる時だけ true にする

// ==============================
// ✅ 出勤者だけ取得（DB差異吸収）
// ==============================
$employees = [];
$workingEmpIds = [];
try {
    $tpCols = tableColumns($pdo, 'time_punches');

    // ✅ punch_type があるなら clock_in系だけに絞る（退勤だけで混入するのを防ぐ）
    $hasPunchType = !empty($tpCols) && isset($tpCols['punch_type']);
    $punchTypeCond = $hasPunchType ? " AND tp.punch_type IN ('clock_in','in','open') " : "";

    // (A) ✅ business_date があるパターン
    if (!empty($tpCols) && isset($tpCols['tenant_id'], $tpCols['employee_id'], $tpCols['business_date'])) {
        if (isset($tpCols['store_id'])) {
            $stWorkingEmp = $pdo->prepare("
                SELECT DISTINCT e.id, e.display_name
                FROM time_punches tp
                JOIN employees e ON e.id = tp.employee_id
                WHERE tp.tenant_id = ?
                  AND tp.store_id = ?
                  AND tp.business_date = ?
                  {$punchTypeCond}
                  AND e.tenant_id = ?
                  AND e.employment_status = 'active'
                ORDER BY e.sort_order ASC, e.id ASC
            ");
            $stWorkingEmp->execute([$tenantId, $storeId, $businessDate, $tenantId]);
        } else {
            $stWorkingEmp = $pdo->prepare("
                SELECT DISTINCT e.id, e.display_name
                FROM time_punches tp
                JOIN employees e ON e.id = tp.employee_id
                WHERE tp.tenant_id = ?
                  AND tp.business_date = ?
                  {$punchTypeCond}
                  AND e.tenant_id = ?
                  AND e.employment_status = 'active'
                ORDER BY e.sort_order ASC, e.id ASC
            ");
            $stWorkingEmp->execute([$tenantId, $businessDate, $tenantId]);
        }

        $employees = $stWorkingEmp->fetchAll();
        foreach ($employees as $e) $workingEmpIds[(int)$e['id']] = true;
        $strictWorkFilter = true;

        // (B) ✅ punched_at があるパターン
    } elseif (!empty($tpCols) && isset($tpCols['tenant_id'], $tpCols['employee_id'], $tpCols['punched_at'])) {
        $dayStart = $businessDate . ' 00:00:00';
        $dayEnd = date('Y-m-d H:i:s', strtotime($businessDate . ' +1 day'));

        if (isset($tpCols['store_id'])) {
            $stWorkingEmp = $pdo->prepare("
                SELECT DISTINCT e.id, e.display_name
                FROM time_punches tp
                JOIN employees e ON e.id = tp.employee_id
                WHERE tp.tenant_id = ?
                  AND tp.store_id = ?
                  AND tp.punched_at >= ?
                  AND tp.punched_at < ?
                  {$punchTypeCond}
                  AND e.tenant_id = ?
                  AND e.employment_status = 'active'
                ORDER BY e.sort_order ASC, e.id ASC
            ");
            $stWorkingEmp->execute([$tenantId, $storeId, $dayStart, $dayEnd, $tenantId]);
        } else {
            $stWorkingEmp = $pdo->prepare("
                SELECT DISTINCT e.id, e.display_name
                FROM time_punches tp
                JOIN employees e ON e.id = tp.employee_id
                WHERE tp.tenant_id = ?
                  AND tp.punched_at >= ?
                  AND tp.punched_at < ?
                  {$punchTypeCond}
                  AND e.tenant_id = ?
                  AND e.employment_status = 'active'
                ORDER BY e.sort_order ASC, e.id ASC
            ");
            $stWorkingEmp->execute([$tenantId, $dayStart, $dayEnd, $tenantId]);
        }

        $employees = $stWorkingEmp->fetchAll();
        foreach ($employees as $e) $workingEmpIds[(int)$e['id']] = true;
        $strictWorkFilter = true;
    } else {
        $noticeWarning = '※ time_punches の列構成が想定と違うため「出勤者のみ表示」は無効（全員表示）です。';
    }
} catch (Throwable $e) {
    $noticeWarning = '※ 出勤者判定でエラーのため「出勤者のみ表示」は無効（全員表示）です。';
    $strictWorkFilter = false;
    $employees = [];
    $workingEmpIds = [];
}

// フォールバック: 全アクティブ
if (!$strictWorkFilter) {
    $stEmp = $pdo->prepare("
        SELECT id, display_name
        FROM employees
        WHERE tenant_id=?
          AND (store_id=? OR store_id IS NULL)
          AND employment_status='active'
        ORDER BY sort_order ASC, id ASC
    ");
    $stEmp->execute([$tenantId, $storeId]);
    $employees = $stEmp->fetchAll();
    foreach ($employees as $e) $workingEmpIds[(int)$e['id']] = true;
}

// ✅ 従業員が0人の日のUI制御
$hasEmployees = !empty($employees);
$employeeCount = is_array($employees) ? count($employees) : 0;

// strictの時だけ登録制限
function mustWorkingEmployee(bool $strictWorkFilter, array $workingEmpIds, int $empId): void
{
    if (!$strictWorkFilter) return;
    if ($empId <= 0 || empty($workingEmpIds[$empId])) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "この日は出勤があるキャストのみ登録できます。";
        exit;
    }
}

// ==============================
// ✅ back_event_types 取得（SP版に寄せる：列名差を吸収）
// ==============================
$eventTypes = [];            // ボタン生成用
$eventTypeLabelMap = [];     // key=>label（履歴表示用）
$eventTypeMetaMap = [];      // key=>meta（将来用）
$eventTypeIdMap = [];        // key=>id（種別紐付け用）

try {
    if (tableExists($pdo, 'back_event_types')) {
        $btc = tableColumns($pdo, 'back_event_types');

        // key列候補
        $colKey = isset($btc['event_type']) ? 'event_type' : (isset($btc['type_key']) ? 'type_key' : (isset($btc['code']) ? 'code' : (isset($btc['key']) ? 'key' : 'event_type')));
        // label列候補
        $colLabel = isset($btc['label']) ? 'label' : (isset($btc['name']) ? 'name' : (isset($btc['display_name']) ? 'display_name' : (isset($btc['type_label']) ? 'type_label' : 'label')));
        // sort列候補
        $colSort = isset($btc['sort_order']) ? 'sort_order' : (isset($btc['sort']) ? 'sort' : '');
        // active列候補（無いなら全件）
        $colActive = isset($btc['is_active']) ? 'is_active' : (isset($btc['enabled']) ? 'enabled' : '');
        $hasStoreCol = isset($btc['store_id']);

        $w = ["tenant_id = :tenant_id"];
        $params = [':tenant_id' => $tenantId];

        if ($hasStoreCol) {
            $w[] = "(store_id = :store_id OR store_id IS NULL OR store_id = 0)";
            $params[':store_id'] = $storeId;
        }
        if ($colActive !== '') {
            $w[] = "($colActive = 1 OR $colActive = '1')";
        }

        $order = ($colSort !== '') ? "ORDER BY $colSort ASC, id ASC" : "ORDER BY id ASC";
        $sql = "SELECT * FROM back_event_types WHERE " . implode(' AND ', $w) . " $order";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rowsType = $st->fetchAll();

        foreach ($rowsType as $tr) {
            $k = (string)($tr[$colKey] ?? '');
            if ($k === '') continue;
            $label = (string)($tr[$colLabel] ?? $k);

            // ★ DBから取った時点では key/label で積む
            $eventTypes[] = ['key' => $k, 'label' => $label];
            $eventTypeLabelMap[$k] = $label;
            $eventTypeMetaMap[$k] = ['label' => $label];
            if (isset($tr['id'])) $eventTypeIdMap[$k] = (int)$tr['id'];
        }
    }
} catch (Throwable $e) {
    // 失敗しても画面を壊さない
    $eventTypes = [];
    $eventTypeLabelMap = [];
    $eventTypeMetaMap = [];
}

// ==============================
// ✅ 種別（項目＋金額）
// ==============================
$typeItemsByKey = [];
try {
    if (!empty($eventTypeIdMap) && tableExists($pdo, 'back_event_type_items')) {
        $typeIds = array_values($eventTypeIdMap);
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $sql = "
            SELECT id, type_id, label, amount_yen, sort_order, is_active
            FROM back_event_type_items
            WHERE tenant_id=? AND store_id=? AND type_id IN ($placeholders) AND is_active=1
            ORDER BY sort_order ASC, id ASC
        ";
        $stItems = $pdo->prepare($sql);
        $params = array_merge([$tenantId, $storeId], $typeIds);
        $stItems->execute($params);
        $rows = $stItems->fetchAll();

        $typeIdToKey = [];
        foreach ($eventTypeIdMap as $k => $id) $typeIdToKey[(int)$id] = $k;

        foreach ($rows as $r) {
            $typeId = (int)$r['type_id'];
            $key = $typeIdToKey[$typeId] ?? '';
            if ($key === '') continue;
            if (!isset($typeItemsByKey[$key])) $typeItemsByKey[$key] = [];
            $typeItemsByKey[$key][] = [
                'id' => (int)$r['id'],
                'label' => (string)$r['label'],
                'amount_yen' => (int)$r['amount_yen'],
            ];
        }
    }
} catch (Throwable $e) {
    // 表示のみなので握りつぶし
}

// フォールバック（DBが無い/空のときだけ）
if (empty($eventTypes)) {
    $eventTypes = [
        ['key' => 'nomination', 'label' => '指名'],
        ['key' => 'drink_back', 'label' => 'ドリンクバック'],
        ['key' => 'escort', 'label' => '同伴・アフター'],
    ];
    foreach ($eventTypes as $t) {
        $eventTypeLabelMap[$t['key']] = $t['label'];
        $eventTypeMetaMap[$t['key']] = ['label' => $t['label']];
    }
}

// ✅ ここで必ず正規化（重要）
normalizeEventTypes($eventTypes);

// ==============================
// ✅ daily_wage_adjustments の列を吸収して UPDATE/INSERT を組み立てる
// ==============================
function getDailyWageCols(PDO $pdo): array
{
    return tableColumns($pdo, 'daily_wage_adjustments');
}
function dailyWageUpsertBonus(PDO $pdo, int $tenantId, int $storeId, int $empId, string $date, int $bonus): void
{
    $cols = getDailyWageCols($pdo);
    if (empty($cols)) {
        throw new RuntimeException('daily_wage_adjustments table/columns not found');
    }

    $hasStoreId = isset($cols['store_id']);
    $hasUpdatedAt = isset($cols['updated_at']);
    $hasCreatedAt = isset($cols['created_at']);

    if ($hasStoreId) {
        $st = $pdo->prepare("
            SELECT id
            FROM daily_wage_adjustments
            WHERE tenant_id=:tenant_id
              AND store_id=:store_id
              AND employee_id=:employee_id
              AND business_date=:business_date
            LIMIT 1
        ");
        $st->execute([
            ':tenant_id' => $tenantId,
            ':store_id' => $storeId,
            ':employee_id' => $empId,
            ':business_date' => $date,
        ]);
    } else {
        $st = $pdo->prepare("
            SELECT id
            FROM daily_wage_adjustments
            WHERE tenant_id=:tenant_id
              AND employee_id=:employee_id
              AND business_date=:business_date
            LIMIT 1
        ");
        $st->execute([
            ':tenant_id' => $tenantId,
            ':employee_id' => $empId,
            ':business_date' => $date,
        ]);
    }

    $found = $st->fetch();
    if ($found && isset($found['id'])) {
        $set = "bonus_yen = :bonus_yen";
        if ($hasUpdatedAt) $set .= ", updated_at = CURRENT_TIMESTAMP";

        $upd = $pdo->prepare("
            UPDATE daily_wage_adjustments
            SET {$set}
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $upd->execute([
            ':bonus_yen' => $bonus,
            ':id' => (int)$found['id'],
            ':tenant_id' => $tenantId,
        ]);
        return;
    }

    $fields = ['tenant_id', 'employee_id', 'business_date', 'bonus_yen'];
    if ($hasStoreId) $fields[] = 'store_id';
    if ($hasCreatedAt) $fields[] = 'created_at';
    if ($hasUpdatedAt) $fields[] = 'updated_at';

    $placeholders = array_map(fn($f) => ':' . $f, $fields);
    $sql = "INSERT INTO daily_wage_adjustments (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";

    $params = [
        ':tenant_id' => $tenantId,
        ':employee_id' => $empId,
        ':business_date' => $date,
        ':bonus_yen' => $bonus,
    ];
    if ($hasStoreId) $params[':store_id'] = $storeId;
    if ($hasCreatedAt) $params[':created_at'] = date('Y-m-d H:i:s');
    if ($hasUpdatedAt) $params[':updated_at'] = date('Y-m-d H:i:s');

    $ins = $pdo->prepare($sql);
    $ins->execute($params);
}

// ==============================
// ✅ POST: bonus 保存/削除
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_bonus') {
        mustPostCsrf($csrf);

        if (empty($employees)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "この日は従業員がいないため登録できません。";
            exit;
        }

        $empId = (int)($_POST['employee_id'] ?? 0);
        $date = (string)($_POST['business_date'] ?? '');
        $bonus = (int)($_POST['bonus_yen'] ?? 0);

        if ($empId <= 0) {
            http_response_code(400);
            echo "employee_id invalid";
            exit;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo "business_date invalid";
            exit;
        }

        mustWorkingEmployee($strictWorkFilter, $workingEmpIds, $empId);

        try {
            dailyWageUpsertBonus($pdo, $tenantId, $storeId, $empId, $date, $bonus);
            header('Location: ' . $returnUrl);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "save_bonus failed\n\n";
            echo $e->getMessage() . "\n";
            exit;
        }
    }

    if ($action === 'delete_bonus') {
        mustPostCsrf($csrf);

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo "id invalid";
            exit;
        }

        try {
            $del = $pdo->prepare("DELETE FROM daily_wage_adjustments WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
            $del->execute([
                ':id' => $id,
                ':tenant_id' => $tenantId,
            ]);
            header('Location: ' . $returnUrl);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "delete_bonus failed\n\n";
            echo $e->getMessage() . "\n";
            exit;
        }
    }
}

// ==============================
// back_events 一覧（✅ confirmed のみ表示）+ 当日サマリ
// ==============================
$rows = [];
$sumQtyByType = [];   // type => qty
$sumYenByType = [];   // type => yen
$sumAmountTotal = 0;

try {
    $stList = $pdo->prepare("
        SELECT be.*, e.display_name
        FROM back_events be
        JOIN employees e ON e.id = be.employee_id
        WHERE be.tenant_id=?
          AND be.store_id=?
          AND be.business_date=?
          AND be.status='confirmed'
        ORDER BY be.id DESC
    ");
    $stList->execute([$tenantId, $storeId, $businessDate]);
    $rows = $stList->fetchAll();

    foreach ($rows as $r) {
        $etype = (string)$r['event_type'];
        $q = (int)$r['quantity'];
        $y = (int)$r['amount_yen'];
        if (!isset($sumQtyByType[$etype])) $sumQtyByType[$etype] = 0;
        if (!isset($sumYenByType[$etype])) $sumYenByType[$etype] = 0;
        $sumQtyByType[$etype] += $q;
        $sumYenByType[$etype] += $y;
        $sumAmountTotal += $y;
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "back_events load failed\n\n";
    echo $e->getMessage();
    exit;
}

// ==============================
// ✅ 追加: back_events 未確定（下書き）一覧（保存(仮)がここに出る）
// ==============================
$draftRows = [];
$draftCount = 0;
try {
    $stDraft = $pdo->prepare("
        SELECT be.*, e.display_name
        FROM back_events be
        JOIN employees e ON e.id = be.employee_id
        WHERE be.tenant_id=?
          AND be.store_id=?
          AND be.business_date=?
          AND (be.status IS NULL OR be.status <> 'confirmed')
        ORDER BY be.id DESC
        LIMIT 200
    ");
    $stDraft->execute([$tenantId, $storeId, $businessDate]);
    $draftRows = $stDraft->fetchAll();
    $draftCount = is_array($draftRows) ? count($draftRows) : 0;
} catch (Throwable $e) {
    $draftRows = [];
    $draftCount = 0;
}

// ==============================
// bonus 一覧 + 合計
// ==============================
$bonusRows = [];
$bonusTotal = 0;
try {
    $dwaCols = tableColumns($pdo, 'daily_wage_adjustments');
    $hasStoreId = !empty($dwaCols) && isset($dwaCols['store_id']);
    if (!empty($dwaCols)) {
        if ($hasStoreId) {
            $stBonus = $pdo->prepare("
                SELECT d.*, e.display_name
                FROM daily_wage_adjustments d
                JOIN employees e ON e.id = d.employee_id
                WHERE d.tenant_id = ?
                  AND d.store_id = ?
                  AND d.business_date = ?
                ORDER BY d.id DESC
            ");
            $stBonus->execute([$tenantId, $storeId, $businessDate]);
        } else {
            $stBonus = $pdo->prepare("
                SELECT d.*, e.display_name
                FROM daily_wage_adjustments d
                JOIN employees e ON e.id = d.employee_id
                WHERE d.tenant_id = ?
                  AND d.business_date = ?
                ORDER BY d.id DESC
            ");
            $stBonus->execute([$tenantId, $businessDate]);
        }

        $bonusRows = $stBonus->fetchAll();
        foreach ($bonusRows as $br) $bonusTotal += (int)($br['bonus_yen'] ?? 0);
    }
} catch (Throwable $e) {
    $bonusRows = [];
    $bonusTotal = 0;
}

// ==============================
// ✅ 履歴の期間切替（UI用）
// - hist_month があれば「その月」
// - なければ従来どおり「直近30日」
// ==============================
$histMonth = (string)($_GET['hist_month'] ?? '');
$histMode = '30d';
if (preg_match('/^\d{4}-\d{2}$/', $histMonth)) {
    $histMode = 'month';
}

// ✅ 月プルダウン（過去1年分）: 表示専用
$histMonthOptions = []; // ['value'=>'YYYY-MM','label'=>'YYYY年MM月']
for ($i = 0; $i < 12; $i++) {
    $ts = strtotime(date('Y-m-01') . " -{$i} month");
    $val = date('Y-m', $ts);
    $lab = date('Y年m月', $ts);
    $histMonthOptions[] = ['value' => $val, 'label' => $lab];
}

// 期間決定
if ($histMode === 'month') {
    $histFrom = $histMonth . '-01';
    $histTo = date('Y-m-t', strtotime($histFrom));
} else {
    $histFrom = date('Y-m-d', strtotime($businessDate . ' -29 days'));
    $histTo = $businessDate;
}

// ==============================
// ✅ 直近30日/指定月の履歴
// - confirmed と 未確定（status NULL/!=confirmed）を両方集計（表示のみ）
// - 種別別 totals は confirmed / 未確定 で分ける（表示のみ）
// - 日別合計にも confirmed / 未確定 を出す（確定忘れを見える化）
// ==============================
$histTypeTotalsConfirmed = [];
$histTypeTotalsDraft = [];
foreach ($eventTypes as $t) {
    $k = (string)$t['type_key'];
    $histTypeTotalsConfirmed[$k] = ['qty' => 0, 'yen' => 0];
    $histTypeTotalsDraft[$k] = ['qty' => 0, 'yen' => 0];
}

$histAllYenConfirmed = 0;
$histAllYenDraft = 0;
$histAllCountConfirmed = 0;
$histAllCountDraft = 0;

// 日別（confirmed/未確定）
$histDaily = []; // date => ['confirmed_yen'=>, 'draft_yen'=>, 'confirmed_nomination_yen'=>...]
$histRankRowsConfirmed = []; // confirmed ranking
$histRankRowsDraft = [];     // draft ranking

try {
    // (1) 日別 & 種別別 totals（軽いSQL→PHP集計）
    $stHistAll = $pdo->prepare("
        SELECT
          be.business_date,
          be.event_type,
          be.quantity,
          be.amount_yen,
          be.status,
          be.employee_id,
          e.display_name
        FROM back_events be
        LEFT JOIN employees e
          ON e.id = be.employee_id
         AND e.tenant_id = be.tenant_id
        WHERE be.tenant_id = ?
          AND be.store_id = ?
          AND be.business_date >= ?
          AND be.business_date <= ?
        ORDER BY be.business_date DESC, be.id DESC
    ");
    $stHistAll->execute([$tenantId, $storeId, $histFrom, $histTo]);
    $histRowsAll = $stHistAll->fetchAll();

    foreach ($histRowsAll as $r) {
        $d = (string)$r['business_date'];
        $t = (string)$r['event_type'];
        $q = (int)$r['quantity'];
        $y = (int)$r['amount_yen'];
        $status = (string)($r['status'] ?? '');

        $isConfirmed = ($status === 'confirmed');

        if (!isset($histDaily[$d])) {
            $histDaily[$d] = [
                'confirmed_yen' => 0,
                'draft_yen' => 0,
                'names' => [],

                // ✅ confirmed の種別内訳（既存の「固定3+その他」思想は維持）
                'confirmed_nomination_yen' => 0,
                'confirmed_drink_back_yen' => 0,
                'confirmed_escort_yen' => 0,
                'confirmed_other_yen' => 0,
            ];
        }
        $ename = trim((string)($r['display_name'] ?? ''));
        if ($ename === '') {
            $eid = (int)($r['employee_id'] ?? 0);
            $ename = ($eid > 0) ? ('ID:' . $eid) : '不明';
        }
        $histDaily[$d]['names'][$ename] = true;

        if ($isConfirmed) {
            $histDaily[$d]['confirmed_yen'] += $y;

            if ($t === 'nomination') $histDaily[$d]['confirmed_nomination_yen'] += $y;
            elseif ($t === 'drink_back') $histDaily[$d]['confirmed_drink_back_yen'] += $y;
            elseif ($t === 'escort') $histDaily[$d]['confirmed_escort_yen'] += $y;
            else $histDaily[$d]['confirmed_other_yen'] += $y;

            if (isset($histTypeTotalsConfirmed[$t])) {
                $histTypeTotalsConfirmed[$t]['qty'] += $q;
                $histTypeTotalsConfirmed[$t]['yen'] += $y;
            }
            $histAllYenConfirmed += $y;
            $histAllCountConfirmed += 1;
        } else {
            $histDaily[$d]['draft_yen'] += $y;

            if (isset($histTypeTotalsDraft[$t])) {
                $histTypeTotalsDraft[$t]['qty'] += $q;
                $histTypeTotalsDraft[$t]['yen'] += $y;
            }
            $histAllYenDraft += $y;
            $histAllCountDraft += 1;
        }
    }

    // (2) ✅ キャストランキング（confirmed）
    $stRankConfirmed = $pdo->prepare("
        SELECT
          be.employee_id,
          e.display_name,
          SUM(be.amount_yen) AS total_yen,
          SUM(CASE WHEN be.event_type='nomination' THEN be.amount_yen ELSE 0 END) AS nomination_yen,
          SUM(CASE WHEN be.event_type='drink_back' THEN be.amount_yen ELSE 0 END) AS drink_back_yen,
          SUM(CASE WHEN be.event_type='escort' THEN be.amount_yen ELSE 0 END) AS escort_yen,
          SUM(CASE WHEN be.event_type NOT IN ('nomination','drink_back','escort') THEN be.amount_yen ELSE 0 END) AS other_yen
        FROM back_events be
        JOIN employees e ON e.id = be.employee_id
        WHERE be.tenant_id = ?
          AND be.store_id = ?
          AND be.status = 'confirmed'
          AND be.business_date >= ?
          AND be.business_date <= ?
        GROUP BY be.employee_id, e.display_name
        ORDER BY total_yen DESC, e.display_name ASC
        LIMIT 20
    ");
    $stRankConfirmed->execute([$tenantId, $storeId, $histFrom, $histTo]);
    $histRankRowsConfirmed = $stRankConfirmed->fetchAll();

    // (3) ✅ キャストランキング（未確定: status NULL/!=confirmed）
    $stRankDraft = $pdo->prepare("
        SELECT
          be.employee_id,
          e.display_name,
          SUM(be.amount_yen) AS total_yen,
          SUM(CASE WHEN be.event_type='nomination' THEN be.amount_yen ELSE 0 END) AS nomination_yen,
          SUM(CASE WHEN be.event_type='drink_back' THEN be.amount_yen ELSE 0 END) AS drink_back_yen,
          SUM(CASE WHEN be.event_type='escort' THEN be.amount_yen ELSE 0 END) AS escort_yen,
          SUM(CASE WHEN be.event_type NOT IN ('nomination','drink_back','escort') THEN be.amount_yen ELSE 0 END) AS other_yen
        FROM back_events be
        JOIN employees e ON e.id = be.employee_id
        WHERE be.tenant_id = ?
          AND be.store_id = ?
          AND (be.status IS NULL OR be.status <> 'confirmed')
          AND be.business_date >= ?
          AND be.business_date <= ?
        GROUP BY be.employee_id, e.display_name
        ORDER BY total_yen DESC, e.display_name ASC
        LIMIT 20
    ");
    $stRankDraft->execute([$tenantId, $storeId, $histFrom, $histTo]);
    $histRankRowsDraft = $stRankDraft->fetchAll();
} catch (Throwable $e) {
    $histDaily = [];
    $histRankRowsConfirmed = [];
    $histRankRowsDraft = [];
    $histAllYenConfirmed = 0;
    $histAllYenDraft = 0;
    $histAllCountConfirmed = 0;
    $histAllCountDraft = 0;
}

$histAllYenTotal = $histAllYenConfirmed + $histAllYenDraft;

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>キャッシュバック入力（PC）</title>
    <style>
    :root {
        --bg: #f4f6f8;
        --card: #fff;
        --text: #0f172a;
        --muted: #64748b;
        --border: #e5e7eb;
        --border2: #eef2f7;
        --shadow: 0 10px 24px rgba(15, 23, 42, .08);
        --blueBg: #f7fbff;
        --blueBd: #e2efff;
        --orangeBg: #fffaf2;
        --orangeBd: #ffe3b3;
        --warnBg: #fff7e6;
        --warnBd: #ffd18a;
        --warnTx: #6b4a00;

        --dangerBg: #fff1f2;
        --dangerBd: #fecdd3;
        --dangerTx: #9f1239;

        --rankBg: #0b1220;
        --rankGold: #fbbf24;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans JP", sans-serif;
        margin: 0;
        background: var(--bg);
        color: var(--text);
        line-height: 1.45;
    }

    .pageWrap {
        max-width: none;
        margin: 0;
        padding: 0 20px 64px;
    }

    /* ✅ UI変更：左右2カラムではなく、タブで切替 */
    .layoutGrid {
        display: block;
    }

    /* ===== タブUI（UIのみ） ===== */
    .tabsBar {
        position: sticky;
        top: 0;
        z-index: 20;
        background: rgba(246, 247, 251, .86);
        backdrop-filter: blur(8px);
        margin-top: 0;
        padding: 10px 0;
        border-bottom: none;
        margin-bottom: 0;
    }

    .tabs {
        display: flex;
        gap: 10px;
        align-items: center;
        justify-content: flex-start;
        flex-wrap: wrap;
    }

    .tabBtn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        border-radius: 999px;
        border: 1px solid var(--border);
        background: #fff;
        color: #111;
        font-weight: 1000;
        font-size: 12px;
        cursor: pointer;
        user-select: none;
        text-decoration: none;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
    }

    .tabBtn small {
        font-weight: 900;
        font-size: 11px;
        color: #334155;
    }

    .tabBtnActive {
        background: #111;
        color: #fff;
        border-color: rgba(0, 0, 0, .14);
    }

    .tabBtnActive small {
        color: rgba(255, 255, 255, .86);
    }

    .tabPanes {
        display: block;
    }

    .tabPane {
        display: none;
    }

    .tabPaneActive {
        display: block;
    }

    /* ===== ベース ===== */
    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    .select,
    .input {
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #fff;
        font-size: 14px;
        max-width: 100%;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 14px;
        border-radius: 12px;
        border: 1px solid #111;
        background: #fff;
        font-weight: 800;
        text-decoration: none;
        color: #111;
        cursor: pointer;
        user-select: none;
    }

    .btnPrimary {
        background: #111;
        color: #fff;
        border-color: #111;
    }

    .btnDisabled {
        opacity: .45;
        pointer-events: none;
        filter: grayscale(1);
    }

    .small {
        font-size: 12px;
        color: var(--muted);
    }

    /* ===== カード（見た目の区切り）===== */
    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 16px;
        box-shadow: var(--shadow);
        padding: 16px;
    }

    .card+.card {
        margin-top: 14px;
    }

    .cardHead {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 10px;
    }

    .stepTitle {
        font-weight: 1000;
        font-size: 14px;
        letter-spacing: .2px;
        color: #0b1220;
    }

    .stepSub {
        font-size: 12px;
        color: var(--muted);
        font-weight: 700;
    }

    .divider {
        height: 1px;
        background: var(--border2);
        margin: 12px 0;
    }

    /* ===== 左カラムの大枠 ===== */
    .leftBigBox {
        border: 0;
        background: transparent;
        padding: 0;
    }

    /* ===== 注意 ===== */
    .warn {
        background: var(--warnBg);
        border: 1px solid var(--warnBd);
        padding: 10px 12px;
        border-radius: 14px;
        font-size: 12px;
        color: var(--warnTx);
    }

    .danger {
        background: var(--dangerBg);
        border: 1px solid var(--dangerBd);
        padding: 10px 12px;
        border-radius: 14px;
        font-size: 12px;
        color: var(--dangerTx);
        font-weight: 900;
    }

    /* ===== 上部フォーム（見た目のみ整理）===== */
    .topForm {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 14px;
        align-items: start;
    }

    .topLeft {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    .topRight {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
        min-width: 260px;
    }

    .topRight .btnCompact {
        width: 260px;
        justify-content: center;
        height: 34px;
        padding: 0 12px;
        font-size: 12px;
        font-weight: 1000;
        border-radius: 12px;
        border: 0;
        background: #111;
        color: #fff;
    }

    .topNote {
        font-size: 12px;
        color: var(--muted);
        line-height: 1.3;
        text-align: right;
        max-width: 260px;
    }

    .topLeft .select,
    .topLeft .input,
    .topLeft .btn {
        height: 34px;
        padding: 0 10px;
        font-size: 12px;
        border-radius: 12px;
    }

    .topLeft input[type="date"] {
        min-width: 180px;
    }

    .topLeft select[name="store_id"] {
        min-width: 130px;
    }

    @media (max-width:900px) {
        .topForm {
            grid-template-columns: 1fr;
        }

        .topRight {
            align-items: flex-start;
            min-width: auto;
        }

        .topRight .btnCompact {
            width: 100%;
        }

        .topNote {
            text-align: left;
            max-width: none;
        }
    }

    /* ===== サマリー（チップ）===== */
    .topBadges {
        grid-column: 1 / -1;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-start;
        background: #f8fafc;
        border: 1px solid #e9eef5;
        padding: 12px;
        border-radius: 14px;
        font-variant-numeric: tabular-nums;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #fff;
        border: 1px solid #e6ebf2;
        padding: 8px 10px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 800;
        color: #0f172a;
        white-space: nowrap;
    }

    .badge b,
    .badge strong {
        font-weight: 1000;
    }

    .topBadges .small {
        background: transparent;
        border: 0;
        padding: 0;
        border-radius: 0;
        font-size: 12px;
        color: var(--muted);
        font-weight: 700;
    }

    /* ===== 大ボタン（登録）===== */
    .bigBtns {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }

    .bigBtns button {
        height: 76px;
        border: 1px solid #111;
        background: #111;
        color: #fff;
        font-weight: 1000;
        font-size: 16px;
        letter-spacing: .2px;
        cursor: pointer;
        border-radius: 14px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 6px;
        box-shadow: 0 14px 26px rgba(0, 0, 0, .12);
        transform: translateY(0);
        transition: transform .12s ease, box-shadow .12s ease, filter .12s ease;
    }

    .bigBtns button:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 34px rgba(0, 0, 0, .16);
        filter: brightness(1.03);
    }

    .bigBtns button:active {
        transform: translateY(0);
        box-shadow: 0 10px 20px rgba(0, 0, 0, .12);
    }

    .bigBtns button small {
        font-size: 12px;
        font-weight: 800;
        opacity: .85;
    }

    .bigBtns button.btnOther {
        background: #fff;
        color: #111;
        border: 1px dashed #111;
        box-shadow: none;
    }

    .bigBtns button.btnOther:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, .08);
    }

    @media (max-width:900px) {
        .bigBtns {
            grid-template-columns: repeat(2, 1fr);
        }

        .bigBtns button {
            height: 74px;
        }
    }

    /* ===== テーブル ===== */
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        background: #fff;
        border-radius: 12px;
        overflow: hidden;
    }

    th,
    td {
        padding: 10px 8px;
        border-bottom: 1px solid #e9edf2;
        text-align: left;
        vertical-align: top;
    }

    table thead th {
        background: #f3f6ff;
        border-bottom: 1px solid #dfe6ff;
        font-weight: 900;
    }

    table tbody tr:nth-child(odd) {
        background: #fbfbfc;
    }

    /* ===== 種別ラベル ===== */
    .eventLabel {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 999px;
        border: 1px solid #ddd;
        font-weight: 1000;
        font-size: 12px;
        margin-right: 6px;
        white-space: nowrap;
    }

    .eventLabel.nomination {
        background: #fff3e0;
        border-color: #ffd8a8;
        color: #b45309;
    }

    .eventLabel.drink_back {
        background: #e7f5ff;
        border-color: #a5d8ff;
        color: #1c7ed6;
    }

    .eventLabel.escort {
        background: #f3f0ff;
        border-color: #d0bfff;
        color: #5f3dc4;
    }

    /* ===== 一覧ブロック背景（テーブル自体は維持）===== */
    .tableWrapConfirmed {
        background: var(--blueBg);
        border: 1px solid var(--blueBd);
        padding: 12px;
        border-radius: 14px;
    }

    .tableWrapDraft {
        background: var(--orangeBg);
        border: 1px solid var(--orangeBd);
        padding: 12px;
        border-radius: 14px;
    }

    .tableWrapConfirmed table,
    .tableWrapDraft table {
        background: #fff;
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        line-height: 1.4;
    }

    .tableWrapConfirmed th,
    .tableWrapDraft th,
    .tableWrapConfirmed td,
    .tableWrapDraft td {
        padding: 10px 10px;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
    }

    .tableWrapConfirmed thead th,
    .tableWrapDraft thead th {
        background: #f8fafc;
        color: #0f172a;
        font-weight: 900;
        font-size: 12px;
        letter-spacing: .2px;
    }

    .tableWrapConfirmed tbody tr:nth-child(even),
    .tableWrapDraft tbody tr:nth-child(even) {
        background: #fafafa;
    }

    .tableWrapConfirmed tbody tr:hover,
    .tableWrapDraft tbody tr:hover {
        background: #f1f5f9;
    }

    .sectionTitle {
        font-weight: 1000;
        font-size: 14px;
        color: #0b1220;
        margin: 0 0 10px;
        letter-spacing: .2px;
    }

    .editIconBtn {
        border: 0 !important;
        background: transparent !important;
        padding: 8px 10px;
        border-radius: 10px;
    }

    .yenCell {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }

    .qtyCell {
        text-align: center;
        font-variant-numeric: tabular-nums;
    }

    .castCell {
        font-weight: 800;
        color: #111827;
    }

    .memoCell {
        color: #6b7280;
        font-size: 12px;
    }

    .actionCell {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .draftBox {
        border: 1px dashed #d1d5db;
        padding: 10px 12px;
        background: #fafafa;
        border-radius: 14px;
        margin-top: 10px;
    }

    /* ===== 履歴パネル ===== */
    details.histBox {
        border: 1px solid var(--border);
        padding: 14px;
        background: #fff;
        border-radius: 16px;
        box-shadow: var(--shadow);
        position: static;
        top: auto;
    }

    details.histBox>summary {
        list-style: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        font-weight: 1000;
        user-select: none;
    }

    details.histBox>summary::-webkit-details-marker {
        display: none;
    }

    .histMeta {
        font-size: 12px;
        color: var(--muted);
        font-weight: 800;
    }

    .histTopBar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .histModePill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid #111;
        border-radius: 999px;
        padding: 8px 10px;
        font-weight: 1000;
        font-size: 12px;
    }

    .histMonthSelectWrap {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    .histMonthSelectWrap .select {
        height: 44px;
        border-radius: 12px;
        font-weight: 900;
    }

    .histMonthHelp {
        font-size: 12px;
        color: var(--muted);
        font-weight: 800;
    }

    .histTotalsGrid {
        margin-top: 10px;
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
    }

    .histTotalCard {
        border: 1px solid #eee;
        border-radius: 14px;
        padding: 10px 12px;
        background: #fff;
    }

    .histTotalCard .k {
        font-size: 12px;
        color: var(--muted);
        font-weight: 900;
    }

    .histTotalCard .v {
        font-size: 20px;
        font-weight: 1000;
        letter-spacing: .2px;
        margin-top: 2px;
    }

    .histTotalCard .sub {
        font-size: 12px;
        color: var(--muted);
        font-weight: 800;
        margin-top: 4px;
    }

    @media (max-width:900px) {
        .histTotalsGrid {
            grid-template-columns: 1fr;
        }
    }

    /* ===== ランキング（派手に） ===== */
    .rankHero {
        background: linear-gradient(135deg, rgba(11, 18, 32, 1) 0%, rgba(17, 24, 39, 1) 60%, rgba(2, 6, 23, 1) 100%);
        border: 1px solid rgba(255, 255, 255, .10);
        border-radius: 18px;
        padding: 16px 16px;
        box-shadow: 0 24px 60px rgba(0, 0, 0, .20);
        color: #fff;
        position: relative;
        overflow: hidden;
    }

    .rankHero:before {
        content: "";
        position: absolute;
        inset: -80px;
        background: radial-gradient(circle at 20% 20%, rgba(251, 191, 36, .18), transparent 45%),
            radial-gradient(circle at 80% 60%, rgba(59, 130, 246, .16), transparent 55%),
            radial-gradient(circle at 40% 90%, rgba(236, 72, 153, .14), transparent 55%);
        transform: rotate(8deg);
    }

    .rankHeroInner {
        position: relative;
        display: flex;
        gap: 14px;
        flex-wrap: wrap;
        align-items: flex-end;
        justify-content: space-between;
    }

    .rankMedal {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        font-weight: 1000;
        background: rgba(0, 0, 0, .28);
        border: 1px solid rgba(255, 255, 255, .18);
        padding: 10px 12px;
        border-radius: 999px;
    }

    .rankMedal b {
        color: var(--rankGold);
    }

    .rankName {
        font-size: 34px;
        font-weight: 1000;
        letter-spacing: .4px;
        line-height: 1.1;
        text-shadow: 0 10px 24px rgba(0, 0, 0, .35);
    }

    .rankAmount {
        font-size: 34px;
        font-weight: 1000;
        letter-spacing: .4px;
        color: var(--rankGold);
        text-shadow: 0 10px 24px rgba(0, 0, 0, .35);
    }

    .rankBreak {
        margin-top: 10px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
        font-size: 12px;
        font-weight: 900;
        color: rgba(255, 255, 255, .86);
    }

    .rankBreak .chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, .08);
        border: 1px solid rgba(255, 255, 255, .12);
        padding: 8px 10px;
        border-radius: 999px;
    }

    /* ===== モーダル（見た目は軽く整えるだけ）===== */
    .overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .45);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 14px;
        z-index: 9999;
    }

    .sheet {
        width: min(760px, 100%);
        max-height: min(92vh, 860px);
        height: 92vh;
        background: #fff;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
        display: flex;
        flex-direction: column;
    }

    .sheetHeader {
        padding: 14px 14px 10px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        flex: 0 0 auto;
    }

    .sheetBody {
        flex: 1 1 auto;
        padding: 14px;
        display: grid;
        gap: 12px;
        overflow: auto;
        -webkit-overflow-scrolling: touch;
    }

    .sheetFooter {
        padding: 14px;
        border-top: 1px solid #eee;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        flex: 0 0 auto;
    }

    .sheetFooter button {
        flex: 1;
        height: 48px;
        min-width: 160px;
        border-radius: 12px;
    }

    .fieldLabel {
        font-size: 12px;
        color: #334155;
        font-weight: 900;
        margin-bottom: 6px;
    }

    .numWrap {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
        width: 100%;
    }

    .numBtn {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        border: 1px solid #ddd;
        background: #fff;
        font-weight: 1000;
        font-size: 18px;
        cursor: pointer;
    }

    .sheet .select,
    .sheet .input {
        width: 100%;
        padding: 14px 14px;
        font-size: 16px;
        border-radius: 12px;
    }

    #qty {
        width: 140px !important;
        font-size: 18px;
        height: 48px;
        text-align: center;
        border-radius: 12px;
    }

    textarea.input {
        resize: vertical;
        min-height: 90px;
        line-height: 1.4;
    }

    /* PC版では viewSwitch 非表示（元の仕様維持） */
    .viewSwitch {
        display: none !important;
    }

    /* ✅ 機能解説ポップアップ */
    .helpPopupBg {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, .45);
        z-index: 9999;
        padding: 16px;
    }

    .helpPopup {
        width: min(520px, 92vw);
        background: #fff;
        border-radius: 16px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        padding: 16px;
    }

    .helpPopupHead {
        font-weight: 1000;
        margin-bottom: 8px;
    }

    .helpPopupBody {
        color: #0f172a;
        font-size: 14px;
        white-space: pre-line;
    }

    .helpPopupActions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 14px;
    }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="pageWrap">
        <div class="layoutGrid">

            <?php
            // タブのラベルに出す数値は「表示用」なので、既存ロジックに影響しない
            $tabBadgeDraft = (int)$draftCount;

            // 履歴タブ：確定+未確定の合計を表示（確定忘れ把握のため）
            $tabBadgeHistTotal = (int)$histAllYenTotal;

            // ランキングタブ：#1（確定）の合計（無いなら0）
            $tabBadgeRankTop = 0;
            if (!empty($histRankRowsConfirmed) && isset($histRankRowsConfirmed[0]['total_yen'])) {
                $tabBadgeRankTop = (int)$histRankRowsConfirmed[0]['total_yen'];
            }
            ?>

            <!-- ✅ UI追加：タブ（3つ） -->
            <div class="tabsBar">
                <div class="tabs" role="tablist" aria-label="キャッシュバック画面タブ">
                <button type="button" class="tabBtn tabBtnActive" data-tab="main" role="tab" aria-selected="true"
                    data-help="入力画面（当日一覧）タブに切り替えます。">
                    入力 / 当日一覧 <small>未確定 <?= (int)$tabBadgeDraft ?> 件</small>
                </button>
                <button type="button" class="tabBtn" data-tab="hist" role="tab" aria-selected="false"
                    data-help="履歴タブに切り替えます。過去の集計を確認できます。">
                    履歴 <small>合計 <?= number_format((int)$tabBadgeHistTotal) ?> 円</small>
                </button>
                <button type="button" class="tabBtn" data-tab="rank" role="tab" aria-selected="false"
                    data-help="キャストランキングタブに切り替えます。">
                    キャストランキング <small>#1 <?= number_format((int)$tabBadgeRankTop) ?> 円</small>
                </button>
                </div>
            </div>

            <div class="tabPanes">

                <!-- ✅ タブ1：メイン -->
                <div class="tabPane tabPaneActive" data-pane="main" role="tabpanel">
                    <div class="mainPanel">
                        <div class="leftBigBox">

                            <?php if ($noticeWarning !== ''): ?>
                            <div class="warn" style="margin-bottom:12px;"><?= h($noticeWarning) ?></div>
                            <?php endif; ?>

                            <?php if (!$hasEmployees): ?>
                            <div class="warn" style="margin-bottom:12px;">※ この日は従業員（出勤者）がいないため、登録はできません（表示のみ）。</div>
                            <?php endif; ?>

                            <!-- ① 日時 -->
                            <div class="card">
                                <div class="cardHead">
                                    <div class="stepTitle">① 日時</div>
                                </div>

                                <?php
                                $qs = $_GET;
                                $qs['store_id'] = (string)($qs['store_id'] ?? $storeId ?? '');
                                $qs['date'] = (string)($qs['date'] ?? $businessDate ?? '');
                                $qs['sp'] = '1';
                                unset($qs['pc']);
                                $qs['staff'] = '1';
                                $spUrl = '/admin/back_events_sp.php' . ($qs ? ('?' . http_build_query($qs)) : '');
                                ?>

                                <form method="get" class="topForm">
                                    <div class="topLeft">
                                        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                                        <input class="input" type="date" name="date" value="<?= h($businessDate) ?>" />
                                        <button class="btn" type="submit" data-help="選択した店舗・日付で画面を更新します。">表示</button>
                                    </div>

                                    <div class="topRight">
                                        <button class="btn btnCompact" type="button"
                                            onclick="openSpAndCopy(event, '<?= h($spUrl) ?>')"
                                            data-help="スタッフ用のURLを発行し、コピーします。">
                                            スマホURLコピー
                                        </button>
                                        <div class="topNote">※ スタッフ用URLの有効期限は24時間です。</div>
                                    </div>

                                    <!-- サマリー（見た目のみ整理） -->
                                    <div class="topBadges">
                                        <span class="badge"><?= $strictWorkFilter ? '出勤者' : '対象キャスト' ?>
                                            <b><?= (int)$employeeCount ?></b>名</span>
                                        <?php if (!$strictWorkFilter): ?>
                                        <span class="small">（※ 出勤判定ができないDB構成のため、全員表示の人数です）</span>
                                        <?php endif; ?>

                                        <?php foreach ($eventTypes as $t): ?>
                                        <?php
                                            $k = (string)$t['type_key'];
                                            $label = (string)$t['type_label'];
                                            $q = (int)($sumQtyByType[$k] ?? 0);
                                            ?>
                                        <span class="badge"><?= h($label) ?> <b><?= $q ?></b></span>
                                        <?php endforeach; ?>

                                        <span class="badge">バック合計 <b><?= number_format((int)$sumAmountTotal) ?></b>
                                            円</span>
                                        <span class="badge">ボーナス合計 <b><?= number_format((int)$bonusTotal) ?></b>
                                            円</span>
                                        <span class="badge">未確定 <b><?= (int)$draftCount ?></b> 件</span>
                                    </div>

                                    <?php if ($draftCount > 0): ?>
                                    <div class="draftBox small" style="grid-column: 1 / -1;">
                                        「保存（仮）」は <b>未確定（下書き）</b> として保存されます。<br>
                                        確定一覧には出ません。下の「未確定一覧」に表示されます。
                                    </div>
                                    <?php endif; ?>
                                </form>
                            </div>

                            <!-- ② バックを登録する -->
                            <div class="card">
                                <div class="cardHead">
                                    <div class="stepTitle">② キャッシュバックを登録</div>
                                </div>
                                <div class="divider"></div>

                                <div class="bigBtns">
                                    <?php foreach ($eventTypes as $t): ?>
                                    <button class="btn" type="button" data-type="<?= h($t['type_key']) ?>"
                                        data-help="<?= h($t['type_label']) ?>の入力シートを開きます。">
                                        ＋ <?= h($t['type_label']) ?>
                                        <small>タップして登録</small>
                                    </button>
                                    <?php endforeach; ?>

                                    <button class="btn btnOther" type="button" onclick="openBonus()"
                                        data-help="その他（ボーナス/調整）の入力シートを開きます。">
                                        ＋ その他
                                        <small>ボーナス/調整</small>
                                    </button>
                                </div>

                                <div class="divider"></div>

                                <div class="row" style="justify-content:space-between;gap:10px;">
                                    <a class="btn" href="/admin/back_event_types.php?store_id=<?= (int)$storeId ?>"
                                        style="padding:10px 12px;font-size:12px;border-radius:12px;"
                                        data-help="項目（イベント種別）の追加や編集画面を開きます。">
                                        項目追加/編集
                                    </a>
                                </div>
                            </div>

                            <!-- ③ 一覧を見る（確定 / 未確定 / その他） -->
                            <div class="card">
                                <div class="cardHead">
                                    <div class="stepTitle">③ 一覧を見る</div>
                                </div>
                                <div class="divider"></div>

                                <!-- ✅ 確定のみ一覧 -->
                                <div class="tableWrapConfirmed" style="margin-top:0;">
                                    <div class="sectionTitle">インセンティブ（確定 / <?= h($businessDate) ?>）</div>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>種別</th>
                                                <th>キャスト</th>
                                                <th>本数/回数</th>
                                                <th>金額</th>
                                                <th>メモ</th>
                                                <th style="width:220px;">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($rows)): ?>
                                            <tr>
                                                <td colspan="6" style="color:#666;">確定データがありません</td>
                                            </tr>
                                            <?php endif; ?>

                                            <?php foreach ($rows as $r): ?>
                                            <?php
                                                $etype = (string)$r['event_type'];
                                                $etypeLabel = $eventTypeLabelMap[$etype] ?? $etype;

                                                $memo = '';
                                                $editDrinkKind = '';
                                                $editMemo = '';
                                                $editTypeItemId = 0;
                                                $editTypeItemLabel = '';
                                                if (!empty($r['meta_json'])) {
                                                    $j = json_decode((string)$r['meta_json'], true);
                                                    if (is_array($j)) {
                                                        if (!empty($j['memo'])) {
                                                            $memo = (string)$j['memo'];
                                                            $editMemo = (string)$j['memo'];
                                                        }
                                                        if (!empty($j['drink_kind'])) {
                                                            $editDrinkKind = (string)$j['drink_kind'];
                                                            $memo = '種別:' . (string)$j['drink_kind'] . ($memo ? ' / ' . $memo : '');
                                                        }
                                                        if (!empty($j['type_item_id'])) {
                                                            $editTypeItemId = (int)$j['type_item_id'];
                                                        }
                                                        if (!empty($j['type_item_label'])) {
                                                            $editTypeItemLabel = (string)$j['type_item_label'];
                                                            $memo = '種別:' . (string)$j['type_item_label'] . ($memo ? ' / ' . $memo : '');
                                                        }
                                                    }
                                                }

                                                $jsPayload = [
                                                    'id' => (int)$r['id'],
                                                    'event_type' => $etype,
                                                    'employee_id' => (int)$r['employee_id'],
                                                    'quantity' => (int)$r['quantity'],
                                                    'amount_yen' => (int)$r['amount_yen'],
                                                    'drink_kind' => $editDrinkKind,
                                                    'type_item_id' => $editTypeItemId,
                                                    'memo' => $editMemo,
                                                ];
                                                $jsJson = json_encode($jsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                                ?>
                                            <tr>
                                                <td><span
                                                        class="eventLabel <?= h($etype) ?>"><?= h($etypeLabel) ?></span>
                                                </td>
                                                <td class="castCell"><?= h((string)$r['display_name']) ?></td>
                                                <td class="qtyCell"><?= (int)$r['quantity'] ?></td>
                                                <td class="yenCell"><?= number_format((int)$r['amount_yen']) ?>円</td>
                                                <td class="memoCell"><?= h($memo) ?></td>
                                                <td class="row actionCell" style="gap:8px;">
                                                    <button class="btn editIconBtn" type="button"
                                                        onclick='openEditFromRow(<?= $jsJson ?>)'
                                                        data-help="この行を編集します。">✏️</button>
                                                    <form method="post" action="/admin/back_event_delete.php"
                                                        style="margin:0;" onsubmit="return confirm('削除しますか？');">
                                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                        <input type="hidden" name="return" value="<?= h($returnUrl) ?>">
                                                        <button class="btn editIconBtn" type="submit"
                                                            data-help="この行を削除します。">🗑️</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div style="height:14px;"></div>

                                <!-- ✅ 未確定（下書き）一覧 -->
                                <div class="tableWrapDraft">
                                    <div class="sectionTitle">未確定一覧</div>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>状態</th>
                                                <th>種別</th>
                                                <th>キャスト</th>
                                                <th>本数/回数</th>
                                                <th>金額</th>
                                                <th>メモ</th>
                                                <th style="width:220px;">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($draftRows)): ?>
                                            <tr>
                                                <td colspan="7" style="color:#666;">未確定データがありません</td>
                                            </tr>
                                            <?php endif; ?>

                                            <?php foreach ($draftRows as $r): ?>
                                            <?php
                                                $status = (string)($r['status'] ?? '');
                                                if ($status === '') $status = 'draft';

                                                $etype = (string)$r['event_type'];
                                                $etypeLabel = $eventTypeLabelMap[$etype] ?? $etype;

                                                $memo = '';
                                                $editDrinkKind = '';
                                                $editMemo = '';
                                                if (!empty($r['meta_json'])) {
                                                    $j = json_decode((string)$r['meta_json'], true);
                                                    if (is_array($j)) {
                                                        if (!empty($j['memo'])) {
                                                            $memo = (string)$j['memo'];
                                                            $editMemo = (string)$j['memo'];
                                                        }
                                                        if (!empty($j['drink_kind'])) {
                                                            $editDrinkKind = (string)$j['drink_kind'];
                                                            $memo = '種別:' . (string)$j['drink_kind'] . ($memo ? ' / ' . $memo : '');
                                                        }
                                                    }
                                                }

                                                $jsPayload = [
                                                    'id' => (int)$r['id'],
                                                    'event_type' => $etype,
                                                    'employee_id' => (int)$r['employee_id'],
                                                    'quantity' => (int)$r['quantity'],
                                                    'amount_yen' => (int)$r['amount_yen'],
                                                    'drink_kind' => $editDrinkKind,
                                                    'memo' => $editMemo,
                                                ];
                                                $jsJson = json_encode($jsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                                                $statusLabelMap = [
                                                    'draft' => '未確定',
                                                    'confirmed' => '確定',
                                                ];
                                                ?>
                                            <tr>
                                                <td>
                                                    <span class="badge" style="border-style:dashed;">
                                                        <?= h($statusLabelMap[$status] ?? $status) ?>
                                                    </span>
                                                </td>
                                                <td><span
                                                        class="eventLabel <?= h($etype) ?>"><?= h($etypeLabel) ?></span>
                                                </td>
                                                <td class="castCell"><?= h((string)$r['display_name']) ?></td>
                                                <td class="qtyCell"><?= (int)$r['quantity'] ?></td>
                                                <td class="yenCell"><?= number_format((int)$r['amount_yen']) ?>円</td>
                                                <td class="memoCell"><?= h($memo) ?></td>
                                                <td class="row actionCell" style="gap:8px;">
                                                    <form method="post" action="/admin/back_event_confirm.php"
                                                        style="margin:0;" onsubmit="return confirm('この1件を確定しますか？');">
                                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                        <input type="hidden" name="return" value="<?= h($returnUrl) ?>">
                                                        <button class="btn" type="submit"
                                                            data-help="この行を確定にします。">確定</button>
                                                    </form>
                                                    <button class="btn editIconBtn" type="button"
                                                        onclick='openEditFromRow(<?= $jsJson ?>)'
                                                        data-help="この行を編集します。">✏️</button>
                                                    <form method="post" action="/admin/back_event_delete.php"
                                                        style="margin:0;" onsubmit="return confirm('削除しますか？');">
                                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                        <input type="hidden" name="return" value="<?= h($returnUrl) ?>">
                                                        <button class="btn editIconBtn" type="submit"
                                                            data-help="この行を削除します。">🗑️</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div style="height:14px;"></div>

                                <!-- その他（bonus） -->
                                <div class="tableWrapConfirmed">
                                    <div class="sectionTitle">その他（<?= h($businessDate) ?>）</div>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>キャスト</th>
                                                <th>ボーナス</th>
                                                <th style="width:160px;">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($bonusRows)): ?>
                                            <tr>
                                                <td colspan="3" style="color:#666;">まだ登録がありません</td>
                                            </tr>
                                            <?php endif; ?>

                                            <?php foreach ($bonusRows as $br): ?>
                                            <tr>
                                                <td class="castCell"><?= h((string)($br['display_name'] ?? '')) ?></td>
                                                <td class="yenCell"><?= number_format((int)($br['bonus_yen'] ?? 0)) ?>円</td>
                                                <td class="row actionCell" style="gap:8px;">
                                                    <button class="btn" type="button"
                                                        onclick="openBonusEdit(<?= (int)($br['employee_id'] ?? 0) ?>, <?= (int)($br['bonus_yen'] ?? 0) ?>)"
                                                        data-help="ボーナスの内容を編集します。">編集</button>
                                                    <form method="post" style="margin:0;"
                                                        onsubmit="return confirm('削除しますか？');">
                                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                                        <input type="hidden" name="action" value="delete_bonus">
                                                        <input type="hidden" name="id"
                                                            value="<?= (int)($br['id'] ?? 0) ?>">
                                                        <button class="btn" type="submit"
                                                            data-help="このボーナスを削除します。">削除</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                            </div><!-- /card ③ -->

                        </div>
                    </div>
                </div>

                <!-- ✅ タブ2：履歴 -->
                <div class="tabPane" data-pane="hist" role="tabpanel">
                    <div class="rightPanel">
                        <details class="histBox" open>
                            <summary>
                                <span>キャッシュバック履歴</span>
                                <span class="histMeta"><?= h($histFrom) ?> 〜 <?= h($histTo) ?></span>
                            </summary>

                            <!-- ✅ 月プルダウン（過去1年） / 未選択=直近30日 -->
                            <div class="histTopBar">
                                <div class="histModePill">
                                    表示モード:
                                    <?php if ($histMode === 'month'): ?>
                                    <b>月別</b>
                                    <?php else: ?>
                                    <b>直近30日</b>
                                    <?php endif; ?>
                                </div>

                                <div class="histMonthSelectWrap">
                                    <select class="select" id="histMonthSelect">
                                        <option value="">（直近30日）</option>
                                        <?php foreach ($histMonthOptions as $op): ?>
                                        <option value="<?= h($op['value']) ?>"
                                            <?= ($histMode === 'month' && $histMonth === $op['value']) ? 'selected' : '' ?>>
                                            <?= h($op['label']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <?php if ($histAllCountDraft > 0 || $histAllYenDraft > 0): ?>
                            <div class="danger" style="margin-top:10px;">
                                ⚠ 未確定（確定忘れ候補）が <?= (int)$histAllCountDraft ?> 件 /
                                <?= number_format((int)$histAllYenDraft) ?>円 あります。
                                日別表の「未確定」列でどの日に残っているか確認できます。
                            </div>
                            <?php endif; ?>

                            <!-- ✅ 確定/未確定 を分離（見える化） -->
                            <div class="histTotalsGrid">
                                <div class="histTotalCard">
                                    <div class="k">合計（確定 + 未確定）</div>
                                    <div class="v"><?= number_format((int)$histAllYenTotal) ?>円</div>
                                    <div class="sub">件数: <?= (int)($histAllCountConfirmed + $histAllCountDraft) ?> 件
                                    </div>
                                </div>
                                <div class="histTotalCard" style="border-color:#111;">
                                    <div class="k">確定（status=confirmed）</div>
                                    <div class="v"><?= number_format((int)$histAllYenConfirmed) ?>円</div>
                                    <div class="sub">件数: <?= (int)$histAllCountConfirmed ?> 件</div>
                                </div>
                                <div class="histTotalCard" style="border-color:var(--dangerBd);">
                                    <div class="k">未確定（status NULL / !=confirmed）</div>
                                    <div class="v"><?= number_format((int)$histAllYenDraft) ?>円</div>
                                    <div class="sub">件数: <?= (int)$histAllCountDraft ?> 件</div>
                                </div>
                            </div>

                            <div style="margin-top:12px; padding-top:10px;">
                                <table class="rankTable">
                                    <thead>
                                        <tr>
                                            <th>種別</th>
                                            <th class="yenCell">確定</th>
                                            <th class="yenCell">未確定</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($eventTypes)): ?>
                                        <tr>
                                            <td colspan="3" style="color:#666;">項目が未設定です</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($eventTypes as $t): ?>
                                        <?php
                                                $k = (string)$t['type_key'];
                                                $label = (string)$t['type_label'];
                                                $yenC = (int)($histTypeTotalsConfirmed[$k]['yen'] ?? 0);
                                                $yenD = (int)($histTypeTotalsDraft[$k]['yen'] ?? 0);
                                                ?>
                                        <tr>
                                            <td><span class="eventLabel <?= h($k) ?>"><?= h($label) ?></span></td>
                                            <td class="yenCell"><?= number_format($yenC) ?>円</td>
                                            <td class="yenCell"><?= number_format($yenD) ?>円</td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div style="margin-top:12px; padding-top:10px;">
                                <table class="histDailyTable">
                                    <thead>
                                        <tr>
                                            <th>日付</th>
                                            <th>従業員</th>
                                            <th class="yenCell">確定合計</th>
                                            <th class="yenCell">未確定合計</th>
                                            <th class="yenCell"><?= h($eventTypeLabelMap['nomination'] ?? '指名') ?></th>
                                            <th class="yenCell"><?= h($eventTypeLabelMap['drink_back'] ?? 'ドリンク') ?>
                                            </th>
                                            <th class="yenCell"><?= h($eventTypeLabelMap['escort'] ?? '同伴') ?></th>
                                            <th class="yenCell">その他</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($histDaily)): ?>
                                        <tr>
                                            <td colspan="8" style="color:#666;">期間内のデータがありません</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($histDaily as $d => $v): ?>
                                        <?php
                                                $draftY = (int)$v['draft_yen'];
                                                $rowStyle = ($draftY > 0) ? 'background:#fff1f2;' : '';
                                                $names = array_keys($v['names'] ?? []);
                                                $namesLabel = '-';
                                                if (!empty($names)) {
                                                    $maxNames = 6;
                                                    $slice = array_slice($names, 0, $maxNames);
                                                    $namesLabel = implode(' / ', $slice);
                                                    $remain = count($names) - count($slice);
                                                    if ($remain > 0) $namesLabel .= ' 他' . $remain . '名';
                                                }
                                                ?>
                                        <tr style="<?= h($rowStyle) ?>">
                                            <td><?= h($d) ?></td>
                                            <td><?= h($namesLabel) ?></td>
                                            <td class="yenCell" style="font-weight:1000;">
                                                <?= number_format((int)$v['confirmed_yen']) ?>円</td>
                                            <td class="yenCell"
                                                style="font-weight:1000;color:<?= ($draftY > 0 ? '#9f1239' : '#0f172a') ?>;">
                                                <?= number_format((int)$draftY) ?>円
                                            </td>
                                            <td class="yenCell">
                                                <?= number_format((int)$v['confirmed_nomination_yen']) ?></td>
                                            <td class="yenCell">
                                                <?= number_format((int)$v['confirmed_drink_back_yen']) ?></td>
                                            <td class="yenCell"><?= number_format((int)$v['confirmed_escort_yen']) ?>
                                            </td>
                                            <td class="yenCell"><?= number_format((int)$v['confirmed_other_yen']) ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>

                                <div class="small" style="margin-top:8px;">
                                    ※ 背景が赤い行は「未確定」が残っている日です（確定忘れの候補）。
                                </div>
                            </div>

                            <div style="margin-top:12px;">
                                <button class="btn" type="button" onclick="setBackEventsTab('main')"
                                    data-help="入力/当日一覧タブに戻ります。">入力/当日一覧へ戻る</button>
                                <button class="btn" type="button" onclick="setBackEventsTab('rank')"
                                    data-help="キャストランキングタブに移動します。">キャストランキングへ</button>
                            </div>
                        </details>
                    </div>
                </div>

                <!-- ✅ タブ3：キャストランキング（派手に） -->
                <div class="tabPane" data-pane="rank" role="tabpanel">
                    <div class="rightPanel">
                        <details class="histBox" open>
                            <summary>
                                <span>👑 キャストランキング</span>
                                <span class="histMeta"><?= h($histFrom) ?> 〜 <?= h($histTo) ?></span>
                            </summary>

                            <!-- ✅ 同じ月プルダウン（履歴と同期表示：UIだけ） -->
                            <div class="histTopBar">
                                <div class="histModePill">
                                    集計期間:
                                    <?php if ($histMode === 'month'): ?>
                                    <b><?= h($histMonth) ?></b>
                                    <?php else: ?>
                                    <b>直近30日</b>
                                    <?php endif; ?>
                                </div>

                                <div class="histMonthSelectWrap">
                                    <select class="select" id="rankMonthSelect">
                                        <option value="">（直近30日）</option>
                                        <?php foreach ($histMonthOptions as $op): ?>
                                        <option value="<?= h($op['value']) ?>"
                                            <?= ($histMode === 'month' && $histMonth === $op['value']) ? 'selected' : '' ?>>
                                            <?= h($op['label']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="small">※ 過去1年分</span>
                                </div>
                            </div>

                            <?php if (!empty($histRankRowsConfirmed)): ?>
                            <?php
                                $top = $histRankRowsConfirmed[0];
                                $topName = (string)($top['display_name'] ?? '');
                                $topTotal = (int)($top['total_yen'] ?? 0);
                                $topNom = (int)($top['nomination_yen'] ?? 0);
                                $topDrink = (int)($top['drink_back_yen'] ?? 0);
                                $topEscort = (int)($top['escort_yen'] ?? 0);
                                $topOther = (int)($top['other_yen'] ?? 0);
                                ?>
                            <div class="rankHero" style="margin-top:10px;">
                                <div class="rankHeroInner">
                                    <div>
                                        <div class="rankMedal">🏆 <b>1位</b> / 確定</div>
                                        <div class="rankName" style="margin-top:8px;"><?= h($topName) ?></div>
                                        <div class="rankBreak">
                                            <span class="chip"><?= h($eventTypeLabelMap['nomination'] ?? '指名') ?>
                                                <?= number_format($topNom) ?>円</span>
                                            <span class="chip"><?= h($eventTypeLabelMap['drink_back'] ?? 'ドリンク') ?>
                                                <?= number_format($topDrink) ?>円</span>
                                            <span class="chip"><?= h($eventTypeLabelMap['escort'] ?? '同伴') ?>
                                                <?= number_format($topEscort) ?>円</span>
                                            <span class="chip">その他 <?= number_format($topOther) ?>円</span>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="small" style="color:rgba(255,255,255,.78);font-weight:900;">合計（確定）
                                        </div>
                                        <div class="rankAmount"><?= number_format($topTotal) ?>円</div>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="warn" style="margin-top:10px;">確定対象データがありません</div>
                            <?php endif; ?>

                            <?php if (!empty($histRankRowsDraft)): ?>
                            <div class="danger" style="margin-top:12px;">
                                ⚠ 未確定（確定忘れ候補）があります。上位に出ている人ほど「未確定の金額が大きい」です。
                            </div>
                            <?php endif; ?>

                            <div style="margin-top:12px; padding-top:10px;">
                                <div class="rightSubTitle">確定（<?= h($histFrom) ?> 〜 <?= h($histTo) ?>）</div>
                                <table class="rankTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>キャスト</th>
                                            <th class="yenCell">合計</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($histRankRowsConfirmed)): ?>
                                        <tr>
                                            <td colspan="3" style="color:#666;">ランキング対象データがありません</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php $rankNo = 0; ?>
                                        <?php foreach ($histRankRowsConfirmed as $rr): ?>
                                        <?php $rankNo++; ?>
                                        <tr>
                                            <td><?= $rankNo ?></td>
                                            <td>
                                                <?= h((string)$rr['display_name']) ?>
                                                <div class="small">
                                                    <?= h($eventTypeLabelMap['nomination'] ?? '指名') ?>
                                                    <?= number_format((int)$rr['nomination_yen']) ?> /
                                                    <?= h($eventTypeLabelMap['drink_back'] ?? 'ドリンク') ?>
                                                    <?= number_format((int)$rr['drink_back_yen']) ?> /
                                                    <?= h($eventTypeLabelMap['escort'] ?? '同伴') ?>
                                                    <?= number_format((int)$rr['escort_yen']) ?> /
                                                    その他 <?= number_format((int)$rr['other_yen']) ?>
                                                </div>
                                            </td>
                                            <td class="yenCell" style="font-weight:1000;">
                                                <?= number_format((int)$rr['total_yen']) ?>円</td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div style="margin-top:12px; padding-top:10px;">
                                <div class="rightSubTitle">未確定（確定忘れ候補）</div>
                                <table class="rankTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>キャスト</th>
                                            <th class="yenCell">未確定合計</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($histRankRowsDraft)): ?>
                                        <tr>
                                            <td colspan="3" style="color:#666;">未確定データがありません</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php $rankNo2 = 0; ?>
                                        <?php foreach ($histRankRowsDraft as $rr): ?>
                                        <?php $rankNo2++; ?>
                                        <tr style="background:#fff1f2;">
                                            <td><?= $rankNo2 ?></td>
                                            <td>
                                                <?= h((string)$rr['display_name']) ?>
                                                <div class="small">
                                                    <?= h($eventTypeLabelMap['nomination'] ?? '指名') ?>
                                                    <?= number_format((int)$rr['nomination_yen']) ?> /
                                                    <?= h($eventTypeLabelMap['drink_back'] ?? 'ドリンク') ?>
                                                    <?= number_format((int)$rr['drink_back_yen']) ?> /
                                                    <?= h($eventTypeLabelMap['escort'] ?? '同伴') ?>
                                                    <?= number_format((int)$rr['escort_yen']) ?> /
                                                    その他 <?= number_format((int)$rr['other_yen']) ?>
                                                </div>
                                            </td>
                                            <td class="yenCell" style="font-weight:1000;color:#9f1239;">
                                                <?= number_format((int)$rr['total_yen']) ?>円</td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div style="margin-top:12px;">
                                <button class="btn" type="button" onclick="setBackEventsTab('hist')"
                                    data-help="履歴タブへ戻ります。">履歴へ戻る</button>
                                <button class="btn" type="button" onclick="setBackEventsTab('main')"
                                    data-help="入力/当日一覧タブに戻ります。">入力/当日一覧へ戻る</button>
                            </div>
                        </details>
                    </div>
                </div>

            </div><!-- /tabPanes -->

        </div>
    </div>

    <!-- back modal -->
    <div id="overlay" class="overlay" onclick="closeSheet(event)">
        <div class="sheet" onclick="event.stopPropagation()">
            <div class="sheetHeader">
                <div style="font-weight:1000;" id="sheetTitle">入力</div>
                <button class="btn" type="button" onclick="closeSheet()" data-help="入力シートを閉じます。">閉じる</button>
            </div>

            <form id="sheetForm" method="post" action="/admin/back_event_save.php">
                <div class="sheetBody">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
                    <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                    <input type="hidden" name="business_date" value="<?= h($businessDate) ?>">
                    <input type="hidden" name="event_type" id="eventType"
                        value="<?= h((string)($eventTypes[0]['type_key'] ?? 'nomination')) ?>">
                    <input type="hidden" name="return" value="<?= h($returnUrl) ?>">
                    <input type="hidden" name="id" id="editId" value="">

                    <div>
                        <div class="fieldLabel">キャスト</div>
                        <select class="select" name="employee_id" id="employeeSelect" required
                            <?= $hasEmployees ? '' : 'disabled' ?>>
                            <?php if (!$hasEmployees): ?>
                            <option value="0" selected>従業員なし</option>
                            <?php else: ?>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?= (int)$e['id'] ?>"><?= h((string)$e['display_name']) ?></option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <div class="fieldLabel">本数/回数</div>
                        <div class="numWrap">
                            <button class="numBtn" type="button" onclick="stepQty(-1)" data-help="本数/回数を1つ減らします。"
                                <?= $hasEmployees ? '' : 'disabled' ?>>-</button>
                            <input class="input" style="width:120px; text-align:center;" id="qty" name="quantity"
                                type="number" min="1" value="1" required <?= $hasEmployees ? '' : 'disabled' ?>>
                            <button class="numBtn" type="button" onclick="stepQty(1)" data-help="本数/回数を1つ増やします。"
                                <?= $hasEmployees ? '' : 'disabled' ?>>+</button>
                        </div>
                    </div>

                    <div id="typeItemWrap" style="display:none;">
                        <div class="fieldLabel">種別</div>
                        <select class="select" name="type_item_id" id="typeItemSelect"
                            <?= $hasEmployees ? '' : 'disabled' ?>>
                            <option value="">未指定</option>
                        </select>
                    </div>

                    <div id="drinkKindWrap" style="display:none;">
                        <div class="fieldLabel">ドリンク種別</div>
                        <select class="select" name="drink_kind" id="drinkKindSelect"
                            <?= $hasEmployees ? '' : 'disabled' ?>>
                            <option value="">未指定</option>
                            <option value="shot">ショット</option>
                            <option value="champagne">シャンパン</option>
                            <option value="bottle">ボトル</option>
                            <option value="soft">ソフト</option>
                            <option value="other">その他</option>
                        </select>
                    </div>

                    <div>
                        <div class="fieldLabel">金額（任意）</div>
                        <input class="input" id="amountYen" name="amount_yen" type="number" min="0" value=""
                            placeholder="0" inputmode="numeric" <?= $hasEmployees ? '' : 'disabled' ?> />
                    </div>

                    <div>
                        <div class="fieldLabel">メモ（任意）</div>
                        <textarea class="input" id="memoText" name="memo" maxlength="120" rows="3"
                            placeholder="例: VIP卓 / ヒント など" <?= $hasEmployees ? '' : 'disabled' ?>></textarea>
                    </div>
                </div>

                <div class="sheetFooter">
                    <button class="btn btnPrimary" type="submit" <?= $hasEmployees ? '' : 'disabled' ?>
                        data-help="未確定（下書き）として保存します。">保存（仮）</button>
                    <button class="btn" type="button" onclick="setAndSubmitConfirm()" data-help="保存して確定にします（確定一覧へ反映）。"
                        <?= $hasEmployees ? '' : 'disabled' ?>>保存して確定</button>
                </div>
            </form>
        </div>
    </div>

    <!-- bonus modal -->
    <div id="bonusOverlay" class="overlay" onclick="closeBonus(event)">
        <div class="sheet" onclick="event.stopPropagation()">
            <div class="sheetHeader">
                <div style="font-weight:1000;">その他（bonus/調整）</div>
                <button class="btn" type="button" onclick="closeBonus()" data-help="ボーナス入力シートを閉じます。">閉じる</button>
            </div>

            <form id="bonusForm" method="post">
                <div class="sheetBody">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="save_bonus">
                    <input type="hidden" name="business_date" value="<?= h($businessDate) ?>">

                    <div>
                        <div class="fieldLabel">キャスト</div>
                        <select class="select" id="bonusEmployee" name="employee_id" required
                            <?= $hasEmployees ? '' : 'disabled' ?>>
                            <?php if (!$hasEmployees): ?>
                            <option value="0" selected>従業員なし</option>
                            <?php else: ?>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?= (int)$e['id'] ?>"><?= h((string)$e['display_name']) ?></option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <div class="fieldLabel">bonus金額（円）</div>
                        <input class="input" id="bonusYen" name="bonus_yen" type="number" step="1" value="50"
                            inputmode="numeric" <?= $hasEmployees ? '' : 'disabled' ?> />
                    </div>
                </div>

                <div class="sheetFooter">
                    <button class="btn btnPrimary" type="submit" <?= $hasEmployees ? '' : 'disabled' ?>
                        data-help="ボーナスを保存します。">保存</button>
                    <button class="btn" type="button" onclick="closeBonus()" data-help="入力内容を破棄して閉じます。">キャンセル</button>
                </div>
            </form>
        </div>
    </div>

    <div id="helpPopupBg" class="helpPopupBg" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="helpPopup" role="document">
            <div class="helpPopupHead">機能解説</div>
            <div class="helpPopupBody" id="helpPopupBody"></div>
            <div class="helpPopupActions">
                <button class="btn" type="button" id="helpPopupCancel">閉じる</button>
                <button class="btn btnPrimary" type="button" id="helpPopupProceed">実行する</button>
            </div>
        </div>
    </div>

    <script>
    const HAS_EMPLOYEES = <?= $hasEmployees ? 'true' : 'false' ?>;
    const TYPE_LABELS = <?= json_encode($eventTypeLabelMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const TYPE_ITEMS = <?= json_encode($typeItemsByKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const HELP_POPUP_ENABLED = <?= $helpPopupEnabled === '1' ? 'true' : 'false' ?>;
    const helpPopupBg = document.getElementById('helpPopupBg');
    const helpPopupBody = document.getElementById('helpPopupBody');
    const helpPopupCancel = document.getElementById('helpPopupCancel');
    const helpPopupProceed = document.getElementById('helpPopupProceed');
    let helpBypass = false;
    let helpPending = null;

    function openHelpPopup(text, proceedFn) {
        if (!helpPopupBg || !helpPopupBody) return;
        helpPopupBody.textContent = text;
        helpPending = proceedFn || null;
        helpPopupBg.style.display = 'flex';
        helpPopupBg.setAttribute('aria-hidden', 'false');
    }

    function closeHelpPopup() {
        if (!helpPopupBg) return;
        helpPopupBg.style.display = 'none';
        helpPopupBg.setAttribute('aria-hidden', 'true');
        helpPending = null;
    }

    if (helpPopupCancel) {
        helpPopupCancel.addEventListener('click', () => closeHelpPopup());
    }
    if (helpPopupProceed) {
        helpPopupProceed.addEventListener('click', () => {
            const fn = helpPending;
            closeHelpPopup();
            if (fn) fn();
        });
    }

    document.addEventListener('click', (ev) => {
        if (!HELP_POPUP_ENABLED) return;
        const target = ev.target;
        if (!target) return;
        const el = target.closest('button, a.btn');
        if (!el) return;
        if (helpPopupBg && el.closest('#helpPopupBg')) return;
        if (el.disabled || el.classList.contains('btnDisabled')) return;
        if (helpBypass) {
            helpBypass = false;
            return;
        }
        const msg = el.getAttribute('data-help');
        if (!msg) return;
        ev.preventDefault();
        ev.stopPropagation();
        openHelpPopup(msg, () => {
            helpBypass = true;
            el.click();
        });
    });

    // =========================
    // ✅ UIのみ：タブ切替（main/hist/rank）
    // =========================
    function setBackEventsTab(tabName) {
        const btns = document.querySelectorAll('.tabBtn[data-tab]');
        const panes = document.querySelectorAll('.tabPane[data-pane]');
        btns.forEach(b => {
            const isActive = (b.getAttribute('data-tab') === tabName);
            b.classList.toggle('tabBtnActive', isActive);
            b.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        panes.forEach(p => {
            const isActive = (p.getAttribute('data-pane') === tabName);
            p.classList.toggle('tabPaneActive', isActive);
        });
        try {
            localStorage.setItem('be_tab', tabName);
        } catch (e) {}
    }

    function initBackEventsTab() {
        let tab = 'main';
        try {
            const saved = localStorage.getItem('be_tab');
            if (saved === 'main' || saved === 'hist' || saved === 'rank') tab = saved;
        } catch (e) {}
        // URL hashで指定できる（UIだけ）
        if (location.hash === '#hist') tab = 'hist';
        if (location.hash === '#main') tab = 'main';
        if (location.hash === '#rank') tab = 'rank';
        setBackEventsTab(tab);
    }
    document.querySelectorAll('.tabBtn[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.getAttribute('data-tab');
            setBackEventsTab(tab);
        });
    });
    initBackEventsTab();

    // =========================
    // ✅ UIのみ：月切替（過去1年）
    // - hist_month GET を差し替えて再読み込み
    // - タブは履歴/ランキングへ固定して移動（見た目だけ）
    // =========================
    function applyHistMonth(val, toTab) {
        const u = new URL(location.href);
        if (!val) u.searchParams.delete('hist_month');
        else u.searchParams.set('hist_month', val);
        u.hash = (toTab === 'rank') ? '#rank' : '#hist';
        location.href = u.toString();
    }

    const histSel = document.getElementById('histMonthSelect');
    if (histSel) {
        histSel.addEventListener('change', () => {
            applyHistMonth(histSel.value, 'hist');
        });
    }
    const rankSel = document.getElementById('rankMonthSelect');
    if (rankSel) {
        rankSel.addEventListener('change', () => {
            applyHistMonth(rankSel.value, 'rank');
        });
    }

    // =========================
    // モーダル / 入力（既存の挙動維持）
    // =========================
    function closeSheet() {
        document.getElementById('overlay').style.display = 'none';
    }

    function closeBonus() {
        document.getElementById('bonusOverlay').style.display = 'none';
    }

    function openSheet(type) {
        if (!HAS_EMPLOYEES) {
            alert('この日は従業員がいないため登録できません。');
            return;
        }
        document.getElementById('editId').value = '';
        document.getElementById('overlay').style.display = 'flex';
        document.getElementById('eventType').value = type;

        const label = TYPE_LABELS[type] || type;
        document.getElementById('sheetTitle').textContent = label + ' 入力';

        setTypeItems(type, '');
        document.getElementById('qty').value = 1;
        document.getElementById('amountYen').value = '';
        document.getElementById('memoText').value = '';
        document.getElementById('drinkKindSelect').value = '';
        document.getElementById('typeItemSelect').value = '';
    }

    function openEditFromRow(row) {
        if (!HAS_EMPLOYEES) {
            alert('この日は従業員がいないため登録できません。');
            return;
        }
        document.getElementById('overlay').style.display = 'flex';
        document.getElementById('editId').value = String(row.id || '');

        const type = row.event_type || 'nomination';
        document.getElementById('eventType').value = type;

        const label = TYPE_LABELS[type] || type;
        document.getElementById('sheetTitle').textContent = label + '（編集）';

        setTypeItems(type, row.type_item_id || '');

        document.getElementById('employeeSelect').value = String(row.employee_id || '');
        document.getElementById('qty').value = String(row.quantity || 1);

        const ay = parseInt(String(row.amount_yen ?? '0'), 10);
        document.getElementById('amountYen').value = (ay > 0) ? String(ay) : '';

        document.getElementById('memoText').value = String(row.memo || '');
        document.getElementById('drinkKindSelect').value = String(row.drink_kind || '');
    }

    function setTypeItems(type, selectedId) {
        const wrap = document.getElementById('typeItemWrap');
        const sel = document.getElementById('typeItemSelect');
        const drinkWrap = document.getElementById('drinkKindWrap');
        const amountEl = document.getElementById('amountYen');
        if (!wrap || !sel) return;

        const items = (TYPE_ITEMS && TYPE_ITEMS[type]) ? TYPE_ITEMS[type] : [];
        sel.innerHTML = '';
        const opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = '未指定';
        sel.appendChild(opt0);
        items.forEach(it => {
            const opt = document.createElement('option');
            opt.value = String(it.id);
            opt.textContent = it.label;
            opt.setAttribute('data-amount', String(it.amount_yen ?? 0));
            sel.appendChild(opt);
        });
        wrap.style.display = (items.length > 0) ? 'block' : 'none';
        if (drinkWrap) drinkWrap.style.display = (items.length === 0 && type === 'drink_back') ? 'block' : 'none';
        if (amountEl) amountEl.readOnly = (items.length > 0);
        sel.value = selectedId ? String(selectedId) : '';
    }

    const typeItemSelect = document.getElementById('typeItemSelect');
    if (typeItemSelect) {
        typeItemSelect.addEventListener('change', () => {
            const opt = typeItemSelect.selectedOptions[0];
            const amount = opt ? opt.getAttribute('data-amount') : '';
            document.getElementById('amountYen').value = (amount !== null) ? String(amount) : '';
        });
    }

    function stepQty(d) {
        const el = document.getElementById('qty');
        el.value = Math.max(1, (parseInt(el.value || '1', 10) + d));
    }

    function setAndSubmitConfirm() {
        const f = document.getElementById('sheetForm');
        const old = f.querySelector('input[name="do_confirm"]');
        if (old) old.remove();

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'do_confirm';
        hidden.value = '1';
        f.appendChild(hidden);

        f.submit();
    }

    function openBonus() {
        if (!HAS_EMPLOYEES) {
            alert('この日は従業員がいないため登録できません。');
            return;
        }
        document.getElementById('bonusOverlay').style.display = 'flex';
        document.getElementById('bonusYen').value = 50;
    }

    function openBonusEdit(employeeId, bonusYen) {
        if (!HAS_EMPLOYEES) {
            alert('この日は従業員がいないため登録できません。');
            return;
        }
        document.getElementById('bonusOverlay').style.display = 'flex';
        document.getElementById('bonusEmployee').value = String(employeeId);
        document.getElementById('bonusYen').value = String(bonusYen);
    }

    async function openSpAndCopy(ev, _spUrlIgnored) {
        ev.preventDefault();
        ev.stopPropagation();

        const qs = new URLSearchParams(location.search);
        const storeId = Number(qs.get('store_id') || "<?= (int)$storeId ?>");
        const date = (qs.get('date') || "<?= h($businessDate) ?>");

        try {
            const res = await fetch('/admin/share_token_create.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    store_id: storeId,
                    date
                })
            });

            const text = await res.text();
            let data = null;
            try {
                data = JSON.parse(text);
            } catch (_) {}

            if (!res.ok || !data || !data.ok || !data.share_url) {
                throw new Error((data && data.error) ? data.error : ('create failed: ' + res.status));
            }

            const absoluteUrl = new URL(data.share_url, location.origin).toString();

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(absoluteUrl);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = absoluteUrl;
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.focus();
                    ta.select();
                    document.execCommand('copy');
                    ta.remove();
                }

                alert("スタッフ用URL（トークン付き）をコピーしました。\n\nLINEなどでスタッフに共有してください。\n\n" + absoluteUrl);
            } catch (e) {
                prompt("コピーできなかったので、このURLをコピーして共有してください:", absoluteUrl);
            }
            return false;

        } catch (e) {
            alert("スタッフ共有リンクの作成に失敗しました。\n\n" + (e?.message || e));
            return false;
        }
    }

    // ✅ 大ボタンのクリックで openSheet を呼ぶ（元コードに無い場合の保険）
    document.querySelectorAll('[data-type]').forEach(btn => {
        btn.addEventListener('click', () => openSheet(btn.getAttribute('data-type')));
    });
    </script>
</body>

</html>
