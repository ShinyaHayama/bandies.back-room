<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/shift_edit.php
 * ✅ 書き込み場所: 新規作成
 *
 * 機能:
 * - 従業員×月 の日別シフトを編集（保存/削除）
 * - 1日1シフト（UNIQUE）
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';

require_once __DIR__ . '/../api/lib/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location:/admin/login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];
function isValidCsrf(string $t): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $t);
}

$storeId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);
$ym = (string)($_GET['ym'] ?? $_POST['ym'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

$st = $pdo->prepare("SELECT name FROM stores WHERE tenant_id=:t AND id=:s LIMIT 1");
$st->execute([':t' => $tenantId, ':s' => $storeId]);
$storeName = (string)($st->fetchColumn() ?: '');

$st = $pdo->prepare("SELECT display_name FROM employees WHERE tenant_id=:t AND store_id=:s AND id=:e LIMIT 1");
$st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
$employeeName = (string)($st->fetchColumn() ?: '');

if ($storeName === '' || $employeeName === '') {
    http_response_code(400);
    echo "store/employee not found";
    exit;
}

[$y, $m] = array_map('intval', explode('-', $ym));
$first = sprintf('%04d-%02d-01', $y, $m);
$firstDt = new DateTimeImmutable($first);
$lastDt = $firstDt->modify('last day of this month');
$days = (int)$lastDt->format('j');

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!isValidCsrf($token)) $errors[] = 'CSRF不正';
    else {
        try {
            $rows = $_POST['shift'] ?? [];
            if (!is_array($rows)) $rows = [];
            $pdo->beginTransaction();

            // まず当月分を一旦 soft delete（必要最小）
            $del = $pdo->prepare("
        UPDATE employee_shifts
           SET deleted_at=NOW(), updated_at=NOW()
         WHERE tenant_id=:t AND store_id=:s AND employee_id=:e
           AND work_date BETWEEN :from AND :to
           AND deleted_at IS NULL
      ");
            $del->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId, ':from' => $firstDt->format('Y-m-d'), ':to' => $lastDt->format('Y-m-d')]);

            $ins = $pdo->prepare("
        INSERT INTO employee_shifts
          (tenant_id, store_id, employee_id, work_date, start_time, end_time, end_next_day, break_minutes, note, created_at, updated_at, deleted_at)
        VALUES
          (:t,:s,:e,:d,:st,:et,:nx,:bm,:note,NOW(),NOW(),NULL)
        ON DUPLICATE KEY UPDATE
          start_time=VALUES(start_time),
          end_time=VALUES(end_time),
          end_next_day=VALUES(end_next_day),
          break_minutes=VALUES(break_minutes),
          note=VALUES(note),
          deleted_at=NULL,
          updated_at=NOW()
      ");

            for ($i = 1; $i <= $days; $i++) {
                $date = sprintf('%04d-%02d-%02d', $y, $m, $i);
                $r = $rows[$date] ?? null;
                if (!is_array($r)) continue;

                $start = trim((string)($r['start'] ?? ''));
                $end   = trim((string)($r['end'] ?? ''));
                $nx    = (int)($r['next'] ?? 0);
                $bm    = (int)($r['break'] ?? 0);
                $note  = trim((string)($r['note'] ?? ''));

                if ($start === '' && $end === '') continue; // 空は保存しない（=削除）

                if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
                    $errors[] = "{$date} の時刻形式が不正です";
                    continue;
                }
                if ($bm < 0 || $bm > 600) $bm = 0;
                if ($nx !== 1) $nx = 0;
                if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

                $ins->execute([
                    ':t' => $tenantId,
                    ':s' => $storeId,
                    ':e' => $employeeId,
                    ':d' => $date,
                    ':st' => $start . ':00',
                    ':et' => $end . ':00',
                    ':nx' => $nx,
                    ':bm' => $bm,
                    ':note' => ($note !== '' ? $note : null)
                ]);
            }

            if ($errors) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
                $success = '保存しました';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'エラー: ' . $e->getMessage();
        }
    }
}

// 表示用に読み直し
$shiftStmt = $pdo->prepare("
  SELECT work_date,start_time,end_time,end_next_day,break_minutes,note
  FROM employee_shifts
  WHERE tenant_id=:t AND store_id=:s AND employee_id=:e
    AND work_date BETWEEN :from AND :to
    AND deleted_at IS NULL
");
$shiftStmt->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId, ':from' => $firstDt->format('Y-m-d'), ':to' => $lastDt->format('Y-m-d')]);
$shiftRows = $shiftStmt->fetchAll();

