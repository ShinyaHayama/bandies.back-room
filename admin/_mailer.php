<?php

declare(strict_types=1);

/**
 * /admin/_mailer.php
 * 最小のメール送信ラッパー
 * - ロリポ等で動かしやすいよう mb_send_mail を優先
 */

function send_mail(string $to, string $subject, string $body, string $fromName = 'AzureSystems', string $fromEmail = ''): bool
{
    $to = trim($to);
    if ($to === '') return false;

    $encodedSubject = $subject;
    $headers = [];

    if ($fromEmail !== '') {
        $from = sprintf('%s <%s>', $fromName, $fromEmail);
        $headers[] = 'From: ' . $from;
        $headers[] = 'Reply-To: ' . $fromEmail;
    }

    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    // mb_send_mail が使えるなら優先
    if (function_exists('mb_send_mail')) {
        mb_language('Japanese');
        mb_internal_encoding('UTF-8');
        return mb_send_mail($to, $encodedSubject, $body, implode("\r\n", $headers));
    }

    // fallback
    return mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}