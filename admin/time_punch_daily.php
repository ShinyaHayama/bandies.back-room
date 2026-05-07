<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/time_punch_daily.php
 * ✅ 書き込み場所: 既存ファイルを「丸ごと置き換え」
 *
 * ✅ 今回の修正（最重要）
 * - 過去の計算を「過去の時給」で行うために employee_wage_histories を参照して時給を決定する
 *   - キーは effective_business_day（= 営業日 cutoff 済みの日付キー）
 *   - ✅「その日ピッタリ」ではなく「effective_business_day <= その日の最新」を採用（変更日だけ保存でも正しく引き継ぐ）
 *   - 履歴が無い日は従来通り employees.hourly_wage_yen（現在の設定時給）にフォールバック（既存を壊さない）
 *
 * ✅ 既存UI/既存ロジックは維持（あなたが貼った現状コードを前提に最小差分で反映）
 * - 「勤怠登録」「給料明細（印刷）」ボタンは常に表示
 * - ボーナス/バックはある日だけ表示（0は非表示）
 * - 営業日(cutoff)で集計
 * - from/to, 月プルダウン, 在籍/退職/全員, 打刻調整(店舗設定0なら非表示) 維持
 * - quick_add の device_id FK 対策 維持
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n\n";
    echo "Exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    echo $e->getTraceAsString();
    exit;
});
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

date_default_timezone_set('Asia/Tokyo');

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
if ($dbFile === null) {
    throw new RuntimeException("db.php not found. tried:\n" . implode("\n", $paths));
}
require_once $dbFile;
require_once __DIR__ . '/../lib/punch_source.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
punch_source_ensure_column($pdo);

// ===== helpers =====
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function wdayJa(string $ymd): string
{
    $ts = strtotime($ymd);
    if ($ts === false) return '';
    return ['日', '月', '火', '水', '木', '金', '土'][(int)date('w', $ts)] ?? '';
}
function wdayNum(string $ymd): int
{
    $ts = strtotime($ymd);
    if ($ts === false) return 0;
    return (int)date('w', $ts); // 0=日..6=土
}

function ceilToMinutes(int $ts, int $unitMinutes): int
{
    if ($unitMinutes <= 0) return $ts;
    $unit = $unitMinutes * 60;
    return (int)(ceil($ts / $unit) * $unit);
}
function floorToMinutes(int $ts, int $unitMinutes): int
{
    if ($unitMinutes <= 0) return $ts;
    $unit = $unitMinutes * 60;
    return (int)(floor($ts / $unit) * $unit);
}
function roundTsForCalc(int $ts, int $unitMinutes, string $type): int
{
    // ✅ 休憩は打刻調整ない（実時間で計算）
    if ($type === 'break_in' || $type === 'break_out') return $ts;

    if ($unitMinutes <= 0) return $ts;
    if ($type === 'clock_out') return floorToMinutes($ts, $unitMinutes);
    return ceilToMinutes($ts, $unitMinutes);
}

function secToHM(int $sec): string
{
    $m = (int)floor($sec / 60);
    $h = (int)floor($m / 60);
    $mm = $m % 60;
    return sprintf('%d:%02d', $h, $mm);
}
function calcNightSeconds(int $startTs, int $endTs): int
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
function fmtTimeRawAndRounded(?int $ts, int $unitMinutes, string $punchType): string
{
    if ($ts === null) return '-';
    $raw = date('H:i', $ts);
    if ($unitMinutes <= 0) return $raw;

    $rounded = ($punchType === 'clock_out' || $punchType === 'break_out')
        ? floorToMinutes($ts, $unitMinutes)
        : ceilToMinutes($ts, $unitMinutes);

    $r = date('H:i', $rounded);
    if ($raw === $r) return $raw;
    return $raw . ' <span class="sub timeRoundedSub">(→' . $r . ')</span>';
}

function punchSourceBadgeMeta(?string $source): array
{
    $normalized = punch_source_normalize($source);
    return match ($normalized) {
        'line' => ['label' => 'LINE', 'short' => 'L', 'class' => 'isLine'],
        'mypage' => ['label' => 'マイページ', 'short' => 'Q', 'class' => 'isQr'],
        'ipad' => ['label' => 'iPad', 'short' => 'I', 'class' => 'isIpad'],
        'admin' => ['label' => '管理', 'short' => 'A', 'class' => 'isAdmin'],
        default => ['label' => '-', 'short' => '?', 'class' => 'isUnknown'],
    };
}

function punchSourceSvgIcon(?string $source): string
{
    $normalized = punch_source_normalize($source);

    return match ($normalized) {
        'line' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.5c-3.59 0-6.5 2.31-6.5 5.17 0 2.56 2.3 4.71 5.39 5.09l-.29 1.95c-.04.26.24.46.48.33l2.27-1.62c3.03-.18 5.15-2.28 5.15-5.09C14.5 3.81 11.59 1.5 8 1.5Z" fill="currentColor"/></svg>',
        'mypage' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 2h4v1.5H3.5V6H2V2Zm8 0h4v4h-1.5V3.5H10V2ZM2 10h1.5v2.5H6V14H2v-4Zm10 2.5V10H14v4h-4v-1.5h2.5ZM5 5h2v2H5V5Zm4 0h2v2H9V5ZM5 9h2v2H5V9Zm4 0h2v2H9V9Z" fill="currentColor"/></svg>',
        'ipad' => '<svg viewBox="0 0 16 16" aria-hidden="true"><rect x="4.2" y="1.8" width="7.6" height="12.4" rx="1.4" fill="none" stroke="currentColor" stroke-width="1.4"/><circle cx="8" cy="11.9" r=".7" fill="currentColor"/></svg>',
        'admin' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 13.5h10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M4.5 12.8V6.2M8 12.8V3.8m3.5 9V7.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M4.5 6.2 8 3.8l3.5 3.7" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
        default => '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M8 5.2v3.1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="8" cy="11.5" r=".8" fill="currentColor"/></svg>',
    };
}

function punchSourceSummaryHtml(?string $clockInSource, ?string $clockOutSource): string
{
    $entries = [];

    $add = function (string $prefix, ?string $source) use (&$entries): void {
        $normalized = punch_source_normalize($source);
        if ($normalized === '') return;
        $meta = punchSourceBadgeMeta($normalized);
        $entries[] = '<span class="sourceBadgeWrap">'
            . '<span class="sourcePrefix">' . h($prefix) . '</span>'
            . '<span class="sourceBadge ' . h($meta['class']) . '" title="' . h($meta['label']) . '">'
            . '<span class="sourceBadgeIcon" aria-hidden="true">' . h($meta['short']) . '</span>'
            . '<span class="sourceBadgeLabel">' . h($meta['label']) . '</span>'
            . '</span>'
            . '</span>';
    };

    $in = punch_source_normalize($clockInSource);
    $out = punch_source_normalize($clockOutSource);
    if ($in !== '' && $out !== '' && $in === $out) {
        $meta = punchSourceBadgeMeta($in);
        return '<span class="sourceBadgeWrap">'
            . '<span class="sourceBadge ' . h($meta['class']) . '" title="' . h($meta['label']) . '">'
            . '<span class="sourceBadgeIcon" aria-hidden="true">' . h($meta['short']) . '</span>'
            . '<span class="sourceBadgeLabel">' . h($meta['label']) . '</span>'
            . '</span>'
            . '</span>';
    }

    $add('出', $clockInSource);
    $add('退', $clockOutSource);

    if (!$entries) {
        return '<span class="sourceBadge sourceBadgeEmpty">-</span>';
    }
    return '<span class="sourceBadges">' . implode('', $entries) . '</span>';
}

function punchSourceTinyIconHtml(?string $source): string
{
    $normalized = punch_source_normalize($source);
    if ($normalized === '') return '';

    $meta = punchSourceBadgeMeta($normalized);
    return '<span class="sourceTiny ' . h($meta['class']) . '" title="' . h($meta['label']) . '" aria-label="' . h($meta['label']) . '">'
        . '<span class="sourceTinyIcon" aria-hidden="true">' . punchSourceSvgIcon($normalized) . '</span>'
        . '</span>';
}

function timeWithPunchSourceHtml(?int $ts, int $unitMinutes, string $punchType, ?string $source): string
{
    $timeHtml = fmtTimeRawAndRounded($ts, $unitMinutes, $punchType);
    $iconHtml = punchSourceTinyIconHtml($source);
    if ($iconHtml === '') {
        return $timeHtml;
    }
    return '<span class="timeWithSource"><span class="timeWithSourceLabel">' . $timeHtml . '</span>' . $iconHtml . '</span>';
}

/**
 * ✅ DB列の差異に強い（列メタキャッシュあり）
 * - SHOW COLUMNS の結果を丸ごとキャッシュして、Null/Default も参照できるようにする
 */
function tableColumnMeta(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $meta = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $field = (string)($r['Field'] ?? '');
        if ($field === '') continue;
        $meta[$field] = $r;
    }
    $cache[$table] = $meta;
    return $meta;
}
function tableColumns(PDO $pdo, string $table): array
{
    $meta = tableColumnMeta($pdo, $table);
    return array_keys($meta);
}
function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return !empty($row);
    } catch (Throwable $e) {
        return false;
    }
}

function safeInsert(PDO $pdo, string $table, array $data): void
{
    $cols = tableColumns($pdo, $table);
    $use = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $cols, true)) $use[$k] = $v;
    }
    if (empty($use)) {
        throw new RuntimeException("INSERT failed: no matching columns for table {$table}");
    }
    $fields = array_keys($use);
    $ph = array_map(fn($f) => ':' . $f, $fields);

    $sql = "INSERT INTO `{$table}` (" . implode(',', array_map(fn($f) => "`{$f}`", $fields)) . ") VALUES (" . implode(',', $ph) . ")";
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($use as $k => $v) $params[':' . $k] = $v;
    $stmt->execute($params);
}

function ensurePaymentStatusTable(PDO $pdo): void
{
    if (tableExists($pdo, 'payroll_payment_statuses')) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_payment_statuses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            store_id INT NOT NULL,
            employee_id INT NOT NULL,
            business_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unconfirmed',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_payment_status (tenant_id, store_id, employee_id, business_date),
            KEY idx_payment_status_range (tenant_id, store_id, business_date),
            KEY idx_payment_status_emp (tenant_id, store_id, employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * ✅ time_punches.device_id FK対策：device_id をDBから安全に解決する
 * - 推測は禁止：DBから「存在する device_id」だけ拾う
 */
function resolveDeviceIdForTimePunch(PDO $pdo, int $tenantId, int $storeId, int $employeeId): ?int
{
    // 1) 同じ従業員の直近 punch から device_id を拾う
    try {
        $stmt = $pdo->prepare("
            SELECT device_id
            FROM time_punches
            WHERE tenant_id = :tenant_id
              AND store_id  = :store_id
              AND employee_id = :employee_id
              AND device_id IS NOT NULL
              AND device_id <> 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':store_id' => $storeId,
            ':employee_id' => $employeeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['device_id'])) {
            $v = (int)$row['device_id'];
            if ($v > 0) return $v;
        }
    } catch (Throwable $e) {
        // 無視（列/テーブル差異など）
    }

    // 2) 同店舗の直近 punch から device_id を拾う
    try {
        $stmt = $pdo->prepare("
            SELECT device_id
            FROM time_punches
            WHERE tenant_id = :tenant_id
              AND store_id  = :store_id
              AND device_id IS NOT NULL
              AND device_id <> 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':store_id' => $storeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['device_id'])) {
            $v = (int)$row['device_id'];
            if ($v > 0) return $v;
        }
    } catch (Throwable $e) {
        // 無視
    }

    // 3) devices テーブルから（存在する列だけで）拾う
    try {
        $devCols = tableColumns($pdo, 'devices');
        $where = [];
        $params = [];

        if (in_array('tenant_id', $devCols, true)) {
            $where[] = "tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }
        if (in_array('store_id', $devCols, true)) {
            $where[] = "store_id = :store_id";
            $params[':store_id'] = $storeId;
        }
        if (in_array('is_active', $devCols, true)) {
            $where[] = "is_active = 1";
        }
        // deleted_at がある場合は NULL のみ優先（値仕様が環境差あるためNULLのみ）
        if (in_array('deleted_at', $devCols, true)) {
            $where[] = "deleted_at IS NULL";
        }

        $sql = "SELECT id FROM devices";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY id ASC LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['id'])) {
            $v = (int)$row['id'];
            if ($v > 0) return $v;
        }
    } catch (Throwable $e) {
        // devices が無い/権限なし等は無視
    }

    return null;
}

/**
 * ✅ business_day_cutoff_time を秒に変換（"05:00" / "05:00:00" どちらもOK）
 * - 不正値は 0秒扱い（=00:00）
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
 * ✅ punched_at(ts) から「営業日(business_date)」を算出
 * - その日の cutoff より前（例: 00:00〜04:59）は「前日」を営業日として返す
 */
function businessDateFromTs(int $ts, int $cutoffSeconds): string
{
    $dayStart = strtotime(date('Y-m-d 00:00:00', $ts));
    if ($dayStart === false) {
        return date('Y-m-d', $ts);
    }
    $cutoffTs = (int)$dayStart + $cutoffSeconds;

    if ($cutoffSeconds <= 0) return date('Y-m-d', $ts);

    if ($ts < $cutoffTs) {
        return date('Y-m-d', strtotime('-1 day', (int)$dayStart));
    }
    return date('Y-m-d', $ts);
}

/**
 * ✅ YYYY-mm-dd の連続日配列（from..to）
 * - なぜ必要か：時給履歴を「変更日だけ保存」していても、日別に時給を引き継いで適用するため
 *
 * @return string[]
 */
