<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /api/v1/punch.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 目的
 * - break_punches.break_end_at が NOT NULL 環境でも 500 を出さない
 * - break_in: break_start_at=now / break_end_at=now（仮）で登録
 * - break_out: 直近の「未終了」(end is null OR end=start) を now でクローズ
 * - time_punches は従来通り「ログ」として必ず残す
 *
 * ✅ 既存要件は維持（壊さない）
 * - JSON POST
 * - devices upsert（device_key_hash=sha256(dev_key)）
 * - clock_out の売上/来客は未送信なら保存スキップOK
 * - スロット777で時給+50（feature flag）
 */

header('Content-Type: application/json; charset=utf-8');

function json_exit(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols[] = (string)$r['Field'];
    }
    $cache[$table] = $cols;
    return $cols;
}

function safe_insert(PDO $pdo, string $table, array $data): void
{
    $cols = table_columns($pdo, $table);
    $use = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $cols, true)) $use[$k] = $v;
    }
    if (empty($use)) throw new RuntimeException("INSERT failed: no matching columns for {$table}");

    $fields = array_keys($use);
    $ph = array_map(fn($f) => ':' . $f, $fields);
    $sql = "INSERT INTO `{$table}` (" . implode(',', array_map(fn($f) => "`{$f}`", $fields)) . ") VALUES (" . implode(',', $ph) . ")";
    $stmt = $pdo->prepare($sql);

    $params = [];
    foreach ($use as $k => $v) $params[':' . $k] = $v;
    $stmt->execute($params);
}

function safe_update(PDO $pdo, string $table, array $data, string $whereSql, array $whereParams): void
{
    $cols = table_columns($pdo, $table);
    $use = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $cols, true)) $use[$k] = $v;
    }
    if (empty($use)) throw new RuntimeException("UPDATE failed: no matching columns for {$table}");

    $sets = [];
    $params = [];
    foreach ($use as $k => $v) {
        $sets[] = "`{$k}` = :set_{$k}";
        $params[":set_{$k}"] = $v;
    }
    foreach ($whereParams as $k => $v) $params[$k] = $v;

    $sql = "UPDATE `{$table}` SET " . implode(',', $sets) . " WHERE {$whereSql}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function ensure_punch_source_column(PDO $pdo): void
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `time_punches` LIKE :col");
        $st->execute([':col' => 'punch_source']);
        if ($st->fetch(PDO::FETCH_ASSOC)) return;
        $pdo->exec("ALTER TABLE time_punches ADD COLUMN punch_source VARCHAR(20) NULL AFTER device_id");
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * ✅ devices の「列差異に強い upsert」
 * - id(pk) がある前提
 * - あれば UPDATE / なければ INSERT
 */
function upsert_by_pk(PDO $pdo, string $table, string $pkField, int $pkValue, array $insertData, array $updateData): void
{
    $sel = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE `{$pkField}` = :id LIMIT 1");
    $sel->execute([':id' => $pkValue]);
    $exists = (bool)$sel->fetchColumn();

    if ($exists) {
        safe_update($pdo, $table, $updateData, "`{$pkField}` = :id", [':id' => $pkValue]);
    } else {
        safe_insert($pdo, $table, $insertData);
    }
}

function normalize_cutoff_time(string $cutoff): string
{
    $cutoff = trim($cutoff);
    if ($cutoff === '') return '05:00:00';
    if (preg_match('/^\d{1,2}:\d{2}$/', $cutoff)) $cutoff .= ':00';
    if (!preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $cutoff)) return '05:00:00';

    [$h, $m, $s] = array_map('intval', explode(':', $cutoff));
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59) return '05:00:00';
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function business_date_from_datetime(DateTimeImmutable $dt, string $cutoffTime): string
{
    $cutoffTime = normalize_cutoff_time($cutoffTime);
    $cut = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $dt->format('Y-m-d') . ' ' . $cutoffTime,
        $dt->getTimezone()
    );
    if ($cut && $dt < $cut) {
        return $dt->modify('-1 day')->format('Y-m-d');
    }
    return $dt->format('Y-m-d');
}

