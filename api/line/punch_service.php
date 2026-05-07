<?php
// ✅ ファイル名: /api/line/punch_service.php
// ✅ 書き込み場所: 既存ファイルを「丸ごと置換（全貼り）」
// ✅ 方針: 休憩は break_punches を正とし、time_punches に break は今後一切書かない

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once dirname(__DIR__, 2) . '/lib/punch_source.php';

/* =========================================================
 * Public APIs  (LINE用)
 * ======================================================= */

function punchClockIn(PDO $pdo, int $tenantId, int $storeId, int $employeeId, DateTimeImmutable $now): string
{
    punch_source_ensure_column($pdo);
    $deviceId = ensureLineDeviceId($pdo, $tenantId, $storeId);
    $cols = get_columns($pdo, 'time_punches');

    $insert = [];
    $bind = [];

    // tenant/store/employee/device
    add_if_exists($cols, $insert, $bind, 'tenant_id', $tenantId);
    add_if_exists($cols, $insert, $bind, 'store_id', $storeId);
    add_if_exists($cols, $insert, $bind, 'employee_id', $employeeId);
    add_if_exists($cols, $insert, $bind, 'device_id', $deviceId);
    add_if_exists($cols, $insert, $bind, 'punch_source', 'line');

    // work_date（あれば入れる）
    add_if_exists($cols, $insert, $bind, 'work_date', $now->format('Y-m-d'));

    // ✅ 必須: punched_at / punch_type（このDBではこれが必須）
    add_if_exists($cols, $insert, $bind, 'punched_at', $now->format('Y-m-d H:i:s'));
    add_if_exists($cols, $insert, $bind, 'punch_type', 'clock_in');

    // clock_in_at がある設計なら入れてもOK
    add_if_exists($cols, $insert, $bind, 'clock_in_at', $now->format('Y-m-d H:i:s'));

    add_if_exists($cols, $insert, $bind, 'created_at', $now->format('Y-m-d H:i:s'));
    add_if_exists($cols, $insert, $bind, 'updated_at', $now->format('Y-m-d H:i:s'));

    ensure_required_columns_filled($pdo, 'time_punches', $insert);

    $sql = 'INSERT INTO time_punches (' . implode(',', array_keys($insert)) . ')
            VALUES (' . implode(',', array_values($insert)) . ')';
    $pdo->prepare($sql)->execute($bind);

    return "✅ 出勤を記録しました（{$now->format('H:i')}）";
}

function punchClockOut(PDO $pdo, int $tenantId, int $storeId, int $employeeId, DateTimeImmutable $now): string
{
    punch_source_ensure_column($pdo);
    $deviceId = ensureLineDeviceId($pdo, $tenantId, $storeId);
    $cols = get_columns($pdo, 'time_punches');

    $insert = [];
    $bind = [];

    add_if_exists($cols, $insert, $bind, 'tenant_id', $tenantId);
    add_if_exists($cols, $insert, $bind, 'store_id', $storeId);
    add_if_exists($cols, $insert, $bind, 'employee_id', $employeeId);
    add_if_exists($cols, $insert, $bind, 'device_id', $deviceId);
    add_if_exists($cols, $insert, $bind, 'punch_source', 'line');

    add_if_exists($cols, $insert, $bind, 'work_date', $now->format('Y-m-d'));

    // ✅ 必須: punched_at / punch_type
    add_if_exists($cols, $insert, $bind, 'punched_at', $now->format('Y-m-d H:i:s'));
    add_if_exists($cols, $insert, $bind, 'punch_type', 'clock_out');

    // clock_out_at があるなら埋める
    add_if_exists($cols, $insert, $bind, 'clock_out_at', $now->format('Y-m-d H:i:s'));

    add_if_exists($cols, $insert, $bind, 'created_at', $now->format('Y-m-d H:i:s'));
    add_if_exists($cols, $insert, $bind, 'updated_at', $now->format('Y-m-d H:i:s'));

    ensure_required_columns_filled($pdo, 'time_punches', $insert);

    $sql = 'INSERT INTO time_punches (' . implode(',', array_keys($insert)) . ')
            VALUES (' . implode(',', array_values($insert)) . ')';
    $pdo->prepare($sql)->execute($bind);

    return "✅ 退勤を記録しました（{$now->format('H:i')}）";
}

/* =========================================================
 * ✅ 休憩（break_punches を正にする）
 *   - break_in  : break_punches に開始行を作る（end は NULL か start 同値）
 *   - break_out : 直近の未終了休憩を探して end を埋める
 * ======================================================= */