function buildDateRangeYmd(string $from, string $to): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) return [];
    if ($from > $to) return [];

    $s = DateTimeImmutable::createFromFormat('Y-m-d', $from, new DateTimeZone('Asia/Tokyo'));
    $e = DateTimeImmutable::createFromFormat('Y-m-d', $to, new DateTimeZone('Asia/Tokyo'));
    if (!$s || !$e) return [];

    $out = [];
    for ($d = $s; $d <= $e; $d = $d->modify('+1 day')) {
        $out[] = $d->format('Y-m-d');
        if (count($out) > 400) break; // 保険
    }
    return $out;
}

/**
 * ✅ 勤怠登録用：時刻プルダウン生成（5分刻み）
 *
 * @return string[]
 */
function buildTimeOptions(int $stepMinutes = 5): array
{
    if ($stepMinutes <= 0) $stepMinutes = 5;
    if (60 % $stepMinutes !== 0) $stepMinutes = 5;

    $opts = [];
    for ($h = 0; $h <= 23; $h++) {
        for ($m = 0; $m < 60; $m += $stepMinutes) {
            $opts[] = sprintf('%02d:%02d', $h, $m);
        }
    }
    return $opts;
}

function buildSessions(array $rows, int $roundUnit, array $typeJa, int $cutoffSeconds): array
{
    $byEmp = [];
    foreach ($rows as $r) {
        $eid = (int)$r['employee_id'];
        $byEmp[$eid][] = $r;
    }

    $sessions = [];
    $openStackByEmp = [];
    $seqCounter = [];

    $ensure = function (int $eid, string $name, string $day, int $seq) use (&$sessions): string {
        $key = $eid . '|' . $day . '|' . $seq;
        if (!isset($sessions[$key])) {
            $sessions[$key] = [
                'employee_id' => $eid,
                'display_name' => $name,
                'day' => $day,
                'seq' => $seq,
                'work_seconds' => 0,
                'break_seconds' => 0,
                'night_seconds' => 0,
                '_night_work_seconds' => 0,
                '_night_break_seconds' => 0,
                'events' => [],
                'warn' => [],
                'clock_in_source' => '',
                'clock_out_source' => '',
                '_display_in_ts' => null,
                '_display_out_ts' => null,
                '_edit_clock_in_id' => 0,
                '_edit_clock_out_id' => 0,
                '_open_in_calc' => null,
                '_open_break_calc' => null,
            ];
        }
        return $key;
    };

    foreach ($byEmp as $eid => $punches) {
        usort($punches, function ($a, $b) {
            $at = strtotime((string)($a['punched_at'] ?? '')) ?: 0;
            $bt = strtotime((string)($b['punched_at'] ?? '')) ?: 0;
            if ($at !== $bt) return $at <=> $bt;
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });

        foreach ($punches as $r) {
            $name = (string)$r['display_name'];
            $type = (string)$r['punch_type'];
            $id   = (int)$r['id'];

            $tsRaw = strtotime((string)$r['punched_at']);
            if ($tsRaw === false) continue;
            $tsRaw = (int)$tsRaw;

            $tsCalc = roundTsForCalc($tsRaw, $roundUnit, $type);

            $day = businessDateFromTs($tsRaw, $cutoffSeconds);

            $eventLine = date('H:i:s', $tsRaw) . ' ' . ($typeJa[$type] ?? $type);

            if (!isset($openStackByEmp[$eid])) $openStackByEmp[$eid] = [];

            if ($type === 'clock_in') {
                $seqKey = $eid . '|' . $day;
                $seqCounter[$seqKey] = ($seqCounter[$seqKey] ?? 0) + 1;
                $seq = $seqCounter[$seqKey];

                $key = $ensure($eid, $name, $day, $seq);
                $sessions[$key]['events'][] = $eventLine;

                if ($sessions[$key]['_display_in_ts'] === null) {
                    $sessions[$key]['_display_in_ts'] = $tsRaw;
                    $sessions[$key]['_edit_clock_in_id'] = $id;
                    $sessions[$key]['clock_in_source'] = punch_source_infer_from_row($r);
                }

                $sessions[$key]['_open_in_calc'] = $tsCalc;

                $openStackByEmp[$eid][] = $key;
                continue;
            }

            $stack = $openStackByEmp[$eid];
            $key = '';
            if (!empty($stack)) {
                $key = (string)end($stack);
            }

            if ($key === '' || !isset($sessions[$key])) {
                $seq = 0;
                $key = $ensure($eid, $name, $day, $seq);
                $sessions[$key]['events'][] = $eventLine;

                if ($type === 'clock_out') {
                    $sessions[$key]['warn'][] = '出勤なし';
                    $sessions[$key]['_display_out_ts'] = $tsRaw;
                    $sessions[$key]['_edit_clock_out_id'] = $id;
                } elseif ($type === 'break_in') {
                    $sessions[$key]['warn'][] = '休憩開始（出勤なし）';
                } elseif ($type === 'break_out') {
                    $sessions[$key]['warn'][] = '休憩終了（開始なし/出勤なし）';
                }
                continue;
            }

            $sessions[$key]['events'][] = $eventLine;

            if ($type === 'break_in') {
                $openBreak = $sessions[$key]['_open_break_calc'] ?? null;
                if ($openBreak !== null) $sessions[$key]['warn'][] = '休憩終了なし';
                $sessions[$key]['_open_break_calc'] = $tsCalc;
            } elseif ($type === 'break_out') {
                $openBreak = $sessions[$key]['_open_break_calc'] ?? null;
                if ($openBreak === null) {
                    $sessions[$key]['warn'][] = '休憩開始なし';
                } else {
                    $sessions[$key]['break_seconds'] += max(0, $tsCalc - (int)$openBreak);
                    $sessions[$key]['_night_break_seconds'] += calcNightSeconds((int)$openBreak, $tsCalc);
                    $sessions[$key]['_open_break_calc'] = null;
                }
            } elseif ($type === 'clock_out') {
                $openIn = $sessions[$key]['_open_in_calc'] ?? null;
                if ($openIn === null) {
                    $sessions[$key]['warn'][] = '出勤なし';
                } else {
                    $sessions[$key]['work_seconds'] += max(0, $tsCalc - (int)$openIn);
                    $sessions[$key]['_night_work_seconds'] += calcNightSeconds((int)$openIn, $tsCalc);
                    $sessions[$key]['_open_in_calc'] = null;

                    if ($sessions[$key]['_display_out_ts'] === null) {
                        $sessions[$key]['_display_out_ts'] = $tsRaw;
                        $sessions[$key]['_edit_clock_out_id'] = $id;
                        $sessions[$key]['clock_out_source'] = punch_source_infer_from_row($r);
                    }

                    array_pop($openStackByEmp[$eid]);
                }
            }
        }
    }

    foreach ($sessions as $k => &$s) {
        if (($s['_open_in_calc'] ?? null) !== null) $s['warn'][] = '退勤なし';
        if (($s['_open_break_calc'] ?? null) !== null) $s['warn'][] = '休憩終了なし';
        $nightWork = (int)($s['_night_work_seconds'] ?? 0);
        $nightBreak = (int)($s['_night_break_seconds'] ?? 0);
        $s['night_seconds'] = max(0, $nightWork - $nightBreak);
        unset(
            $s['_open_in_calc'],
            $s['_open_break_calc'],
            $s['_night_work_seconds'],
            $s['_night_break_seconds']
        );
    }
    unset($s);

    foreach ($sessions as $k => $s) {
        if (empty($s['events'])) unset($sessions[$k]);
    }

    return array_values($sessions);
}

/**
 * ✅ 従業員の「在籍/退職」を判定（列名は環境差があるため、存在する列だけ使う）
 */
function employeeActiveState(array $e, array $meta): ?bool
{
    if ($meta['has_is_active']) {
        $v = $e['is_active'] ?? null;
        if ($v === null) return null;
        return ((int)$v === 1);
    }

    if ($meta['has_is_retired']) {
        $v = $e['is_retired'] ?? null;
        if ($v === null) return null;
        return ((int)$v === 0);
    }

    $dateCols = $meta['date_cols'];
    foreach ($dateCols as $col) {
        $v = $e[$col] ?? null;
        if ($v === null) continue;
        $sv = trim((string)$v);
        if ($sv === '') continue;
        if ($sv === '0000-00-00 00:00:00' || $sv === '0000-00-00') continue;
        return false;
    }
    if (!empty($dateCols)) {
        return true;
    }

    $statusCols = $meta['status_cols'];
    foreach ($statusCols as $col) {
        $v = $e[$col] ?? null;
        if ($v === null) continue;
        $sv = mb_strtolower(trim((string)$v));
        if ($sv === '') continue;

        $activeWords = ['active', 'enabled', 'current', 'employed', 'working', 'in_service', 'valid', 'on'];
        $activeJa    = ['在籍', '有効', '稼働', '勤務', '現役'];

        $retiredWords = ['retired', 'terminated', 'inactive', 'disabled', 'left', 'quit', 'resigned', 'off'];
        $retiredJa    = ['退職', '退社', '離職', '無効', '停止', '削除'];

        foreach ($activeWords as $w) {
            if ($sv === $w) return true;
        }
        foreach ($retiredWords as $w) {
            if ($sv === $w) return false;
        }

        foreach ($activeJa as $w) {
            if (trim((string)$v) === $w) return true;
        }
        foreach ($retiredJa as $w) {
            if (trim((string)$v) === $w) return false;
        }

        return null;
    }

    return null;
}

// ===== input =====
$msg = (string)($_GET['msg'] ?? '');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

