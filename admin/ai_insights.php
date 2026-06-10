<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/ai_insights.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * 役割:
 * - 「AIに改善案」ボタン用（JSON返却）
 * - 表示期間: 売上/人件費/率 + 定休日/入力漏れ疑い
 * - ✅ 従業員評価は time_punches（punch_type/punched_at）から確定集計
 * - ✅ shifts があれば遅刻/残業/打刻漏れ(シフト有なのにin/out不足)も集計
 * - ✅ 日跨ぎ対応（clock_in→翌日clock_out）
 * - ✅ employees.display_name があればそれを表示名に使う
 * - ✅ 5ボタン用 choices を返す（UIでボタン化）
 */

header('Content-Type: application/json; charset=utf-8');

function out(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $e): void {
    out([
        'ok' => false,
        'error' => 'exception',
        'message' => $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
});
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    out([
        'ok' => false,
        'error' => 'php_error',
        'message' => $message,
        'where' => basename($file) . ':' . $line,
        'severity' => $severity,
    ]);
    return true;
});

require_once __DIR__ . '/_auth.php';
admin_session_bootstrap();

if (!isset($_SESSION['admin_auth']) || (int)$_SESSION['admin_auth'] !== 1) {
    out(['ok' => false, 'error' => 'not logged in']);
}

if (!isset($_SESSION['tenant_id'])) {
    $_SESSION['tenant_id'] = 1;
}

$authTenantId = (int)($_SESSION['tenant_id'] ?? 0);
if ($authTenantId > 0 && admin_is_tenant_inactive($authTenantId)) {
    out(['ok' => false, 'error' => 'tenant inactive']);
}
admin_load_acl();
if ($authTenantId > 0 && admin_is_trial_restricted($authTenantId)) {
    out(['ok' => false, 'error' => 'trial expired']);
}

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    out(['ok' => false, 'error' => 'tenant not found']);
}
$tenantId = (int)$tenantId;

/* ===========================
 * DB
 * =========================== */
try {
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
    if ($dbFile === null) throw new RuntimeException('db.php not found');
    require_once $dbFile;

    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // dotenv / openai
    $bootstrap = __DIR__ . '/../api/bootstrap.php';
    if (!is_file($bootstrap)) throw new RuntimeException('bootstrap.php not found');
    require_once $bootstrap;

    $client = __DIR__ . '/../api/lib/openai_client.php';
    if (!is_file($client)) throw new RuntimeException('openai_client.php not found');
    require_once $client;
} catch (Throwable $e) {
    out(['ok' => false, 'error' => $e->getMessage()]);
}

/* ===========================
 * util
 * =========================== */
function table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
function has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
        $st->execute([':c' => $column]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function valid_ymd_param(string $ymd): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $ymd;
}

function classify_punch_type(string $v): string
{
    $t = strtolower(trim($v));
    if ($t === 'clock_in')  return 'in';
    if ($t === 'clock_out') return 'out';
    if ($t === 'break_in')  return 'break_in';
    if ($t === 'break_out') return 'break_out';
    return 'unknown';
}

function find_employee_table(PDO $pdo): ?string
{
    foreach (['employees', 'staffs', 'workers'] as $t) {
        if (table_exists($pdo, $t)) return $t;
    }
    return null;
}
function find_employee_name_col(PDO $pdo, string $empTable): ?string
{
    // ✅ display_name を最優先
    foreach (['display_name', 'name', 'full_name', 'nickname'] as $c) {
        if (has_column($pdo, $empTable, $c)) return $c;
    }
    return null;
}

function build_employee_name_map(PDO $pdo, int $tenantId, int $storeId): array
{
    $empTable = find_employee_table($pdo);
    if (!$empTable) return ['table' => null, 'name_col' => null, 'map' => []];

    $nameCol = find_employee_name_col($pdo, $empTable);
    if (!$nameCol) return ['table' => $empTable, 'name_col' => null, 'map' => []];

    $where = [];
    $params = [];
    if (has_column($pdo, $empTable, 'tenant_id')) {
        $where[] = "tenant_id=:t";
        $params[':t'] = $tenantId;
    }
    if (has_column($pdo, $empTable, 'store_id')) {
        $where[] = "store_id=:s";
        $params[':s'] = $storeId;
    }

    $sql = "SELECT id, `{$nameCol}` AS nm FROM `{$empTable}`";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$r['id'];
        $nm = trim((string)($r['nm'] ?? ''));
        if ($id > 0) $map[$id] = ($nm !== '' ? $nm : "ID:{$id}");
    }
    return ['table' => $empTable, 'name_col' => $nameCol, 'map' => $map];
}

