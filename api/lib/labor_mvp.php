<?php

/**
 * /api/lib/labor_mvp.php
 *
 * ✅ 目的
 * - 売上（日別）: daily_store_reports.business_date / sales_yen
 * - 人件費（日別）: time_punches + employees.hourly_wage_yen
 * - 深夜割増（日別）: 22:00〜翌5:00 の実働に対して従業員ごとの割増率を適用
 *
 * ✅ 重要（深夜跨ぎ対応）
 * - employeeごとに時系列で走査し、clock_in → 次の clock_out を 1勤務として確定
 * - 深夜跨ぎでも「clock_in の営業日」に人件費を計上
 *
 * ✅ 休憩
 * - break_in → break_out を控除（勤務中のみ）
 * - 欠損（片方だけ等）は安全側で無視（盛らない）
 *
 * ✅ 打刻調整
 * - roundOn=true のとき stores.payroll_round_unit_minutes（例:15）で打刻調整
 *   - clock_in: 切り上げ / clock_out: 切り捨て
 *   - break_in / break_out は time_punch_daily.php と同じく実時間
 *
 * ✅ 今回の修正（最重要）
 * - 過去の計算を「過去の時給」で行うために employee_wage_histories を参照して時給を決定する
 *   - キーは effective_business_day（= 営業日 cutoff 済みの日付キーの想定）
 *   - このファイルは「clock_in の日付（出勤日）」に計上する設計なので、その日付で
 *     effective_business_day <= 出勤日の最新 を採用する（変更日だけ保存でも正しく引き継ぐ）
 *   - 履歴が無い/テーブルが無い/参照できない場合は employees.hourly_wage_yen にフォールバック（既存を壊さない）
 */

/* =========================================================
 * 共通ユーティリティ
 * ======================================================= */
function mvp_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $db = (string)($pdo->query("SELECT DATABASE()")->fetchColumn() ?: '');
        if ($db === '') return false;

        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME   = :t
              AND COLUMN_NAME  = :c
            LIMIT 1
        ");
        $st->execute([':db' => $db, ':t' => $table, ':c' => $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mvp_calc_night_seconds(int $startTs, int $endTs): int
{
    if ($endTs <= $startTs) return 0;

    $total = 0;
    $dayStart = strtotime(date('Y-m-d 00:00:00', $startTs));
    if ($dayStart === false) return 0;

    while ($dayStart < $endTs) {
        $nightStart = $dayStart + 22 * 3600;
        $nightEnd = $dayStart + 24 * 3600 + 5 * 3600;

        $s = max($startTs, $nightStart);
        $e = min($endTs, $nightEnd);
        if ($e > $s) {
            $total += ($e - $s);
        }

        $dayStart += 86400;
    }
    return $total;
}

function mvp_normalize_cutoff_time(string $cutoff): string
{
    $cutoff = trim($cutoff);
    if ($cutoff === '') return '05:00:00';
    if (preg_match('/^\d{1,2}:\d{2}$/', $cutoff)) $cutoff .= ':00';
    if (!preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $cutoff)) return '05:00:00';

    [$h, $m, $s] = array_map('intval', explode(':', $cutoff));
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59) return '05:00:00';
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function mvp_store_time_context(PDO $pdo, int $tenantId, int $storeId): array
{
    $tz = 'Asia/Tokyo';
    $cutoff = '05:00:00';

    try {
        if (mvp_has_column($pdo, 'tenants', 'timezone')) {
            $st = $pdo->prepare("SELECT timezone FROM tenants WHERE id = :id LIMIT 1");
            $st->execute([':id' => $tenantId]);
            $tenantTz = (string)($st->fetchColumn() ?: '');
            if ($tenantTz !== '') $tz = $tenantTz;
        }
    } catch (Throwable $e) {
    }

    try {
        $select = [];
        if (mvp_has_column($pdo, 'stores', 'payroll_tz')) {
            $select[] = "COALESCE(payroll_tz, '') AS payroll_tz";
        }
        if (mvp_has_column($pdo, 'stores', 'business_day_cutoff_time')) {
            $select[] = "COALESCE(business_day_cutoff_time, '05:00:00') AS business_day_cutoff_time";
        }
        if ($select) {
            $st = $pdo->prepare("
                SELECT " . implode(', ', $select) . "
                FROM stores
                WHERE tenant_id = :t AND id = :s
                LIMIT 1
            ");
            $st->execute([':t' => $tenantId, ':s' => $storeId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $storeTz = trim((string)($row['payroll_tz'] ?? ''));
            if ($storeTz !== '') $tz = $storeTz;
            $cutoff = mvp_normalize_cutoff_time((string)($row['business_day_cutoff_time'] ?? $cutoff));
        }
    } catch (Throwable $e) {
    }

    return ['tz' => $tz, 'cutoff' => $cutoff];
}

function mvp_business_date_from_ts(int $ts, string $tz, string $cutoff): string
{
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($tz));
    $cutoff = mvp_normalize_cutoff_time($cutoff);
    $cut = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $dt->format('Y-m-d') . ' ' . $cutoff,
        new DateTimeZone($tz)
    );
    if ($cut && $dt < $cut) {
        return $dt->modify('-1 day')->format('Y-m-d');
    }
    return $dt->format('Y-m-d');
}

function mvp_next_business_boundary_ts(int $ts, string $tz, string $cutoff): int
{
    $tzObj = new DateTimeZone($tz);
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tzObj);
    $cutoff = mvp_normalize_cutoff_time($cutoff);

    $todayBoundary = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $dt->format('Y-m-d') . ' ' . $cutoff,
        $tzObj
    );
    if (!$todayBoundary) {
        return $ts + 86400;
    }

    if ($dt < $todayBoundary) {
        return $todayBoundary->getTimestamp();
    }
    return $todayBoundary->modify('+1 day')->getTimestamp();
}

