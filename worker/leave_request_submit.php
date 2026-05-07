<?php
// /worker/leave_request_submit.php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_worker_login();

header('Content-Type: application/json; charset=utf-8');

function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/shift_leave_requests.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
shift_leave_requests_ensure_schema($pdo);

$tenantId = (int)($_SESSION['worker_tenant_id'] ?? 0);
$storeId = (int)($_SESSION['worker_store_id'] ?? 0);
$employeeId = (int)($_SESSION['worker_employee_id'] ?? 0);
$employeeName = (string)($_SESSION['worker_employee_name'] ?? '');
if ($tenantId <= 0 || $storeId <= 0 || $employeeId <= 0) {
    json_error('認証情報が無効です。', 401);
}

$payload = [];
$rawBody = file_get_contents('php://input');
if ($rawBody !== '') {
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) $payload = [];
}

$requestDate = (string)($payload['request_date'] ?? '');
$reason = trim((string)($payload['reason'] ?? ''));
if (!shift_leave_requests_valid_date($requestDate)) {
    json_error('日付が不正です。');
}
if (mb_strlen($reason) > 1000) {
    $reason = mb_substr($reason, 0, 1000);
}

$empStmt = $pdo->prepare("
    SELECT display_name
    FROM employees
    WHERE tenant_id = :t AND store_id = :s AND id = :e
    LIMIT 1
");
$empStmt->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
$emp = $empStmt->fetch();
if (!$emp) {
    json_error('従業員情報が見つかりません。', 404);
}
$employeeName = (string)($emp['display_name'] ?? $employeeName);

$storeStmt = $pdo->prepare("SELECT name FROM stores WHERE tenant_id = :t AND id = :s LIMIT 1");
$storeStmt->execute([':t' => $tenantId, ':s' => $storeId]);
$storeName = (string)($storeStmt->fetch()['name'] ?? '');

$existingStmt = $pdo->prepare("
    SELECT id, status
    FROM shift_leave_requests
    WHERE tenant_id = :t
      AND store_id = :s
      AND employee_id = :e
      AND request_date = :d
      AND status IN ('pending', 'approved')
    ORDER BY id DESC
    LIMIT 1
");
$existingStmt->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId, ':d' => $requestDate]);
$existing = $existingStmt->fetch();
if ($existing) {
    $status = (string)$existing['status'];
    echo json_encode([
        'ok' => true,
        'already' => true,
        'status' => $status,
        'message' => $status === 'approved' ? 'この日はすでに休み承認済みです。' : 'この日はすでに休み申請中です。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = bin2hex(random_bytes(32));
try {
    $ins = $pdo->prepare("
        INSERT INTO shift_leave_requests
          (tenant_id, store_id, employee_id, request_date, reason, status, token, requested_at, created_at, updated_at)
        VALUES
          (:t, :s, :e, :d, :reason, 'pending', :token, NOW(), NOW(), NOW())
    ");
    $ins->execute([
        ':t' => $tenantId,
        ':s' => $storeId,
        ':e' => $employeeId,
        ':d' => $requestDate,
        ':reason' => $reason,
        ':token' => $token,
    ]);
} catch (Throwable $e) {
    json_error('申請の保存に失敗しました。', 500);
}

$adminUrl = shift_leave_requests_base_url() . '/admin/leave_request_confirm.php?token=' . rawurlencode($token);
$subject = '【シメナビ】休み申請が届きました';
$body = "休み申請が届きました。\n\n"
    . "店舗: {$storeName}\n"
    . "従業員: {$employeeName}\n"
    . "申請日: {$requestDate}\n"
    . "理由: " . ($reason !== '' ? $reason : '未入力') . "\n\n"
    . "管理者でログイン後、以下のURLから認証または非認証を選択してください。\n";

$emails = shift_leave_requests_notification_emails($pdo, $tenantId, $storeId);
$body .= "通知先: " . (!empty($emails) ? implode(', ', $emails) : '未設定') . "\n";
$body .= $adminUrl . "\n";
$mailSent = false;
foreach ($emails as $email) {
    if (send_mail($email, $subject, $body, 'SHIMENABI', '')) {
        $mailSent = true;
    }
}

echo json_encode([
    'ok' => true,
    'mail_sent' => $mailSent,
    'message' => $mailSent ? '休み申請を送信しました。' : '休み申請を保存しました。管理者メールの送信設定を確認してください。',
], JSON_UNESCAPED_UNICODE);
