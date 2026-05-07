<?php

declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/mailer.php';

function redirectWith(string $type, string $msg): void
{
  $_SESSION['flash'] = ['type' => $type, 'message' => $msg];
  header('Location: /index.php#apply');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirectWith('err', '不正なリクエストです。');
}

// CSRF
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
  redirectWith('err', 'セキュリティ確認に失敗しました（CSRF）。');
}

// honeypot
if (!empty($_POST['website'] ?? '')) {
  redirectWith('ok', '送信を受け付けました。');
}

$company   = trim((string)($_POST['company'] ?? ''));
$name      = trim((string)($_POST['name'] ?? ''));
$email     = trim((string)($_POST['email'] ?? ''));
$stores    = trim((string)($_POST['stores'] ?? ''));
$employees = trim((string)($_POST['employees'] ?? ''));
$plan      = trim((string)($_POST['plan'] ?? ''));
$note      = trim((string)($_POST['note'] ?? ''));
$agree     = (string)($_POST['agree'] ?? '');

if ($company === '' || $name === '' || $email === '' || $stores === '' || $employees === '' || $plan === '') {
  redirectWith('err', '必須項目が未入力です。');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  redirectWith('err', 'メールアドレスの形式が正しくありません。');
}
if ($agree !== '1') {
  redirectWith('err', '同意チェックが必要です。');
}

// ===== 送信先（あなたの受信先に変更）=====
$to = 'work@fader.group';
$subject = '【シメナビ】無料デモ/トライアル申込を受信しました';

$body = "シメナビ 申込\n\n"
  . "会社名/店舗名: {$company}\n"
  . "お名前: {$name}\n"
  . "メール: {$email}\n"
  . "店舗数: {$stores}\n"
  . "従業員数: {$employees}\n"
  . "希望: {$plan}\n\n"
  . "備考:\n{$note}\n\n"
  . "送信元IP: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n"
  . "UA: " . ($_SERVER['HTTP_USER_AGENT'] ?? '') . "\n";

$ok = send_mail($to, $subject, $body, 'SHIMENABI', $email);

if (!$ok) {
  redirectWith('err', '送信に失敗しました。サーバーのメール設定をご確認ください。');
}

// （任意）申込者へ自動返信
$replySub = '【シメナビ】お申し込みありがとうございます（自動返信）';
$replyBody = "{$name} 様\n\n"
  . "シメナビ へのお申し込みありがとうございます。\n"
  . "内容を確認のうえ、担当よりご連絡します。\n\n"
  . "―― 申込内容 ――\n"
  . "会社名/店舗名: {$company}\n"
  . "店舗数: {$stores}\n"
  . "従業員数: {$employees}\n"
  . "希望: {$plan}\n\n"
  . "シメナビ\n";

send_mail($email, $replySub, $replyBody, 'SHIMENABI', '');

redirectWith('ok', 'お申し込みを送信しました。ありがとうございました。');
