<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
date_default_timezone_set('Asia/Tokyo');

function json_out(array $arr, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function param_str(string $k, string $default = ''): string
{
    return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $default;
}
function param_int(string $k, int $default = 0): int
{
    $v = isset($_GET[$k]) ? (string)$_GET[$k] : '';
    if ($v === '' || !preg_match('/^\d+$/', $v)) return $default;
    return (int)$v;
}
function load_table_columns(PDO $pdo, string $table): array
{
    // name => ['type' => 'varchar(255)' ...]
    $cols = [];
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = (string)$r['Field'];
        $type = strtolower((string)$r['Type']);
        $cols[$name] = ['type' => $type];
    }
    return $cols;
}
function pick_first_existing(array $cols, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (isset($cols[$c])) return $c;
    }
    return null;
}
function col_type(array $cols, ?string $name): string
{
    if (!$name) return '';
    return $cols[$name]['type'] ?? '';
}

try {
    // ===== DB include（環境差に対応）=====
    $paths = [
        __DIR__ . '/../lib/db.php',
        __DIR__ . '/../api/lib/db.php',
        __DIR__ . '/lib/db.php',
    ];
    $dbFile = null;
    foreach ($paths as $p) {
        if (is_file($p)) {
            $dbFile = $p;
            break;
        }
    }
    if (!$dbFile) json_out(['ok' => false, 'message' => 'db.php not found'], 500);
    require_once $dbFile;

    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $tenantId   = param_int('tenant_id', 0);
    $storeId    = param_int('store_id', 0);
    $employeeId = param_int('employee_id', 0);
    $pin        = param_str('pin', '');

    if ($tenantId <= 0 || $storeId <= 0 || $employeeId <= 0) {
        json_out(['ok' => false, 'message' => 'missing params'], 400);
    }
    if ($pin === '' || !preg_match('/^\d{4}$/', $pin)) {
        json_out(['ok' => false, 'message' => 'PIN must be 4 digits'], 400);
    }

    // ===== employees の PIN列自動判定（無ければPINチェックはスキップ）=====
    $empCols = load_table_columns($pdo, 'employees');
    $pinCol = pick_first_existing($empCols, ['pin_hash', 'pin_code', 'pin', 'pin_plain']);

    if ($pinCol !== null) {
        $sql = "SELECT id, `{$pinCol}` AS pinv
                FROM employees
                WHERE id = :eid AND tenant_id = :tid AND store_id = :sid
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':eid' => $employeeId, ':tid' => $tenantId, ':sid' => $storeId]);
        $row = $st->fetch();
        if (!$row) json_out(['ok' => false, 'message' => 'employee not found'], 404);

        $pinv = (string)($row['pinv'] ?? '');
        if ($pinCol === 'pin_hash') {
            if ($pinv === '' || !password_verify($pin, $pinv)) {
                json_out(['ok' => false, 'message' => 'PIN mismatch'], 403);
            }
        } else {
            if ($pinv === '' || $pinv !== $pin) {
                json_out(['ok' => false, 'message' => 'PIN mismatch'], 403);
            }
        }
    }

    // ===== shifts の列自動判定 =====
    $shiftCols = load_table_columns($pdo, 'shifts');

    // (A) 1本の開始日時（DATETIME/TIMESTAMP/VARCHARでも可）
    $startCol = pick_first_existing($shiftCols, [
        'start_at',
        'start_datetime',
        'starts_at',
        'shift_start_at',
        'shift_start',
        'start',
        'from_at',
        'from_datetime'
    ]);

    // (B) 日付 + 時刻 が分かれているケース
    $dateCol = pick_first_existing($shiftCols, [
        'work_date',
        'shift_date',
        'date',
        'day',
        'workday',
        'business_date',
        'ymd'
    ]);
    $timeCol = pick_first_existing($shiftCols, [
        'start_time',
        'start_hm',
        'from_time',
        'begin_time',
        'stime'
    ]);

    // 終了（あれば返す）
    $endCol = pick_first_existing($shiftCols, [
        'end_at',
        'end_datetime',
        'ends_at',
        'shift_end_at',
        'shift_end',
        'end',
        'to_at',
        'to_datetime',
        'end_time',
        'to_time'
    ]);

    $breakCol = pick_first_existing($shiftCols, ['break_minutes', 'break_min', 'break', 'rest_minutes']);
    $noteCol  = pick_first_existing($shiftCols, ['note', 'memo', 'remarks', 'comment']);

    // 削除/無効フラグがあれば除外
    $isDeletedCol = pick_first_existing($shiftCols, ['is_deleted', 'deleted', 'del_flg']);
    $deletedAtCol = pick_first_existing($shiftCols, ['deleted_at']);
    $statusCol    = pick_first_existing($shiftCols, ['status', 'shift_status']);
    $cancelCol    = pick_first_existing($shiftCols, ['is_canceled', 'canceled']);

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

    // ===== 開始日時の“比較用式”と“返却用 start_at”を作る =====
    // 優先順位:
    // 1) startCol が DATETIME/TIMESTAMP系 → そのまま
    // 2) dateCol + timeCol がある → CONCATしてDATETIME化
    // 3) startCol が DATE で timeCol がある → CONCATしてDATETIME化
    // 4) startCol が DATE のみ → 00:00扱いで比較（次回は出るはず）
    // 5) それ以外は debug 返す

    $startExpr = null;   // WHERE/ORDERに使う式
    $startSelect = null; // SELECTで start_at として返す式

    $startType = col_type($shiftCols, $startCol);
    $dateType  = col_type($shiftCols, $dateCol);
    $timeType  = col_type($shiftCols, $timeCol);

    $isDateType = fn($t) => strpos($t, 'date') !== false && strpos($t, 'datetime') === false;
    $isDateTimeType = fn($t) => (strpos($t, 'datetime') !== false) || (strpos($t, 'timestamp') !== false);
    $isTimeType = fn($t) => strpos($t, 'time') !== false && strpos($t, 'datetime') === false;

    if ($startCol && $isDateTimeType($startType)) {
        $startExpr = "`{$startCol}`";
        $startSelect = "`{$startCol}`";
    } elseif ($dateCol && $timeCol) {
        // DATE + TIME など
        // 秒が無い場合もあるので、':00' 付与も許容したいが、まずは素直に CONCAT
        $startExpr = "STR_TO_DATE(CONCAT(`{$dateCol}`,' ',`{$timeCol}`), '%Y-%m-%d %H:%i:%s')";
        $startSelect = "DATE_FORMAT({$startExpr}, '%Y-%m-%d %H:%i:%s')";
    } elseif ($startCol && $isDateType($startType) && $timeCol) {
        // startCol が日付、timeCol が時刻
        $startExpr = "STR_TO_DATE(CONCAT(`{$startCol}`,' ',`{$timeCol}`), '%Y-%m-%d %H:%i:%s')";
        $startSelect = "DATE_FORMAT({$startExpr}, '%Y-%m-%d %H:%i:%s')";
    } elseif ($startCol && $isDateType($startType)) {
        $startExpr = "STR_TO_DATE(CONCAT(`{$startCol}`,' 00:00:00'), '%Y-%m-%d %H:%i:%s')";
        $startSelect = "DATE_FORMAT({$startExpr}, '%Y-%m-%d %H:%i:%s')";
    } else {
        json_out([
            'ok' => false,
            'message' => 'cannot detect shift start columns',
            'debug' => [
                'columns' => array_keys($shiftCols),
                'detected' => [
                    'startCol' => $startCol,
                    'startType' => $startType,
                    'dateCol'  => $dateCol,
                    'dateType'  => $dateType,
                    'timeCol'  => $timeCol,
                    'timeType'  => $timeType,
                ],
                'hint' => 'Need either a DATETIME start column or (date + time) columns.'
            ]
        ], 500);
    }

    $select = [];
    $select[] = "{$startSelect} AS start_at";
    $select[] = ($endCol ? "`{$endCol}` AS end_at" : "NULL AS end_at");
    $select[] = ($breakCol ? "`{$breakCol}` AS break_minutes" : "NULL AS break_minutes");
    $select[] = ($noteCol ? "`{$noteCol}` AS note" : "NULL AS note");

    $where = [];
    $where[] = "tenant_id = :tid";
    $where[] = "store_id = :sid";
    $where[] = "employee_id = :eid";
    $where[] = "{$startExpr} > :now";

    if ($isDeletedCol) $where[] = "(`{$isDeletedCol}` = 0 OR `{$isDeletedCol}` IS NULL)";
    if ($deletedAtCol) $where[] = "`{$deletedAtCol}` IS NULL";
    if ($cancelCol) $where[] = "(`{$cancelCol}` = 0 OR `{$cancelCol}` IS NULL)";
    if ($statusCol) {
        // よくある: active / published / confirmed だけ通す（合わなければ後で調整）
        $where[] = "(`{$statusCol}` IS NULL OR `{$statusCol}` IN ('active','published','confirmed','ok'))";
    }

    $sql = "SELECT " . implode(", ", $select) . "
            FROM shifts
            WHERE " . implode(" AND ", $where) . "
            ORDER BY {$startExpr} ASC
            LIMIT 1";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':tid' => $tenantId,
        ':sid' => $storeId,
        ':eid' => $employeeId,
        ':now' => $now,
    ]);

    $shift = $st->fetch();

    json_out([
        'ok' => true,
        'message' => null,
        'next_shift' => $shift ?: null,
        // デバッグしたい時に役立つ（必要なら後で消す）
        'debug' => [
            'now' => $now,
            'detected' => [
                'startCol' => $startCol,
                'dateCol' => $dateCol,
                'timeCol' => $timeCol,
                'endCol' => $endCol,
            ],
        ],
    ]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'server error: ' . $e->getMessage()], 500);
}