<?php
// ✅ ファイル名: /admin/back_events_sp.php
// ✅ 書き込み場所: このファイルを「丸ごと置き換え」

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

// ★ token を先に読んで staffMode を確定（session開始より前でOK）
$shareToken = (string)($_GET['token'] ?? '');
$staffMode = (((string)($_GET['staff'] ?? '') === '1') || $shareToken !== '');

// ===== セッション開始（CSRF用）=====
// staff は STAFFSESSID、管理者は既存の ADMINSESSID（_auth.php側）に寄せる
if ($staffMode) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('STAFFSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();
    }
} else {
    // 管理者側はここで session_name を変えない（_auth.php が ADMINSESSID を使う）
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}


// ===== 強制切替（最優先）=====
$forcePc = ((string)($_GET['pc'] ?? '') === '1');
$forceSp = ((string)($_GET['sp'] ?? '') === '1');

if ($forcePc) {
    $qs = $_GET;
    unset($qs['pc'], $qs['sp']);
    $to = '/admin/back_events.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $to);
    exit;
}

$cookieMode = (string)($_COOKIE['view_mode'] ?? '');
if (!$forceSp && $cookieMode === 'pc') {
    $qs = $_GET;
    $to = '/admin/back_events.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $to);
    exit;
}

// ===== DB =====
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

function tableColumns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
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

// ===== staff token 解決 =====
$tenantId = 0;
$shareToken = (string)($_GET['token'] ?? '');

// ★重要：staff=1 が欠けても token があれば staff とみなす（ここが欠けると form action が壊れる）
$staffMode = (((string)($_GET['staff'] ?? '') === '1') || $shareToken !== '');

$tokenStoreId = 0;
$tokenDate = '';
$tokenRole = 'staff';

// GET（仮）
$storeId = (int)($_GET['store_id'] ?? 0);

// user input date（仮）
$businessDate = (string)($_GET['date'] ?? '');
if ($businessDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
    $businessDate = '';
}

if (!$staffMode) {
    // ===== 管理者ログインモード =====
    require_once __DIR__ . '/_auth.php';
    require_admin_login();

    require_once __DIR__ . '/_tenant_context.php';
    if (!isset($tenantId) || (int)$tenantId <= 0) {
        header('Location: /admin/login.php');
        exit;
    }
    $tenantId = (int)$tenantId;
} else {
    // ===== staffMode =====
    if ($shareToken === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token missing';
        exit;
    }

    // token テーブル名候補（✅ staff_share_tokens を最優先）
    $tokenTables = ['staff_share_tokens', 'staff_tokens', 'share_tokens'];
    $tokenTable = null;
    foreach ($tokenTables as $t) {
        if (tableExists($pdo, $t)) {
            $tokenTable = $t;
            if ($t === 'staff_share_tokens') break;
        }
    }
    if (!$tokenTable) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token table not found (staff_share_tokens/staff_tokens/share_tokens)';
        exit;
    }

    $cols = tableColumns($pdo, $tokenTable);

    $colToken  = isset($cols['token']) ? 'token' : (isset($cols['share_token']) ? 'share_token' : 'token');
    $colTenant = 'tenant_id';
    $colStore  = isset($cols['store_id']) ? 'store_id' : (isset($cols['shop_id']) ? 'shop_id' : 'store_id');

    // ✅ date_ymd を最優先
    $colDate = isset($cols['date_ymd']) ? 'date_ymd'
        : (isset($cols['business_date']) ? 'business_date'
            : (isset($cols['date']) ? 'date'
                : 'date_ymd'));

    $colRole = isset($cols['role']) ? 'role' : '';
    $colExp  = isset($cols['expires_at']) ? 'expires_at' : (isset($cols['expire_at']) ? 'expire_at' : '');

    // token行取得
    $sql = "SELECT * FROM `$tokenTable` WHERE `$colToken` = :tok LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':tok' => $shareToken]);
    $row = $st->fetch();

    if (!$row) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token invalid';
        exit;
    }

    // 期限チェック（列がある場合だけ）
    if ($colExp !== '' && !empty($row[$colExp])) {
        $exp = strtotime((string)$row[$colExp]);
        if ($exp !== false && $exp < time()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'token expired';
            exit;
        }
    }

    $tenantId = (int)($row[$colTenant] ?? 0);
    $tokenStoreId = (int)($row[$colStore] ?? 0);
    $tokenDate = (string)($row[$colDate] ?? '');
    $tokenRole = ($colRole !== '' && isset($row[$colRole])) ? (string)$row[$colRole] : 'staff';

    if ($tenantId <= 0) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token tenant_id invalid';
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tokenDate)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'token date invalid (expected YYYY-MM-DD)';
        exit;
    }

    // store は token を優先
    if ($tokenStoreId > 0) $storeId = $tokenStoreId;

    // ★重要：date が付いて来ていない(token-only)なら tokenDate を採用
    $hasDateParam  = array_key_exists('date', $_GET);
    $hasStoreParam = array_key_exists('store_id', $_GET);

    if (!$hasDateParam || $businessDate === '') {
        $businessDate = $tokenDate;
    }

    // ✅ ここが本丸：
    if (($hasDateParam || $hasStoreParam) && $businessDate !== $tokenDate) {
        $to = '/s/back_events_sp.php?' . http_build_query([
            'token'    => $shareToken,
            'store_id' => $storeId,
            'date'     => $businessDate,
        ]);
        header('Location: ' . $to);
        exit;
    }
}

