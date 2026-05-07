<?php
// public/admin/time_punches.php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';

require_once __DIR__ . '/../../lib/db.php';

$pdo = db();

$tenantId = 1;
$storeId  = 1;

$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$sql = "
SELECT
  t.id,
  t.employee_id,
  e.display_name,
  t.punch_type,
  t.punched_at,
  t.device_id
FROM time_punches t
JOIN employees e ON e.id = t.employee_id
WHERE t.tenant_id = :tenant_id
  AND t.store_id  = :store_id
  AND DATE(t.punched_at) BETWEEN :from AND :to
";

$params = [
    ':tenant_id' => $tenantId,
    ':store_id' => $storeId,
    ':from' => $from,
    ':to' => $to,
];

if ($employeeId > 0) {
    $sql .= " AND t.employee_id = :employee_id";
    $params[':employee_id'] = $employeeId;
}

$sql .= " ORDER BY t.punched_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 従業員プルダウン用
// public/admin/time_punches.php
$emps = $pdo->prepare("
  SELECT id, display_name, sort_order
  FROM employees
  WHERE tenant_id=:t AND store_id=:s AND employment_status='active'
  ORDER BY sort_order ASC, id ASC
");
$emps->execute([':t' => $tenantId, ':s' => $storeId]);
$employees = $emps->fetchAll(PDO::FETCH_ASSOC);

function label(string $type): string
{
    return match ($type) {
        'clock_in' => '出勤',
        'clock_out' => '退勤',
        'break_in' => '休憩開始',
        'break_out' => '休憩終了',
        default => $type
    };
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>打刻ログ</title>
    <style>
    body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Hiragino Sans", Meiryo, sans-serif;
        padding: 20px
    }

    table {
        border-collapse: collapse;
        width: 100%
    }

    th,
    td {
        border: 1px solid #ddd;
        padding: 8px;
        font-size: 14px
    }

    th {
        background: #f6f6f6;
        text-align: left
    }

    .row {
        display: flex;
        gap: 12px;
        align-items: end;
        flex-wrap: wrap;
        margin-bottom: 12px
    }

    label {
        font-size: 12px;
        color: #666
    }

    input,
    select {
        padding: 8px;
        font-size: 14px
    }

    .btn {
        padding: 8px 12px;
        border: 1px solid #ddd;
        background: #fff;
        cursor: pointer
    }
    </style>
</head>

<body>
    <h1>打刻ログ</h1>

    <form class="row" method="get">
        <div>
            <label>従業員</label><br>
            <select name="employee_id">
                <option value="0">全員</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= (int)$e['id'] ?>" <?= $employeeId === (int)$e['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($e['display_name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>開始日</label><br>
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div>
            <label>終了日</label><br>
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div>
            <button class="btn" type="submit">検索</button>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th>日時</th>
                <th>従業員</th>
                <th>種別</th>
                <th>端末</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['punched_at']) ?></td>
                <td><?= htmlspecialchars($r['display_name']) ?></td>
                <td><?= htmlspecialchars(label($r['punch_type'])) ?></td>
                <td><?= htmlspecialchars((string)$r['device_id']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (count($rows) === 0): ?>
            <tr>
                <td colspan="4">ログなし</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>

</html>