/**
 * shifts テーブル探索（あなたの環境差を吸収）
 * 想定候補:
 * - shifts: start_at/end_at (datetime)
 * - shifts: start_time/end_time (time) + shift_date/work_date/business_date (date)
 */
function find_shifts_table(PDO $pdo): ?string
{
    foreach (['shifts', 'shift', 'shift_logs', 'work_shifts'] as $t) {
        if (table_exists($pdo, $t)) return $t;
    }
    return null;
}

/**
 * shifts を「日別・従業員別」に正規化して返す
 * return: [employee_id][Y-m-d] => [ [start_ts,end_ts], ... ]  (複数シフト対応)
 *
 * ✅ 日跨ぎ: end < start なら end を +1day
 */
function load_shift_windows(PDO $pdo, int $tenantId, int $storeId, string $fromYmd, string $toYmd, string $tz): array
{
    $t = find_shifts_table($pdo);
    if (!$t) return [];

    $hasEmp = has_column($pdo, $t, 'employee_id');
    if (!$hasEmp) return [];

    $startAt = null;
    $endAt = null;
    foreach (['start_at', 'scheduled_start', 'shift_start', 'starts_at'] as $c) {
        if (has_column($pdo, $t, $c)) {
            $startAt = $c;
            break;
        }
    }
    foreach (['end_at', 'scheduled_end', 'shift_end', 'ends_at'] as $c) {
        if (has_column($pdo, $t, $c)) {
            $endAt = $c;
            break;
        }
    }

    // time型（start_time/end_time + date）
    $startTime = null;
    $endTime = null;
    $dateCol = null;
    foreach (['start_time', 'shift_start_time'] as $c) {
        if (has_column($pdo, $t, $c)) {
            $startTime = $c;
            break;
        }
    }
    foreach (['end_time', 'shift_end_time'] as $c) {
        if (has_column($pdo, $t, $c)) {
            $endTime = $c;
            break;
        }
    }
    foreach (['work_date', 'shift_date', 'business_date', 'date'] as $c) {
        if (has_column($pdo, $t, $c)) {
            $dateCol = $c;
            break;
        }
    }

    $where = [];
    $params = [':from' => $fromYmd, ':to' => $toYmd, ':t' => $tenantId, ':s' => $storeId];

    if (has_column($pdo, $t, 'tenant_id')) $where[] = "tenant_id=:t";
    if (has_column($pdo, $t, 'store_id'))  $where[] = "store_id=:s";

    if ($startAt && $endAt) {
        // startAt の日付で期間絞り
        $where[] = "DATE(`{$startAt}`) BETWEEN :from AND :to";
        $sql = "SELECT employee_id, `{$startAt}` AS st, `{$endAt}` AS en FROM `{$t}` WHERE " . implode(' AND ', $where);
    } elseif ($startTime && $endTime && $dateCol) {
        $where[] = "`{$dateCol}` BETWEEN :from AND :to";
        $sql = "SELECT employee_id, `{$dateCol}` AS d, `{$startTime}` AS stt, `{$endTime}` AS ent FROM `{$t}` WHERE " . implode(' AND ', $where);
    } else {
        return [];
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = [];
    $tzObj = new DateTimeZone($tz);

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $eid = (int)$r['employee_id'];
        if ($eid <= 0) continue;

        if ($startAt && $endAt) {
            $stStr = (string)($r['st'] ?? '');
            $enStr = (string)($r['en'] ?? '');
            if ($stStr === '' || $enStr === '') continue;

            $stDt = new DateTimeImmutable($stStr, $tzObj);
            $enDt = new DateTimeImmutable($enStr, $tzObj);

            // ✅ 日跨ぎ
            if ($enDt < $stDt) $enDt = $enDt->modify('+1 day');

            $ymd = $stDt->format('Y-m-d');
            $out[$eid][$ymd][] = ['start_ts' => $stDt->getTimestamp(), 'end_ts' => $enDt->getTimestamp()];
        } else {
            $d = (string)($r['d'] ?? '');
            $stt = (string)($r['stt'] ?? '');
            $ent = (string)($r['ent'] ?? '');
            if ($d === '' || $stt === '' || $ent === '') continue;

            $stDt = new DateTimeImmutable($d . ' ' . $stt, $tzObj);
            $enDt = new DateTimeImmutable($d . ' ' . $ent, $tzObj);
            if ($enDt < $stDt) $enDt = $enDt->modify('+1 day');

            $out[$eid][$d][] = ['start_ts' => $stDt->getTimestamp(), 'end_ts' => $enDt->getTimestamp()];
        }
    }
    return $out;
}