// ===== ここから先は元の処理（stores, employees, 表示/保存） =====

// CSRF
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
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

// ✅ returnUrl
$returnUrl = (string)($_SERVER['REQUEST_URI'] ?? '/admin/back_events_sp.php');
if ($staffMode) {
    // ★保存後に “元の日付へ戻る” を防ぐ：いま表示している日付も /s に渡す
    $t = (string)($_GET['token'] ?? '');
    if ($t !== '') {
        $returnUrl = '/s/back_events_sp.php?' . http_build_query([
            'token'    => $t,
            'store_id' => $storeId,
            'date'     => $businessDate,
        ]);
    }
}

// stores（この tenant のみ）
$stStores = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=? ORDER BY id");
$stStores->execute([$tenantId]);
$stores = $stStores->fetchAll();
if ($storeId <= 0 && !empty($stores)) $storeId = (int)$stores[0]['id'];

// storeId バリデーション
$storeIds = array_map('intval', array_column($stores, 'id'));
if ($storeId > 0 && !in_array($storeId, $storeIds, true) && !empty($stores)) {
    $storeId = (int)$stores[0]['id'];
}

$noticeWarning = '';
$strictWorkFilter = false;
$workingEmpIds = [];
$employees = [];

// ✅ 追加：出勤者判定が「0件」だった日は、表示は全員に倒しても「入力だけは不可」にするためのフラグ
// 理由：出勤者判定が0件＝DB/打刻/条件の問題の可能性が高く、その日に入力できると誤登録になりやすい
$workDetectZero = false;