function mvp_allocate_interval_by_business_date(array &$target, int $startTs, int $endTs, string $tz, string $cutoff, string $prefix): void
{
    if ($endTs <= $startTs) return;

    $cursor = $startTs;
    while ($cursor < $endTs) {
        $day = mvp_business_date_from_ts($cursor, $tz, $cutoff);
        $boundaryTs = mvp_next_business_boundary_ts($cursor, $tz, $cutoff);
        $segmentEnd = min($endTs, $boundaryTs);
        $sec = max(0, $segmentEnd - $cursor);
        if ($sec > 0) {
            $key = $prefix . '|' . $day;
            if (!isset($target[$key])) $target[$key] = 0;
            $target[$key] += $sec;
        }
        $cursor = $segmentEnd;
    }
}

function mvp_allocate_night_interval_by_business_date(array &$target, int $startTs, int $endTs, string $tz, string $cutoff, string $prefix): void
{
    if ($endTs <= $startTs) return;

    $tzObj = new DateTimeZone($tz);
    $startDt = (new DateTimeImmutable('@' . $startTs))->setTimezone($tzObj)->modify('-1 day')->setTime(0, 0, 0);
    $endDt = (new DateTimeImmutable('@' . $endTs))->setTimezone($tzObj)->modify('+1 day')->setTime(0, 0, 0);

    for ($d = $startDt; $d <= $endDt; $d = $d->modify('+1 day')) {
        $nightStart = $d->setTime(22, 0, 0)->getTimestamp();
        $nightEnd = $d->modify('+1 day')->setTime(5, 0, 0)->getTimestamp();
        $segStart = max($startTs, $nightStart);
        $segEnd = min($endTs, $nightEnd);
        if ($segEnd > $segStart) {
            mvp_allocate_interval_by_business_date($target, $segStart, $segEnd, $tz, $cutoff, $prefix);
        }
    }
}

/* =========================================================
  * 設定（しきい値）
  * ======================================================= */