try {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) json_exit(400, ['error' => 'invalid_json']);

    $tenantId   = (int)($body['tenant_id'] ?? 0);
    $storeId    = (int)($body['store_id'] ?? 0);
    $employeeId = (int)($body['employee_id'] ?? 0);
    $deviceId   = (int)($body['device_id'] ?? 0);
    $punchType  = (string)($body['punch_type'] ?? '');
    $devKey     = trim((string)($body['dev_key'] ?? ''));

    $slotRewardType = (string)($body['slot_reward_type'] ?? '');

    $allowed = ['clock_in', 'clock_out', 'break_in', 'break_out'];
    if ($tenantId <= 0 || $storeId <= 0 || $employeeId <= 0 || $deviceId <= 0 || $devKey === '' || !in_array($punchType, $allowed, true)) {
        json_exit(400, ['error' => 'invalid_params']);
    }

    $deviceKeyHash = hash('sha256', $devKey);

    // clock_out レポート（任意）
    $businessDate = (string)($body['business_date'] ?? '');
    $salesYenRaw  = $body['today_sales_yen'] ?? null;
    $visitorsRaw  = $body['today_visitors'] ?? null;
    $hasSalesVisitors = !($salesYenRaw === null || $visitorsRaw === null);

    // DB
    require_once __DIR__ . '/../lib/db.php';
    $pdo = db();

    // timezone
    $tzStmt = $pdo->prepare("SELECT timezone FROM tenants WHERE id = :id");
    $tzStmt->execute([':id' => $tenantId]);
    $tenantTz = (string)($tzStmt->fetchColumn() ?: 'Asia/Tokyo');

    $storeTz = $tenantTz;
    $storeCutoff = '05:00:00';
    $storeCols = table_columns($pdo, 'stores');
    $pick = [];
    if (in_array('payroll_tz', $storeCols, true)) $pick[] = 'payroll_tz';
    if (in_array('business_day_cutoff_time', $storeCols, true)) $pick[] = 'business_day_cutoff_time';
    if ($pick) {
        $sql = "SELECT " . implode(', ', $pick) . " FROM stores WHERE tenant_id = :t AND id = :s LIMIT 1";
        $storeStmt = $pdo->prepare($sql);
        $storeStmt->execute([':t' => $tenantId, ':s' => $storeId]);
        $storeRow = $storeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $tzCandidate = trim((string)($storeRow['payroll_tz'] ?? ''));
        if ($tzCandidate !== '') $storeTz = $tzCandidate;
        $storeCutoff = normalize_cutoff_time((string)($storeRow['business_day_cutoff_time'] ?? '05:00:00'));
    }

    $nowDt = new DateTimeImmutable('now', new DateTimeZone($storeTz));
    $now   = $nowDt->format('Y-m-d H:i:s');

    // 営業日はクライアント値を信用せず、サーバー時刻と店舗cutoffから確定する
    $businessDate = business_date_from_datetime($nowDt, $storeCutoff);

    $salesYen = null;
    $visitors = null;
    if ($punchType === 'clock_out' && $hasSalesVisitors) {
        if (!is_numeric($salesYenRaw) || !is_numeric($visitorsRaw)) json_exit(400, ['error' => 'sales_visitors_must_be_number']);
        $salesYen = (int)$salesYenRaw;
        $visitors = (int)$visitorsRaw;
        if ($salesYen < 0 || $visitors < 0) json_exit(400, ['error' => 'sales_visitors_must_be_non_negative']);
    }

    $pdo->beginTransaction();
    try {
        ensure_punch_source_column($pdo);
        // ✅ devices upsert（列差異で落ちない）
        // insert側
        $insertDev = [
            'id'              => $deviceId,
            'dev_key'         => $devKey,
            'device_key_hash' => $deviceKeyHash,
            'tenant_id'       => $tenantId,
            'store_id'        => $storeId,
            'device_name'     => 'iPad',
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        // update側（created_at は触らない）
        $updateDev = [
            'dev_key'         => $devKey,
            'device_key_hash' => $deviceKeyHash,
            'tenant_id'       => $tenantId,
            'store_id'        => $storeId,
            'device_name'     => 'iPad',
            'status'          => 'active',
            'updated_at'      => $now,
        ];
        upsert_by_pk($pdo, 'devices', 'id', $deviceId, $insertDev, $updateDev);

        // feature flag
        $flagStmt = $pdo->prepare("
            SELECT enabled
            FROM tenant_feature_flags
            WHERE tenant_id = :tenant_id
              AND feature_key = 'slot_wage_bonus'
            LIMIT 1
        ");
        $flagStmt->execute([':tenant_id' => $tenantId]);
        $slotBonusEnabled = ((int)($flagStmt->fetchColumn() ?? 0) === 1);

        // ✅ break_punches（複数休憩）
        $breakSaved = false;

        if ($punchType === 'break_in') {
            // 直近の未終了（end IS NULL OR end=start） があれば二重開始を拒否
            $chk = $pdo->prepare("
                SELECT id
                FROM break_punches
                WHERE tenant_id = :tenant_id
                  AND store_id  = :store_id
                  AND employee_id = :employee_id
                  AND device_id = :device_id
                  AND (break_end_at IS NULL OR break_end_at = break_start_at)
                ORDER BY break_start_at DESC, id DESC
                LIMIT 1
            ");
            $chk->execute([
                ':tenant_id' => $tenantId,
                ':store_id' => $storeId,
                ':employee_id' => $employeeId,
                ':device_id' => $deviceId,
            ]);
            $openId = (int)($chk->fetchColumn() ?? 0);
            if ($openId > 0) {
                json_exit(409, [
                    'error' => 'break_already_open',
                    'message' => '休憩が開始済みです。先に「休憩終了」を行ってください。',
                ]);
            }

            // ✅ NOT NULL環境でも絶対落ちない：end は start と同値で必ず入れる
            safe_insert($pdo, 'break_punches', [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'employee_id' => $employeeId,
                'device_id' => $deviceId,
                'break_start_at' => $now,
                'break_end_at' => $now,       // ★NULL禁止対策
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $breakSaved = true;
        }

        if ($punchType === 'break_out') {
            // 直近の未終了（end IS NULL OR end=start）をクローズ
            $sel = $pdo->prepare("
                SELECT id
                FROM break_punches
                WHERE tenant_id = :tenant_id
                  AND store_id  = :store_id
                  AND employee_id = :employee_id
                  AND device_id = :device_id
                  AND (break_end_at IS NULL OR break_end_at = break_start_at)
                ORDER BY break_start_at DESC, id DESC
                LIMIT 1
            ");
            $sel->execute([
                ':tenant_id' => $tenantId,
                ':store_id' => $storeId,
                ':employee_id' => $employeeId,
                ':device_id' => $deviceId,
            ]);
            $openId = (int)($sel->fetchColumn() ?? 0);
            if ($openId <= 0) {
                json_exit(409, [
                    'error' => 'no_open_break',
                    'message' => '開始中の休憩が見つかりません。',
                ]);
            }

            safe_update($pdo, 'break_punches', [
                'break_end_at' => $now,
                'updated_at' => $now,
            ], "id = :id AND tenant_id = :tenant_id AND store_id = :store_id AND employee_id = :employee_id AND device_id = :device_id", [
                ':id' => $openId,
                ':tenant_id' => $tenantId,
                ':store_id' => $storeId,
                ':employee_id' => $employeeId,
                ':device_id' => $deviceId,
            ]);

            $breakSaved = true;
        }

        // 退勤時：daily_store_reports（送られてきた時だけ保存）
        $reportSaved = false;
        if ($punchType === 'clock_out' && $hasSalesVisitors) {
            $reportCols = table_columns($pdo, 'daily_store_reports');
            $insertCols = [
                'tenant_id',
                'store_id',
                'business_date',
                'sales_yen',
                'visitors',
                'updated_by_employee_id',
            ];
            $insertVals = [
                ':tenant_id',
                ':store_id',
                ':business_date',
                ':sales_yen',
                ':visitors',
                ':employee_id',
            ];
            $updates = [
                'sales_yen = VALUES(sales_yen)',
                'visitors = VALUES(visitors)',
                'updated_by_employee_id = VALUES(updated_by_employee_id)',
                'updated_at = CURRENT_TIMESTAMP',
            ];
            $reportParams = [
                ':tenant_id'     => $tenantId,
                ':store_id'      => $storeId,
                ':business_date' => $businessDate,
                ':sales_yen'     => (int)$salesYen,
                ':visitors'      => (int)$visitors,
                ':employee_id'   => $employeeId,
            ];
            if (in_array('reported_at', $reportCols, true)) {
                $insertCols[] = 'reported_at';
                $insertVals[] = ':reported_at';
                $updates[] = 'reported_at = VALUES(reported_at)';
                $reportParams[':reported_at'] = $now;
            }
            $stmt = $pdo->prepare("
                INSERT INTO daily_store_reports
                    (" . implode(', ', $insertCols) . ")
                VALUES
                    (" . implode(', ', $insertVals) . ")
                ON DUPLICATE KEY UPDATE
                    " . implode(",\n                    ", $updates) . "
            ");
            $stmt->execute($reportParams);
            $reportSaved = true;
        }

        // time_punches（ログ）※列差異に強く
        safe_insert($pdo, 'time_punches', [
            'tenant_id'   => $tenantId,
            'store_id'    => $storeId,
            'employee_id' => $employeeId,
            'device_id'   => $deviceId,
            'punch_source' => 'ipad',
            'punch_type'  => $punchType,
            'punched_at'  => $now,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        // 出勤 + スロット777 → 当日だけ時給 +50（※元要件を維持：あなたのDBは bonus_yen だが要件通り）
        if ($slotBonusEnabled && $punchType === 'clock_in' && $slotRewardType === 'wagePlus50') {
            $stmtBonus = $pdo->prepare("
                INSERT INTO daily_wage_adjustments
                    (tenant_id, employee_id, business_date, bonus_yen, reason)
                VALUES
                    (:tenant_id, :employee_id, :business_date, 50, 'slot_777')
                ON DUPLICATE KEY UPDATE
                    bonus_yen = VALUES(bonus_yen)
            ");
            $stmtBonus->execute([
                ':tenant_id'     => $tenantId,
                ':employee_id'   => $employeeId,
                ':business_date' => $businessDate,
            ]);
        }

        $pdo->commit();

        json_exit(200, [
            'ok' => true,
            'punched_at' => $now,
            'business_date' => $businessDate,
            'report_saved' => $reportSaved,
            'break_saved' => $breakSaved,
            'slot_bonus_saved' => ($slotBonusEnabled && $punchType === 'clock_in' && $slotRewardType === 'wagePlus50'),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_exit(500, ['error' => 'server_error', 'message' => $e->getMessage()]);
    }
} catch (Throwable $e) {
    json_exit(500, ['error' => 'server_error', 'message' => $e->getMessage()]);
}