try {
    $tpCols = tableColumns($pdo, 'time_punches');

    // ✅ punch_type があるなら clock_in系だけに絞る（退勤だけで混入するのを防ぐ）
    $hasPunchType = (!empty($tpCols) && isset($tpCols['punch_type']));
    $punchTypeCond = $hasPunchType ? " AND tp.punch_type IN ('clock_in','in','open') " : "";

    /**
     * ✅ 方針（SP版と同じ）
     * - business_date 列があるなら、必ず tp.business_date = ? を使う（DATE(punched_at)は使わない）
     * - punch_type があるなら clock_in系のみ
     */
    if (!empty($tpCols) && isset($tpCols['tenant_id'], $tpCols['employee_id'], $tpCols['business_date'])) {

        if (isset($tpCols['store_id'])) {
            $stWorkingEmp = $pdo->prepare("
                SELECT DISTINCT e.id, e.display_name
                FROM time_punches tp
                JOIN employees e ON e.id = tp.employee_id
                WHERE tp.tenant_id = ?
                  AND tp.store_id  = ?
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

        // ✅ 追加：出勤者判定が0件なら「入力不可」にしたい
        if (empty($employees)) {
            $workDetectZero = true;
        }
    } elseif (!empty($tpCols) && isset($tpCols['tenant_id'], $tpCols['employee_id'], $tpCols['punched_at'])) {

        // business_date が無い環境は DATE(punched_at)
        if (isset($tpCols['store_id'])) {
            $stWorkingEmp = $pdo->prepare("
                SELECT DISTINCT e.id, e.display_name
                FROM time_punches tp
                JOIN employees e ON e.id = tp.employee_id
                WHERE tp.tenant_id = ?
                  AND tp.store_id  = ?
                  AND DATE(tp.punched_at) = ?
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
                  AND DATE(tp.punched_at) = ?
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

        // ✅ 追加：出勤者判定が0件なら「入力不可」にしたい
        if (empty($employees)) {
            $workDetectZero = true;
        }
    } else {
        $noticeWarning = '※ time_punches の列構成が想定と違うため「出勤者のみ表示」は無効（全員表示）です。';
        $strictWorkFilter = false;
        $employees = [];
        $workingEmpIds = [];
    }
} catch (Throwable $e) {
    // ✅ ここで「何が原因で落ちているか」を見える化する
    $noticeWarning = '※ 出勤者判定でSQLエラー → 全員表示にフォールバック中: ' . $e->getMessage();
    $strictWorkFilter = false;
    $employees = [];
    $workingEmpIds = [];
}


// フォールバック（全員表示）
if (!$strictWorkFilter) {
    $stEmp = $pdo->prepare("
        SELECT id, display_name
        FROM employees
        WHERE tenant_id=? AND (store_id=? OR store_id IS NULL)
          AND employment_status='active'
        ORDER BY sort_order ASC, id ASC
    ");
    $stEmp->execute([$tenantId, $storeId]);
    $employees = $stEmp->fetchAll();
}

// ✅ 出勤者フィルタが有効なのに 0件だった場合は「全員表示」に倒す（一覧は見えるようにする）
//   ただし、入力（項目ボタン）は押せないようにするため $workDetectZero は維持する
if ($strictWorkFilter && empty($employees)) {
    $noticeWarning = ($noticeWarning ? $noticeWarning . "\n" : '')
        . '';

    // 出勤者フィルタはUI表示上は「全員表示」になるので false に倒す（表示のため）
    $strictWorkFilter = false;
    $workingEmpIds = [];

    $stEmp = $pdo->prepare("
        SELECT id, display_name
        FROM employees
        WHERE tenant_id=? AND (store_id=? OR store_id IS NULL)
          AND employment_status='active'
        ORDER BY sort_order ASC, id ASC
    ");
    $stEmp->execute([$tenantId, $storeId]);
    $employees = $stEmp->fetchAll();
}

// ✅ ここが本丸：入力禁止フラグ
// - 出勤者判定が0件の日は「表示は全員」でも「入力は不可」
// - もともと従業員が0人ならもちろん入力不可
$entryDisabled = ($workDetectZero || empty($employees));

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

// ✅ 既存の $noEmployees を「入力可否」に合わせる（ボタンdisabled・フォームdisabledの判定に使う）
$noEmployees = $entryDisabled;
$employeeCount = is_array($employees) ? count($employees) : 0;

// ==============================
// ✅ back_event_types 取得（ここだけ追加：他機能は一切変更しない）
// ==============================
$eventTypes = [];              // 表示用（ボタン生成）
$eventTypeLabelMap = [];       // key => label（履歴表示用）
$eventTypeMetaMap = [];        // key => {label, hasDrinkKind}
$eventTypeIdMap = [];          // key => id（種別紐付け用）
$eventTypeIdMap = [];          // key => id（種別紐付け用）

try {
    if (tableExists($pdo, 'back_event_types')) {
        $btc = tableColumns($pdo, 'back_event_types');

        // key列候補
        $colKey = isset($btc['event_type']) ? 'event_type'
            : (isset($btc['type_key']) ? 'type_key'
                : (isset($btc['code']) ? 'code'
                    : (isset($btc['key']) ? 'key' : 'event_type')));

        // label列候補
        $colLabel = isset($btc['label']) ? 'label'
            : (isset($btc['name']) ? 'name'
                : (isset($btc['display_name']) ? 'display_name' : 'label'));

        // sort列候補
        $colSort = isset($btc['sort_order']) ? 'sort_order'
            : (isset($btc['sort']) ? 'sort' : '');

        // active列候補（無いなら全件）
        $colActive = isset($btc['is_active']) ? 'is_active'
            : (isset($btc['enabled']) ? 'enabled' : '');

        // store_id があるか（テナント全体 or 店舗別）
        $hasStoreCol = isset($btc['store_id']);

        // 「ドリンク種別」を出すべきか（列がある場合のみ使う。無い場合は key 名に含まれていれば true）
        $colDrinkFlag = isset($btc['has_drink_kind']) ? 'has_drink_kind'
            : (isset($btc['use_drink_kind']) ? 'use_drink_kind'
                : (isset($btc['is_drink']) ? 'is_drink' : ''));

        $w = ["tenant_id = :tenant_id"];
        $params = [':tenant_id' => $tenantId];

        if ($hasStoreCol) {
            // store_id がある場合：店舗に紐づくもの or 共通(NULL/0) も拾う
            $w[] = "(store_id = :store_id OR store_id IS NULL OR store_id = 0)";
            $params[':store_id'] = $storeId;
        }

        if ($colActive !== '') {
            $w[] = "($colActive = 1 OR $colActive = '1')";
        }

        $order = "";
        if ($colSort !== '') $order = "ORDER BY $colSort ASC, id ASC";
        else $order = "ORDER BY id ASC";

        $sql = "SELECT * FROM back_event_types WHERE " . implode(' AND ', $w) . " $order";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rowsType = $st->fetchAll();

        foreach ($rowsType as $tr) {
            $k = (string)($tr[$colKey] ?? '');
            if ($k === '') continue;
            $label = (string)($tr[$colLabel] ?? $k);

            // drink kind 表示条件
            $hasDrinkKind = false;
            if ($colDrinkFlag !== '' && array_key_exists($colDrinkFlag, $tr)) {
                $v = $tr[$colDrinkFlag];
                $hasDrinkKind = ($v === 1 || $v === '1' || $v === true || $v === 'true');
            } else {
                // 列が無い環境でも最低限：キー名に drink が含まれるなら true
                $hasDrinkKind = (stripos($k, 'drink') !== false);
            }

            $eventTypes[] = [
                'key' => $k,
                'label' => $label,
                'hasDrinkKind' => $hasDrinkKind,
            ];
            $eventTypeLabelMap[$k] = $label;
            $eventTypeMetaMap[$k] = ['label' => $label, 'hasDrinkKind' => $hasDrinkKind];
            if (isset($tr['id'])) $eventTypeIdMap[$k] = (int)$tr['id'];
        }
    }
} catch (Throwable $e) {
    // 失敗したら（UIや保存など）他機能を一切壊さない：従来固定にフォールバック
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

// フォールバック（back_event_types が無い/空）
if (empty($eventTypes)) {
    $eventTypes = [
        ['key' => 'nomination', 'label' => '指名', 'hasDrinkKind' => false],
        ['key' => 'drink_back', 'label' => 'ドリンク', 'hasDrinkKind' => true],
        ['key' => 'escort', 'label' => '同伴', 'hasDrinkKind' => false],
    ];
    foreach ($eventTypes as $t) {
        $eventTypeLabelMap[$t['key']] = $t['label'];
        $eventTypeMetaMap[$t['key']] = ['label' => $t['label'], 'hasDrinkKind' => (bool)$t['hasDrinkKind']];
    }
}


// ==============================
// ✅ POST bonus
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_bonus') {
        mustPostCsrf($csrf);

        if ($entryDisabled) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "この日は入力できません（出勤者判定が0件、または従業員がいません）。";
            exit;
        }

        $empId = (int)($_POST['employee_id'] ?? 0);
        $date  = (string)($_POST['business_date'] ?? '');
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

        $dwaCols = tableColumns($pdo, 'daily_wage_adjustments');
        $hasUpdatedAt = isset($dwaCols['updated_at']);
        $hasCreatedAt = isset($dwaCols['created_at']);
        $hasStoreId   = isset($dwaCols['store_id']);

        try {
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
                    ':business_date' => $date
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
                    ':business_date' => $date
                ]);
            }

            $found = $st->fetch();

            if ($found && isset($found['id'])) {
                $set = ['bonus_yen=:bonus_yen'];
                if ($hasUpdatedAt) $set[] = 'updated_at=CURRENT_TIMESTAMP';

                $sql = "
                    UPDATE daily_wage_adjustments
                    SET " . implode(', ', $set) . "
                    WHERE id=:id AND tenant_id=:tenant_id
                    LIMIT 1
                ";
                $upd = $pdo->prepare($sql);
                $upd->execute([
                    ':bonus_yen' => $bonus,
                    ':id' => (int)$found['id'],
                    ':tenant_id' => $tenantId
                ]);
            } else {
                $cols = ['tenant_id', 'employee_id', 'business_date', 'bonus_yen'];
                $vals = [':tenant_id', ':employee_id', ':business_date', ':bonus_yen'];
                $params = [
                    ':tenant_id' => $tenantId,
                    ':employee_id' => $empId,
                    ':business_date' => $date,
                    ':bonus_yen' => $bonus
                ];

                if ($hasStoreId) {
                    $cols[] = 'store_id';
                    $vals[] = ':store_id';
                    $params[':store_id'] = $storeId;
                }
                if ($hasCreatedAt) {
                    $cols[] = 'created_at';
                    $vals[] = 'CURRENT_TIMESTAMP';
                }
                if ($hasUpdatedAt) {
                    $cols[] = 'updated_at';
                    $vals[] = 'CURRENT_TIMESTAMP';
                }

                $insSql = "
                    INSERT INTO daily_wage_adjustments
                      (" . implode(', ', $cols) . ")
                    VALUES
                      (" . implode(', ', $vals) . ")
                ";
                $ins = $pdo->prepare($insSql);
                $ins->execute($params);
            }

            header('Location: ' . $returnUrl);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "save_bonus failed\n\n" . $e->getMessage();
            exit;
        }
    }

    if ($action === 'delete_bonus') {
        mustPostCsrf($csrf);

        if ($entryDisabled) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "この日は入力できません（出勤者判定が0件、または従業員がいません）。";
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo "id invalid";
            exit;
        }

        try {
            $del = $pdo->prepare("DELETE FROM daily_wage_adjustments WHERE id=:id AND tenant_id=:tenant_id LIMIT 1");
            $del->execute([':id' => $id, ':tenant_id' => $tenantId]);
            header('Location: ' . $returnUrl);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "delete_bonus failed\n\n" . $e->getMessage();
            exit;
        }
    }
}