function mvp_get_labor_settings(PDO $pdo, int $tenantId, int $storeId): array
{
    $defaultGreenMax  = 30.0;
    $defaultYellowMax = 35.0;

    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(labor_green_max_rate, 30.00)  AS green_max,
                COALESCE(labor_yellow_max_rate, 35.00) AS yellow_max
            FROM stores
            WHERE tenant_id = :t AND id = :s
            LIMIT 1
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['green_max' => $defaultGreenMax, 'yellow_max' => $defaultYellowMax];
        }

        $greenMax  = (float)$row['green_max'];
        $yellowMax = (float)$row['yellow_max'];

        if (!is_finite($greenMax)) $greenMax = $defaultGreenMax;
        if (!is_finite($yellowMax)) $yellowMax = $defaultYellowMax;

        $greenMax  = max(0.0, min(100.0, round($greenMax, 2)));
        $yellowMax = max(0.0, min(100.0, round($yellowMax, 2)));
        if ($yellowMax < $greenMax) $yellowMax = $greenMax;

        return ['green_max' => $greenMax, 'yellow_max' => $yellowMax];
    } catch (Throwable $e) {
        return ['green_max' => $defaultGreenMax, 'yellow_max' => $defaultYellowMax];
    }
}

function mvp_color(float $rate, float $greenMax, float $yellowMax): array
{
    if ($rate <= $greenMax) return ['green', 'success'];
    if ($rate <= $yellowMax) return ['yellow', 'warning'];
    return ['red', 'danger'];
}

/* =========================================================
 * 売上
 * ======================================================= */
function mvp_sum_sales(PDO $pdo, int $tenantId, int $storeId, string $startYmd, string $endYmd): int
{
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(sales_yen),0)
        FROM daily_store_reports
        WHERE tenant_id=:t AND store_id=:s
          AND business_date BETWEEN :start AND :end
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':start' => $startYmd, ':end' => $endYmd]);
    return (int)$st->fetchColumn();
}

function mvp_sales_map(PDO $pdo, int $tenantId, int $storeId, string $startYmd, string $endYmd): array
{
    $st = $pdo->prepare("
        SELECT business_date, sales_yen
        FROM daily_store_reports
        WHERE tenant_id=:t AND store_id=:s
          AND business_date BETWEEN :start AND :end
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':start' => $startYmd, ':end' => $endYmd]);

    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $map[(string)$r['business_date']] = (int)$r['sales_yen'];
    }
    return $map;
}

/* =========================================================
 * 従業員の時給（store内全員）: フォールバック用
 * ======================================================= */
function mvp_employee_wage_map(PDO $pdo, int $tenantId, int $storeId): array
{
    $st = $pdo->prepare("
        SELECT id, COALESCE(hourly_wage_yen,0) AS hourly_wage_yen
        FROM employees
        WHERE tenant_id=:t AND store_id=:s
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId]);

    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$r['id']] = (int)$r['hourly_wage_yen'];
    }
    return $map;
}

/* =========================================================
 * 従業員の深夜割増設定（store内全員）
 * ======================================================= */
function mvp_employee_night_premium_map(PDO $pdo, int $tenantId, int $storeId): array
{
    $hasEnabled = mvp_has_column($pdo, 'employees', 'night_premium_enabled');
    $hasRate = mvp_has_column($pdo, 'employees', 'night_premium_rate_percent');
    if (!$hasEnabled && !$hasRate) return [];

    $selectEnabled = $hasEnabled ? "COALESCE(night_premium_enabled,0) AS night_premium_enabled" : "0 AS night_premium_enabled";
    $selectRate = $hasRate ? "COALESCE(night_premium_rate_percent,25) AS night_premium_rate_percent" : "25 AS night_premium_rate_percent";

    $st = $pdo->prepare("
        SELECT id, {$selectEnabled}, {$selectRate}
        FROM employees
        WHERE tenant_id=:t AND store_id=:s
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId]);

    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $eid = (int)($r['id'] ?? 0);
        if ($eid <= 0) continue;
        $enabled = ((int)($r['night_premium_enabled'] ?? 0) === 1);
        $rate = (int)($r['night_premium_rate_percent'] ?? 25);
        if (!in_array($rate, [25, 30, 35, 40, 45, 50], true)) $rate = 25;
        $map[$eid] = ['enabled' => $enabled, 'rate' => $rate];
    }
    return $map;
}