$map = [];
foreach ($shiftRows as $r) {
    $d = (string)$r['work_date'];
    $map[$d] = [
        'start' => substr((string)$r['start_time'], 0, 5),
        'end' => substr((string)$r['end_time'], 0, 5),
        'next' => (int)$r['end_next_day'],
        'break' => (int)$r['break_minutes'],
        'note' => (string)($r['note'] ?? ''),
    ];
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>シフト編集</title>
    <style>
    body {
        margin: 0;
        font-family: system-ui, -apple-system, sans-serif;
        background: #f7f7f7;
        color: #111
    }

    .page {
        padding: 24px;
        padding-bottom: 64px
    }

    .card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 16px
    }

    .muted {
        color: #666;
        font-size: 12px
    }

    .err {
        background: #ffecec;
        border: 1px solid #ffb3b3;
        padding: 10px;
        border-radius: 10px;
        margin-bottom: 12px
    }

    .ok {
        background: #eaffea;
        border: 1px solid #9be59b;
        padding: 10px;
        border-radius: 10px;
        margin-bottom: 12px
    }

    table {
        width: 100%;
        border-collapse: collapse
    }

    th,
    td {
        border: 1px solid #eee;
        padding: 8px;
        font-size: 12px
    }

    th {
        background: #fafafa
    }

    input {
        padding: 6px
    }

    .btn {
        display: inline-block;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid #111;
        background: #111;
        color: #fff;
        font-weight: 800
    }

    .btn2 {
        display: inline-block;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid #111;
        background: #fff;
        color: #111;
        font-weight: 800;
        text-decoration: none
    }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/_header.php'; ?>
    <div class="page">
        <div class="card">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
                <div>
                    <div style="font-weight:900;font-size:18px;">シフト編集</div>
                    <div class="muted"><?= h($storeName) ?> /
                        <?= h($employeeName) ?>（employee_id=<?= (int)$employeeId ?>）/ <?= h($ym) ?></div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="btn2" href="/admin/shifts.php?store_id=<?= (int)$storeId ?>&ym=<?= h($ym) ?>">一覧へ戻る</a>
                </div>
            </div>

            <?php if ($success): ?><div class="ok" style="margin-top:12px;"><?= h($success) ?></div><?php endif; ?>
            <?php if ($errors): ?><div class="err" style="margin-top:12px;"><?php foreach ($errors as $e): ?><div>
                    <?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>

            <form method="post" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
                <input type="hidden" name="ym" value="<?= h($ym) ?>">

                <table>
                    <thead>
                        <tr>
                            <th>日付</th>
                            <th>開始</th>
                            <th>終了</th>
                            <th>翌日</th>
                            <th>休憩(分)</th>
                            <th>メモ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 1; $i <= $days; $i++): ?>
                        <?php
                            $date = sprintf('%04d-%02d-%02d', $y, $m, $i);
                            $v = $map[$date] ?? ['start' => '', 'end' => '', 'next' => 0, 'break' => 0, 'note' => ''];
                            ?>
                        <tr>
                            <td style="white-space:nowrap;">
                                <?= h($date) ?>
                            </td>
                            <td><input type="time" name="shift[<?= h($date) ?>][start]" value="<?= h($v['start']) ?>">
                            </td>
                            <td><input type="time" name="shift[<?= h($date) ?>][end]" value="<?= h($v['end']) ?>"></td>
                            <td style="text-align:center;">
                                <input type="checkbox" name="shift[<?= h($date) ?>][next]" value="1"
                                    <?= ($v['next'] ? 'checked' : '') ?>>
                            </td>
                            <td><input type="number" min="0" max="600" style="width:90px;"
                                    name="shift[<?= h($date) ?>][break]" value="<?= (int)$v['break'] ?>"></td>
                            <td><input type="text" maxlength="255" name="shift[<?= h($date) ?>][note]"
                                    value="<?= h($v['note']) ?>" style="width:100%;"></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <div class="muted" style="margin-top:10px;">
                    ※開始/終了が両方空の行は「保存されません（=削除）」扱い
                </div>

                <button class="btn" type="submit" style="margin-top:12px;">保存</button>
            </form>
        </div>
    </div>
</body>

</html>