// ==============================
// back_events list
// ==============================
$stList = $pdo->prepare("
    SELECT be.*, e.display_name
    FROM back_events be
    JOIN employees e ON e.id = be.employee_id
    WHERE be.tenant_id=? AND be.store_id=? AND be.business_date=?
    ORDER BY be.status ASC, be.id DESC
");
$stList->execute([$tenantId, $storeId, $businessDate]);
$rows = $stList->fetchAll();

$sum = ['nomination' => 0, 'drink_back' => 0, 'escort' => 0, 'amount_yen' => 0];
foreach ($rows as $r) {
    $etype = (string)$r['event_type'];
    // ✅ sum は既存仕様を壊さない（既存3キーのみカウント）
    if (isset($sum[$etype])) $sum[$etype] += (int)$r['quantity'];
    $sum['amount_yen'] += (int)$r['amount_yen'];
}

// bonus list
$bonusRows = [];
$bonusTotal = 0;
try {
    $hasStoreId2 = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM daily_wage_adjustments")->fetchAll();
        foreach ($c as $col) {
            if (($col['Field'] ?? '') === 'store_id') {
                $hasStoreId2 = true;
                break;
            }
        }
    } catch (Throwable $e) {
    }

    if ($hasStoreId2) {
        $stBonus = $pdo->prepare("
            SELECT d.*, e.display_name
            FROM daily_wage_adjustments d
            JOIN employees e ON e.id = d.employee_id
            WHERE d.tenant_id=? AND d.store_id=? AND d.business_date=?
            ORDER BY d.id DESC
        ");
        $stBonus->execute([$tenantId, $storeId, $businessDate]);
    } else {
        $stBonus = $pdo->prepare("
            SELECT d.*, e.display_name
            FROM daily_wage_adjustments d
            JOIN employees e ON e.id = d.employee_id
            WHERE d.tenant_id=? AND d.business_date=?
            ORDER BY d.id DESC
        ");
        $stBonus->execute([$tenantId, $businessDate]);
    }

    $bonusRows = $stBonus->fetchAll();
    foreach ($bonusRows as $br) $bonusTotal += (int)($br['bonus_yen'] ?? 0);
} catch (Throwable $e) {
    $bonusRows = [];
    $bonusTotal = 0;
}

// ✅ 書き込み場所: 「bonus list」取得ブロックの直後（$bonusTotal を計算した後あたり）に追記
$bonusByEmp = [];
foreach ($bonusRows as $br) {
    $eid = (int)($br['employee_id'] ?? 0);
    if ($eid > 0) {
        // 同一日・同一キャストは基本1件の想定だが、複数あっても「最新（先に取れたもの）」で上書き
        $bonusByEmp[$eid] = (int)($br['bonus_yen'] ?? 0);
    }
}
$bonusByEmpJson = json_encode($bonusByEmp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


// JS用
$eventTypeMetaJson = json_encode($eventTypeMetaMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$typeItemsJson = json_encode($typeItemsByKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <title>キャッシュバック入力（スマホ）</title>
    <style>
    body {
        font-family: system-ui, -apple-system, sans-serif;
        margin: 0;
        background: #f7f7f7;
        color: #111
    }

    .topbar {
        position: sticky;
        top: 0;
        z-index: 50;
        background: rgba(255, 255, 255, .96);
        backdrop-filter: blur(8px);
        border-bottom: 1px solid #eee;
        padding: 10px 12px;
        display: flex;
        gap: 10px;
        align-items: center
    }

    .title {
        font-weight: 900;
        font-size: 14px;
        line-height: 1.1
    }

    .subtitle {
        font-weight: 700;
        font-size: 12px;
        color: #666;
        margin-top: 2px
    }

    .grow {
        flex: 1
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid #ddd;
        background: #fff;
        font-weight: 900;
        text-decoration: none;
        color: #111;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent
    }

    .btnPrimary {
        background: #111;
        color: #fff;
        border-color: #111
    }

    .wrap {
        padding: 12px 12px 90px;
        max-width: 560px;
        margin: 0 auto
    }

    .card {
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 16px;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .04);
        padding: 12px;
        margin-bottom: 12px
    }

    .select,
    .input {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 14px;
        font-size: 16px;
        box-sizing: border-box;
        background: #fff
    }

    .grid2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px
    }

    .grid4 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px
    }

    .badge {
        display: inline-flex;
        padding: 6px 10px;
        border-radius: 999px;
        border: 1px solid #ddd;
        font-weight: 900;
        font-size: 12px;
        background: #fff;
        white-space: nowrap
    }

    .sectionTitle {
        font-weight: 800;
        font-size: 13px;
        letter-spacing: .04em;
        color: #555;
        margin: 8px 0 10px
    }

    .list {
        display: grid;
        gap: 10px
    }

    .item {
        border: 1px solid #eee;
        border-radius: 14px;
        padding: 12px;
        display: grid;
        gap: 8px;
        position: relative
    }

    .itemTop {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px
    }

    .pill {
        display: inline-flex;
        padding: 4px 8px;
        border-radius: 999px;
        border: 1px solid #ddd;
        font-weight: 900;
        font-size: 12px
    }

    .pill.confirmed {
        background: #eaffea;
        border-color: #b8f3b8
    }

    .pill.draft {
        background: #f6f6f6
    }

    .muted {
        color: #666;
        font-size: 12px
    }

    .btnRow {
        display: flex;
        gap: 8px;
        flex-wrap: wrap
    }

    .btnSmall {
        padding: 10px 12px;
        border-radius: 12px;
        font-size: 14px
    }

    .overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .45);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        padding: 14px
    }

    .sheet {
        width: 100%;
        max-width: 560px;
        background: #fff;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
        max-height: 92vh;
        display: flex;
        flex-direction: column
    }

    .sheetHeader {
        padding: 12px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px
    }

    .sheetBody {
        padding: 12px;
        display: grid;
        gap: 10px;
        overflow: auto
    }

    .sheetFooter {
        padding: 12px;
        border-top: 1px solid #eee;
        display: grid;
        gap: 10px
    }

    .fieldLabel {
        font-size: 12px;
        font-weight: 900;
        color: #444;
        margin-bottom: 4px
    }

    .numWrap {
        display: flex;
        gap: 8px;
        align-items: center
    }

    .numBtn {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        border: 1px solid #ddd;
        background: #fff;
        font-weight: 900;
        font-size: 18px;
        cursor: pointer
    }

    .warn {
        background: #fff7e6;
        border: 1px solid #ffd18a;
        padding: 10px 12px;
        border-radius: 12px;
        font-size: 12px;
        color: #6b4a00;
        margin-bottom: 12px;
        white-space: pre-wrap;
    }

    .topForm .select,
    .topForm .input,
    .topForm .btnBig {
        height: 52px;
        padding: 0 16px;
        font-size: 16px;
        border-radius: 16px;
        box-sizing: border-box
    }

    .topForm .btnBig {
        width: 100%
    }

    .topForm input[type="date"] {
        min-height: 52px
    }

    .btn:disabled {
        opacity: .45;
        cursor: not-allowed
    }

    .eventLabel {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        margin-right: 6px;
        line-height: 1.6;
        background: #f3f4f6;
        color: #111;
    }

    .eventLabel.nomination {
        background: #eef4ff;
        color: #2f5cff
    }

    .eventLabel.drink_back {
        background: #fff3e6;
        color: #d97706
    }

    .eventLabel.escort {
        background: #f0fdf4;
        color: #15803d
    }
    </style>