$storeId    = (int)($_GET['store_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);

$empScope = (string)($_GET['emp_scope'] ?? 'active');
if (!in_array($empScope, ['active', 'retired', 'all'], true)) $empScope = 'active';

$hasPeriodParam = array_key_exists('period', $_GET);
$hasFromParam = array_key_exists('from', $_GET);
$hasToParam = array_key_exists('to', $_GET);
$defaultPeriod = date('Y-m');
$period = $hasPeriodParam
    ? (string)$_GET['period']
    : (($hasFromParam || $hasToParam) ? '' : $defaultPeriod);

$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-t');
$from = (string)($_GET['from'] ?? $defaultFrom);
$to   = (string)($_GET['to']   ?? $defaultTo);

if ($period === 'recent30') {
    $to = date('Y-m-d');
    $from = date('Y-m-d', strtotime($to . ' -29 day'));
} elseif (preg_match('/^\d{4}-\d{2}$/', $period)) {
    $y = (int)substr($period, 0, 4);
    $m = (int)substr($period, 5, 2);
    if ($y >= 2000 && $y <= 2100 && $m >= 1 && $m <= 12) {
        $from = sprintf('%04d-%02d-01', $y, $m);
        $to = date('Y-m-t', strtotime($from));
    }
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $defaultTo;
if ($from > $to) [$from, $to] = [$to, $from];

$roundOnParam = ((int)($_GET['round15'] ?? 1) === 1);

$startDt = date('Y-m-d', strtotime($from . ' -1 day')) . ' 00:00:00';
$endDt   = date('Y-m-d', strtotime($to . ' +2 day')) . ' 00:00:00';

// ===== stores =====
$storeCols = tableColumns($pdo, 'stores');
$hasCutoff = in_array('business_day_cutoff_time', $storeCols, true);
$hasRound  = in_array('payroll_round_unit_minutes', $storeCols, true);

$selectCutoff = $hasCutoff
    ? "COALESCE(business_day_cutoff_time, '00:00:00') AS business_day_cutoff_time"
    : "'00:00:00' AS business_day_cutoff_time";

$selectRound = $hasRound
    ? "COALESCE(payroll_round_unit_minutes, 15) AS payroll_round_unit_minutes"
    : "15 AS payroll_round_unit_minutes";

$storesStmt = $pdo->prepare("
    SELECT id, name, {$selectRound}, {$selectCutoff}
    FROM stores
    WHERE tenant_id = :tenant_id
    ORDER BY id ASC
");
$storesStmt->execute([':tenant_id' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (empty($stores)) {
    http_response_code(400);
    echo "stores がありません。tenant_id={$tenantId}";
    exit;
}

$validStoreIds = array_map(fn($s) => (int)$s['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $validStoreIds, true)) $storeId = (int)$stores[0]['id'];

$storeRoundUnit = 15;
$storeName = '';
$storeCutoffStr = '00:00:00';

foreach ($stores as $st) {
    if ((int)$st['id'] === $storeId) {
        $storeName = (string)$st['name'];
        $storeRoundUnit = (int)($st['payroll_round_unit_minutes'] ?? 15);
        $storeCutoffStr = (string)($st['business_day_cutoff_time'] ?? '00:00:00');
        break;
    }
}
if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $storeCutoffStr)) {
    $storeCutoffStr = substr($storeCutoffStr, 0, 5);
}

$allowedUnits = [0, 5, 10, 15, 20, 25, 30];
if (!in_array($storeRoundUnit, $allowedUnits, true)) $storeRoundUnit = 15;

$cutoffSeconds = cutoffToSeconds($storeCutoffStr);

$canToggleRounding = ($storeRoundUnit > 0);
$roundOn = $canToggleRounding ? $roundOnParam : false;
$roundUnit = $roundOn ? $storeRoundUnit : 0;

// ===== employees =====
$empCols = tableColumns($pdo, 'employees');

$possibleStatusCols = [];
foreach (['status', 'employment_status'] as $c) {
    if (in_array($c, $empCols, true)) $possibleStatusCols[] = $c;
}
$possibleDateCols = [];
foreach (['retired_at', 'terminated_at', 'deleted_at'] as $c) {
    if (in_array($c, $empCols, true)) $possibleDateCols[] = $c;
}
$hasIsActive  = in_array('is_active', $empCols, true);
$hasIsRetired = in_array('is_retired', $empCols, true);
$hasAnyStatusSignal = $hasIsActive || $hasIsRetired || !empty($possibleStatusCols) || !empty($possibleDateCols);

$extraSelects = [];
if ($hasIsActive)  $extraSelects[] = "is_active";
if ($hasIsRetired) $extraSelects[] = "is_retired";
foreach ($possibleStatusCols as $c) $extraSelects[] = $c;
foreach ($possibleDateCols as $c)   $extraSelects[] = $c;
$hasNightPremiumEnabled = in_array('night_premium_enabled', $empCols, true);
$hasNightPremiumRate = in_array('night_premium_rate_percent', $empCols, true);
if ($hasNightPremiumEnabled) $extraSelects[] = "night_premium_enabled";
if ($hasNightPremiumRate) $extraSelects[] = "night_premium_rate_percent";

$extraSql = '';
if (!empty($extraSelects)) {
    $extraSql = ", " . implode(", ", array_map(fn($c) => "`{$c}`", $extraSelects));
}

$empStmt = $pdo->prepare("
  SELECT id, display_name, sort_order, hourly_wage_yen {$extraSql}
  FROM employees
  WHERE tenant_id = :tenant_id AND store_id = :store_id
  ORDER BY sort_order ASC, id ASC
");
$empStmt->execute([':tenant_id' => $tenantId, ':store_id' => $storeId]);
$employeesAll = $empStmt->fetchAll();

$empMeta = [
    'has_is_active'   => $hasIsActive,
    'has_is_retired'  => $hasIsRetired,
    'status_cols'     => $possibleStatusCols,
    'date_cols'       => $possibleDateCols,
];

$employeesActive = [];
$employeesRetired = [];
$employeesUnknown = [];

foreach ($employeesAll as $e) {
    $st = employeeActiveState($e, $empMeta);
    if ($st === true) {
        $employeesActive[] = $e;
    } elseif ($st === false) {
        $employeesRetired[] = $e;
    } else {
        $employeesUnknown[] = $e;
    }
}

$employeesFiltered = [];
if ($empScope === 'active') {
    $employeesFiltered = $hasAnyStatusSignal ? $employeesActive : $employeesAll;
} elseif ($empScope === 'retired') {
    $employeesFiltered = $employeesRetired;
} else {
    $employeesFiltered = $employeesAll;
}

$scopeEmpIds = [];
if ($employeeId === 0 && $empScope !== 'all') {
    foreach ($employeesFiltered as $e) {
        $scopeEmpIds[] = (int)$e['id'];
    }
    $scopeEmpIds = array_values(array_unique($scopeEmpIds));
}

$existsSelected = false;
foreach ($employeesFiltered as $e) {
    if ((int)$e['id'] === $employeeId) {
        $existsSelected = true;
        break;
    }
}
if ($employeeId > 0 && !$existsSelected) {
    foreach ($employeesAll as $e) {
        if ((int)$e['id'] === $employeeId) {
            $employeesFiltered[] = $e;
            break;
        }
    }
}

// ✅ 設定時給マップ（従来どおりのフォールバック用）
$wageMap = [];
foreach ($employeesAll as $e) $wageMap[(int)$e['id']] = (int)($e['hourly_wage_yen'] ?? 0);
$nightPremiumEnabledMap = [];
$nightPremiumRateMap = [];
foreach ($employeesAll as $e) {
    $eid = (int)($e['id'] ?? 0);
    if ($eid <= 0) continue;
    $nightPremiumEnabledMap[$eid] = (int)($e['night_premium_enabled'] ?? 0) === 1;
    $rate = (int)($e['night_premium_rate_percent'] ?? 25);
    if (!in_array($rate, [25, 30, 35, 40, 45, 50], true)) $rate = 25;
    $nightPremiumRateMap[$eid] = $rate;
}

/**
 * ✅ 追加：過去時給（営業日キー）マップ（employee_wage_histories）
 * - 重要：履歴が「変更日だけ」保存されても正しく使えるように
 *   「effective_business_day <= 当日の最新」を日別に引き継いで埋める
 */
$effectiveWageMap = []; // "eid|day" => hourly
$hasWageHistory = tableExists($pdo, 'employee_wage_histories');
if ($hasWageHistory) {
    try {
        $whParams = [
            ':tenant_id' => $tenantId,
            ':store_id'  => $storeId,
            ':to_day'    => $to,
        ];
        $whWhereEmp = "";
        if ($employeeId > 0) {
            $whWhereEmp = " AND employee_id = :employee_id ";
            $whParams[':employee_id'] = $employeeId;
        }

        // ✅ from以前の履歴も必要（期間開始時点の時給を引き継ぐため）
        $whStmt = $pdo->prepare("
            SELECT employee_id, effective_business_day, hourly_wage_yen
            FROM employee_wage_histories
            WHERE tenant_id = :tenant_id
              AND store_id  = :store_id
              $whWhereEmp
              AND effective_business_day <= :to_day
            ORDER BY employee_id ASC, effective_business_day ASC, id ASC
        ");
        $whStmt->execute($whParams);

        // eid => [[day,wage], ...]（昇順）
        $whByEmp = [];
        foreach ($whStmt->fetchAll() as $r) {
            $eid = (int)($r['employee_id'] ?? 0);
            $day = (string)($r['effective_business_day'] ?? '');
            if ($eid <= 0) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) continue;
            $whByEmp[$eid][] = [$day, (int)($r['hourly_wage_yen'] ?? 0)];
        }

        $daysInRange = buildDateRangeYmd($from, $to);

        // 対象従業員（表示対象 + 選択従業員）
        $targetEmpIds = [];
        foreach ($employeesAll as $e) $targetEmpIds[] = (int)$e['id'];
        $targetEmpIds = array_values(array_unique($targetEmpIds));

        foreach ($targetEmpIds as $eid) {
            $defaultHourly = (int)($wageMap[$eid] ?? 0);
            $hist = $whByEmp[$eid] ?? [];

            // pointerで日付を前から追う（<=当日 の最新を適用）
            $idx = 0;
            $cur = $defaultHourly;
            if (!empty($hist)) {
                // ✅ 最古履歴を過去に引き継ぐ（過去が新時給で汚染されないようにする）
                $cur = (int)$hist[0][1];
            }

            foreach ($daysInRange as $dayKey) {
                while ($idx < count($hist) && (string)$hist[$idx][0] <= $dayKey) {
                    $cur = (int)$hist[$idx][1];
                    $idx++;
                }
                $effectiveWageMap[$eid . '|' . $dayKey] = $cur;
            }
        }
    } catch (Throwable $e) {
        // ここで落ちると既存表示が壊れるので、履歴は無かった扱いにして従来計算に戻す
        $effectiveWageMap = [];
        $hasWageHistory = false;
    }
}

// ===== POST quick_add（そのまま + FK device_id 対策だけ）=====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'set_payment_status' || $action === 'bulk_payment_status') {
        $csrfPost = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrf, $csrfPost)) {
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('CSRFエラー') . '&' . http_build_query($_GET));
            exit;
        }

        $status = (string)($_POST['payment_status'] ?? '');
        $validStatuses = ['unconfirmed', 'checking', 'paid', 'canceled'];
        if (!in_array($status, $validStatuses, true)) {
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('ステータスが不正です') . '&' . http_build_query($_GET));
            exit;
        }

        try {
            ensurePaymentStatusTable($pdo);
        } catch (Throwable $e) {
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('保存先の準備に失敗しました') . '&' . http_build_query($_GET));
            exit;
        }

        $validEmpIds = array_map(fn($e) => (int)$e['id'], $employeesAll);

        $targets = [];
        if ($action === 'set_payment_status') {
            $eid = (int)($_POST['employee_id'] ?? 0);
            $day = (string)($_POST['day'] ?? '');
            if ($eid <= 0 || !in_array($eid, $validEmpIds, true)) {
                header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('従業員が不正です') . '&' . http_build_query($_GET));
                exit;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('日付が不正です') . '&' . http_build_query($_GET));
                exit;
            }
            $targets[] = [$eid, $day];
        } else {
            $keys = $_POST['payment_keys'] ?? [];
            if (!is_array($keys) || empty($keys)) {
                header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('対象が選択されていません') . '&' . http_build_query($_GET));
                exit;
            }
            foreach ($keys as $k) {
                $k = (string)$k;
                if (!preg_match('/^(\d+)\|(\d{4}-\d{2}-\d{2})$/', $k, $m)) continue;
                $eid = (int)$m[1];
                $day = (string)$m[2];
                if ($eid <= 0 || !in_array($eid, $validEmpIds, true)) continue;
                $targets[] = [$eid, $day];
            }
            if (!$targets) {
                header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('対象が不正です') . '&' . http_build_query($_GET));
                exit;
            }
        }

        try {
            $pdo->beginTransaction();
            foreach ($targets as [$eid, $day]) {
                if ($status === 'unconfirmed') {
                    $del = $pdo->prepare("
                        DELETE FROM payroll_payment_statuses
                        WHERE tenant_id = :t AND store_id = :s AND employee_id = :e AND business_date = :d
                        LIMIT 1
                    ");
                    $del->execute([
                        ':t' => $tenantId,
                        ':s' => $storeId,
                        ':e' => $eid,
                        ':d' => $day,
                    ]);
                } else {
                    $up = $pdo->prepare("
                        INSERT INTO payroll_payment_statuses
                          (tenant_id, store_id, employee_id, business_date, status, created_at, updated_at)
                        VALUES (:t,:s,:e,:d,:st,NOW(),NOW())
                        ON DUPLICATE KEY UPDATE status = :st, updated_at = NOW()
                    ");
                    $up->execute([
                        ':t' => $tenantId,
                        ':s' => $storeId,
                        ':e' => $eid,
                        ':d' => $day,
                        ':st' => $status,
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('更新に失敗しました') . '&' . http_build_query($_GET));
            exit;
        }

        $q = $_GET;
        $q['msg'] = '更新しました';
        header('Location: /admin/time_punch_daily.php?' . http_build_query($q));
        exit;
    }
    if ($action === 'quick_add') {
        $csrfPost = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrf, $csrfPost)) {
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('CSRFエラー') . '&' . http_build_query($_GET));
            exit;
        }

        $pStoreId = (int)($_POST['store_id'] ?? 0);
        $pEmployeeId = (int)($_POST['employee_id'] ?? 0);
        $pDay = (string)($_POST['day'] ?? '');
        $pIn = (string)($_POST['clock_in'] ?? '');
        $pOut = (string)($_POST['clock_out'] ?? '');
        $pOutNextDay = ((string)($_POST['clock_out_next_day'] ?? '') === '1');

        if ($pStoreId !== $storeId) $pStoreId = $storeId;

        $validEmpIds = array_map(fn($e) => (int)$e['id'], $employeesAll);
        if ($pEmployeeId <= 0 || !in_array($pEmployeeId, $validEmpIds, true)) {
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('従業員が不正です') . '&' . http_build_query($_GET));
            exit;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pDay)) {
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('日付が不正です') . '&' . http_build_query($_GET));
            exit;
        }

        $hasIn = preg_match('/^\d{2}:\d{2}$/', $pIn) === 1;
        $hasOut = preg_match('/^\d{2}:\d{2}$/', $pOut) === 1;

        if (!$hasIn && !$hasOut) {
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('出勤か退勤のどちらかを入力してください') . '&' . http_build_query($_GET));
            exit;
        }
        if ($hasIn && $hasOut) {
            [$inH, $inM] = array_map('intval', explode(':', $pIn));
            [$outH, $outM] = array_map('intval', explode(':', $pOut));
            $inMin = $inH * 60 + $inM;
            $outMin = $outH * 60 + $outM;
            if ($outMin < $inMin && !$pOutNextDay) {
                header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('退勤は翌日（深夜退勤）にチェックを入れてください。') . '&' . http_build_query($_GET));
                exit;
            }
        }

        // ✅ FK device_id 対策：time_punches.device_id があるなら、DBから解決してセットする
        $tpMeta = [];
        $tpHasDeviceId = false;
        $tpDeviceNullable = true;
        $resolvedDeviceId = null;

        try {
            $tpMeta = tableColumnMeta($pdo, 'time_punches');
            $tpHasDeviceId = isset($tpMeta['device_id']);
            if ($tpHasDeviceId) {
                $nullFlag = (string)($tpMeta['device_id']['Null'] ?? '');
                $tpDeviceNullable = ($nullFlag === 'YES');
                $resolvedDeviceId = resolveDeviceIdForTimePunch($pdo, $tenantId, $pStoreId, $pEmployeeId);
            }
        } catch (Throwable $e) {
            // ここで落とすと既存環境を壊すので無視（device_id 対策できない環境は従来のまま）
            $tpHasDeviceId = false;
            $tpDeviceNullable = true;
            $resolvedDeviceId = null;
        }

        if ($tpHasDeviceId && !$tpDeviceNullable && ($resolvedDeviceId === null || $resolvedDeviceId <= 0)) {
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('登録失敗: 端末(device)が未登録のため勤怠登録できません（device_id 必須）') . '&' . http_build_query($_GET));
            exit;
        }

        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            if ($hasIn) {
                $ts = $pDay . ' ' . $pIn . ':00';
                $data = [
                    'tenant_id'   => $tenantId,
                    'store_id'    => $pStoreId,
                    'employee_id' => $pEmployeeId,
                    'punch_source'=> 'admin',
                    'punch_type'  => 'clock_in',
                    'punched_at'  => $ts,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
                if ($tpHasDeviceId) {
                    // ✅ NULL許容なら NULL を入れて default(0) 事故を避ける
                    $data['device_id'] = ($resolvedDeviceId !== null && $resolvedDeviceId > 0) ? $resolvedDeviceId : null;
                }
                safeInsert($pdo, 'time_punches', $data);
            }

            if ($hasOut) {
                $outDay = $pOutNextDay ? date('Y-m-d', strtotime($pDay . ' +1 day')) : $pDay;
                $ts = $outDay . ' ' . $pOut . ':00';
                $data = [
                    'tenant_id'   => $tenantId,
                    'store_id'    => $pStoreId,
                    'employee_id' => $pEmployeeId,
                    'punch_source'=> 'admin',
                    'punch_type'  => 'clock_out',
                    'punched_at'  => $ts,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
                if ($tpHasDeviceId) {
                    $data['device_id'] = ($resolvedDeviceId !== null && $resolvedDeviceId > 0) ? $resolvedDeviceId : null;
                }
                safeInsert($pdo, 'time_punches', $data);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            header('Location: /admin/time_punch_daily.php?msg=' . rawurlencode('登録失敗: ' . $e->getMessage()) . '&' . http_build_query($_GET));
            exit;
        }

        $q = $_GET;
        $q['store_id'] = $storeId;
        $q['from'] = min($from, $pDay);
        $outDay = ($hasOut && $pOutNextDay) ? date('Y-m-d', strtotime($pDay . ' +1 day')) : $pDay;
        $q['to'] = max($to, $outDay);
        $q['msg'] = '登録しました';

        if (!$canToggleRounding) {
            unset($q['round15']);
        }

        header('Location: /admin/time_punch_daily.php?' . http_build_query($q));
        exit;
    }
}