/* =========================================================
 * 時給履歴（employee_wage_histories）
 * ======================================================= */
function mvp_has_employee_wage_histories(PDO $pdo): bool
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
 * ✅ 期間終了日までの履歴を employee別にロード（昇順）
 * @return array<int, array<int, array{0:string,1:int}>>  [eid => [[ymd,wage], ...]]
 */
function mvp_load_wage_histories(PDO $pdo, int $tenantId, int $storeId, string $endYmd): array
{
    $out = [];
    try {
        $st = $pdo->prepare("
            SELECT employee_id, effective_business_day, hourly_wage_yen
            FROM employee_wage_histories
            WHERE tenant_id = :t
              AND store_id  = :s
              AND effective_business_day <= :end
            ORDER BY employee_id ASC, effective_business_day ASC, id ASC
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId, ':end' => $endYmd]);

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $eid = (int)($r['employee_id'] ?? 0);
            $day = (string)($r['effective_business_day'] ?? '');
            if ($eid <= 0) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) continue;

            $out[$eid][] = [$day, (int)($r['hourly_wage_yen'] ?? 0)];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * ✅ ある従業員の「その日」の時給を返す（履歴優先）
 * - effective_business_day <= day の最新
 * - 無ければ defaultWage
 *
 * @param array<int, array<int, array{0:string,1:int}>> $histByEmp
 */
function mvp_wage_for_day(array $histByEmp, int $eid, string $day, int $defaultWage): int
{
    static $cache = []; // "eid|day" => wage
    $ck = $eid . '|' . $day;
    if (isset($cache[$ck])) return (int)$cache[$ck];

    $hist = $histByEmp[$eid] ?? [];
    if (!$hist) {
        $cache[$ck] = $defaultWage;
        return $defaultWage;
    }

    // ✅ 二分探索（最後の <= day を探す）
    $lo = 0;
    $hi = count($hist) - 1;
    $best = null;

    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        $d = (string)($hist[$mid][0] ?? '');
        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            // 変なデータは安全にスキップ
            $lo = $mid + 1;
            continue;
        }

        if ($d <= $day) {
            $best = (int)($hist[$mid][1] ?? 0);
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }

    if ($best === null) {
        // ✅ 最古履歴を過去に引き継ぐ
        $best = (int)($hist[0][1] ?? $defaultWage);
    }
    $w = (int)$best;
    $cache[$ck] = $w;
    return $w;
}

/* =========================================================
 * ボーナス（日別）
 * ======================================================= */
function mvp_bonus_map(PDO $pdo, int $tenantId, string $startYmd, string $endYmd): array
{
    $st = $pdo->prepare("
        SELECT employee_id, business_date, COALESCE(SUM(bonus_yen),0) AS bonus
        FROM daily_wage_adjustments
        WHERE tenant_id=:t AND business_date BETWEEN :start AND :end
        GROUP BY employee_id, business_date
    ");
    $st->execute([':t' => $tenantId, ':start' => $startYmd, ':end' => $endYmd]);

    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $key = (int)$r['employee_id'] . '|' . (string)$r['business_date'];
        $map[$key] = (int)$r['bonus'];
    }
    return $map;
}

/* =========================================================
 * 店舗の打刻調整単位（分）
 * ======================================================= */