</head>

<body>
    <div class="wrap">
        <?php if ($noticeWarning !== ''): ?>
        <div class="warn"><?= h($noticeWarning) ?></div>
        <?php endif; ?>

        <?php if ($workDetectZero): ?>
        <div class="warn">※ 出勤者判定が0件のため、この日は入力できません（一覧表示のみ）。</div>
        <?php endif; ?>

        <div class="card">
            <?php $topFormAction = ($staffMode ? '/s/back_events_sp.php' : ''); ?>

            <form method="get" class="topForm" action="<?= h($topFormAction) ?>">
                <?php if ($staffMode): ?>
                <input type="hidden" name="token" value="<?= h((string)($_GET['token'] ?? '')) ?>">
                <?php endif; ?>

                <div class="fieldLabel">店舗</div>
                <select class="select" name="store_id" <?= $staffMode ? 'disabled' : '' ?>>
                    <?php foreach ($stores as $s): ?>
                    <?php $sid = (int)$s['id']; ?>
                    <option value="<?= $sid ?>" <?= ($sid === (int)$storeId ? 'selected' : '') ?>>
                        <?= h((string)$s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($staffMode): ?>
                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                <?php endif; ?>

                <div class="grid2" style="margin-top:10px;">
                    <div>
                        <div class="fieldLabel">日付</div>
                        <input class="input" type="date" name="date" value="<?= h($businessDate) ?>" />
                    </div>
                    <div style="display:flex;align-items:flex-end;">
                        <button class="btn btnPrimary btnBig" type="submit">表示</button>
                    </div>
                </div>
            </form>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <span class="badge" style="background:#f0f7ff;border-color:#cfe3ff;color:#2457c5;">
                    <?= $strictWorkFilter ? '出勤者' : '対象キャスト' ?> / <?= (int)$employeeCount ?>人
                </span>

                <?php if (!$strictWorkFilter): ?>
                <span class="muted">（※DB構成の都合で出勤判定できないため全員表示の人数）</span>
                <?php endif; ?>

                <?php if ($workDetectZero): ?>
                <span class="badge" style="background:#fff3f3;border-color:#ffc9c9;color:#c92a2a;">入力不可（出勤者0判定）</span>
                <?php endif; ?>

                <span class="badge">指名 <?= (int)$sum['nomination'] ?></span>
                <span class="badge">ドリンク <?= (int)$sum['drink_back'] ?></span>
                <span class="badge">同伴 <?= (int)$sum['escort'] ?></span>
                <span class="badge">バック <?= number_format((int)$sum['amount_yen']) ?>円</span>
                <span class="badge">ボーナス <?= number_format((int)$bonusTotal) ?>円</span>
            </div>
        </div>

        <div class="card">
            <div class="sectionTitle">項目</div>

            <div class="grid4">
                <?php foreach ($eventTypes as $t): ?>
                <button class="btn" type="button" onclick="openSheet('<?= h((string)$t['key']) ?>')"
                    <?= $noEmployees ? 'disabled' : '' ?>>
                    <?= h((string)$t['label']) ?>
                </button>
                <?php endforeach; ?>

                <button class="btn" type="button" onclick="openBonus()"
                    <?= $noEmployees ? 'disabled' : '' ?>>ボーナス</button>
            </div>
        </div>

        <div class="card">
            <div class="sectionTitle">バック 履歴</div>
            <div class="list">
                <?php if (empty($rows)): ?><div class="muted">まだ登録がありません</div><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <?php
                    $etype = (string)$r['event_type'];
                    $etypeLabel = $eventTypeLabelMap[$etype] ?? ($etype === 'nomination' ? '指名' : ($etype === 'drink_back' ? 'ドリンク' : '同伴/アフター'));

                    $memo = '';
                    if (!empty($r['meta_json'])) {
                        $j = json_decode((string)$r['meta_json'], true);
                        if (is_array($j) && !empty($j['memo'])) $memo = (string)$j['memo'];
                        if (is_array($j) && !empty($j['drink_kind'])) $memo = '種別:' . (string)$j['drink_kind'] . ($memo ? ' / ' . $memo : '');
                    }
                    $isConfirmed = ((string)$r['status'] === 'confirmed');
                    ?>
                <div class="item">
                    <div class="itemTop">
                        <div style="font-weight:900; line-height:1.3;">
                            <div><span class="eventLabel <?= h($etype) ?>"><?= h($etypeLabel) ?></span></div>
                            <div><?= h((string)$r['display_name']) ?></div>
                        </div>
                        <?php
                            $statusLabelMap = [
                                'draft'     => '仮保存',
                                'confirmed' => '確定',
                            ];
                            ?>

                        <span class="pill <?= $isConfirmed ? 'confirmed' : 'draft' ?>">
                            <?= h($statusLabelMap[(string)$r['status']] ?? (string)$r['status']) ?>
                        </span>

                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <span class="badge">回数 <?= (int)$r['quantity'] ?></span>
                        <span class="badge">金額 <?= number_format((int)$r['amount_yen']) ?>円</span>
                    </div>

                    <?php if ($memo !== ''): ?><div class="muted"><?= h($memo) ?></div><?php endif; ?>

                    <div class="btnRow">
                        <?php if (!$isConfirmed): ?>
                        <form method="post" action="/admin/back_event_toggle_confirm.php" style="margin:0;">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <?php if ($staffMode): ?>
                            <input type="hidden" name="staff" value="1">
                            <input type="hidden" name="token" value="<?= h((string)($_GET['token'] ?? '')) ?>">
                            <?php endif; ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="return" value="<?= h($returnUrl) ?>">
                            <button class="btn btnSmall" type="submit">確定</button>
                        </form>

                        <form method="post" action="/admin/back_event_delete.php" style="margin:0;"
                            onsubmit="return confirm('削除しますか？');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <?php if ($staffMode): ?>
                            <input type="hidden" name="staff" value="1">
                            <input type="hidden" name="token" value="<?= h((string)($_GET['token'] ?? '')) ?>">
                            <?php endif; ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="return" value="<?= h($returnUrl) ?>">
                            <button class="btn btnSmall" type="submit">削除</button>
                        </form>

                        <?php else: ?>
                        <span class="muted">確定済</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="sectionTitle">ボーナス 履歴</div>
            <div class="list">
                <?php if (empty($bonusRows)): ?><div class="muted">まだ登録がありません</div><?php endif; ?>
                <?php foreach ($bonusRows as $br): ?>
                <div class="item">
                    <div style="font-weight:900; line-height:1.3;">
                        <div><?= h((string)($br['display_name'] ?? '')) ?></div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <span class="badge">bonus <?= number_format((int)($br['bonus_yen'] ?? 0)) ?>円</span>
                    </div>
                    <div class="btnRow">
                        <button class="btn btnSmall" type="button"
                            onclick="openBonusEdit(<?= (int)($br['employee_id'] ?? 0) ?>, <?= (int)($br['bonus_yen'] ?? 0) ?>)"
                            <?= $noEmployees ? 'disabled' : '' ?>>編集</button>
                        <form method="post" style="margin:0;" onsubmit="return confirm('削除しますか？');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="delete_bonus">
                            <input type="hidden" name="id" value="<?= (int)($br['id'] ?? 0) ?>">
                            <button class="btn btnSmall" type="submit" <?= $noEmployees ? 'disabled' : '' ?>>削除</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- modals -->
    <div id="overlay" class="overlay" onclick="closeSheet(event)">
        <div class="sheet" onclick="event.stopPropagation()">
            <div class="sheetHeader">
                <div style="font-weight:900;" id="sheetTitle">入力</div>
                <button class="btn" type="button" onclick="closeSheet()">閉じる</button>
            </div>

            <form id="sheetForm" method="post" action="/admin/back_event_save.php">
                <?php if ($staffMode): ?>
                <input type="hidden" name="staff" value="1">
                <input type="hidden" name="token" value="<?= h((string)($_GET['token'] ?? '')) ?>">
                <?php endif; ?>

                <div class="sheetBody">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
                    <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                    <input type="hidden" name="business_date" value="<?= h($businessDate) ?>">
                    <input type="hidden" name="event_type" id="eventType"
                        value="<?= h((string)$eventTypes[0]['key']) ?>">
                    <input type="hidden" name="return" value="<?= h($returnUrl) ?>">

                    <div>
                        <div class="fieldLabel">キャスト</div>
                        <select class="select" name="employee_id" id="employeeSelect"
                            <?= $noEmployees ? 'disabled' : 'required' ?>>
                            <?php if ($noEmployees): ?>
                            <option value="" selected disabled>入力不可</option>
                            <?php else: ?>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?= (int)$e['id'] ?>"><?= h((string)$e['display_name']) ?></option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if ($noEmployees): ?><div class="muted">※ この日は入力できません（出勤者判定が0件、または従業員がいません）</div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="fieldLabel">本数/回数</div>
                        <div class="numWrap">
                            <button class="numBtn" type="button" onclick="stepQty(-1)"
                                <?= $noEmployees ? 'disabled' : '' ?>>-</button>
                            <input class="input" style="width:120px;text-align:center;" id="qty" name="quantity"
                                type="number" min="1" value="1" <?= $noEmployees ? 'disabled' : 'required' ?>>
                            <button class="numBtn" type="button" onclick="stepQty(1)"
                                <?= $noEmployees ? 'disabled' : '' ?>>+</button>
                        </div>
                        <div class="muted">※ 回数で管理</div>
                    </div>

                    <div id="typeItemWrap" style="display:none;">
                        <div class="fieldLabel">種別</div>
                        <select class="select" name="type_item_id" id="typeItemSelect"
                            <?= $noEmployees ? 'disabled' : '' ?>>
                            <option value="">未指定</option>
                        </select>
                    </div>

                    <div id="drinkKindWrap" style="display:none;">
                        <div class="fieldLabel">ドリンク種別</div>
                        <select class="select" name="drink_kind" <?= $noEmployees ? 'disabled' : '' ?>>
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
                        <input class="input" id="amountYen" name="amount_yen" type="number" min="0" placeholder="0"
                            inputmode="numeric" <?= $noEmployees ? 'disabled' : '' ?> />
                    </div>

                    <div>
                        <div class="fieldLabel">メモ（任意）</div>
                        <input class="input" name="memo" maxlength="120" placeholder="例: VIP卓 / ヒント など"
                            <?= $noEmployees ? 'disabled' : '' ?> />
                    </div>
                </div>

                <div class="sheetFooter">
                    <button class="btn btnPrimary" type="submit" <?= $noEmployees ? 'disabled' : '' ?>>保存（仮）</button>
                    <button class="btn" type="button" onclick="setAndSubmitConfirm()"
                        <?= $noEmployees ? 'disabled' : '' ?>>保存して確定</button>
                </div>
            </form>
        </div>
    </div>

    <div id="bonusOverlay" class="overlay" onclick="closeBonus(event)">
        <div class="sheet" onclick="event.stopPropagation()">
            <div class="sheetHeader">
                <div style="font-weight:900;">その他（bonus/調整）</div>
                <button class="btn" type="button" onclick="closeBonus()">閉じる</button>
            </div>

            <form id="bonusForm" method="post">
                <div class="sheetBody">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="save_bonus">
                    <input type="hidden" name="business_date" value="<?= h($businessDate) ?>">

                    <div>
                        <div class="fieldLabel">キャスト</div>
                        <select class="select" id="bonusEmployee" name="employee_id"
                            <?= $noEmployees ? 'disabled' : 'required' ?>>
                            <?php if ($noEmployees): ?>
                            <option value="" selected disabled>入力不可</option>
                            <?php else: ?>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?= (int)$e['id'] ?>"><?= h((string)$e['display_name']) ?></option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>

                        <div class="muted">※ 同一日・同一キャストは上書き保存</div>
                        <?php if ($noEmployees): ?><div class="muted">※ この日は入力できません（出勤者判定が0件、または従業員がいません）</div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="fieldLabel">bonus金額（円）</div>
                        <input class="input" id="bonusYen" name="bonus_yen" type="number" step="1" value="50"
                            inputmode="numeric" <?= $noEmployees ? 'disabled' : '' ?> />
                    </div>
                </div>

                <div class="sheetFooter">
                    <button class="btn btnPrimary" type="submit" <?= $noEmployees ? 'disabled' : '' ?>>保存</button>
                    <button class="btn" type="button" onclick="closeBonus()">キャンセル</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // ✅ back_event_types メタ（label / hasDrinkKind）
    const EVENT_TYPES = <?= $eventTypeMetaJson ?: '{}' ?>;
    const TYPE_ITEMS = <?= $typeItemsJson ?: '{}' ?>;

    // ✅ 追加：従業員ごとの当日bonus（履歴があればここに入る）
    const BONUS_BY_EMP = <?= $bonusByEmpJson ?: '{}' ?>;

    function setBonusDefaultByEmployee(employeeId) {
        const v = BONUS_BY_EMP[String(employeeId)];
        document.getElementById('bonusYen').value = (v !== undefined && v !== null) ? String(v) : '';
    }

    function openSheet(type) {
        if (<?= $noEmployees ? 'true' : 'false' ?>) {
            alert('この日は入力できません（出勤者判定が0件、または従業員がいません）。');
            return;
        }
        document.getElementById('overlay').style.display = 'flex';
        document.getElementById('eventType').value = type;

        const meta = EVENT_TYPES[type] || {
            label: type,
            hasDrinkKind: false
        };
        document.getElementById('sheetTitle').textContent = meta.label + ' 入力';

        setTypeItems(type);
        document.getElementById('qty').value = 1;
    }

    function closeSheet() {
        document.getElementById('overlay').style.display = 'none';
    }

    function stepQty(d) {
        const el = document.getElementById('qty');
        el.value = Math.max(1, (parseInt(el.value || '1', 10) + d));
    }

    function setAndSubmitConfirm() {
        const f = document.getElementById('sheetForm');
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'do_confirm';
        hidden.value = '1';
        f.appendChild(hidden);
        f.submit();
    }

    function openBonus() {
        if (<?= $noEmployees ? 'true' : 'false' ?>) {
            alert('この日は入力できません（出勤者判定が0件、または従業員がいません）。');
            return;
        }
        document.getElementById('bonusOverlay').style.display = 'flex';

        const empSel = document.getElementById('bonusEmployee');
        const empId = empSel ? empSel.value : '';
        setBonusDefaultByEmployee(empId);
    }

    function openBonusEdit(employeeId, bonusYen) {
        if (<?= $noEmployees ? 'true' : 'false' ?>) {
            alert('この日は入力できません（出勤者判定が0件、または従業員がいません）。');
            return;
        }
        document.getElementById('bonusOverlay').style.display = 'flex';
        document.getElementById('bonusEmployee').value = String(employeeId);
        document.getElementById('bonusYen').value = String(bonusYen);
    }

    function setTypeItems(type) {
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
        if (drinkWrap) {
            const meta = EVENT_TYPES[type] || {
                label: type,
                hasDrinkKind: false
            };
            drinkWrap.style.display = (items.length === 0 && meta.hasDrinkKind) ? 'block' : 'none';
        }
        if (amountEl) amountEl.readOnly = (items.length > 0);
        sel.value = '';
    }

    const typeItemSelect = document.getElementById('typeItemSelect');
    if (typeItemSelect) {
        typeItemSelect.addEventListener('change', () => {
            const opt = typeItemSelect.selectedOptions[0];
            const amount = opt ? opt.getAttribute('data-amount') : '';
            document.getElementById('amountYen').value = (amount !== null) ? String(amount) : '';
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const empSel = document.getElementById('bonusEmployee');
        if (empSel) {
            empSel.addEventListener('change', () => {
                setBonusDefaultByEmployee(empSel.value);
            });
        }
    });

    function closeBonus() {
        document.getElementById('bonusOverlay').style.display = 'none';
    }
    </script>
</body>

</html>