// ===== cashback(back) =====
$cashbackMap = [];
$cashParams = [
    ':tenant_id' => $tenantId,
    ':store_id'  => $storeId,
    ':start_day' => $from,
    ':end_day'   => $to,
];
$cashWhereEmp = "";
if ($employeeId > 0) {
    $cashWhereEmp = " AND employee_id = :employee_id ";
    $cashParams[':employee_id'] = $employeeId;
}
$cashStmt = $pdo->prepare("
    SELECT employee_id, business_date, SUM(amount_yen) AS cashback_yen
    FROM back_events
    WHERE tenant_id = :tenant_id
      AND store_id  = :store_id
      AND status = 'confirmed'
      $cashWhereEmp
      AND business_date >= :start_day
      AND business_date <= :end_day
    GROUP BY employee_id, business_date
");
$cashStmt->execute($cashParams);
foreach ($cashStmt->fetchAll() as $cr) {
    $cashbackMap[((int)$cr['employee_id']) . '|' . ((string)$cr['business_date'])] = (int)($cr['cashback_yen'] ?? 0);
}

// ===== bonus =====
$bonusMap = [];

// daily_wage_adjustments の store_id 列有無により絞り込み（列無しでも壊さない）
$bonusHasStoreId = false;
try {
    $bonusCols = tableColumns($pdo, 'daily_wage_adjustments');
    $bonusHasStoreId = in_array('store_id', $bonusCols, true);
} catch (Throwable $e) {
    $bonusHasStoreId = false;
}

$bonusParams = [
    ':tenant_id' => $tenantId,
    ':start_day' => $from,
    ':end_day'   => $to,
];
$bonusWhereEmp = "";
if ($employeeId > 0) {
    $bonusWhereEmp = " AND employee_id = :employee_id ";
    $bonusParams[':employee_id'] = $employeeId;
}
$bonusWhereStore = "";
if ($bonusHasStoreId) {
    $bonusWhereStore = " AND store_id = :store_id ";
    $bonusParams[':store_id'] = $storeId;
}

try {
    $bonusStmt = $pdo->prepare("
        SELECT employee_id, business_date, SUM(bonus_yen) AS bonus_yen
        FROM daily_wage_adjustments
        WHERE tenant_id = :tenant_id
          $bonusWhereStore
          $bonusWhereEmp
          AND business_date >= :start_day
          AND business_date <= :end_day
        GROUP BY employee_id, business_date
    ");
    $bonusStmt->execute($bonusParams);
    foreach ($bonusStmt->fetchAll() as $br) {
        $bonusMap[((int)$br['employee_id']) . '|' . ((string)$br['business_date'])] = (int)($br['bonus_yen'] ?? 0);
    }
} catch (Throwable $e) {
    // 無い環境は0扱い
}

// ===== raw punches =====
$params = [':tenant_id' => $tenantId, ':store_id' => $storeId, ':start_dt' => $startDt, ':end_dt' => $endDt];
$whereEmp = "";
if ($employeeId > 0) {
    $whereEmp = " AND tp.employee_id = :employee_id ";
    $params[':employee_id'] = $employeeId;
}
$scopeSql = '';
if ($employeeId === 0 && $empScope !== 'all' && !empty($scopeEmpIds)) {
    $placeholders = [];
    foreach ($scopeEmpIds as $i => $eid) {
        $ph = ':scope_emp_' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $eid;
    }
    $scopeSql = " AND tp.employee_id IN (" . implode(',', $placeholders) . ") ";
}
$stmt = $pdo->prepare("
  SELECT tp.employee_id, e.display_name, tp.punch_type, tp.punched_at, tp.id,
         tp.device_id, tp.punch_source, tp.source,
         COALESCE(d.device_name, '') AS device_name
  FROM time_punches tp
  JOIN employees e
    ON e.id = tp.employee_id
   AND e.tenant_id = tp.tenant_id
   AND e.store_id  = tp.store_id
  LEFT JOIN devices d
    ON d.id = tp.device_id
  WHERE tp.tenant_id = :tenant_id
    AND tp.store_id  = :store_id
    $whereEmp
    $scopeSql
    AND tp.punched_at >= :start_dt
    AND tp.punched_at <  :end_dt
  ORDER BY tp.employee_id ASC, tp.punched_at ASC, tp.id ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// break_punches を混ぜる（そのまま）
try {
    $seen = [];
    foreach ($rows as $r) {
        $k = ((int)$r['employee_id']) . '|' . ((string)$r['punch_type']) . '|' . ((string)$r['punched_at']);
        $seen[$k] = true;
    }

    $bpParams = [
        ':tenant_id' => $tenantId,
        ':store_id'  => $storeId,
        ':start_dt'  => $startDt,
        ':end_dt'    => $endDt,
    ];
    $bpWhereEmp = "";
    if ($employeeId > 0) {
        $bpWhereEmp = " AND bp.employee_id = :employee_id ";
        $bpParams[':employee_id'] = $employeeId;
    }
    $bpScopeSql = '';
    if ($employeeId === 0 && $empScope !== 'all' && !empty($scopeEmpIds)) {
        $placeholders = [];
        foreach ($scopeEmpIds as $i => $eid) {
            $ph = ':bp_scope_emp_' . $i;
            $placeholders[] = $ph;
            $bpParams[$ph] = $eid;
        }
        $bpScopeSql = " AND bp.employee_id IN (" . implode(',', $placeholders) . ") ";
    }

    $bpStmt = $pdo->prepare("
        SELECT
            bp.id,
            bp.employee_id,
            e.display_name,
            bp.break_start_at,
            COALESCE(bp.break_end_at, bp.break_start_at) AS break_end_at_safe,
            bp.break_end_at
        FROM break_punches bp
        JOIN employees e
          ON e.id = bp.employee_id
         AND e.tenant_id = bp.tenant_id
         AND e.store_id  = bp.store_id
        WHERE bp.tenant_id = :tenant_id
          AND bp.store_id  = :store_id
          $bpWhereEmp
          $bpScopeSql
          AND COALESCE(bp.break_end_at, bp.break_start_at) >= :start_dt
          AND bp.break_start_at <  :end_dt
        ORDER BY bp.employee_id ASC, bp.break_start_at ASC, bp.id ASC
    ");
    $bpStmt->execute($bpParams);
    $bpRows = $bpStmt->fetchAll();

    foreach ($bpRows as $br) {
        $bid  = (int)$br['id'];
        $eid  = (int)$br['employee_id'];
        $name = (string)$br['display_name'];

        $bs = (string)$br['break_start_at'];
        $beSafe = (string)$br['break_end_at_safe'];
        $beRaw  = $br['break_end_at'];

        if ($bs !== '') {
            $k = $eid . '|break_in|' . $bs;
            if (!isset($seen[$k])) {
                $rows[] = [
                    'employee_id'  => $eid,
                    'display_name' => $name,
                    'punch_type'   => 'break_in',
                    'punched_at'   => $bs,
                    'id'           => - ($bid * 2 + 1),
                ];
                $seen[$k] = true;
            }
        }

        if ($beRaw !== null && $beSafe !== '' && $bs !== '' && $beSafe !== $bs) {
            $k = $eid . '|break_out|' . $beSafe;
            if (!isset($seen[$k])) {
                $rows[] = [
                    'employee_id'  => $eid,
                    'display_name' => $name,
                    'punch_type'   => 'break_out',
                    'punched_at'   => $beSafe,
                    'id'           => - ($bid * 2 + 2),
                ];
                $seen[$k] = true;
            }
        }
    }

    usort($rows, function ($a, $b) {
        $ae = (int)$a['employee_id'];
        $be = (int)$b['employee_id'];
        if ($ae !== $be) return $ae <=> $be;

        $at = strtotime((string)$a['punched_at']) ?: 0;
        $bt = strtotime((string)$b['punched_at']) ?: 0;
        if ($at !== $bt) return $at <=> $bt;

        return ((int)$a['id']) <=> ((int)$b['id']);
    });
} catch (Throwable $e) {
    // 無い環境は無視
}

$typeJa = [
    'clock_in'  => '出勤',
    'clock_out' => '退勤',
    'break_in'  => '休憩開始',
    'break_out' => '休憩終了',
];

$sessions = buildSessions($rows, $roundUnit, $typeJa, $cutoffSeconds);

$items = array_values(array_filter($sessions, function ($s) use ($from, $to) {
    $day = (string)$s['day'];
    return ($day >= $from && $day <= $to);
}));

// 支払状況（エクスポートでも使用するため先に用意）
$paymentStatusLabels = [
    'unconfirmed' => '未確認',
    'checking' => '確認済',
    'paid' => '振込済',
    'canceled' => '取消',
];
$paymentStatusClasses = [
    'unconfirmed' => 'status-unconfirmed',
    'checking' => 'status-checking',
    'paid' => 'status-paid',
    'canceled' => 'status-canceled',
];
$paymentStatusMap = [];
try {
    ensurePaymentStatusTable($pdo);
    $psParams = [
        ':tenant_id' => $tenantId,
        ':store_id' => $storeId,
        ':from' => $from,
        ':to' => $to,
    ];
    $psEmpWhere = '';
    if ($employeeId > 0) {
        $psEmpWhere = ' AND employee_id = :employee_id ';
        $psParams[':employee_id'] = $employeeId;
    }
    $ps = $pdo->prepare("
        SELECT employee_id, business_date, status
        FROM payroll_payment_statuses
        WHERE tenant_id = :tenant_id
          AND store_id = :store_id
          AND business_date >= :from
          AND business_date <= :to
          {$psEmpWhere}
    ");
    $ps->execute($psParams);
    foreach ($ps->fetchAll() as $r) {
        $eid = (int)($r['employee_id'] ?? 0);
        $day = (string)($r['business_date'] ?? '');
        $st = (string)($r['status'] ?? 'unconfirmed');
        if ($eid > 0 && $day !== '') {
            $paymentStatusMap[$eid . '|' . $day] = $st;
        }
    }
} catch (Throwable $e) {
    $paymentStatusMap = [];
}

// =========================
// ✅ CSV / PDF Export
// =========================
$export = (string)($_GET['export'] ?? '');
if ($export === 'csv' || $export === 'pdf') {
    $exportRows = [];
    foreach ($items as $d) {
        $work  = (int)$d['work_seconds'];
        $break = (int)$d['break_seconds'];
        $net   = max(0, $work - $break);
        $warnText = implode(' / ', array_unique($d['warn']));

        $eid = (int)$d['employee_id'];
        $dayKey = (string)$d['day'];

        $hk = $eid . '|' . $dayKey;
        if ($hasWageHistory && isset($effectiveWageMap[$hk])) {
            $hourly = (int)$effectiveWageMap[$hk];
        } else {
            $hourly = (int)($wageMap[$eid] ?? 0);
        }

        $netForPay = (int)(floor($net / 60) * 60);
        $basePayYen = ($hourly > 0 && $netForPay > 0)
            ? (int)round(($netForPay * $hourly) / 3600)
            : 0;
        $nightSec = (int)($d['night_seconds'] ?? 0);
        $nightForPay = (int)(floor($nightSec / 60) * 60);
        $nightPremiumYen = 0;
        if ($hourly > 0 && $nightForPay > 0 && !empty($nightPremiumEnabledMap[$eid])) {
            $rate = (int)($nightPremiumRateMap[$eid] ?? 25);
            $nightPremiumYen = (int)round(($nightForPay * $hourly * $rate) / 100 / 3600);
        }
        $dayPayYen = $basePayYen + $nightPremiumYen;

        $payKey = $eid . '|' . $dayKey;
        $payStatus = $paymentStatusMap[$payKey] ?? 'unconfirmed';
        $payLabel = $paymentStatusLabels[$payStatus] ?? '未確認';

        $inTs  = $d['_display_in_ts'];
        $outTs = $d['_display_out_ts'];

        $exportRows[] = [
            '営業日時' => $dayKey,
            '従業員' => (string)$d['display_name'],
            '出勤' => fmtTimeRawAndRounded($inTs, $roundUnit, 'clock_in'),
            '退勤' => fmtTimeRawAndRounded($outTs, $roundUnit, 'clock_out'),
            '打刻元' => punch_source_summary((string)($d['clock_in_source'] ?? ''), (string)($d['clock_out_source'] ?? '')),
            '勤務' => secToHM($work),
            '休憩' => secToHM($break),
            '実働' => secToHM($net),
            '日給' => (string)$dayPayYen,
            '支払状況' => $payLabel,
            '打刻入力' => ($warnText !== '' ? $warnText : 'OK'),
        ];
    }

    if ($export === 'csv') {
        $filename = 'time_punch_daily_' . $from . '_' . $to . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        if (!empty($exportRows)) {
            fputcsv($out, array_keys($exportRows[0]));
            foreach ($exportRows as $r) {
                fputcsv($out, $r);
            }
        }
        fclose($out);
        exit;
    }

    $rowsHtml = '';
    foreach ($exportRows as $r) {
        $rowsHtml .= '<tr>'
            . '<td>' . h($r['営業日時']) . '</td>'
            . '<td>' . h($r['従業員']) . '</td>'
            . '<td>' . h($r['出勤']) . '</td>'
            . '<td>' . h($r['退勤']) . '</td>'
            . '<td>' . h($r['打刻元']) . '</td>'
            . '<td>' . h($r['勤務']) . '</td>'
            . '<td>' . h($r['休憩']) . '</td>'
            . '<td>' . h($r['実働']) . '</td>'
            . '<td style="text-align:right;">' . h($r['日給']) . '</td>'
            . '<td>' . h($r['支払状況']) . '</td>'
            . '<td>' . h($r['打刻入力']) . '</td>'
            . '</tr>';
    }

    $html = '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
        . '<style>body{font-family:DejaVu Sans, sans-serif;font-size:11px;}'
        . 'table{width:100%;border-collapse:collapse;}'
        . 'th,td{border:1px solid #ddd;padding:6px;text-align:left;}'
        . 'th{background:#f5f5f5;}</style></head><body>'
        . '<h3>勤怠（日次） ' . h($from) . ' 〜 ' . h($to) . '</h3>'
        . '<table><thead><tr>'
        . '<th>営業日時</th><th>従業員</th><th>出勤</th><th>退勤</th><th>打刻元</th><th>勤務</th>'
        . '<th>休憩</th><th>実働</th><th>日給</th><th>支払状況</th><th>打刻入力</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table></body></html>';

    $dompdfCandidates = [
        __DIR__ . '/../dompdf/autoload.inc.php',
        __DIR__ . '/../../dompdf/autoload.inc.php',
        __DIR__ . '/dompdf/autoload.inc.php',
    ];
    foreach ($dompdfCandidates as $p) {
        if (is_file($p)) {
            require_once $p;
            break;
        }
    }

    if (class_exists(\Dompdf\Dompdf::class)) {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="time_punch_daily_' . $from . '_' . $to . '.pdf"');
        echo $dompdf->output();
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

usort($items, function ($a, $b) {
    if ($a['day'] === $b['day']) {
        if ((int)$a['employee_id'] === (int)$b['employee_id']) {
            $ain = (int)($a['_display_in_ts'] ?? 0);
            $bin = (int)($b['_display_in_ts'] ?? 0);
            if (($ain > 0) !== ($bin > 0)) return ($bin > 0) <=> ($ain > 0);
            if ($ain !== $bin) return $bin <=> $ain;
            return (int)$a['seq'] <=> (int)$b['seq'];
        }
        return (int)$a['employee_id'] <=> (int)$b['employee_id'];
    }
    return strcmp((string)$b['day'], (string)$a['day']);
});

$totalWorkSec = 0;
$totalBreakSec = 0;
$totalNetSec = 0;
$totalLaborYen = 0;
$totalNightPremiumYen = 0;

// 期間プルダウン
$periodOptions = [];
$periodOptions[] = ['value' => '', 'label' => 'カスタム'];
$periodOptions[] = ['value' => 'recent30', 'label' => '直近30日'];

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
for ($i = 0; $i < 12; $i++) {
    $dt = $now->modify("-{$i} month");
    $ym = $dt->format('Y-m');
    $label = $dt->format('Y年m月度');
    $periodOptions[] = ['value' => $ym, 'label' => $label];
}

$baseQuery = [
    'store_id'    => (int)$storeId,
    'employee_id' => (int)$employeeId,
    'emp_scope'   => (string)$empScope,
    'from'        => (string)$from,
    'to'          => (string)$to,
    'period'      => (string)$period,
];
if ($canToggleRounding) {
    $baseQuery['round15'] = (int)($_GET['round15'] ?? 1);
}

// ✅ UIボタン用（印刷リンク）
$payrollPrintUrl = '/admin/payslip_simple_pdf.php?' . http_build_query([
    'store_id'    => (int)$storeId,
    'employee_id' => (int)$employeeId,
    'from'        => (string)$from,
    'to'          => (string)$to,
]);
$payrollPrintDisabled = ($employeeId === 0);

// ✅ 勤怠登録モーダルのデフォルト日付（見ている期間の to を初期値にする：UIだけ）
$quickAddDefaultDay = (string)$to;
$quickAddTimeOptions = buildTimeOptions(5);

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>日別 勤怠サマリー</title>

    <style>
        :root {
            --font: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans JP", sans-serif;
            --text: #111;
            --muted: #8b8b8b;
            --line: #eeeeee;
            --zebra: #f6f6f6;
            --sun: #e11d48;
            --sat: #365EAB;
            --fs: 14px;
            --lh: 1.6;
            --ctrl-h: 34px;
            --pad-x: 12px;
            --radius: 14px;
            --radius-sm: 12px;
            --radius-lg: 16px;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            font-family: var(--font);
            color: var(--text);
            background: #fff;
            font-size: var(--fs);
            line-height: var(--lh);
            font-weight: 400;
        }

        body,
        table,
        th,
        td,
        input,
        select,
        button,
        .num {
            font-variant-numeric: tabular-nums lining-nums !important;
            font-feature-settings: "tnum" 1, "lnum" 1 !important;
        }

        table,
        thead th,
        tbody td,
        tfoot td,
        .label,
        .ctrl,
        .btn,
        .pill,
        .flash,
        .dateLine,
        .sub,
        .muted,
        details summary {
            font-size: var(--fs);
            line-height: var(--lh);
        }

        .wrap {
            width: 100%;
            max-width: none;
            padding: 18px 20px;
            box-sizing: border-box;
        }

        .filterRow {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin: 0;
            padding: 0;
            background: transparent;
        }

        .filterBand {
            background: rgba(246, 247, 251, .86);
            margin: -18px -20px 10px;
            padding: 16px 20px 10px;
        }

        .label {
            font-weight: 600;
            color: #444;
        }

        .ctrl {
            height: var(--ctrl-h);
            padding: 0 10px;
            border: 1px solid #ddd;
            background: #fff;
            font-weight: 600;
            border-radius: var(--radius-sm);
            box-sizing: border-box;
        }

        .ctrlDate {
            height: var(--ctrl-h);
            padding: 0 10px;
            border: 1px solid #ddd;
            background: #fff;
            font-weight: 600;
            border-radius: var(--radius-sm);
            box-sizing: border-box;
        }

        .btn {
            height: var(--ctrl-h);
            padding: 0 14px;
            border: 1px solid #ddd;
            background: #fff;
            font-weight: 700;
            border-radius: var(--radius);
            cursor: pointer;
            box-sizing: border-box;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }

        .btn.primary {
            background: #111;
            color: #fff;
            border-color: #111;
        }

        .btn.ghost {
            background: #f3f3f3;
            border-color: #e5e5e5;
        }

        .btn.disabled {
            opacity: .45;
            pointer-events: none;
            filter: grayscale(1);
        }

        .pill {
            margin-left: auto;
            padding: 6px 12px;
            background: #f3f3f3;
            border: 1px solid #e5e5e5;
            border-radius: 999px;
            font-weight: 700;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #9ca3af;
        }

        .flash {
            width: 100%;
            padding: 10px 12px;
            background: #f3f3f3;
            border: 1px solid #e5e5e5;
            border-radius: var(--radius);
            font-weight: 700;
        }

        .tableWrap {
            border-top: none;
            overflow: auto;
            -webkit-overflow-scrolling: touch;
        }

        .tpTableStickyWrap {
            position: fixed;
            display: none;
            z-index: 30;
            pointer-events: none;
        }

        .tpTableSticky {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--radius) var(--radius) 0 0;
            overflow: hidden;
            box-shadow: 0 10px 24px rgba(0, 0, 0, .08);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        thead th {
            padding: 10px var(--pad-x);
            font-weight: 700;
            border-bottom: 1px solid var(--line);
            background: #fafafe;
            text-align: left;
            white-space: nowrap;
        }

        .tpDaily thead th {
            text-align: center;
        }

        .tpDaily thead th:nth-child(n+2),
        .tpDaily tbody td:nth-child(n+2) {
            width: calc((100% - 36px) / 12);
        }

        thead th:first-child,
        tbody td:first-child {
            width: 36px;
            min-width: 36px;
        }

        thead th:nth-child(2),
        tbody td:nth-child(2) {
            width: auto;
            min-width: 0;
        }

        thead th:nth-child(3),
        tbody td:nth-child(3) {
            width: auto;
            min-width: 0;
        }

        tbody td {
            padding: 12px var(--pad-x);
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        tbody td:nth-child(2) {
            overflow: visible;
        }

        tbody tr:nth-child(odd) {
            background: #fff;
        }

        .tpDaily tbody td {
            padding: 10px var(--pad-x);
            font-size: 13px;
            line-height: 1.4;
        }

        .tpDaily .dateLine {
            font-size: 14px;
            font-weight: 800;
        }

        .tpDaily .dateLine>span:first-child {
            font-weight: 800;
        }

        .cardWrap {
            display: none;
            border-top: 1px solid var(--line);
            padding: 10px var(--pad-x) 12px;
            gap: 10px;
        }

        .tpCard {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px;
            background: #fff;
        }

        .tpCardHead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-weight: 700;
        }

        .tpCardRow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 6px 0;
            border-bottom: 1px dashed var(--line);
            font-size: 13px;
        }

        .tpCardRow:last-child {
            border-bottom: 0;
        }

        .tpCardKey {
            color: var(--muted);
            font-weight: 700;
        }

        .payStatusCell {
            position: relative;
        }

        @media (max-width: 900px) {
            .tableWrap table {
                display: none;
            }

            .cardWrap {
                display: grid;
            }
        }

        .dateLine {
            display: inline-flex;
            align-items: baseline;
            gap: 10px;
            white-space: nowrap;
            font-weight: 700;
            letter-spacing: .01em;
        }

        .dateCell {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .tpDaily th:nth-child(10),
        .tpDaily td:nth-child(10),
        .tpDaily th:nth-child(11),
        .tpDaily td:nth-child(11),
        .tpDaily th:nth-child(12),
        .tpDaily td:nth-child(12),
        .tpDaily th:nth-child(13),
        .tpDaily td:nth-child(13) {
            text-align: center;
        }

        .tpDaily td:nth-child(4),
        .tpDaily td:nth-child(5),
        .tpDaily td:nth-child(6),
        .tpDaily td:nth-child(7),
        .tpDaily td:nth-child(8) {
            text-align: right;
        }

        .payStatusBadge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid #ddd;
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .status-unconfirmed {
            color: #374151;
            border-color: #cbd5e1;
            background: #f8fafc;
        }

        .status-checking {
            color: #2f4f93;
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .status-paid {
            color: #166534;
            border-color: #bbf7d0;
            background: #f0fdf4;
        }

        .status-canceled {
            color: #b91c1c;
            border-color: #fecaca;
            background: #fff1f2;
        }

        .payStatusMenu {
            display: none;
            margin-top: 6px;
        }

        .payStatusMenu.is-open {
            display: block;
        }

        .payStatusSelect {
            height: 30px;
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 0 8px;
            font-weight: 600;
            width: 100%;
        }

        .payStatusSelectWrap {
            width: fit-content;
            min-width: 80px;
        }

        .bulkRow {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            padding: 10px 0;
        }

        .bulkRow .label,
        .bulkRow .ctrl,
        .bulkRow .btn {
            font-size: 12px;
            height: 28px;
        }

        .bulkRow .ctrl {
            padding: 0 8px;
        }

        .smallLabel {
            font-size: 12px;
        }

        .checkCell {
            text-align: center;
        }

        .sun {
            color: var(--sun);
        }

        .sat {
            color: var(--sat);
        }

        .wk {
            color: #111;
        }

        .sub {
            display: block;
            margin-top: 6px;
            color: var(--muted);
            font-weight: 500;
            white-space: normal;
        }

    .bonusBackSub {
        font-size: 0.85em;
        line-height: 1.2;
    }

    .nightPremiumSub {
        font-size: 0.8em;
        line-height: 1.2;
    }

    .timeRoundedSub {
        font-size: 0.8em;
        line-height: 1.2;
    }

        .muted {
            color: var(--muted);
            font-weight: 500;
        }

        .okText {
            color: #64748b;
            font-weight: 600;
        }

        .warnText {
            color: #b91c1c;
            font-weight: 700;
        }

        .tpDaily .tpRowWarn {
            background: #FFF0F0;
        }

        .tpDaily .tpRowUnconfirmed {
            background: #FFFBEA;
        }

        .tpDaily .tpRowPaid {
            background: #f3f4f6;
        }

        .tpDaily .tpRowCanceled {
            background: #FFECEC;
        }

        .tpDaily .dateLine>span:first-child {
            font-weight: 900;
        }

        .tpDaily .wk {
            color: #6b7280;
        }

        .tpDaily .warnText::before {
            content: "⚠ ";
            font-weight: 900;
        }

        .tpDaily .okText::before {
            content: "✓ ";
            color: #94a3b8;
            font-weight: 900;
        }

        .tpDaily .dayPayCell {
            font-weight: 700;
            font-size: 14px;
            text-align: right;
        }

        .dayPayWrap {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .dayPayRowValue {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }

        .dayPayAmount {
            flex: 1;
            text-align: right;
        }

        .tpDaily .timeCell {
            color: #6b7280;
            font-weight: 500;
            font-size: 13px;
        }

        .tpDaily .ops .iconBtn {
            opacity: .35;
            transition: opacity .12s ease;
        }

        .tpDaily tbody tr:hover .ops .iconBtn,
        .tpDaily .ops .iconBtn:hover {
            opacity: 1;
        }

        .tpDaily .ops .iconBtn:disabled {
            opacity: .35;
            cursor: not-allowed;
        }

        .tpDaily .tpRowPaid .ops .iconBtn {
            opacity: .35;
            cursor: not-allowed;
        }

        .tpDaily .tpRowPaid .ops .iconBtn:hover {
            opacity: .35;
        }

        .tpDaily .tableWrap table th:nth-child(5),
        .tpDaily .tableWrap table td:nth-child(5),
        .tpDaily .tableWrap table th:nth-child(10),
        .tpDaily .tableWrap table td:nth-child(10),
        .tpDaily .tableWrap table th:nth-child(11),
        .tpDaily .tableWrap table td:nth-child(11),
        .tpDaily .tableWrap table th:nth-child(12),
        .tpDaily .tableWrap table td:nth-child(12) {
            border-left: 1px solid rgba(0, 0, 0, .06);
        }

        details summary {
            cursor: pointer;
            font-weight: 700;
            color: #111;
            user-select: none;
            list-style: none;
        }

        details summary::-webkit-details-marker {
            display: none;
        }

        .ops {
            display: flex;
            gap: 10px;
            justify-content: flex-start;
            align-items: center;
        }

        .iconBtn {
            width: 36px;
            height: 36px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: var(--radius);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            line-height: 1;
            font-weight: 600;
            box-sizing: border-box;
            text-decoration: none;
            white-space: nowrap;
        }

        .iconBtn.danger {
            background: #fff5f5;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .editIconBtn {
            border: 0 !important;
            background: transparent !important;
            padding: 8px 10px;
        }

        .sourceBadges {
            display: inline-flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }

        .sourceBadgeWrap {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .sourcePrefix {
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
        }

        .sourceBadge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 28px;
            padding: 4px 10px 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .sourceBadgeIcon {
            width: 18px;
            height: 18px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
            background: rgba(255, 255, 255, .72);
        }

        .sourceBadge.isLine {
            background: #ecfdf3;
            border-color: #a7f3d0;
            color: #065f46;
        }

        .sourceBadge.isQr {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .sourceBadge.isIpad {
            background: #f5f3ff;
            border-color: #ddd6fe;
            color: #5b21b6;
        }

        .sourceBadge.isAdmin {
            background: #fff7ed;
            border-color: #fed7aa;
            color: #9a3412;
        }

        .sourceBadge.isUnknown,
        .sourceBadgeEmpty {
            background: #f3f4f6;
            border-color: #e5e7eb;
            color: #6b7280;
        }

        .timeWithSource {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }

        .timeWithSourceLabel {
            flex: 1 1 auto;
            min-width: 0;
            text-align: left;
        }

        .sourceTiny {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 12px;
            height: 12px;
            vertical-align: middle;
            flex: 0 0 auto;
            margin-left: auto;
        }

        .sourceTinyIcon {
            width: 10px;
            height: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        .sourceTinyIcon svg {
            width: 10px;
            height: 10px;
            display: block;
        }

        .tpDaily .punchTimeCell {
            text-align: left !important;
        }

        .sourceTiny.isLine {
            color: #065f46;
        }

        .sourceTiny.isQr {
            color: #1d4ed8;
        }

        .sourceTiny.isIpad {
            color: #5b21b6;
        }

        .sourceTiny.isAdmin {
            color: #9a3412;
        }

        .sourceTiny.isUnknown {
            color: #6b7280;
        }

        /* =========================
       ✅ UI追加：勤怠登録モーダル（既存処理 quick_add を使うだけ）
       ========================= */
        .tpModalBackdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .35);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 9999;
        }

        .tpModalBackdrop.isOpen {
            display: flex;
        }

        .tpModal {
            width: min(680px, 100%);
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: var(--radius-lg);
            box-sizing: border-box;
        }

        .tpModalHead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            border-bottom: 1px solid #eee;
            font-weight: 800;
        }

        .tpModalBody {
            padding: 14px;
        }

        .tpGrid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            align-items: center;
        }

        .tpGrid .full {
            grid-column: 1 / -1;
        }

        .tpRow {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .tpCheckRow {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-top: 6px;
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
        }

        .tpCheckRow input {
            width: 16px;
            height: 16px;
        }

        .tpHelp {
            margin-top: 10px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
            font-weight: 600;
        }

        .customRange {
            display: none;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .customRange.is-show {
            display: inline-flex;
        }

        .customMenuWrap {
            position: relative;
        }

        .customMenu {
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            min-width: 220px;
            padding: 10px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: var(--shadow2);
            display: none;
            z-index: 60;
        }

        .customMenu.is-open {
            display: block;
        }

        .customMenu .menuRow {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .customMenu .menuRow+.menuRow {
            margin-top: 8px;
        }

        .customMenu .menuRow .ctrl {
            flex: 1;
            min-width: 140px;
        }

        .helpWrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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
            font-weight: 700;
            cursor: pointer;
            line-height: 1;
        }

        .helpPopover {
            position: absolute;
            top: 26px;
            right: 0;
            z-index: 60;
            width: min(520px, 90vw);
            background: #fff;
            border: 1px solid #d9dde6;
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12);
            font-size: 12px;
            line-height: 1.6;
            color: #111;
            display: none;
            white-space: normal;
        }

        .helpPopover.is-open {
            display: block;
        }

        .exportRow {
            display: flex;
            justify-content: flex-end;
            margin: 6px 0;
        }

        .exportSelect {
            height: 28px;
            font-size: 12px;
            padding: 0 8px;
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="wrap tpDaily">
        <div class="filterBand">
            <form method="get" class="filterRow" id="filterForm">
                <?php if ($msg !== ''): ?>
                    <div class="flash"><?= h($msg) ?></div>
                <?php endif; ?>

                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">

                <select class="ctrl" name="period" id="periodSelect">
                    <?php foreach ($periodOptions as $opt): ?>
                        <option value="<?= h((string)$opt['value']) ?>"
                            <?= ((string)$opt['value'] === (string)$period) ? 'selected' : '' ?>>
                            <?= h((string)$opt['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <span class="customRange" id="customRange">
                    <span class="label">from</span>
                    <input class="ctrlDate" type="date" name="from" id="fromInput" value="<?= h($from) ?>">
                    <span class="label">to</span>
                    <input class="ctrlDate" type="date" name="to" id="toInput" value="<?= h($to) ?>">
                </span>

                <select class="ctrl" name="employee_id" id="employeeSelect">
                    <option value="0" <?= $employeeId === 0 ? 'selected' : '' ?>>全員</option>
                    <?php foreach ($employeesFiltered as $e): ?>
                        <?php $eid = (int)$e['id']; ?>
                        <option value="<?= $eid ?>" <?= ($eid === $employeeId) ? 'selected' : '' ?>>
                            <?= h((string)$e['display_name']) ?> (<?= $eid ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="customMenuWrap">
                    <button class="btn" type="button" id="customizeBtn">カスタマイズ ▾</button>
                    <div class="customMenu" id="customizeMenu" aria-hidden="true">
                        <div class="menuRow">
                            <span class="label">在籍/退職</span>
                            <select class="ctrl" name="emp_scope" id="empScopeSelect">
                                <option value="active" <?= $empScope === 'active' ? 'selected' : '' ?>>在籍</option>
                                <option value="retired" <?= $empScope === 'retired' ? 'selected' : '' ?>>退職</option>
                                <option value="all" <?= $empScope === 'all' ? 'selected' : '' ?>>全員</option>
                            </select>
                        </div>

                        <?php if ($canToggleRounding): ?>
                            <div class="menuRow">
                                <span class="label">打刻調整</span>
                                <select class="ctrl" name="round15" id="round15Select">
                                    <option value="0" <?= $roundOn ? '' : 'selected' ?>>OFF</option>
                                    <option value="1" <?= $roundOn ? 'selected' : '' ?>>ON（<?= (int)$storeRoundUnit ?>分）
                                    </option>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ✅ 復活：勤怠登録（常に表示 / 従業員選択と無関係） -->
                <a class="btn ghost" href="/admin/time_punch_new.php?store_id=<?= (int)$storeId ?>">勤怠登録</a>

                <!-- ✅ 復活：給料明細（印刷）（常に表示） -->
                <a class="btn ghost <?= $payrollPrintDisabled ? 'disabled' : '' ?>" id="payrollPrintBtn"
                    href="<?= $payrollPrintDisabled ? 'javascript:void(0)' : h($payrollPrintUrl) ?>"
                    data-href="<?= h($payrollPrintUrl) ?>" target="_blank" rel="noopener"
                    aria-disabled="<?= $payrollPrintDisabled ? 'true' : 'false' ?>"
                    tabindex="<?= $payrollPrintDisabled ? '-1' : '0' ?>">給料明細</a>
                <span class="helpWrap">
                    <button class="helpIcon" type="button" data-help-target="help-payroll-print">?</button>
                    <span class="helpPopover" id="help-payroll-print">
                        給与明細を出したいユーザーを選択後、印刷できます。
                    </span>
                </span>

                <span class="pill">
                    <span>
                        <?php if ($canToggleRounding): ?>打刻調整<?= (int)$roundUnit ?>分<?php endif; ?>
                        / 締時間：<?= h($storeCutoffStr) ?>
                    </span>
                    <span class="helpWrap">
                        <button class="helpIcon" type="button" data-help-target="help-store-settings">?</button>
                        <span class="helpPopover" id="help-store-settings">
                            打刻調整、締時間の変更は、設定から行なってください。
                        </span>
                    </span>
                </span>
            </form>
        </div>

        <!-- =========================
             ✅ UI追加：勤怠登録モーダル（既存 quick_add を叩くだけ）
             ========================= -->
        <div class="tpModalBackdrop" id="quickAddModal" aria-hidden="true">
            <div class="tpModal" role="dialog" aria-modal="true" aria-label="勤怠登録">
                <div class="tpModalHead">
                    <div>勤怠登録</div>
                    <button type="button" class="btn" id="closeQuickAdd" style="height:32px;padding:0 10px;">✕</button>
                </div>
                <div class="tpModalBody">
                    <form method="post" action="/admin/time_punch_daily.php?<?= h(http_build_query($_GET)) ?>">
                        <input type="hidden" name="action" value="quick_add">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">

                        <div class="tpGrid">
                            <div class="full">
                                <span class="label">従業員</span><br>
                                <select class="ctrl" name="employee_id" style="width:min(520px,100%);">
                                    <?php foreach ($employeesAll as $e): ?>
                                        <?php $eid = (int)$e['id']; ?>
                                        <option value="<?= $eid ?>"><?= h((string)$e['display_name']) ?> (<?= $eid ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <span class="label">日付</span><br>
                                <input class="ctrlDate" type="date" name="day" value="<?= h($quickAddDefaultDay) ?>"
                                    style="width:100%;">
                            </div>

                            <div>
                                <span class="label">出勤</span><br>
                                <select class="ctrl" name="clock_in" style="width:100%;">
                                    <option value="">未設定</option>
                                    <?php foreach ($quickAddTimeOptions as $t): ?>
                                        <option value="<?= h($t) ?>" <?= $t === '12:00' ? 'selected' : '' ?>>
                                            <?= h($t) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <span class="label">退勤</span><br>
                                <select class="ctrl" name="clock_out" style="width:100%;">
                                    <option value="">未設定</option>
                                    <?php foreach ($quickAddTimeOptions as $t): ?>
                                        <option value="<?= h($t) ?>" <?= $t === '12:00' ? 'selected' : '' ?>>
                                            <?= h($t) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="tpCheckRow">
                                    <input type="checkbox" name="clock_out_next_day" value="1" id="quickOutNextDay">
                                    <label for="quickOutNextDay">退勤は翌日（深夜退勤）</label>
                                </div>
                            </div>

                            <div class="full tpRow" style="margin-top:6px;">
                                <button class="btn primary" type="submit">登録</button>
                                <button class="btn" type="button" id="cancelQuickAdd">キャンセル</button>
                            </div>
                        </div>

                        <div class="tpHelp">
                            ※ 出勤 or 退勤のどちらか一方だけでも登録できます（既存 quick_add の仕様そのまま）<br>
                            ※ 時刻は5分刻みで選択できます
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="tableWrap">
            <form method="post" id="bulkPaymentForm"
                action="/admin/time_punch_daily.php?<?= h(http_build_query($_GET)) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="bulk_payment_status">
                <div class="bulkRow" style="align-items:center; justify-content:space-between; gap:10px;">
                    <div style="display:flex; gap:8px; align-items:center;">
                        <select class="ctrl" name="payment_status">
                            <?php $i = 0;
                            foreach ($paymentStatusLabels as $k => $label): ?>
                                <option value="<?= h($k) ?>" <?= $i === 0 ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php $i++;
                            endforeach; ?>
                        </select>
                        <button class="btn" type="submit">実行</button>
                    </div>
                    <select class="ctrl exportSelect" id="exportSelect">
                        <option value="">エクスポート</option>
                        <option value="csv">CSV</option>
                        <option value="pdf">PDF</option>
                    </select>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th class="checkCell">
                                <input type="checkbox" id="selectAllPayments">
                            </th>
                            <th>営 業 日 時</th>
                            <th>従 業 員</th>
                            <th>出 勤</th>
                            <th>退 勤</th>
                            <th>勤 務</th>
                            <th>休 憩</th>
                            <th>実 働</th>
                            <th>日 給</th>
                            <th>支 払 状 況</th>
                            <th>打刻入力</th>
                            <th>編集・削除</th>
                            <th>当日出勤回数</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="13" class="muted">該当データなし</td>
                            </tr>
                        <?php else: ?>
                            <?php $backUrl = '/admin/time_punch_daily.php?' . http_build_query($baseQuery); ?>
                            <?php foreach ($items as $d): ?>
                                <?php
                                $work  = (int)$d['work_seconds'];
                                $break = (int)$d['break_seconds'];
                                $net   = max(0, $work - $break);

                                $warnText = implode(' / ', array_unique($d['warn']));

                                // ✅ ここが本丸：当日の営業日キーで「effective_business_day <= 当日の最新」を優先
                                $eid = (int)$d['employee_id'];
                                $dayKey = (string)$d['day'];

                                $hk = $eid . '|' . $dayKey;
                                if ($hasWageHistory && isset($effectiveWageMap[$hk])) {
                                    $hourly = (int)$effectiveWageMap[$hk];
                                } else {
                                    $hourly = (int)($wageMap[$eid] ?? 0); // 従来互換フォールバック
                                }

                                $key = $eid . '|' . $dayKey;
                                $bonusYen    = (int)($bonusMap[$key] ?? 0);
                                $cashbackYen = (int)($cashbackMap[$key] ?? 0);

                                /**
                                 * ✅ 日給(円) は「設定時給 ×（打刻調整後の実働）」のみ
                                 */
                                $netForPay = (int)(floor($net / 60) * 60); // 分未満(秒)は切り捨て
                                $basePayYen = ($hourly > 0 && $netForPay > 0)
                                    ? (int)round(($netForPay * $hourly) / 3600)
                                    : 0;
                                $nightSec = (int)($d['night_seconds'] ?? 0);
                                $nightForPay = (int)(floor($nightSec / 60) * 60);
                                $nightPremiumYen = 0;
                                if ($hourly > 0 && $nightForPay > 0 && !empty($nightPremiumEnabledMap[$eid])) {
                                    $rate = (int)($nightPremiumRateMap[$eid] ?? 25);
                                    $nightPremiumYen = (int)round(($nightForPay * $hourly * $rate) / 100 / 3600);
                                }

                                // ✅ bonus/cashback を日給に加算しない（深夜割増は加算）
                                $dayPayYen = $basePayYen + $nightPremiumYen;

                                $totalWorkSec  += $work;
                                $totalBreakSec += $break;
                                $totalNetSec   += $net;
                                $totalLaborYen += $dayPayYen;
                                $totalNightPremiumYen += $nightPremiumYen;

                                $editUrl = 'time_punch_edit.php?' . http_build_query([
                                    'store_id'     => (int)$storeId,
                                    'employee_id'  => $eid,
                                    'day'          => $dayKey,
                                    'clock_in_id'  => (int)$d['_edit_clock_in_id'],
                                    'clock_out_id' => (int)$d['_edit_clock_out_id'],
                                    'back_url'     => $backUrl,
                                ]);

                                $inTs  = $d['_display_in_ts'];
                                $outTs = $d['_display_out_ts'];

                                $w = wdayNum($dayKey);
                                $dateClass = ($w === 0) ? 'sun' : (($w === 6) ? 'sat' : 'wk');

                                // ✅ 表示用：ボーナス/バックは「ある時だけ」出す
                                $hasBonusOrBack = ($bonusYen > 0) || ($cashbackYen > 0);
                                $bonusBackParts = [];
                                if ($bonusYen > 0) $bonusBackParts[] = 'BO:' . number_format($bonusYen);
                                if ($cashbackYen > 0) $bonusBackParts[] = 'BA:' . number_format($cashbackYen);
                                $bonusBackLine = implode(' / ', $bonusBackParts);
                                ?>
                                <?php
                                $payKey = $eid . '|' . $dayKey;
                                $payStatus = $paymentStatusMap[$payKey] ?? 'unconfirmed';
                                $payLabel = $paymentStatusLabels[$payStatus] ?? '未確認';
                                $payClass = $paymentStatusClasses[$payStatus] ?? 'status-unconfirmed';
                                $rowStateClass = ($payStatus === 'canceled') ? 'tpRowCanceled'
                                    : ($warnText ? 'tpRowWarn' : (($payStatus === 'paid') ? 'tpRowPaid' : (($payStatus === 'unconfirmed') ? 'tpRowUnconfirmed' : '')));
                                ?>
                                <tr class="<?= h($rowStateClass) ?>" data-employee-id="<?= (int)$eid ?>"
                                    data-day="<?= h($dayKey) ?>" data-pay-status="<?= h($payStatus) ?>">
                                    <td class="checkCell">
                                        <input type="checkbox" class="payRowCheck" name="payment_keys[]"
                                            value="<?= h($payKey) ?>" <?= $payStatus === 'paid' ? 'disabled' : '' ?>>
                                    </td>
                                    <td>
                                        <div class="dateCell">
                                            <span class="dateLine <?= h($dateClass) ?>">
                                                <span><?= h(date('m-d', strtotime($dayKey))) ?></span>
                                                <span>(<?= h(wdayJa($dayKey)) ?>)</span>
                                            </span>
                                        </div>
                                    </td>

                                    <td><?= h((string)$d['display_name']) ?></td>

                                    <td class="num timeCell punchTimeCell"><?= timeWithPunchSourceHtml($inTs, $roundUnit, 'clock_in', (string)($d['clock_in_source'] ?? '')) ?></td>
                                    <td class="num timeCell punchTimeCell"><?= timeWithPunchSourceHtml($outTs, $roundUnit, 'clock_out', (string)($d['clock_out_source'] ?? '')) ?></td>

                                    <td class="num timeCell"><?= h(secToHM($work)) ?></td>
                                    <td class="num timeCell"><?= h(secToHM($break)) ?></td>
                                    <td class="num timeCell"><?= h(secToHM($net)) ?></td>

                                    <td class="num dayPayCell">
                                        <?= number_format($dayPayYen) ?>
                                        <?php if ($hasBonusOrBack): ?>
                                            <span class="sub bonusBackSub"><?= h($bonusBackLine) ?></span>
                                        <?php endif; ?>
                                        <?php if ($nightPremiumYen > 0): ?>
                                            <span class="sub nightPremiumSub">深割:+<?= number_format($nightPremiumYen) ?>円</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="payStatusCell">
                                            <button type="button" class="payStatusBadge <?= h($payClass) ?>" data-pay-toggle>
                                                <?= h($payLabel) ?>
                                            </button>
                                            <span class="payStatusMenu">
                                                <span class="payStatusSelectWrap">
                                                    <select class="payStatusSelect" data-employee-id="<?= (int)$eid ?>"
                                                        data-day="<?= h($dayKey) ?>">
                                                        <?php foreach ($paymentStatusLabels as $k => $label): ?>
                                                            <option value="<?= h($k) ?>" <?= $k === $payStatus ? 'selected' : '' ?>>
                                                                <?= h($label) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </span>
                                            </span>
                                        </span>
                                    </td>

                                    <td class="<?= $warnText ? 'warnText' : 'okText' ?>">
                                        <?= $warnText ? h($warnText) : 'OK' ?>
                                    </td>

                                    <td>
                                        <div class="ops">
                                            <a class="iconBtn editIconBtn" title="編集" aria-label="編集"
                                                href="<?= h($editUrl) ?>">✏️</a>

                                            <?php
                                            $canDeletePair = ((int)$d['_edit_clock_out_id'] > 0);
                                            ?>
                                            <button type="button" class="iconBtn danger editIconBtn" title="削除" aria-label="削除"
                                                <?= $canDeletePair ? '' : 'disabled' ?>
                                                onclick="deletePunchPair(<?= (int)$storeId ?>, <?= (int)$eid ?>, '<?= h($dayKey) ?>', <?= (int)$d['_edit_clock_in_id'] ?>, <?= (int)$d['_edit_clock_out_id'] ?>)">
                                                🗑️
                                            </button>
                                        </div>
                                    </td>
                                    <td class="muted"><?= ((int)$d['seq'] > 0) ? (string)((int)$d['seq']) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                    <?php if (!empty($items)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="5" style="text-align:right;">合計</td>
                                <td class="num"><?= h(secToHM($totalWorkSec)) ?></td>
                                <td class="num"><?= h(secToHM($totalBreakSec)) ?></td>
                                <td class="num"><?= h(secToHM($totalNetSec)) ?></td>
                                <td class="num">
                                    <?= number_format($totalLaborYen) ?>
                                    <?php if ($totalNightPremiumYen > 0): ?>
                                        <span class="sub nightPremiumSub">深割合計:+<?= number_format($totalNightPremiumYen) ?>円</span>
                                    <?php endif; ?>
                                </td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>

                <div class="cardWrap">
                    <?php if (empty($items)): ?>
                        <div class="tpCard">
                            <div class="muted">該当データなし</div>
                        </div>
                    <?php else: ?>
                        <?php $backUrl = '/admin/time_punch_daily.php?' . http_build_query($baseQuery); ?>
                        <?php foreach ($items as $d): ?>
                            <?php
                            $work  = (int)$d['work_seconds'];
                            $break = (int)$d['break_seconds'];
                            $net   = max(0, $work - $break);

                            $warnText = implode(' / ', array_unique($d['warn']));

                            $eid = (int)$d['employee_id'];
                            $dayKey = (string)$d['day'];

                            $hk = $eid . '|' . $dayKey;
                            if ($hasWageHistory && isset($effectiveWageMap[$hk])) {
                                $hourly = (int)$effectiveWageMap[$hk];
                            } else {
                                $hourly = (int)($wageMap[$eid] ?? 0);
                            }

                            $key = $eid . '|' . $dayKey;
                            $bonusYen    = (int)($bonusMap[$key] ?? 0);
                            $cashbackYen = (int)($cashbackMap[$key] ?? 0);

                            $netForPay = (int)(floor($net / 60) * 60);
                            $basePayYen = ($hourly > 0 && $netForPay > 0)
                                ? (int)round(($netForPay * $hourly) / 3600)
                                : 0;
                            $nightSec = (int)($d['night_seconds'] ?? 0);
                            $nightForPay = (int)(floor($nightSec / 60) * 60);
                            $nightPremiumYen = 0;
                            if ($hourly > 0 && $nightForPay > 0 && !empty($nightPremiumEnabledMap[$eid])) {
                                $rate = (int)($nightPremiumRateMap[$eid] ?? 25);
                                $nightPremiumYen = (int)round(($nightForPay * $hourly * $rate) / 100 / 3600);
                            }
                            $dayPayYen = $basePayYen + $nightPremiumYen;

                            $editUrl = 'time_punch_edit.php?' . http_build_query([
                                'store_id'     => (int)$storeId,
                                'employee_id'  => $eid,
                                'day'          => $dayKey,
                                'clock_in_id'  => (int)$d['_edit_clock_in_id'],
                                'clock_out_id' => (int)$d['_edit_clock_out_id'],
                                'back_url'     => $backUrl,
                            ]);

                            $inTs  = $d['_display_in_ts'];
                            $outTs = $d['_display_out_ts'];

                            $w = wdayNum($dayKey);
                            $dateClass = ($w === 0) ? 'sun' : (($w === 6) ? 'sat' : 'wk');

                            $hasBonusOrBack = ($bonusYen > 0) || ($cashbackYen > 0);
                            $bonusBackParts = [];
                            if ($bonusYen > 0) $bonusBackParts[] = 'BO:' . number_format($bonusYen);
                            if ($cashbackYen > 0) $bonusBackParts[] = 'BA:' . number_format($cashbackYen);
                            $bonusBackLine = implode(' / ', $bonusBackParts);

                            $payKey = $eid . '|' . $dayKey;
                            $payStatus = $paymentStatusMap[$payKey] ?? 'unconfirmed';
                            $payLabel = $paymentStatusLabels[$payStatus] ?? '未確認';
                            $payClass = $paymentStatusClasses[$payStatus] ?? 'status-unconfirmed';

                            $canDeletePair = ((int)$d['_edit_clock_out_id'] > 0);
                            $rowStateClass = ($payStatus === 'canceled') ? 'tpRowCanceled'
                                : ($warnText ? 'tpRowWarn' : (($payStatus === 'paid') ? 'tpRowPaid' : (($payStatus === 'unconfirmed') ? 'tpRowUnconfirmed' : '')));
                            ?>
                            <div class="tpCard <?= h($rowStateClass) ?>" data-employee-id="<?= (int)$eid ?>"
                                data-day="<?= h($dayKey) ?>" data-pay-status="<?= h($payStatus) ?>">
                                <div class="tpCardHead">
                                    <label class="checkCell">
                                        <input type="checkbox" class="payRowCheck" name="payment_keys[]"
                                            value="<?= h($payKey) ?>" <?= $payStatus === 'paid' ? 'disabled' : '' ?>>
                                    </label>
                                    <span class="dateLine <?= h($dateClass) ?>">
                                        <span><?= h(date('m-d', strtotime($dayKey))) ?></span>
                                        <span>(<?= h(wdayJa($dayKey)) ?>)</span>
                                    </span>
                                </div>

                                <div class="tpCardRow">
                                    <span class="tpCardKey">従業員</span>
                                    <span><?= h((string)$d['display_name']) ?></span>
                                </div>
                                <div class="tpCardRow">
                                    <span class="tpCardKey">出勤</span>
                                    <span
                                        class="num timeCell punchTimeCell"><?= timeWithPunchSourceHtml($inTs, $roundUnit, 'clock_in', (string)($d['clock_in_source'] ?? '')) ?></span>
                                </div>
                                <div class="tpCardRow">
                                    <span class="tpCardKey">退勤</span>
                                    <span
                                        class="num timeCell punchTimeCell"><?= timeWithPunchSourceHtml($outTs, $roundUnit, 'clock_out', (string)($d['clock_out_source'] ?? '')) ?></span>
                                </div>
                                <div class="tpCardRow">
                                    <span class="tpCardKey">勤務/休憩/実働</span>
                                    <span class="num timeCell"><?= h(secToHM($work)) ?> / <?= h(secToHM($break)) ?> /
                                        <?= h(secToHM($net)) ?></span>
                                </div>
                                <div class="tpCardRow">
                                    <span class="tpCardKey">日給</span>
                                    <span class="num dayPayCell">
                                        <span class="dayPayRowValue">
                                            <span class="dayPayAmount">
                                                <?= number_format($dayPayYen) ?>円
                                                <?php if ($hasBonusOrBack): ?>
                                                    <span class="sub bonusBackSub"><?= h($bonusBackLine) ?></span>
                                                <?php endif; ?>
                                                <?php if ($nightPremiumYen > 0): ?>
                                                    <span class="sub nightPremiumSub">深割:+<?= number_format($nightPremiumYen) ?>円</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="payStatusCell dayPayStatus">
                                                <button type="button" class="payStatusBadge <?= h($payClass) ?>"
                                                    data-pay-toggle>
                                                    <?= h($payLabel) ?>
                                                </button>
                                                <span class="payStatusMenu">
                                                    <span class="payStatusSelectWrap">
                                                        <select class="payStatusSelect" data-employee-id="<?= (int)$eid ?>"
                                                            data-day="<?= h($dayKey) ?>">
                                                            <?php foreach ($paymentStatusLabels as $k => $label): ?>
                                                                <option value="<?= h($k) ?>"
                                                                    <?= $k === $payStatus ? 'selected' : '' ?>>
                                                                    <?= h($label) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </span>
                                                </span>
                                            </span>
                                        </span>
                                    </span>
                                </div>
                                <div class="tpCardRow">
                                    <span class="tpCardKey">打刻入力</span>
                                    <span class="<?= $warnText ? 'warnText' : 'okText' ?>">
                                        <?= $warnText ? h($warnText) : 'OK' ?>
                                    </span>
                                </div>
                                <div class="tpCardRow">
                                    <span class="tpCardKey">操作</span>
                                    <span class="ops">
                                        <a class="iconBtn editIconBtn" title="編集" aria-label="編集"
                                            href="<?= h($editUrl) ?>">✏️</a>
                                        <button type="button" class="iconBtn danger editIconBtn" title="削除" aria-label="削除"
                                            <?= $canDeletePair ? '' : 'disabled' ?>
                                            onclick="deletePunchPair(<?= (int)$storeId ?>, <?= (int)$eid ?>, '<?= h($dayKey) ?>', <?= (int)$d['_edit_clock_in_id'] ?>, <?= (int)$d['_edit_clock_out_id'] ?>)">
                                            🗑️
                                        </button>
                                    </span>
                                </div>
                                <div class="tpCardRow">
                                    <span class="tpCardKey">当日出勤回数</span>
                                    <span class="muted"><?= ((int)$d['seq'] > 0) ? (string)((int)$d['seq']) : '-' ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function() {
            const sel = document.getElementById('periodSelect');
            const scope = document.getElementById('empScopeSelect');
            const roundSelect = document.getElementById('round15Select');
            const empSelect = document.getElementById('employeeSelect');
            const printBtn = document.getElementById('payrollPrintBtn');
            const form = document.getElementById('filterForm');
            const from = document.getElementById('fromInput');
            const to = document.getElementById('toInput');
            const selectAll = document.getElementById('selectAllPayments');
            const customRange = document.getElementById('customRange');
            const customizeBtn = document.getElementById('customizeBtn');
            const customizeMenu = document.getElementById('customizeMenu');
            const exportSelect = document.getElementById('exportSelect');

            if (!form) return;

            const updateCustomRangeVisibility = () => {
                if (!customRange || !sel) return;
                const isCustom = (sel.value === '');
                customRange.classList.toggle('is-show', isCustom);
            };

            if (sel) {
                sel.addEventListener('change', function() {
                    updateCustomRangeVisibility();
                    if (sel.value !== '') form.submit();
                });
            }

            if (scope) {
                scope.addEventListener('change', function() {
                    form.submit();
                });
            }

            if (roundSelect) {
                roundSelect.addEventListener('change', function() {
                    form.submit();
                });
            }

            const updatePrintLink = () => {
                if (!empSelect || !printBtn) return;
                const isAll = (empSelect.value === '0');
                printBtn.classList.toggle('disabled', isAll);
                printBtn.setAttribute('aria-disabled', isAll ? 'true' : 'false');
                if (isAll) {
                    printBtn.setAttribute('tabindex', '-1');
                    printBtn.removeAttribute('href');
                    return;
                }
                printBtn.removeAttribute('tabindex');
                const base = printBtn.getAttribute('data-href') || '';
                if (!base) return;
                const u = new URL(base, window.location.origin);
                u.searchParams.set('employee_id', empSelect.value);
                printBtn.setAttribute('href', u.pathname + u.search);
            };

            if (empSelect) {
                empSelect.addEventListener('change', function() {
                    updatePrintLink();
                    form.submit();
                });
            }
            updatePrintLink();

            const onCustom = function() {
                if (sel && sel.value !== '') {
                    sel.value = '';
                    updateCustomRangeVisibility();
                }
            };
            if (from) from.addEventListener('change', function() {
                onCustom();
                form.submit();
            });
            if (to) to.addEventListener('change', function() {
                onCustom();
                form.submit();
            });

            updateCustomRangeVisibility();

            if (exportSelect) {
                exportSelect.addEventListener('change', function() {
                    const v = exportSelect.value || '';
                    if (!v) return;
                    const u = new URL(window.location.href);
                    u.searchParams.set('export', v);
                    window.location.href = u.toString();
                    exportSelect.value = '';
                });
            }

            if (customizeBtn && customizeMenu) {
                customizeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const isOpen = customizeMenu.classList.contains('is-open');
                    customizeMenu.classList.toggle('is-open', !isOpen);
                });

                document.addEventListener('click', function(e) {
                    if (!customizeMenu.classList.contains('is-open')) return;
                    const target = e.target;
                    if (target && (target.closest('#customizeBtn') || target.closest('#customizeMenu'))) return;
                    customizeMenu.classList.remove('is-open');
                });
            }

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    const checks = document.querySelectorAll('.payRowCheck');
                    checks.forEach(chk => {
                        chk.checked = selectAll.checked;
                    });
                });
            }

            const closeAllMenus = () => {
                document.querySelectorAll('.payStatusMenu').forEach(m => m.classList.remove('is-open'));
            };

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

            document.querySelectorAll('[data-pay-toggle]').forEach(btn => {
                btn.addEventListener('click', function() {
                    const cell = btn.closest('.payStatusCell');
                    if (!cell) return;
                    const menu = cell.querySelector('.payStatusMenu');
                    if (!menu) return;
                    const isOpen = menu.classList.contains('is-open');
                    closeAllMenus();
                    if (!isOpen) menu.classList.add('is-open');
                });
            });

            document.addEventListener('click', function(e) {
                const target = e.target;
                if (target && target.closest && (target.closest('[data-pay-toggle]') || target.closest(
                        '.payStatusMenu'))) {
                    return;
                }
                closeAllMenus();
            });

            const singleForm = document.createElement('form');
            singleForm.method = 'post';
            singleForm.action =
                <?= json_encode('/admin/time_punch_daily.php?' . http_build_query($_GET), JSON_UNESCAPED_UNICODE) ?>;
            singleForm.style.display = 'none';
            singleForm.innerHTML =
                '<input type="hidden" name="csrf_token" value="' + <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?> +
                '">' +
                '<input type="hidden" name="action" value="set_payment_status">' +
                '<input type="hidden" name="employee_id" value="">' +
                '<input type="hidden" name="day" value="">' +
                '<input type="hidden" name="payment_status" value="">';
            document.body.appendChild(singleForm);

            const setPaymentStatus = (empId, day, status) => {
                if (!empId || !day || !status) return;
                singleForm.querySelector('input[name="employee_id"]').value = empId;
                singleForm.querySelector('input[name="day"]').value = day;
                singleForm.querySelector('input[name="payment_status"]').value = status;
                singleForm.submit();
            };

            document.querySelectorAll('.payStatusSelect').forEach(select => {
                select.addEventListener('change', function() {
                    const empId = select.getAttribute('data-employee-id') || '';
                    const day = select.getAttribute('data-day') || '';
                    const status = select.value || '';
                    if (!empId || !day || !status) return;
                    setPaymentStatus(empId, day, status);
                });
            });

            document.addEventListener('click', function(e) {
                const target = e.target;
                const actionBtn = target && target.closest ? target.closest(
                    '.ops .iconBtn, .tpCard .ops .iconBtn, a.editIconBtn, button.editIconBtn') : null;
                if (!actionBtn) return;
                const row = actionBtn.closest('tr[data-pay-status], .tpCard[data-pay-status]');
                if (!row) return;
                if (row.getAttribute('data-pay-status') !== 'paid') return;

                e.preventDefault();
                e.stopImmediatePropagation();

                const empId = row.getAttribute('data-employee-id') || '';
                const day = row.getAttribute('data-day') || '';
                const ok = window.confirm('振込済なので直接編集できません。修正する場合は「取消」に変更してください。\\n\\n取消に変更しますか？');
                if (ok) {
                    setPaymentStatus(empId, day, 'canceled');
                }
            }, true);
        })();

        (function() {
            const tableWrap = document.querySelector('.tableWrap');
            const table = tableWrap ? tableWrap.querySelector('table') : null;
            if (!tableWrap || !table) return;

            const wrap = document.createElement('div');
            wrap.className = 'tpTableStickyWrap';
            const cloneTable = document.createElement('table');
            cloneTable.className = 'tpTableSticky';
            wrap.appendChild(cloneTable);
            tableWrap.insertBefore(wrap, table);

            const buildClone = () => {
                const thead = table.querySelector('thead');
                if (!thead) return;
                cloneTable.innerHTML = '';
                cloneTable.appendChild(thead.cloneNode(true));
            };

            const syncWidths = () => {
                const head = table.querySelector('thead');
                const cloneHead = cloneTable.querySelector('thead');
                if (!head || !cloneHead) return;

                const rect = tableWrap.getBoundingClientRect();
                const header = document.querySelector('.azHd');
                const topOffset = header ? header.getBoundingClientRect().height : 0;
                wrap.style.top = Math.max(0, topOffset) + 'px';
                wrap.style.left = rect.left + 'px';
                wrap.style.width = rect.width + 'px';

                const ths = head.querySelectorAll('th');
                const cloneThs = cloneHead.querySelectorAll('th');
                cloneTable.style.width = table.scrollWidth + 'px';
                ths.forEach((th, i) => {
                    const c = cloneThs[i];
                    if (!c) return;
                    c.style.width = th.getBoundingClientRect().width + 'px';
                });

                cloneTable.style.transform = 'translateX(' + (-tableWrap.scrollLeft) + 'px)';
            };

            const shouldEnable = () => window.matchMedia('(min-width: 901px)').matches;

            const onScroll = () => {
                if (!shouldEnable()) {
                    wrap.style.display = 'none';
                    return;
                }
                const head = table.querySelector('thead');
                if (!head) return;
                const rect = tableWrap.getBoundingClientRect();
                const headHeight = head.getBoundingClientRect().height;
                const header = document.querySelector('.azHd');
                const topOffset = header ? header.getBoundingClientRect().height : 0;
                const inViewport = rect.bottom > topOffset && rect.top < window.innerHeight;
                const pastTop = rect.top < topOffset && rect.bottom - headHeight > topOffset;
                const hasInnerScroll = tableWrap.scrollTop > 0;
                const isVisible = inViewport && (pastTop || hasInnerScroll);
                wrap.style.display = isVisible ? 'block' : 'none';
                if (isVisible) syncWidths();
            };

            buildClone();
            syncWidths();
            onScroll();

            window.addEventListener('scroll', onScroll, {
                passive: true
            });
            tableWrap.addEventListener('scroll', onScroll, {
                passive: true
            });
            window.addEventListener('resize', () => {
                buildClone();
                syncWidths();
                onScroll();
            });
        })();

        function deletePunchPair(storeId, employeeId, day, clockInId, clockOutId) {
            if (!clockOutId || clockOutId <= 0) {
                alert('退勤が未入力のため削除できません。');
                return;
            }
            const ok = confirm(`【削除確認】\n${day} の打刻を1件削除します。\nよろしいですか？`);
            if (!ok) return;

            const pw = prompt('削除パスワードを入力してください');
            if (pw === null) return;
            if (pw.trim() === '') {
                alert('パスワードが空です');
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/admin/time_punch_delete_pair.php';

            const params = {
                csrf_token: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
                store_id: String(storeId),
                employee_id: String(employeeId),
                day: String(day),
                clock_in_id: String(clockInId || ''),
                clock_out_id: String(clockOutId || ''),
                admin_password: pw,
                back_url: <?= json_encode('/admin/time_punch_daily.php?' . http_build_query($baseQuery), JSON_UNESCAPED_UNICODE) ?>
            };

            for (const [k, v] of Object.entries(params)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = k;
                input.value = v;
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }

        // =========================
        // ✅ UI追加：勤怠登録モーダル（既存 quick_add を使うだけ）
        // =========================
        (function() {
            const openBtn = document.getElementById('openQuickAdd');
            const modal = document.getElementById('quickAddModal');
            const closeBtn = document.getElementById('closeQuickAdd');
            const cancelBtn = document.getElementById('cancelQuickAdd');
            const form = modal ? modal.querySelector('form') : null;
            const inSel = modal ? modal.querySelector('select[name="clock_in"]') : null;
            const outSel = modal ? modal.querySelector('select[name="clock_out"]') : null;
            const nextDay = modal ? modal.querySelector('input[name="clock_out_next_day"]') : null;

            if (!openBtn || !modal) return;

            const open = () => {
                modal.classList.add('isOpen');
                modal.setAttribute('aria-hidden', 'false');

                // ✅ 最初の入力にフォーカス（UIだけ）
                const first = modal.querySelector('select, input, button');
                if (first && typeof first.focus === 'function') first.focus();
            };

            const close = () => {
                modal.classList.remove('isOpen');
                modal.setAttribute('aria-hidden', 'true');
            };

            openBtn.addEventListener('click', open);
            if (closeBtn) closeBtn.addEventListener('click', close);
            if (cancelBtn) cancelBtn.addEventListener('click', close);

            modal.addEventListener('click', (e) => {
                if (e.target === modal) close();
            });

            window.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('isOpen')) close();
            });

            if (form && inSel && outSel && nextDay) {
                form.addEventListener('submit', (e) => {
                    const inVal = (inSel.value || '').trim();
                    const outVal = (outSel.value || '').trim();
                    if (inVal && outVal) {
                        const [inH, inM] = inVal.split(':').map(v => parseInt(v, 10));
                        const [outH, outM] = outVal.split(':').map(v => parseInt(v, 10));
                        const inMin = (inH * 60) + inM;
                        const outMin = (outH * 60) + outM;
                        if (outMin < inMin && !nextDay.checked) {
                            e.preventDefault();
                            alert('退勤は翌日（深夜退勤）にチェックを入れてください。');
                        }
                    }
                });
            }
        })();
    </script>

</body>

</html>
