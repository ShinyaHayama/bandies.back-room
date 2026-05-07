<?php

declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/mailer.php';

function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function redirectWith(string $type, string $msg): void
{
  $_SESSION['flash'] = ['type' => $type, 'message' => $msg];
  header('Location: /index.php#contact');
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

// honeypot（ボット対策）
if (!empty($_POST['website'] ?? '')) {
  redirectWith('ok', '送信を受け付けました。'); // ボットは成功に見せる
}

$company = trim((string)($_POST['company'] ?? ''));
$name    = trim((string)($_POST['name'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$tel     = trim((string)($_POST['tel'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($company === '' || $name === '' || $email === '' || $message === '') {
  redirectWith('err', '必須項目が未入力です。');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  redirectWith('err', 'メールアドレスの形式が正しくありません。');
}

// ===== 送信先（あなたの受信先に変更）=====
$to = 'work@fader.group';
$subject = '【シメナビ】お問い合わせを受信しました';

// 本文
$body = "シメナビ お問い合わせ\n\n"
  . "会社名/店舗名: {$company}\n"
  . "お名前: {$name}\n"
  . "メール: {$email}\n"
  . "電話: {$tel}\n\n"
  . "内容:\n{$message}\n\n"
  . "送信元IP: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n"
  . "UA: " . ($_SERVER['HTTP_USER_AGENT'] ?? '') . "\n";

$ok = send_mail($to, $subject, $body, 'SHIMENABI', $email);

if (!$ok) {
  redirectWith('err', '送信に失敗しました。サーバーのメール設定をご確認ください。');
}

redirectWith('ok', 'お問い合わせを送信しました。ありがとうございました。');
