<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/ai_followup.php
 * ✅ 書き込み場所: 既存の /admin/ai_followup.php を「丸ごと置き換え」
 *
 * ポイント:
 * - どんな場合も JSON で返す（"not logged in" 文字列を返さない）
 * - セッションで admin_auth を検証（_auth.php の require_admin_login() は使わない）
 * - ✅ time_punches 集計だけで「出勤日数」「打刻漏れ疑い」を数字で返す
 * - ✅ shifts があれば「残業過多疑い（予定比）」も数字で返す
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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

/* ===========================
 * Admin Session
 * =========================== */
session_name('ADMINSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

if (!isset($_SESSION['admin_auth']) || (int)$_SESSION['admin_auth'] !== 1) {
    out(['ok' => false, 'error' => 'not logged in']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(['ok' => false, 'error' => 'method not allowed']);
}

/* ===========================
 * tenantId（セッション優先）
 * =========================== */
$tenantId = 0;
foreach (['tenantId', 'tenant_id', 'admin_tenant_id'] as $k) {
    if (isset($_SESSION[$k]) && (int)$_SESSION[$k] > 0) {
        $tenantId = (int)$_SESSION[$k];
        break;
    }
}
if ($tenantId <= 0) {
    $tc = __DIR__ . '/_tenant_context.php';
    if (is_file($tc)) {
        require_once $tc; // $tenantId が入る想定
        if (isset($tenantId) && (int)$tenantId > 0) $tenantId = (int)$tenantId;
    }
}
if ($tenantId <= 0) out(['ok' => false, 'error' => 'tenant not found']);

/* ===========================
 * DB
 * =========================== */
$paths = [
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) if (is_file($p)) {
    $dbFile = $p;
    break;
}
if ($dbFile === null) out(['ok' => false, 'error' => 'db.php not found']);
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// openai libs
$bootstrap = __DIR__ . '/../api/bootstrap.php';
if (!is_file($bootstrap)) out(['ok' => false, 'error' => 'bootstrap.php not found']);
require_once $bootstrap;

$client = __DIR__ . '/../api/lib/openai_client.php';
if (!is_file($client)) out(['ok' => false, 'error' => 'openai_client.php not found']);
require_once $client;

/* ===========================
 * util
 * =========================== */
function s(string $v, int $max = 4000): string
{
    $v = trim($v);
    $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
    if (mb_strlen($v, 'UTF-8') > $max) $v = mb_substr($v, 0, $max, 'UTF-8');
    return $v;
}
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
function normalize_person_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/(さん|様|ちゃん|くん)$/u', '', $name) ?? $name;
    $name = preg_replace('/\s+/u', '', $name) ?? $name;
    return $name;
}
function extract_employee_name_from_question(string $q): ?string
{
    $q = trim($q);
    if (preg_match('/([^\s　]+?)(さん|様|ちゃん|くん)?の出勤日数/u', $q, $m)) return normalize_person_name((string)$m[1]);
    if (preg_match('/従業員\s*([^\s　]+?)(さん|様|ちゃん|くん)/u', $q, $m)) return normalize_person_name((string)$m[1]);
    if (preg_match('/([^\s　]+?)(さん|様|ちゃん|くん)/u', $q, $m)) return normalize_person_name((string)$m[1]);
    return null;
}
function find_employee_table(PDO $pdo): ?string
{
    foreach (['employees', 'staffs', 'workers'] as $t) if (table_exists($pdo, $t)) return $t;
    return null;
}
function find_employee_name_col(PDO $pdo, string $empTable): ?string
{
    foreach (['display_name', 'name', 'full_name', 'nickname'] as $c) if (has_column($pdo, $empTable, $c)) return $c;
    return null;
}

/** ✅ time_punches を日別に集計（出勤/打刻漏れ/実働分） */
function tp_daily_aggregate(PDO $pdo, int $tenantId, int $storeId, string $fromYmd, string $toYmd): array
{
    if (!table_exists($pdo, 'time_punches')) return ['ok' => false, 'reason' => 'time_punches not found'];

    $t = 'time_punches';
    if (!has_column($pdo, $t, 'employee_id')) return ['ok' => false, 'reason' => 'time_punches.employee_id missing'];

    // この実装は punch_type + punched_at が前提（あなたの構成に最適化）
    if (!has_column($pdo, $t, 'punch_type') || !has_column($pdo, $t, 'punched_at')) {
        return ['ok' => false, 'reason' => 'time_punches needs punch_type + punched_at'];
    }

    $where = [];
    $params = [
        ':s' => $storeId,
        ':fromdt' => $fromYmd . ' 00:00:00',
        ':todt' => $toYmd . ' 23:59:59',
        ':t' => $tenantId,
    ];

    if (has_column($pdo, $t, 'store_id')) {
        $where[] = "store_id=:s";
    }
    if (has_column($pdo, $t, 'tenant_id')) {
        $where[] = "tenant_id=:t";
    }
    if (has_column($pdo, $t, 'deleted_at')) {
        $where[] = "deleted_at IS NULL";
    }

    $where[] = "punched_at BETWEEN :fromdt AND :todt";

    $sql = "
        SELECT
            employee_id,
            DATE(punched_at) AS d,
            SUM(punch_type='clock_in')  AS cin,
            SUM(punch_type='clock_out') AS cout,
            MIN(CASE WHEN punch_type='clock_in'  THEN punched_at END) AS first_in,
            MAX(CASE WHEN punch_type='clock_out' THEN punched_at END) AS last_out
        FROM {$t}
        WHERE " . implode(' AND ', $where) . "
        GROUP BY employee_id, DATE(punched_at)
        ORDER BY employee_id, d
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // employee_id => stats
    $stats = [];
    foreach ($rows as $r) {
        $eid = (int)$r['employee_id'];
        $cin = (int)$r['cin'];
        $cout = (int)$r['cout'];

        $firstIn = $r['first_in'] ? strtotime((string)$r['first_in']) : null;
        $lastOut = $r['last_out'] ? strtotime((string)$r['last_out']) : null;
        $workedMin = 0;
        if ($firstIn !== null && $lastOut !== null && $lastOut > $firstIn) {
            $workedMin = (int)floor(($lastOut - $firstIn) / 60);
        }

        if (!isset($stats[$eid])) {
            $stats[$eid] = [
                'attend_days' => 0,
                'missing_in_days' => 0,
                'missing_out_days' => 0,
                'worked_minutes' => 0,
                'days' => [], // d => workedMin
            ];
        }

        $stats[$eid]['attend_days']++;
        if ($cin > 0 && $cout === 0) $stats[$eid]['missing_out_days']++;
        if ($cout > 0 && $cin === 0) $stats[$eid]['missing_in_days']++;
        $stats[$eid]['worked_minutes'] += $workedMin;
        $stats[$eid]['days'][(string)$r['d']] = $workedMin;
    }

    return ['ok' => true, 'stats' => $stats];
}

/** ✅ shifts があれば「予定分」を出して残業分を算出 */
function shifts_overtime(PDO $pdo, int $tenantId, int $storeId, string $fromYmd, string $toYmd, array $tpStats): array
{
    if (!table_exists($pdo, 'shifts')) return ['ok' => false, 'reason' => 'shifts not found'];

    // 必須っぽいカラムを緩く探す
    if (!has_column($pdo, 'shifts', 'employee_id')) return ['ok' => false, 'reason' => 'shifts.employee_id missing'];

    $hasDate = has_column($pdo, 'shifts', 'work_date');
    $hasStart = has_column($pdo, 'shifts', 'start_time');
    $hasEnd = has_column($pdo, 'shifts', 'end_time');
    if (!$hasDate || !$hasStart || !$hasEnd) {
        return ['ok' => false, 'reason' => 'shifts needs work_date/start_time/end_time'];
    }

    $where = [];
    $params = [':from' => $fromYmd, ':to' => $toYmd, ':t' => $tenantId, ':s' => $storeId];

    if (has_column($pdo, 'shifts', 'tenant_id')) {
        $where[] = "tenant_id=:t";
    }
    if (has_column($pdo, 'shifts', 'store_id')) {
        $where[] = "store_id=:s";
    }
    if (has_column($pdo, 'shifts', 'deleted_at')) {
        $where[] = "deleted_at IS NULL";
    }

    $where[] = "work_date BETWEEN :from AND :to";

    $sql = "
        SELECT employee_id, work_date, start_time, end_time, " .
        (has_column($pdo, 'shifts', 'break_minutes') ? "break_minutes" : "0 AS break_minutes") . "
        FROM shifts
        WHERE " . implode(' AND ', $where);

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // employee_id => overtime_minutes
    $ot = [];
    foreach ($rows as $r) {
        $eid = (int)$r['employee_id'];
        $d = (string)$r['work_date'];
        $stt = (string)$r['start_time'];
        $edt = (string)$r['end_time'];
        $brk = (int)($r['break_minutes'] ?? 0);

        // 予定分（分）
        $ss = strtotime($d . ' ' . $stt);
        $ee = strtotime($d . ' ' . $edt);
        if ($ss === false || $ee === false) continue;
        if ($ee < $ss) $ee += 86400; // 深夜跨ぎ
        $schedMin = (int)floor(($ee - $ss) / 60) - max(0, $brk);
        if ($schedMin < 0) $schedMin = 0;

        // 実働分（分）: time_punches集計のその日
        $workedMin = 0;
        if (isset($tpStats[$eid]['days'][$d])) $workedMin = (int)$tpStats[$eid]['days'][$d];

        $o = max(0, $workedMin - $schedMin);
        if (!isset($ot[$eid])) $ot[$eid] = ['overtime_minutes' => 0, 'overtime_days' => 0];
        if ($o > 0) $ot[$eid]['overtime_days']++;
        $ot[$eid]['overtime_minutes'] += $o;
    }

    return ['ok' => true, 'overtime' => $ot];
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

/* ===========================
 * input
 * =========================== */
$storeId = (int)($_POST['store_id'] ?? 0);
$roundOn = ((int)($_POST['round15'] ?? 1) === 1);

$question = s((string)($_POST['question'] ?? ''), 600);
if ($question === '') out(['ok' => false, 'error' => 'question is empty']);

$ctxFrom = s((string)($_POST['from'] ?? ''), 30);
$ctxTo   = s((string)($_POST['to'] ?? ''), 30);
$rateAvg = s((string)($_POST['rate_avg'] ?? ''), 40);
$insights = s((string)($_POST['insights'] ?? ''), 8000);

$empFrom = s((string)($_POST['employee_summary_period_from'] ?? ''), 30);
$empTo   = s((string)($_POST['employee_summary_period_to'] ?? ''), 30);
if ($empFrom === '' || $empTo === '') {
    $now = new DateTimeImmutable('now', new DateTimeZone($tz));
    $empTo = $now->format('Y-m-d');
    $empFrom = $now->modify('-29 days')->format('Y-m-d');
}

/* ===========================
 * store validate
 * =========================== */
if (!table_exists($pdo, 'stores')) out(['ok' => false, 'error' => 'stores table not found']);
$stStores = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:t ORDER BY id ASC");
$stStores->execute([':t' => $tenantId]);
$stores = $stStores->fetchAll();
if (!$stores) out(['ok' => false, 'error' => 'stores not found']);

$storeIds = array_map('intval', array_column($stores, 'id'));
if (!in_array($storeId, $storeIds, true)) $storeId = (int)$stores[0]['id'];

$storeName = '';
foreach ($stores as $r) if ((int)$r['id'] === $storeId) {
    $storeName = (string)$r['name'];
    break;
}

/* ===========================
 * ✅ 従業員名解決（id→名前）
 * =========================== */
$empTable = find_employee_table($pdo);
$nameCol = $empTable ? find_employee_name_col($pdo, $empTable) : null;

$empNames = []; // employee_id => name
if ($empTable && $nameCol) {
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
    $sql = "SELECT id, `{$nameCol}` AS nm FROM `{$empTable}`" . ($where ? (" WHERE " . implode(' AND ', $where)) : "");
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
        $empNames[(int)$r['id']] = (string)$r['nm'];
    }
}

/* ===========================
 * ✅ 「従業員評価 深掘り」ルート（time_punchesで確定）
 * =========================== */
$isDeepEval = (bool)preg_match('/従業員評価.*深掘り|打刻漏れ疑い|残業過多疑い|出勤日数/u', $question);

if ($isDeepEval) {
    $tp = tp_daily_aggregate($pdo, $tenantId, $storeId, $empFrom, $empTo);
    if (!$tp['ok']) {
        out([
            'ok' => true,
            'answer' =>
            "結論：打刻データが取れていないため、打刻漏れ疑い・残業過多疑い・出勤日数は不明です。\n\n" .
                "根拠：{$tp['reason']}\n\n" .
                "必要なデータ項目：\n" .
                "- time_punches.employee_id\n" .
                "- time_punches.punch_type（clock_in/clock_out/break_in/break_out）\n" .
                "- time_punches.punched_at（打刻時刻）\n" .
                "- （残業判定するなら）shifts.work_date/start_time/end_time/break_minutes",
        ]);
    }

    $stats = (array)$tp['stats']; // employee_id => stats

    // shifts があれば「予定比の残業」
    $ot = shifts_overtime($pdo, $tenantId, $storeId, $empFrom, $empTo, $stats);
    $overtime = $ot['ok'] ? (array)$ot['overtime'] : [];

    // 出力用に並び替え（出勤日数 desc）
    $rows = [];
    foreach ($stats as $eid => $v) {
        $eid = (int)$eid;
        $name = $empNames[$eid] ?? ("ID:" . $eid);

        $att = (int)($v['attend_days'] ?? 0);
        $mi  = (int)($v['missing_in_days'] ?? 0);
        $mo  = (int)($v['missing_out_days'] ?? 0);
        $wm  = (int)($v['worked_minutes'] ?? 0);

        $otMin = (int)($overtime[$eid]['overtime_minutes'] ?? 0);
        $otDays = (int)($overtime[$eid]['overtime_days'] ?? 0);

        $rows[] = [
            'name' => $name,
            'att' => $att,
            'mi' => $mi,
            'mo' => $mo,
            'wm' => $wm,
            'otMin' => $otMin,
            'otDays' => $otDays,
        ];
    }

    usort($rows, fn($a, $b) => ($b['att'] <=> $a['att']) ?: (($b['mo'] + $b['mi']) <=> ($a['mo'] + $a['mi'])));

    $top = array_slice($rows, 0, 20);

    $lines = [];
    $lines[] = "結論：打刻データ（time_punches）から、出勤日数・打刻漏れ疑いを集計しました（期間：{$empFrom}〜{$empTo}）。";
    $lines[] = $ot['ok']
        ? "残業過多疑いは、予定シフト（shifts）との差分で集計しました。"
        : "残業過多疑いは、予定シフト（shifts）が無いため確定できません（※shiftsがあれば確定可）。";
    $lines[] = "";
    $lines[] = "集計（上位 " . count($top) . "件）：";
    $lines[] = "※ 打刻漏れ疑い＝「出勤打刻あり/退勤打刻なし」または「退勤打刻あり/出勤打刻なし」の日数";

    foreach ($top as $r) {
        $hWork = round($r['wm'] / 60, 1);
        if ($ot['ok']) {
            $hOt = round($r['otMin'] / 60, 1);
            $lines[] = "- {$r['name']}：出勤{$r['att']}日 / 打刻漏れ疑い(出勤なし{$r['mi']}日, 退勤なし{$r['mo']}日) / 実働{$hWork}h / 残業{$hOt}h（{$r['otDays']}日）";
        } else {
            $lines[] = "- {$r['name']}：出勤{$r['att']}日 / 打刻漏れ疑い(出勤なし{$r['mi']}日, 退勤なし{$r['mo']}日) / 実働{$hWork}h";
        }
    }

    $lines[] = "";
    $lines[] = "次アクション：";
    $lines[] = "1) 残業を確定したいなら shifts を整備（work_date/start_time/end_time/break_minutes）。";
    $lines[] = "2) 打刻漏れ疑いが多い人は、当日の打刻ログを見て原因（深夜跨ぎ/端末/運用）を特定。";

    out(['ok' => true, 'answer' => implode("\n", $lines)]);
}

/* ===========================
 * AI回答ルート（一般）
 * =========================== */
$prompt = [
    "あなたは飲食店の店長補佐AIです。",
    "ユーザーの質問に、短く・具体的に・結論から日本語で答えてください。",
    "",
    "【重要な表現ルール】",
    "- データベース名・テーブル名・カラム名などの専門用語は一切使わない",
    "- 技術用語（例：time_punches / shifts / employee_id など）は出さない",
    "- 「打刻データ」は必ず「出退勤の記録」と言い換える",
    "- 「シフトデータ」は必ず「予定されていた勤務時間」と言い換える",
    "- 技術的な理由ではなく、現場の運用・記録・仕組みの観点で説明する",
    "- 店長がそのまま従業員に読み上げられる文章にする",
    "",
    "店舗: {$storeName}",
    "期間: {$empFrom}〜{$empTo}",
    "打刻調整: " . ($roundOn ? "ON(15分)" : "OFF"),
    "",
    "直前のAI改善案（原文）:",
    $insights !== '' ? $insights : "(なし)",
    "",
    "ユーザーの質問:",
    $question,
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
$resp = openai_responses('gpt-4.1-mini', $inputText, 24);
$text = trim((string)openai_extract_text($resp));

if ($text === '') {
    $text = "結論：AI応答が空でした。\n根拠：モデル応答が空文字のため。\n次アクション：サーバログを確認してください。";
}
out(['ok' => true, 'answer' => $text]);
