<?php
// public/api/v1/employees_with_status.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/db.php';

try {
  // GETを優先（iOSが tenant_id/store_id を付けてるので）
  $tenantId = (int)($_GET['tenant_id'] ?? 1);
  $storeId  = (int)($_GET['store_id'] ?? 1);

  $pdo = db();

  $sql = "
SELECT
  e.id,
  e.display_name,
  e.sort_order,
  tp.punch_type AS last_punch_type,
  tp.punched_at AS last_punched_at
FROM employees e
LEFT JOIN (
  SELECT t1.*
  FROM time_punches t1
  JOIN (
    SELECT employee_id, MAX(punched_at) AS max_punched_at
    FROM time_punches
    WHERE tenant_id = :tenant_id AND store_id = :store_id
    GROUP BY employee_id
  ) t2
    ON t1.employee_id = t2.employee_id
   AND t1.punched_at = t2.max_punched_at
  WHERE t1.tenant_id = :tenant_id AND t1.store_id = :store_id
) tp ON tp.employee_id = e.id
WHERE e.tenant_id = :tenant_id
  AND e.store_id  = :store_id
  AND e.employment_status = 'active'
ORDER BY e.sort_order ASC, e.id ASC
";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':tenant_id' => $tenantId, ':store_id' => $storeId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $employees = array_map(function (array $r): array {
    $last = $r['last_punch_type'] ?? null;

    $status = 'off';
    if ($last === 'clock_in' || $last === 'break_out') $status = 'working';
    if ($last === 'break_in') $status = 'break';
    if ($last === 'clock_out' || $last === null) $status = 'off';

    return [
      'id' => (int)$r['id'],
      'display_name' => (string)$r['display_name'],
      'sort_order' => (int)($r['sort_order'] ?? 0), // ✅ 返す

      'status' => $status,
      'last_punched_at' => $r['last_punched_at'],
      'last_punch_type' => $r['last_punch_type'],

      'can_clock_in'  => ($status === 'off'),
      'can_clock_out' => ($status === 'working' || $status === 'break'),
      'can_break_in'  => ($status === 'working'),
      'can_break_out' => ($status === 'break'),
    ];
  }, $rows);

  echo json_encode(['ok' => true, 'employees' => $employees], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(
    ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
    JSON_UNESCAPED_UNICODE
  );
}