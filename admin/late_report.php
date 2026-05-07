<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/late_report.php
 * ✅ 書き込み場所: 新規作成
 *
 * 機能:
 * - 月/期間の遅刻回数と遅刻分（合計）を従業員別に確定表示
 * - 根拠: employee_shifts + time_punches(clock_in)
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

$storeId = (int)($_GET['store_id'] ?? 0);
$ym = (string)($_GET['ym'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

[$y, $m] = array_map('intval', explode('-', $ym));
$first = sprintf('%04d-%02d-01', $y, $m);
$firstDt = new DateTimeImmutable($first);
$lastDt = $firstDt->modify('last day of this month');

$st = $pdo->prepare("SELECT name, COALESCE(late_grace_minutes,5) AS late_grace_minutes FROM stores WHERE tenant_id=:t AND id=:s LIMIT 1");
$st->execute([':t' => $tenantId, ':s' => $storeId]);
$row = $st->fetch();
if (!$row) {
    http_response_code(400);
    echo "store not found";
    exit;
}
$storeName = (string)$row['name'];
$grace = (int)$row['late_grace_minutes'];

/**
 * 遅刻算出（SQLで確定）
 * - shift_start_dt = work_date + start_time
 * - actual_in_dt   = MIN(clock_in) その日
 * - late_minutes   = GREATEST(0, TIMESTAMPDIFF(MINUTE, shift_start_dt, actual_in_dt) - grace)
 */
$sql = "
SELECT
  e.id AS employee_id,
  e.display_name,
  COUNT(*) AS shift_days,
  SUM(CASE WHEN x.actual_in_dt IS NULL THEN 1 ELSE 0 END) AS no_clockin_days,

  SUM(CASE WHEN x.late_minutes > 0 THEN 1 ELSE 0 END) AS late_days,
  SUM(CASE WHEN x.late_minutes > 0 THEN x.late_minutes ELSE 0 END) AS late_minutes_total

FROM employee_shifts sh
JOIN employees e
  ON e.id = sh.employee_id
 AND e.tenant_id = sh.tenant_id
 AND e.store_id  = sh.store_id

LEFT JOIN (
  SELECT
    sh2.tenant_id, sh2.store_id, sh2.employee_id, sh2.work_date,
    MIN(tp.punched_at) AS actual_in_dt,
    GREATEST(
      0,
      TIMESTAMPDIFF(
        MINUTE,
        TIMESTAMP(sh2.work_date, sh2.start_time),
        MIN(tp.punched_at)
      ) - :grace
    ) AS late_minutes
  FROM employee_shifts sh2
  LEFT JOIN time_punches tp
    ON tp.tenant_id = sh2.tenant_id
   AND tp.store_id  = sh2.store_id
   AND tp.employee_id = sh2.employee_id
   AND tp.punch_type = 'clock_in'
   AND tp.deleted_at IS NULL
   AND DATE(tp.punched_at) = sh2.work_date
  WHERE sh2.tenant_id = :t
    AND sh2.store_id  = :s
    AND sh2.deleted_at IS NULL
    AND sh2.work_date BETWEEN :from AND :to
  GROUP BY sh2.tenant_id, sh2.store_id, sh2.employee_id, sh2.work_date, sh2.start_time
) x
  ON x.tenant_id=sh.tenant_id AND x.store_id=sh.store_id AND x.employee_id=sh.employee_id AND x.work_date=sh.work_date

WHERE sh.tenant_id = :t
  AND sh.store_id  = :s
  AND sh.deleted_at IS NULL
  AND sh.work_date BETWEEN :from AND :to
GROUP BY e.id, e.display_name
ORDER BY late_days DESC, late_minutes_total DESC, e.id ASC
";
$st = $pdo->prepare($sql);
$st->execute([
    ':t' => $tenantId,
    ':s' => $storeId,
    ':from' => $firstDt->format('Y-m-d'),
    ':to' => $lastDt->format('Y-m-d'),
    ':grace' => $grace
]);
$rows = $st->fetchAll();
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>遅刻レポート</title>
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

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px
    }

    th,
    td {
        border: 1px solid #eee;
        padding: 10px;
        font-size: 13px
    }

    th {
        background: #fafafa
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
                    <div style="font-weight:900;font-size:18px;">遅刻レポート</div>
                    <div class="muted"><?= h($storeName) ?> / <?= h($ym) ?> / grace=<?= (int)$grace ?>分</div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="btn2" href="/admin/shifts.php?store_id=<?= (int)$storeId ?>&ym=<?= h($ym) ?>">シフトへ戻る</a>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>従業員</th>
                        <th>シフト日数</th>
                        <th>clock_in無し</th>
                        <th>遅刻回数</th>
                        <th>遅刻合計(分)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                    <tr>
                        <td colspan="5" class="muted">データがありません（シフトを入力してください）</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><b><?= h((string)$r['display_name']) ?></b> <span
                                class="muted">(ID:<?= (int)$r['employee_id'] ?>)</span></td>
                        <td><?= (int)$r['shift_days'] ?></td>
                        <td><?= (int)$r['no_clockin_days'] ?></td>
                        <td><b><?= (int)$r['late_days'] ?></b></td>
                        <td><?= (int)$r['late_minutes_total'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="muted" style="margin-top:10px;">
                ※「clock_in無し」は遅刻ではなく「欠勤 or 打刻漏れ」候補（別管理推奨）
            </div>
        </div>
    </div>
</body>

</html>