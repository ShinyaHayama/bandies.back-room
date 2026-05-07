<?php
// ✅ ファイル名: /api/line/punch_functions.php
// ✅ 書き込み場所: 新規作成して全貼り
declare(strict_types=1);

/**
 * punchIn / punchOut（暫定実装）
 * ※ time_punches の実DDLに合わせてカラム名を調整する前提
 */

function punchIn(PDO $pdo, int $tenantId, int $storeId, int $employeeId): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $workDate = $now->format('Y-m-d');        // 勤務日（基本は当日）
    $time = $now->format('H:i:s');

    // すでに今日のレコードがあるか（未退勤ならそれを使う）
    $row = findTodayRow($pdo, $tenantId, $storeId, $employeeId, $workDate);

    if ($row) {
        // すでに出勤済みなら「二重打刻防止」
        if (!empty($row['clock_in_time'])) {
            return ['ok' => false, 'msg' => "すでに出勤済みです\n出勤: {$row['clock_in_time']}"];
        }

        // clock_in だけ入れる（カラム名は仮）
        $st = $pdo->prepare("
            UPDATE time_punches
               SET clock_in_time = :t,
                   updated_at = NOW()
             WHERE id = :id
        ");
        $st->execute([':t' => $time, ':id' => (int)$row['id']]);

        return ['ok' => true, 'time' => $time];
    }

    // 新規作成（カラム名は仮）
    $st = $pdo->prepare("
        INSERT INTO time_punches (tenant_id, store_id, employee_id, work_date, clock_in_time, created_at, updated_at)
        VALUES (:tenant, :store, :emp, :work_date, :in_time, NOW(), NOW())
    ");
    $st->execute([
        ':tenant' => $tenantId,
        ':store' => $storeId,
        ':emp' => $employeeId,
        ':work_date' => $workDate,
        ':in_time' => $time,
    ]);

    return ['ok' => true, 'time' => $time];
}

function punchOut(PDO $pdo, int $tenantId, int $storeId, int $employeeId): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $workDate = $now->format('Y-m-d');
    $time = $now->format('H:i:s');

    $row = findTodayRow($pdo, $tenantId, $storeId, $employeeId, $workDate);

    if (!$row) {
        return ['ok' => false, 'msg' => "出勤が見つかりません（先に出勤してください）"];
    }

    if (!empty($row['clock_out_time'])) {
        return ['ok' => false, 'msg' => "すでに退勤済みです\n退勤: {$row['clock_out_time']}"];
    }

    $st = $pdo->prepare("
        UPDATE time_punches
           SET clock_out_time = :t,
               updated_at = NOW()
         WHERE id = :id
    ");
    $st->execute([':t' => $time, ':id' => (int)$row['id']]);

    return ['ok' => true, 'time' => $time];
}

function findTodayRow(PDO $pdo, int $tenantId, int $storeId, int $employeeId, string $workDate): ?array
{
    $st = $pdo->prepare("
        SELECT *
          FROM time_punches
         WHERE tenant_id = :tenant
           AND store_id  = :store
           AND employee_id = :emp
           AND work_date = :work_date
         ORDER BY id DESC
         LIMIT 1
    ");
    $st->execute([
        ':tenant' => $tenantId,
        ':store' => $storeId,
        ':emp' => $employeeId,
        ':work_date' => $workDate,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}