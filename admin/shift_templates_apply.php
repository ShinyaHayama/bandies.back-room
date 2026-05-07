<?php

/**
 * ✅ ファイル名: /admin/shift_templates_apply.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * 固定出勤テンプレを指定期間に反映する（夜勤対応 / 重複安全）
 *
 * ✅ 今回の追加（あなたの要望）
 * - 「同じ時刻に既に登録されている場合」は反映しない（= スキップ）
 *   ※ここでの「同じ時刻」= start_time と end_time が同一（end_next_day列がある場合は end_next_day も同一）
 *   ※ deleted_at IS NULL の “生きているシフト” が対象
 *
 * 重要:
 * - shifts.uq_shift（例: tenant_id, store_id, employee_id, shift_date, start_time）に衝突しても
 *   500にならないように UPSERT（ON DUPLICATE KEY UPDATE）で処理する
 * - 夜勤対応：終了<=開始 を許可（end_next_day=1 扱い）
 * - shifts テーブルに end_next_day 列があれば保存（無ければ無視）
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';

require_once __DIR__ . '/../api/lib/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
function isValidCsrf(string $t): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $t);
}

function hasColumn(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
    $stmt->execute([':c' => $col]);
    $cache[$key] = (bool)$stmt->fetch();
    return $cache[$key];
}

function toMin(string $hhmm): ?int
{
    if (!preg_match('/^\d{2}:\d{2}$/', $hhmm)) return null;
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59) return null;
    return $h * 60 + $m;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo "Method Not Allowed";
        exit;
    }

    $token = (string)($_POST['csrf_token'] ?? '');
    if (!isValidCsrf($token)) {
        throw new RuntimeException('CSRF不正（再読み込みしてください）');
    }

    $storeId = (int)($_POST['store_id'] ?? 0);
    if ($storeId <= 0) throw new RuntimeException('store_id 不正');

    $dateFrom = (string)($_POST['date_from'] ?? '');
    $dateTo   = (string)($_POST['date_to'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) throw new RuntimeException('開始日が不正');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) throw new RuntimeException('終了日が不正');

    $employeeId = (int)($_POST['employee_id'] ?? 0); // 0=全員
    $returnTo = (string)($_POST['return_to'] ?? '/admin/shifts.php?store_id=' . $storeId);
    if ($returnTo === '' || $returnTo[0] !== '/') $returnTo = '/admin/shifts.php?store_id=' . $storeId;

    $d1 = new DateTimeImmutable($dateFrom);
    $d2 = new DateTimeImmutable($dateTo);
    if ($d2 < $d1) throw new RuntimeException('日付範囲が逆です');

    // ✅ 期間一覧
    $days = [];
    $cur = $d1;
    while ($cur <= $d2) {
        $days[] = $cur;
        $cur = $cur->modify('+1 day');
    }

    // ✅ 従業員（対象）
    $empParams = [':t' => $tenantId, ':s' => $storeId];
    $empWhere = '';
    if ($employeeId > 0) {
        $empWhere = ' AND id = :eid ';
        $empParams[':eid'] = $employeeId;
    }
    $empStmt = $pdo->prepare("
        SELECT id
        FROM employees
        WHERE tenant_id=:t AND store_id=:s {$empWhere}
        ORDER BY sort_order ASC, id ASC
    ");
    $empStmt->execute($empParams);
    $empIds = array_map(fn($r) => (int)$r['id'], $empStmt->fetchAll());
    if (!$empIds) throw new RuntimeException('対象の従業員がいません');

    // ✅ テンプレ（end_next_day含む）
    $tplParams = [':t' => $tenantId, ':s' => $storeId];
    $tplWhere = '';
    if ($employeeId > 0) {
        $tplWhere = ' AND employee_id = :eid ';
        $tplParams[':eid'] = $employeeId;
    }
    $tplStmt = $pdo->prepare("
        SELECT id, employee_id, wday, start_time, end_time, end_next_day, break_minutes, note
        FROM shift_templates
        WHERE tenant_id=:t AND store_id=:s AND deleted_at IS NULL {$tplWhere}
        ORDER BY employee_id ASC, wday ASC, start_time ASC
    ");
    $tplStmt->execute($tplParams);
    $templates = $tplStmt->fetchAll();
    if (!$templates) {
        header('Location: ' . $returnTo . (strpos($returnTo, '?') === false ? '?' : '&') . 'applied=0');
        exit;
    }

    // employee_id + wday でまとめる（複数テンプレOK）
    $tplMap = [];
    foreach ($templates as $t) {
        $eid = (int)$t['employee_id'];
        $w = (int)$t['wday'];
        $tplMap[$eid][$w][] = $t;
    }

    // ✅ shifts に end_next_day があるか
    $shiftsHasEndNextDay = hasColumn($pdo, 'shifts', 'end_next_day');

    // ✅ 追加：同時刻（start/end一致）が既にあるか確認するSQL（存在したらスキップ）
    if ($shiftsHasEndNextDay) {
        $existsSql = "
            SELECT 1
            FROM shifts
            WHERE tenant_id=:t AND store_id=:s
              AND employee_id=:eid
              AND shift_date=:d
              AND start_time=:st
              AND end_time=:et
              AND end_next_day=:nd
              AND deleted_at IS NULL
            LIMIT 1
        ";
    } else {
        $existsSql = "
            SELECT 1
            FROM shifts
            WHERE tenant_id=:t AND store_id=:s
              AND employee_id=:eid
              AND shift_date=:d
              AND start_time=:st
              AND end_time=:et
              AND deleted_at IS NULL
            LIMIT 1
        ";
    }
    $existsStmt = $pdo->prepare($existsSql);

    // ✅ UPSERT文を作る（uq_shift に衝突したら更新）
    if ($shiftsHasEndNextDay) {
        $upsertSql = "
            INSERT INTO shifts
                (tenant_id, store_id, employee_id, shift_date, start_time, end_time, end_next_day, break_minutes, note, created_at, updated_at, deleted_at)
            VALUES
                (:t,:s,:eid,:d,:st,:et,:nd,:br,:note,NOW(),NOW(),NULL)
            ON DUPLICATE KEY UPDATE
                end_time=VALUES(end_time),
                end_next_day=VALUES(end_next_day),
                break_minutes=VALUES(break_minutes),
                note=VALUES(note),
                deleted_at=NULL,
                updated_at=NOW()
        ";
    } else {
        $upsertSql = "
            INSERT INTO shifts
                (tenant_id, store_id, employee_id, shift_date, start_time, end_time, break_minutes, note, created_at, updated_at, deleted_at)
            VALUES
                (:t,:s,:eid,:d,:st,:et,:br,:note,NOW(),NOW(),NULL)
            ON DUPLICATE KEY UPDATE
                end_time=VALUES(end_time),
                break_minutes=VALUES(break_minutes),
                note=VALUES(note),
                deleted_at=NULL,
                updated_at=NOW()
        ";
    }
    $upsertStmt = $pdo->prepare($upsertSql);

    $pdo->beginTransaction();

    $applied = 0;

    foreach ($days as $day) {
        $wday = (int)$day->format('w');
        $dateKey = $day->format('Y-m-d');

        foreach ($empIds as $eid) {
            $list = $tplMap[$eid][$wday] ?? [];
            if (!$list) continue;

            // ✅ 同一日・同一開始時刻のテンプレが重複してても二重実行しない（念のため）
            $seenStart = [];

            foreach ($list as $tpl) {
                $start = substr((string)$tpl['start_time'], 0, 5);
                $end   = substr((string)$tpl['end_time'], 0, 5);
                $br    = (int)($tpl['break_minutes'] ?? 0);
                $note  = (string)($tpl['note'] ?? '');

                if ($br < 0) $br = 0;
                if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

                // 24h（開始=終了）は今回スキップ（テンプレ側と合わせる）
                if ($start === $end) continue;

                // ✅ 夜勤判定：テンプレの end_next_day OR 終了<=開始
                $endNext = !empty($tpl['end_next_day']) ? 1 : 0;
                $sMin = toMin($start);
                $eMin = toMin($end);
                if ($sMin !== null && $eMin !== null && $eMin <= $sMin) $endNext = 1;

                // 同一開始時刻の多重テンプレを抑止（念のため）
                $k = $eid . '|' . $dateKey . '|' . $start;
                if (isset($seenStart[$k])) continue;
                $seenStart[$k] = true;

                // =========================================================
                // ✅ 追加：同じ時刻（start/end一致）が既にあるなら “反映しない”
                // - ここでスキップするので、同じ内容での上書き（更新）は発生しない
                // =========================================================
                $existsParams = [
                    ':t' => $tenantId,
                    ':s' => $storeId,
                    ':eid' => $eid,
                    ':d' => $dateKey,
                    ':st' => $start,
                    ':et' => $end,
                ];
                if ($shiftsHasEndNextDay) {
                    $existsParams[':nd'] = $endNext;
                }
                $existsStmt->execute($existsParams);
                $alreadySame = (bool)$existsStmt->fetchColumn();
                if ($alreadySame) {
                    // ✅ 同じ時刻が既にあるので反映しない
                    continue;
                }

                // ✅ 反映（既存仕様：uq_shift が start_time で衝突した場合は更新）
                $params = [
                    ':t' => $tenantId,
                    ':s' => $storeId,
                    ':eid' => $eid,
                    ':d' => $dateKey,
                    ':st' => $start,
                    ':et' => $end,
                    ':br' => $br,
                    ':note' => ($note !== '' ? $note : null),
                ];
                if ($shiftsHasEndNextDay) {
                    $params[':nd'] = $endNext;
                }

                $upsertStmt->execute($params);
                $applied++;
            }
        }
    }

    $pdo->commit();

    $sep = (strpos($returnTo, '?') === false) ? '?' : '&';
    header('Location: ' . $returnTo . $sep . 'applied=' . $applied);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "500 Internal Server Error\n\n";
    echo $e->getMessage();
    exit;
}