/**
 * time_punches（イベントログ型）から、従業員別に
 * - 出勤日数
 * - 稼働分（in-outペア）
 * - 休憩分（break_in-break_out）
 * - 打刻異常（outだけ等）
 * を集計
 *
 * ✅ 日跨ぎ：clock_in の後、次の clock_out は翌日でもペアにする（最大+24h程度）
 */
function build_workers_from_punches(PDO $pdo, int $tenantId, int $storeId, string $fromYmd, string $toYmd, string $tz, array $nameMap, array $shiftWindows, int $graceMin = 5): array
{
    if (!table_exists($pdo, 'time_punches')) {
        return ['status' => 'unknown', 'note' => 'time_punches が見つかりません', 'rows' => [], 'employee_summary_json' => []];
    }
    $t = 'time_punches';
    foreach (['employee_id', 'punch_type', 'punched_at'] as $c) {
        if (!has_column($pdo, $t, $c)) {
            return ['status' => 'unknown', 'note' => "time_punches に必要カラム {$c} がありません", 'rows' => [], 'employee_summary_json' => []];
        }
    }

    $where = ["DATE(punched_at) BETWEEN :from AND :to"];
    $params = [':from' => $fromYmd, ':to' => $toYmd, ':t' => $tenantId, ':s' => $storeId];

    if (has_column($pdo, $t, 'tenant_id')) $where[] = "tenant_id=:t";
    if (has_column($pdo, $t, 'store_id'))  $where[] = "store_id=:s";

    // deleted_at があれば除外
    if (has_column($pdo, $t, 'deleted_at')) $where[] = "deleted_at IS NULL";

    $sql = "SELECT employee_id, punch_type, punched_at
            FROM `{$t}`
            WHERE " . implode(' AND ', $where) . "
            ORDER BY employee_id ASC, punched_at ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $tzObj = new DateTimeZone($tz);

    // events grouped by employee
    $eventsByEmp = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $eid = (int)$r['employee_id'];
        if ($eid <= 0) continue;

        $pt = classify_punch_type((string)$r['punch_type']);
        $at = (string)$r['punched_at'];
        if ($at === '') continue;

        $dt = new DateTimeImmutable($at, $tzObj);
        $eventsByEmp[$eid][] = [
            'ts' => $dt->getTimestamp(),
            'ymd' => $dt->format('Y-m-d'),
            'type' => $pt,
        ];
    }

    if (!$eventsByEmp) {
        return ['status' => 'empty', 'note' => '期間内の打刻がありません', 'rows' => [], 'employee_summary_json' => []];
    }

    $rows = [];
    $summary = [];

    foreach ($eventsByEmp as $eid => $events) {
        $workDaysSet = [];
        $workMinutes = 0;
        $breakMinutes = 0;

        $lateCount = 0;
        $lateMinutes = 0;
        $overtimeMinutes = 0;
        $noPunchShifts = 0;

        $anomaly = 0;

        // セッション状態
        $openIn = null;      // ts
        $openInDay = null;   // ymd
        $breakIn = null;     // ts

        // その日ごとの first_in / last_out を作る（シフト突合用）
        $firstInByDay = [];
        $lastOutByDay = [];

        foreach ($events as $e) {
            $ts = (int)$e['ts'];
            $ymd = (string)$e['ymd'];
            $type = (string)$e['type'];

            if ($type === 'in') {
                $workDaysSet[$ymd] = true;
                if (!isset($firstInByDay[$ymd]) || $ts < $firstInByDay[$ymd]) $firstInByDay[$ymd] = $ts;

                // 既にopenが残ってたら異常（上書きして続行）
                if ($openIn !== null) $anomaly++;
                $openIn = $ts;
                $openInDay = $ymd;
            } elseif ($type === 'out') {
                $workDaysSet[$ymd] = true;
                if (!isset($lastOutByDay[$ymd]) || $ts > $lastOutByDay[$ymd]) $lastOutByDay[$ymd] = $ts;

                if ($openIn === null) {
                    $anomaly++;
                    continue;
                }
                $durMin = (int)max(0, ($ts - $openIn) / 60);
                // ✅ 24h超はおかしいので切る
                if ($durMin > 24 * 60) {
                    $anomaly++;
                    $openIn = null;
                    $openInDay = null;
                    continue;
                }

                $workMinutes += $durMin;
                $openIn = null;
                $openInDay = null;

                // break_in が閉じてなければ異常扱いでリセット
                if ($breakIn !== null) {
                    $anomaly++;
                    $breakIn = null;
                }
            } elseif ($type === 'break_in') {
                if ($breakIn !== null) {
                    $anomaly++;
                }
                $breakIn = $ts;
            } elseif ($type === 'break_out') {
                if ($breakIn === null) {
                    $anomaly++;
                    continue;
                }
                $b = (int)max(0, ($ts - $breakIn) / 60);
                if ($b > 6 * 60) {
                    $anomaly++;
                    $breakIn = null;
                    continue;
                } // 休憩6h超は異常
                $breakMinutes += $b;
                $breakIn = null;
            }
        }

        // 休憩を差し引く（最低0）
        $netMinutes = max(0, $workMinutes - $breakMinutes);

        // ✅ シフト突合（あれば）
        if (isset($shiftWindows[$eid]) && is_array($shiftWindows[$eid])) {
            foreach ($shiftWindows[$eid] as $d => $wins) {
                foreach ($wins as $w) {
                    $stTs = (int)$w['start_ts'];
                    $enTs = (int)$w['end_ts'];

                    // シフトあるのに in/out が見当たらない → 打刻なしシフト
                    $inTs = $firstInByDay[$d] ?? null;
                    // 日跨ぎの退勤は翌日へ出るので、翌日も見る
                    $outTs = $lastOutByDay[$d] ?? null;
                    if ($outTs === null) {
                        // 翌日側も探す（シフトが跨ぎなら特に）
                        $next = (new DateTimeImmutable($d, $tzObj))->modify('+1 day')->format('Y-m-d');
                        $outTs = $lastOutByDay[$next] ?? null;
                    }

                    if ($inTs === null && $outTs === null) {
                        $noPunchShifts++;
                        continue;
                    }

                    // 遅刻（grace）
                    if ($inTs !== null) {
                        $late = (int)max(0, ($inTs - ($stTs + $graceMin * 60)) / 60);
                        if ($late > 0) {
                            $lateCount++;
                            $lateMinutes += $late;
                        }
                    }

                    // 残業（退勤がシフト終了+graceより遅い）
                    if ($outTs !== null) {
                        $ot = (int)max(0, ($outTs - ($enTs + $graceMin * 60)) / 60);
                        if ($ot > 0) $overtimeMinutes += $ot;
                    }
                }
            }
        } else {
            // shifts 無い環境なら null で返す
            $lateCount = -1;
            $lateMinutes = -1;
            $overtimeMinutes = -1;
            $noPunchShifts = -1;
        }

        $nm = $nameMap[$eid] ?? "ID:{$eid}";

        $rows[] = [
            'employee_id' => $eid,
            'employee_name' => $nm,
            'work_days' => count($workDaysSet),
            'work_minutes' => $netMinutes,
            'break_minutes' => $breakMinutes,
            'late_count' => ($lateCount < 0 ? null : $lateCount),
            'late_minutes' => ($lateMinutes < 0 ? null : $lateMinutes),
            'overtime_minutes' => ($overtimeMinutes < 0 ? null : $overtimeMinutes),
            'no_punch_shifts' => ($noPunchShifts < 0 ? null : $noPunchShifts),
            'punch_anomaly' => $anomaly,
        ];

        $summary[] = [
            'employee_id' => $eid,
            'employee_name' => $nm,
            'attend_days' => count($workDaysSet),
        ];
    }

    // ソート：稼働分 desc
    usort($rows, fn($a, $b) => ((int)$b['work_minutes'] <=> (int)$a['work_minutes']) ?: ((int)$a['employee_id'] <=> (int)$b['employee_id']));
    usort($summary, fn($a, $b) => ((int)$b['attend_days'] <=> (int)$a['attend_days']) ?: ((int)$a['employee_id'] <=> (int)$b['employee_id']));

    return [
        'status' => 'ok',
        'note' => 'time_punches(punch_type/punched_at) + shifts(あれば) で集計しました。',
        'rows' => $rows,
        'employee_summary_json' => $summary,
    ];
}