function mvp_round_unit_minutes(PDO $pdo, int $tenantId, int $storeId): int
{
    $roundUnit = 15;
    try {
        $st = $pdo->prepare("
            SELECT payroll_round_unit_minutes AS u
            FROM stores
            WHERE tenant_id=:t AND id=:s
            LIMIT 1
        ");
        $st->execute([':t' => $tenantId, ':s' => $storeId]);
        $val = $st->fetchColumn();
        if ($val === false || $val === null) {
            $roundUnit = 15;
        } else {
            $roundUnit = (int)$val; // 0=調整なし を維持
        }
    } catch (Throwable $e) {
        $roundUnit = 15;
    }

    $allowed = [0, 5, 10, 15, 20, 25, 30];
    if (!in_array($roundUnit, $allowed, true)) $roundUnit = 15;
    return $roundUnit;
}

/* =========================================================
 * 人件費（日別）
 * ======================================================= */
function mvp_daily_labor_detail(PDO $pdo, int $tenantId, int $storeId, string $startYmd, string $endYmd, bool $roundOn = true): array
{
    $timeCtx = mvp_store_time_context($pdo, $tenantId, $storeId);
    $storeTz = (string)($timeCtx['tz'] ?? 'Asia/Tokyo');
    $cutoffTime = (string)($timeCtx['cutoff'] ?? '05:00:00');

    // ✅ フォールバック（従来の現在時給）
    $wageMap  = mvp_employee_wage_map($pdo, $tenantId, $storeId);
    $nightPremiumMap = mvp_employee_night_premium_map($pdo, $tenantId, $storeId);

    // ✅ 履歴（あれば）
    $hasHist = mvp_has_employee_wage_histories($pdo);
    $histByEmp = $hasHist ? mvp_load_wage_histories($pdo, $tenantId, $storeId, $endYmd) : [];

    // ✅ 深夜跨ぎ対策：前日〜翌日まで拾う（clock_inが前日、clock_outが当日等）
    $startDt = date('Y-m-d', strtotime($startYmd . ' -1 day')) . ' 00:00:00';
    $endDt   = date('Y-m-d', strtotime($endYmd . ' +1 day')) . ' 23:59:59';

    $deletedSql = mvp_has_column($pdo, 'time_punches', 'deleted_at') ? " AND deleted_at IS NULL" : "";
    $st = $pdo->prepare("
        SELECT id, employee_id, punch_type, punched_at
        FROM time_punches
        WHERE tenant_id=:t AND store_id=:s
          {$deletedSql}
          AND punched_at >= :start_dt
          AND punched_at <= :end_dt
        ORDER BY employee_id ASC, punched_at ASC, id ASC
    ");
    $st->execute([':t' => $tenantId, ':s' => $storeId, ':start_dt' => $startDt, ':end_dt' => $endDt]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // time_punch_daily.php と同じく、別テーブルの休憩も混ぜる。
    try {
        $seen = [];
        foreach ($rows as $r) {
            $seen[((int)$r['employee_id']) . '|' . ((string)$r['punch_type']) . '|' . ((string)$r['punched_at'])] = true;
        }

        $bp = $pdo->prepare("
            SELECT id, employee_id, break_start_at,
                   COALESCE(break_end_at, break_start_at) AS break_end_at_safe,
                   break_end_at
            FROM break_punches
            WHERE tenant_id = :t
              AND store_id = :s
              AND COALESCE(break_end_at, break_start_at) >= :start_dt
              AND break_start_at <= :end_dt
            ORDER BY employee_id ASC, break_start_at ASC, id ASC
        ");
        $bp->execute([':t' => $tenantId, ':s' => $storeId, ':start_dt' => $startDt, ':end_dt' => $endDt]);
        foreach ($bp->fetchAll(PDO::FETCH_ASSOC) as $br) {
            $eid = (int)$br['employee_id'];
            $bid = (int)$br['id'];
            $bs = (string)($br['break_start_at'] ?? '');
            $beSafe = (string)($br['break_end_at_safe'] ?? '');
            $beRaw = $br['break_end_at'] ?? null;

            if ($bs !== '') {
                $k = $eid . '|break_in|' . $bs;
                if (!isset($seen[$k])) {
                    $rows[] = ['id' => -($bid * 2 + 1), 'employee_id' => $eid, 'punch_type' => 'break_in', 'punched_at' => $bs];
                    $seen[$k] = true;
                }
            }
            if ($beRaw !== null && $beSafe !== '' && $bs !== '' && $beSafe !== $bs) {
                $k = $eid . '|break_out|' . $beSafe;
                if (!isset($seen[$k])) {
                    $rows[] = ['id' => -($bid * 2 + 2), 'employee_id' => $eid, 'punch_type' => 'break_out', 'punched_at' => $beSafe];
                    $seen[$k] = true;
                }
            }
        }
        usort($rows, function ($a, $b) {
            $ae = (int)($a['employee_id'] ?? 0);
            $be = (int)($b['employee_id'] ?? 0);
            if ($ae !== $be) return $ae <=> $be;
            $at = strtotime((string)($a['punched_at'] ?? '')) ?: 0;
            $bt = strtotime((string)($b['punched_at'] ?? '')) ?: 0;
            if ($at !== $bt) return $at <=> $bt;
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });
    } catch (Throwable $e) {
        // break_punches が無い環境では time_punches の休憩だけで計算する
    }

    $unit = $roundOn ? mvp_round_unit_minutes($pdo, $tenantId, $storeId) : 0;

    // 打刻調整関数（in=切上げ / out=切捨て）
    $ceilTo = function (int $ts, int $unitMin): int {
        if ($unitMin <= 0) return $ts;
        $u = $unitMin * 60;
        return (int)(ceil($ts / $u) * $u);
    };
    $floorTo = function (int $ts, int $unitMin): int {
        if ($unitMin <= 0) return $ts;
        $u = $unitMin * 60;
        return (int)(floor($ts / $u) * $u);
    };
    $roundForCalc = function (int $ts, int $unitMin, string $type) use ($ceilTo, $floorTo): int {
        if ($type === 'break_in' || $type === 'break_out') return $ts;
        if ($unitMin <= 0) return $ts;
        if ($type === 'clock_out') return $floorTo($ts, $unitMin);
        return $ceilTo($ts, $unitMin);
    };

    // employee別の状態
    $state = []; // [eid => ['open_in'=>ts, 'break_in'=>ts, 'open_day'=>Y-m-d]]

    // employee×day の労働秒を積む
    $empDayWorkSec = []; // ["eid|Y-m-d" => seconds]
    $empDayBreakSec = []; // ["eid|Y-m-d" => seconds]
    $empDayNightWorkSec = []; // ["eid|Y-m-d" => seconds]
    $empDayNightBreakSec = []; // ["eid|Y-m-d" => seconds]

    foreach ($rows as $r) {
        $eid  = (int)$r['employee_id'];
        $type = (string)$r['punch_type'];

        $tsRaw = strtotime((string)$r['punched_at']);
        if ($tsRaw === false) continue;

        if (!isset($state[$eid])) {
            $state[$eid] = [
                'open_in' => null,
                'break_in' => null,
                'open_day' => null,
            ];
        }

        $ts = $roundForCalc((int)$tsRaw, $unit, $type);

        if ($type === 'clock_in') {
            $state[$eid]['open_in']   = $ts;
            $state[$eid]['break_in']  = null;
            $state[$eid]['open_day']  = mvp_business_date_from_ts((int)$tsRaw, $storeTz, $cutoffTime);
            continue;
        }

        if ($type === 'break_in') {
            if ($state[$eid]['open_in'] !== null && $state[$eid]['break_in'] === null) {
                $state[$eid]['break_in'] = $ts;
            }
            continue;
        }

        if ($type === 'break_out') {
            if ($state[$eid]['break_in'] !== null) {
                $day = (string)($state[$eid]['open_day'] ?? '');
                if ($day !== '') {
                    $key = $eid . '|' . $day;
                    $empDayBreakSec[$key] = (int)($empDayBreakSec[$key] ?? 0) + max(0, $ts - (int)$state[$eid]['break_in']);
                    $empDayNightBreakSec[$key] = (int)($empDayNightBreakSec[$key] ?? 0) + mvp_calc_night_seconds((int)$state[$eid]['break_in'], $ts);
                }
                $state[$eid]['break_in'] = null;
            }
            continue;
        }

        if ($type === 'clock_out') {
            if ($state[$eid]['open_in'] === null) {
                // 出勤なしの退勤は無視（盛らない）
                continue;
            }

            $day = (string)($state[$eid]['open_day'] ?? '');
            if ($day !== '') {
                $key = $eid . '|' . $day;
                $empDayWorkSec[$key] = (int)($empDayWorkSec[$key] ?? 0) + max(0, $ts - (int)$state[$eid]['open_in']);
                $empDayNightWorkSec[$key] = (int)($empDayNightWorkSec[$key] ?? 0) + mvp_calc_night_seconds((int)$state[$eid]['open_in'], $ts);
            }

            // リセット
            $state[$eid] = [
                'open_in' => null,
                'break_in' => null,
                'open_day' => null,
            ];
            continue;
        }

        // その他の punch_type は無視
    }

    // employee×day 秒 → 円 → day に合算
    $dailyYen = []; // [Y-m-d => yen]
    $dailyNightPremiumYen = []; // [Y-m-d => night premium yen]
    foreach ($empDayWorkSec as $k => $workSec) {
        [$eidStr, $day] = explode('|', $k, 2);
        $eid = (int)$eidStr;

        // 対象期間に絞る
        if ($day < $startYmd || $day > $endYmd) continue;

        $breakSec = (int)($empDayBreakSec[$k] ?? 0);
        $sec = max(0, $workSec - $breakSec);
        if ($sec <= 0) continue;

        // ✅ ここが本対応：履歴優先（<= day の最新）。無ければ employees の現在時給。
        $defaultHourly = (int)($wageMap[$eid] ?? 0);
        $hourly = $hasHist ? mvp_wage_for_day($histByEmp, $eid, $day, $defaultHourly) : $defaultHourly;

        if ($hourly <= 0) continue;

        $minutes = (int)floor(((int)$sec) / 60);
        if ($minutes <= 0) continue;

        // 既存仕様（分単位で円換算）: time_punch_daily と同じ丸め方に統一
        $base  = (int)round($minutes * ($hourly / 60.0));
        $nightSec = max(0, (int)($empDayNightWorkSec[$k] ?? 0) - (int)($empDayNightBreakSec[$k] ?? 0));
        $nightMinutes = (int)floor($nightSec / 60);
        $nightPremium = 0;
        if ($nightMinutes > 0) {
            $np = $nightPremiumMap[$eid] ?? null;
            if (!empty($np) && !empty($np['enabled'])) {
                $rate = (int)($np['rate'] ?? 25);
                $nightPremium = (int)round($nightMinutes * ($hourly / 60.0) * ($rate / 100.0));
            }
        }
        // time_punch_daily.php の「日給」と同じく、ボーナス/バックは人件費に加算しない。
        $yen   = $base + $nightPremium;

        if (!isset($dailyYen[$day])) $dailyYen[$day] = 0;
        $dailyYen[$day] += $yen;
        if (!isset($dailyNightPremiumYen[$day])) $dailyNightPremiumYen[$day] = 0;
        $dailyNightPremiumYen[$day] += $nightPremium;
    }

    ksort($dailyYen);
    ksort($dailyNightPremiumYen);
    return [
        'total' => $dailyYen,
        'night_premium' => $dailyNightPremiumYen,
    ];
}

function mvp_daily_labor(PDO $pdo, int $tenantId, int $storeId, string $startYmd, string $endYmd, bool $roundOn = true): array
{
    $detail = mvp_daily_labor_detail($pdo, $tenantId, $storeId, $startYmd, $endYmd, $roundOn);
    return $detail['total'] ?? [];
}