function punchBreakIn(PDO $pdo, int $tenantId, int $storeId, int $employeeId, DateTimeImmutable $nowJst): string
{
    // ✅ time_punches には書かない
    // ✅ break_punches に書く（環境差に強い）
    $cols = get_columns($pdo, 'break_punches');

    $insert = [];
    $bind = [];

    add_if_exists($cols, $insert, $bind, 'tenant_id', $tenantId);
    add_if_exists($cols, $insert, $bind, 'store_id', $storeId);
    add_if_exists($cols, $insert, $bind, 'employee_id', $employeeId);

    // device_id がある環境なら入れる（無ければ無視）
    $deviceId = ensureLineDeviceId($pdo, $tenantId, $storeId);
    add_if_exists($cols, $insert, $bind, 'device_id', $deviceId);

    // break_start_at は基本必須
    add_if_exists($cols, $insert, $bind, 'break_start_at', $nowJst->format('Y-m-d H:i:s'));

    // end が NOT NULL 必須のDBもあり得るので、存在するなら「NULLが許されない時だけ」start同値で埋める
    if (isset($cols['break_end_at'])) {
        $null = (string)($cols['break_end_at']['Null'] ?? 'YES');
        if ($null === 'NO') {
            add_if_exists($cols, $insert, $bind, 'break_end_at', $nowJst->format('Y-m-d H:i:s'));
        } else {
            // NULL 許容なら入れない（NULLのまま）
            // 何もしない
        }
    }

    add_if_exists($cols, $insert, $bind, 'created_at', $nowJst->format('Y-m-d H:i:s'));
    add_if_exists($cols, $insert, $bind, 'updated_at', $nowJst->format('Y-m-d H:i:s'));

    ensure_required_columns_filled($pdo, 'break_punches', $insert);

    $sql = 'INSERT INTO break_punches (' . implode(',', array_keys($insert)) . ')
            VALUES (' . implode(',', array_values($insert)) . ')';
    $pdo->prepare($sql)->execute($bind);

    return "✅ 休憩開始: " . $nowJst->format('H:i');
}

function punchBreakOut(PDO $pdo, int $tenantId, int $storeId, int $employeeId, DateTimeImmutable $nowJst): string
{
    // ✅ time_punches には書かない
    // ✅ break_punches の「未終了」を閉じる
    $cols = get_columns($pdo, 'break_punches');
    if (!isset($cols['break_start_at']) || !isset($cols['break_end_at'])) {
        throw new RuntimeException('break_punches に break_start_at / break_end_at がありません');
    }

    // 未終了条件:
    // - break_end_at IS NULL
    // - または break_end_at = break_start_at（仮埋め運用）
    $st = $pdo->prepare("
        SELECT id, break_start_at, break_end_at
        FROM break_punches
        WHERE tenant_id = :t
          AND store_id  = :s
          AND employee_id = :e
          AND (break_end_at IS NULL OR break_end_at = break_start_at)
        ORDER BY break_start_at DESC, id DESC
        LIMIT 1
    ");
    $st->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // ここで新規作成して閉じてしまうと「開始なし」が隠れるので、明示的に返す
        return "⚠️ 休憩開始が見つかりません（先に「休憩開始」をしてください）";
    }

    $id = (int)$row['id'];
    $nowStr = $nowJst->format('Y-m-d H:i:s');

    $updSql = "UPDATE break_punches
               SET break_end_at = :end"
        . (isset($cols['updated_at']) ? ", updated_at = :u" : "")
        . " WHERE id = :id";

    $params = [
        ':end' => $nowStr,
        ':id'  => $id,
    ];
    if (isset($cols['updated_at'])) $params[':u'] = $nowStr;

    $pdo->prepare($updSql)->execute($params);

    return "✅ 休憩終了: " . $nowJst->format('H:i');
}

/* =========================================================
 * LINE仮想デバイス生成
 * ======================================================= */

function ensureLineDeviceId(PDO $pdo, int $tenantId, int $storeId): int
{
    $deviceKey = "LINE_BOT:t{$tenantId}:s{$storeId}";
    $deviceKeyHash = hash('sha256', $deviceKey);

    $st = $pdo->prepare("SELECT id FROM devices WHERE device_key_hash = :h LIMIT 1");
    $st->execute([':h' => $deviceKeyHash]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;

    $pdo->prepare("
        INSERT INTO devices (tenant_id, store_id, device_name, device_key_hash, created_at, updated_at)
        VALUES (:t, :s, :n, :h, NOW(), NOW())
    ")->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':n' => 'LINE Bot',
        ':h' => $deviceKeyHash,
    ]);

    return (int)$pdo->lastInsertId();
}

/* =========================================================
 * Helpers
 * ======================================================= */

function get_columns(PDO $pdo, string $table): array
{
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $cols = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $cols[$r['Field']] = $r;
    }
    return $cols;
}

function add_if_exists(array $cols, array &$insert, array &$bind, string $col, $value): void
{
    if (!isset($cols[$col])) return;
    $insert[$col] = ':' . $col;
    $bind[':' . $col] = $value;
}

function ensure_required_columns_filled(PDO $pdo, string $table, array $insertCols): void
{
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $missing = [];

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (($r['Extra'] ?? '') === 'auto_increment') continue;
        $field = (string)$r['Field'];
        $null  = (string)$r['Null'];
        $def   = $r['Default'];

        if ($null === 'NO' && $def === null && !isset($insertCols[$field])) {
            $missing[] = $field;
        }
    }

    if ($missing) {
        throw new RuntimeException("Table {$table}: required columns missing in INSERT: " . implode(', ', $missing));
    }
}