/**
 * AI本文を 1)〜5) で分割（UIで5ボタン化しやすくする）
 */
function split_sections(string $text): array
{
    $text = trim($text);
    $parts = preg_split('/^\s*(\d\))\s*/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!$parts || count($parts) < 3) {
        return [
            'full' => $text,
            '1' => null,
            '2' => null,
            '3' => null,
            '4' => null,
            '5' => null,
        ];
    }
    $out = ['full' => $text, '1' => null, '2' => null, '3' => null, '4' => null, '5' => null];

    // parts: [before, "1)", body1, "2)", body2, ...]
    for ($i = 1; $i < count($parts) - 1; $i += 2) {
        $k = trim($parts[$i]);
        $body = trim($parts[$i + 1] ?? '');
        $num = (int)str_replace(')', '', $k);
        if ($num >= 1 && $num <= 5) $out[(string)$num] = $body;
    }
    return $out;
}
/**
 * 分 → 「〇〇時間〇〇分」
 * - null は null のまま返す（呼び出し側で "不明" にできる）
 */
function minutes_to_hm(?int $minutes): ?string
{
    if ($minutes === null) return null;
    $m = max(0, (int)$minutes);
    $h = intdiv($m, 60);
    $r = $m % 60;
    return "{$h}時間{$r}分";
}

