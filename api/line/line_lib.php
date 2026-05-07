<?php
// ✅ ファイル名: /api/line/line_lib.php
// ✅ 書き込み場所: 新規作成して全貼り
declare(strict_types=1);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * 署名検証
 * X-Line-Signature = base64( HMAC-SHA256(body, channelSecret) )
 */
function verifyLineSignature(string $body, string $signature, string $channelSecret): bool
{
    if ($signature === '' || $channelSecret === '') return false;
    $hash = hash_hmac('sha256', $body, $channelSecret, true);
    $expected = base64_encode($hash);
    return hash_equals($expected, $signature);
}

function logLine(string $logFile, string $label, string $body = ''): void
{
    $line = date('Y-m-d H:i:s') . " [$label]\n";
    if ($body !== '') $line .= $body . "\n";
    $line .= "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * replyToken で返信（テキスト）
 */
function lineReplyText(string $channelAccessToken, string $replyToken, string $text): array
{
    $url = 'https://api.line.me/v2/bot/message/reply';

    $payload = json_encode([
        'replyToken' => $replyToken,
        'messages' => [
            ['type' => 'text', 'text' => $text],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $channelAccessToken,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
    ]);

    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => ($err === '' && $code >= 200 && $code < 300),
        'http_code' => $code,
        'error' => $err,
        'response' => $res,
    ];
}

/**
 * 文字正規化
 */
function normalizeText(string $s): string
{
    $s = trim($s);
    // 全角スペース→半角
    $s = str_replace("\xE3\x80\x80", ' ', $s);
    // 連続空白を1つ
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return $s;
}

/**
 * PIN抽出（"1378" または "PIN 1378"）
 */
function extractPin(string $text): ?string
{
    $t = strtoupper(normalizeText($text));
    if (preg_match('/^(PIN\s*)?(\d{4})$/', $t, $m)) {
        return $m[2];
    }
    return null;
}