/* ===========================
 * timezone
 * =========================== */
$tz = 'Asia/Tokyo';
try {
    if (table_exists($pdo, 'tenants') && has_column($pdo, 'tenants', 'timezone')) {
        $st = $pdo->prepare("SELECT timezone FROM tenants WHERE id=:id");
        $st->execute([':id' => $tenantId]);
        $v = (string)($st->fetchColumn() ?: '');
        if ($v !== '') $tz = $v;
    }
} catch (Throwable $e) {
}
date_default_timezone_set($tz);

$now   = new DateTimeImmutable('now', new DateTimeZone($tz));
$today = $now->format('Y-m-d');
$from  = $now->modify('-29 days')->format('Y-m-d');

$reqFrom = (string)($_GET['from'] ?? '');
$reqTo = (string)($_GET['to'] ?? '');
if (valid_ymd_param($reqFrom) && valid_ymd_param($reqTo)) {
    $fromDt = new DateTimeImmutable($reqFrom, new DateTimeZone($tz));
    $toDt = new DateTimeImmutable($reqTo, new DateTimeZone($tz));
    if ($fromDt <= $toDt && $fromDt->diff($toDt)->days <= 62) {
        $from = $reqFrom;
        $today = $reqTo;
    }
}

/* ===========================
 * stores / threshold (DB優先)
 * =========================== */
if (!table_exists($pdo, 'stores')) out(['ok' => false, 'error' => 'stores table not found']);

$storesStmt = $pdo->prepare("
    SELECT id, name,
           COALESCE(labor_green_max_rate, 30.00)  AS g,
           COALESCE(labor_yellow_max_rate, 35.00) AS y
    FROM stores
    WHERE tenant_id = :tenant_id
    ORDER BY id ASC
");
$storesStmt->execute([':tenant_id' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (!$stores) out(['ok' => false, 'error' => 'stores not found']);

$storeId = (int)($_GET['store_id'] ?? $stores[0]['id']);
$storeIds = array_map('intval', array_column($stores, 'id'));
if (!in_array($storeId, $storeIds, true)) $storeId = (int)$stores[0]['id'];

$roundOn = ((int)($_GET['round15'] ?? 1) === 1);
$sideCommentMode = ((string)($_GET['side_comment'] ?? '') === '1');

$greenMax = 30.0;
$yellowMax = 35.0;
$storeName = '';

foreach ($stores as $s) {
    if ((int)$s['id'] === $storeId) {
        $greenMax  = (float)$s['g'];     // ✅ DB設定が最優先（固定ではない）
        $yellowMax = (float)$s['y'];
        $storeName = (string)($s['name'] ?? '');
        break;
    }
}

/* ===========================
 * labor_mvp + sales map
 * =========================== */
$laborMvpPath = __DIR__ . '/../api/lib/labor_mvp.php';
if (!is_file($laborMvpPath)) out(['ok' => false, 'error' => 'labor_mvp.php not found']);
require_once $laborMvpPath;

// sales map
$salesMap = [];
if (table_exists($pdo, 'daily_store_reports') && has_column($pdo, 'daily_store_reports', 'sales_yen') && has_column($pdo, 'daily_store_reports', 'business_date')) {
    $stSales = $pdo->prepare("
        SELECT business_date, COALESCE(sales_yen,0) AS sales_yen
        FROM daily_store_reports
        WHERE tenant_id=:t AND store_id=:s
          AND business_date BETWEEN :from AND :to
    ");
    $stSales->execute([':t' => $tenantId, ':s' => $storeId, ':from' => $from, ':to' => $today]);
    while ($r = $stSales->fetch(PDO::FETCH_ASSOC)) {
        $d = (string)$r['business_date'];
        $salesMap[$d] = (int)$r['sales_yen'];
    }
}

// labor map (既存のmvp算出)
$laborMap = mvp_daily_labor($pdo, $tenantId, $storeId, $from, $today, $roundOn);

/* ===========================
 * 表示期間配列
 * =========================== */
$dates = [];
$dt  = new DateTimeImmutable($from, new DateTimeZone($tz));
$end = new DateTimeImmutable($today, new DateTimeZone($tz));
for ($i = 0; $i < 60; $i++) {
    $d = $dt->format('Y-m-d');
    $dates[] = $d;
    if ($d === $end->format('Y-m-d')) break;
    $dt = $dt->modify('+1 day');
}

/* ===========================
 * 集計（定休日/入力漏れ疑い）
 * =========================== */
$sumSalesAll = 0;
$sumLaborAll = 0;

$openSalesSum = 0;
$openLaborSum = 0;
$openDays = 0;

$rateListOpen = [];

$salesZeroTotalDays = 0;
$holidayDays = 0;
$holidayList = [];
$missingSalesSuspectDays = 0;
$missingSalesSuspectList = [];
$highDays = 0;
$weekJa = ['日', '月', '火', '水', '木', '金', '土'];
$highWeekdayCounts = array_fill(0, 7, 0);
$missingWeekdayCounts = array_fill(0, 7, 0);

$dailyCompact = [];

foreach ($dates as $d) {
    $sales = (int)($salesMap[$d] ?? 0);
    $labor = (int)($laborMap[$d] ?? 0);

    $sumSalesAll += $sales;
    $sumLaborAll += $labor;

    $rate = null;

    if ($sales <= 0) {
        $salesZeroTotalDays++;
        if ($labor <= 0) {
            $holidayDays++;
            $holidayList[] = $d;
        } else {
            $missingSalesSuspectDays++;
            $missingSalesSuspectList[] = $d;
            $missingWeekdayCounts[(int)(new DateTimeImmutable($d, new DateTimeZone($tz)))->format('w')]++;
        }
    } else {
        $openDays++;
        $openSalesSum += $sales;
        $openLaborSum += $labor;

        $rate = ($labor / $sales * 100.0);
        $rateListOpen[] = $rate;
        if ($rate > $yellowMax) {
            $highDays++;
            $highWeekdayCounts[(int)(new DateTimeImmutable($d, new DateTimeZone($tz)))->format('w')]++;
        }
    }

    $weekdayIndex = (int)(new DateTimeImmutable($d, new DateTimeZone($tz)))->format('w');
    $dailyCompact[] = [
        'date' => $d,
        'weekday' => $weekJa[$weekdayIndex],
        'sales' => $sales,
        'labor' => $labor,
        'rate' => ($rate === null ? null : round($rate, 1)),
        'is_holiday' => ($sales <= 0 && $labor <= 0),
        'is_missing_sales_suspect' => ($sales <= 0 && $labor > 0),
        'is_high_labor_rate' => ($rate !== null && $rate > $yellowMax),
        'is_open' => ($sales > 0),
    ];
}

$avgRate = ($openSalesSum > 0) ? round(($openLaborSum / $openSalesSum) * 100.0, 1) : null;
$avgRateDaily = $rateListOpen ? round(array_sum($rateListOpen) / count($rateListOpen), 1) : null;
$periodDays = count($dates);

/* ===========================
 * ✅ 従業員集計（あなたのtime_punches仕様に対応）
 * =========================== */
$nameInfo = build_employee_name_map($pdo, $tenantId, $storeId);
$nameMap  = $nameInfo['map'] ?? [];

$shiftWindows = load_shift_windows($pdo, $tenantId, $storeId, $from, $today, $tz);

$workersPack = build_workers_from_punches($pdo, $tenantId, $storeId, $from, $today, $tz, $nameMap, $shiftWindows, 5);

$workers = [
    'status' => $workersPack['status'] ?? 'unknown',
    'schema' => 'time_punches(punch_type/punched_at)' . (find_shifts_table($pdo) ? '+shifts' : ''),
    'rows'   => $workersPack['rows'] ?? [],
    'note'   => $workersPack['note'] ?? '',
    'employee_table' => $nameInfo['table'] ?? null,
    'employee_name_col' => $nameInfo['name_col'] ?? null,
    'shifts_table' => find_shifts_table($pdo),
];

$employeeSummaryJson = $workersPack['employee_summary_json'] ?? [];

/* ===========================
 * OpenAI prompt（“人名を入れる” + 事実ベース）
 * =========================== */
$missingListText = $missingSalesSuspectList ? implode(', ', array_slice($missingSalesSuspectList, 0, 10)) : '-';
$holidayListText = $holidayList ? implode(', ', array_slice($holidayList, 0, 10)) : '-';
$weekdaySummary = [];
foreach ($weekJa as $idx => $label) {
    $weekdaySummary[] = $label . ':注意' . (int)$highWeekdayCounts[$idx] . '日/入力漏れ疑い' . (int)$missingWeekdayCounts[$idx] . '日';
}
$weekdaySummaryText = implode(', ', $weekdaySummary);

// workers 上位10を材料に
// workers 上位10を材料に（AIには「〇〇時間〇〇分」で渡す）
$workerMaterial = "従業員データ: 取得不可";
if (($workers['status'] ?? '') === 'ok' && !empty($workers['rows'])) {
    $top = array_slice($workers['rows'], 0, 10);
    $lines = [];

    foreach ($top as $r) {
        $nm = (string)$r['employee_name'];

        $workHm = minutes_to_hm((int)$r['work_minutes']); // work_minutes は常にint想定
        $lateHm = ($r['late_minutes'] === null) ? null : minutes_to_hm((int)$r['late_minutes']);
        $otHm   = ($r['overtime_minutes'] === null) ? null : minutes_to_hm((int)$r['overtime_minutes']);

        $lines[] =
            "- {$nm}：{$r['work_days']}日 / {$workHm}" .
            ($r['late_count'] !== null
                ? " / 遅刻{$r['late_count']}回(" . ($lateHm ?? "0時間0分") . ")"
                : " / 遅刻:不明"
            ) .
            ($r['overtime_minutes'] !== null
                ? " / 残業" . ($otHm ?? "0時間0分")
                : " / 残業:不明"
            ) .
            ($r['no_punch_shifts'] !== null ? " / 打刻なし予定{$r['no_punch_shifts']}回" : "");
    }

    $workerMaterial =
        "従業員データ（事実・上位10）:\n" . implode("\n", $lines) .
        "\n※必ず『名前』で書く。推測はしない。";
}


$prompt = $sideCommentMode ? [
    "あなたは店舗の勤怠・人件費分析AIです。",
    "下の表示期間データを見て、人件費率が高い日の傾向を短く分析してください。",
    "必ず曜日傾向（何曜日が多いか）、売上0で人件費がある日、すぐ確認することを含めてください。",
    "形式: 1)結論 2)曜日傾向 3)確認すべき日 4)次の打ち手。各項目は1〜2行。",
    "",
    "店舗: {$storeName}",
    "期間: {$from}〜{$today}",
    "注意基準: 人件費率 {$yellowMax}% 超",
    "平均人件費率: " . ($avgRate === null ? "不明" : "{$avgRate}%"),
    "注意日数: {$highDays}日",
    "曜日集計: {$weekdaySummaryText}",
    "売上0かつ人件費あり: {$missingSalesSuspectDays}日 / {$missingListText}",
    "日別データ(JSON):",
    json_encode($dailyCompact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
] : [
    "あなたは飲食店の店長補佐AIです。",
    "読みやすさ最優先。長文禁止。専門用語禁止。",
    "",
    "【出力形式（厳守）】",
    "1) 結論（1行）",
    "2) 今の問題（最大3つ）",
    "3) すぐやる改善（最大3つ）",
    "4) 従業員評価（最大8行・必ず個人名を入れる・事実だけ）",
    "   - 良い動き（誰が/何が良い）",
    "   - 注意（誰が/何が問題）",
    "   - シフトの使い方（1つ）",
    "5) 明日やること（最大3つ）",
    "",
    "【判定ルール（超重要）】",
    "- ✅定休日は『売上0 かつ 人件費0』。入力漏れ扱いしない。",
    "- ✅入力漏れ疑いは『売上0 かつ 人件費>0』のみ。",
    "- ✅平均人件費率は『営業日（売上>0）』だけで考える。",
    "- 人件費率が {$yellowMax}% を超える営業日は『注意日』。",
    "- 従業員評価は、下の従業員データがある範囲のみで書く。無い項目は『不明』と書く。",
    "",
    "【店舗・期間】",
    "店舗: {$storeName} (store_id={$storeId})",
    "期間: {$from}〜{$today}",
    "打刻調整: " . ($roundOn ? "ON(15分)" : "OFF"),
    "",
    "【集計（表示期間: {$periodDays}日）】",
    "合計売上（全日）: " . number_format($sumSalesAll) . "円",
    "合計人件費（全日）: " . number_format($sumLaborAll) . "円",
    "営業日数（売上>0）: {$openDays}日",
    "平均人件費率（営業日ベース）: " . ($avgRate === null ? "不明" : "{$avgRate}%"),
    "注意日数(営業日で>{$yellowMax}%): {$highDays}日",
    "",
    "売上0の日（合計）: {$salesZeroTotalDays}日",
    "定休日（売上0&人件費0）: {$holidayDays}日 / 先頭10件: {$holidayListText}",
    "入力漏れ疑い（売上0&人件費>0）: {$missingSalesSuspectDays}日 / 先頭10件: {$missingListText}",
    "",
    $workerMaterial,
    "",
    "日別(表示期間)データ:",
    json_encode($dailyCompact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    "【次アクションの表現ルール】",
    "- 次アクションでは専門用語を使わない",
    "- 次の言い換えを必ず使う：",
    "  * shifts → 予定の勤務時間（開始・終了・休憩）",
    "  * work_date/start_time/end_time/break_minutes → 日付/開始/終了/休憩",
    "  * time_punches → 出退勤の記録",
    "  * 打刻ログ → 出退勤ボタンの履歴",
    "  * 深夜跨ぎ → 日付またぎ",
    "  * 端末 → スマホ/タブレット/レジ端末（現場の呼び方）",
    "- 文章は「店長がスタッフに口頭で伝える」言い方にする（敬語は丁寧すぎず、現場向け）",
    "- 1文は長くしない（最大60文字目安）。箇条書きを優先する",
    "- 文章は「店長がスタッフに口頭で伝える」言い方にする（敬語は丁寧すぎず、現場向け）",
    "- 1文は長くしない（最大60文字目安）。箇条書きを優先する",
    "【言い換え辞書】",
    "- 打刻：出勤/退勤ボタン（出退勤の記録）",
    "- シフト：予定されていた勤務時間",
    "- 残業：予定より長く働いた時間",
    "- 未打刻：押し忘れ（記録が片方だけ/無い）",
    "- 集計：まとめ（数を数える/合計する）",
    "【重要】",
    "- 取れていない情報で断定しない。必ず『今わかること/わからないこと』を分けて書く",
    "- 次アクションは「ユーザーの質問に直結するもの」だけを2〜3個に絞る（関係ない一般論は書かない）",
    "- 個人を責めない言い方にする（原因は『仕組み/運用/記録の抜け』を優先）",
    "- 注意が必要な場合も、改善の一言を必ず添える",
];

$inputText = implode("\n", $prompt);

try {
    // ✅ max tokens 短め
    $resp = openai_responses('gpt-4.1-mini', $inputText, 24);
    $text = trim((string)openai_extract_text($resp));
} catch (Throwable $e) {
    out(['ok' => false, 'error' => $e->getMessage()]);
}

$sections = split_sections($text);

out([
    'ok' => true,
    'from' => $from,
    'to' => $today,
    'store_id' => $storeId,
    'store_name' => $storeName,
    'round15' => $roundOn ? 1 : 0,

    'sales_sum_all' => $sumSalesAll,
    'labor_sum_all' => $sumLaborAll,

    'open_days' => $openDays,
    'open_sales_sum' => $openSalesSum,
    'open_labor_sum' => $openLaborSum,

    'rate_avg' => $avgRate,
    'rate_avg_daily_open' => $avgRateDaily,

    'sales_zero_days' => $salesZeroTotalDays,
    'holiday_days' => $holidayDays,
    'holiday_list' => $holidayList,
    'missing_sales_suspect_days' => $missingSalesSuspectDays,
    'missing_sales_suspect_list' => $missingSalesSuspectList,
    'high_days' => $highDays,
    'weekday_summary' => $weekdaySummaryText,
    'daily' => $dailyCompact,

    // ✅ 従業員（ここが今まで空だった）
    'employee_summary_period_from' => $from,
    'employee_summary_period_to' => $today,
    'employee_summary_json' => $employeeSummaryJson,

    'workers' => $workers,

    // AIテキスト + 分割
    'insights' => $text,
    'sections' => $sections,

    // ✅ UIで5ボタン化するためのchoices（index.php側で表示してクリック→ai_followupへ）
    'followup_choices' => [
        ['key' => '1', 'label' => '1) 結論を詳しく'],
        ['key' => '2', 'label' => '2) 今の問題を詳しく'],
        ['key' => '3', 'label' => '3) すぐやる改善を詳しく'],
        ['key' => '4', 'label' => '4) 従業員評価を詳しく'],
        ['key' => '5', 'label' => '5) 明日やることを詳しく'],
    ],
]);
