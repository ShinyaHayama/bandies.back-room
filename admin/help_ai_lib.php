<?php

declare(strict_types=1);

/**
 * ✅ /admin/help_ai_lib.php
 * - .env（/a-zure/.env）を読んで APIキーを取得
 * - OpenAIへ直接 curl（内部URLは一切叩かない）
 * - 失敗原因をログに出す
 */

function help_ai_log(string $msg): void
{
    $dir = __DIR__ . '/logs';
    @mkdir($dir, 0777, true);
    @file_put_contents($dir . '/help_ai.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function help_ai_load_dotenv(string $path): void
{
    if (!is_file($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);

        // クォート除去
        if ($v !== '' && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }

        // 既に入ってたら上書きしない
        $exists = getenv($k);
        if ($exists !== false && $exists !== '') continue;

        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
    }
}

function help_ai_get_key(string $envPath): string
{
    // ✅ 必ず最初に .env をロード
    help_ai_load_dotenv($envPath);

    // ✅ あなたの .env 実態に合わせて優先順
    $key = (string)(getenv('AI_API_KEY_HELP') ?: '');
    if ($key !== '') return $key;

    $key = (string)(getenv('OPENAI_API_KEY_HELP') ?: '');
    if ($key !== '') return $key;

    // 予備（もし昔のキー名が残ってても拾えるように）
    $key = (string)(getenv('AI_API_KEY') ?: '');
    return $key;
}

/**
 * @return array{ok:bool, answer?:string, error?:string, detail?:string, http?:int, curl_errno?:int, curl_error?:string, raw_head?:string}
 */
function help_ai_answer(int $tenantId, int $storeId, string $question, array $history = []): array
{
    $envPath = dirname(__DIR__) . '/.env'; // ✅ /a-zure/.env（adminと同じ階層）
    $exists = is_file($envPath) ? '1' : '0';

    $apiKey = help_ai_get_key($envPath);
    if ($apiKey === '') {
        help_ai_log("KEY EMPTY envPath={$envPath} exists={$exists}");
        return [
            'ok' => false,
            'error' => 'api_key_empty',
            'detail' => "AI_API_KEY_HELP / OPENAI_API_KEY_HELP が空です（envPath={$envPath} exists={$exists}）",
        ];
    }

    // 履歴は最短で安定のため、今は空でもOK（必要になったら足す）
    $histText = '';
    foreach ($history as $m) {
        if (!is_array($m)) continue;
        $r = trim((string)($m['role'] ?? ''));
        $c = trim((string)($m['content'] ?? ''));
        if ($c === '') continue;
        $histText .= strtoupper($r) . ': ' . $c . "\n";
    }

    $system = <<<SYS
あなたは勤怠システムのサポートAIです。
日本語で短く答えてください。
手順がある場合は箇条書きにしてください。
不明なDB/権限/テナント固有の調査が必要なら「担当者に連絡してください」と書いてください。
SYS;

    $user = "tenant_id={$tenantId}\nstore_id={$storeId}\n\n[history]\n{$histText}\n\n[user]\n{$question}\n";

    $payload = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.2,
    ];

    help_ai_log('REQ q=' . mb_substr($question, 0, 200));

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 35,
        CURLOPT_CONNECTTIMEOUT => 10,

        // ✅ ロリポ環境で証明書まわりが怪しい時の切り分け用ログ（基本はONのまま）
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $resp = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr   = curl_error($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        help_ai_log("AI call http=0 errno={$curlErrNo} err={$curlErr}");
        return [
            'ok' => false,
            'error' => 'curl_error',
            'http' => 0,
            'curl_errno' => $curlErrNo,
            'curl_error' => $curlErr,
        ];
    }

    $head = substr((string)$resp, 0, 180);

    if ($httpCode < 200 || $httpCode >= 300) {
        help_ai_log("AI call http={$httpCode} head=" . str_replace(["\n", "\r"], ['\\n', '\\r'], $head));
        return [
            'ok' => false,
            'error' => 'openai_http',
            'http' => $httpCode,
            'raw_head' => $head,
        ];
    }

    $j = json_decode((string)$resp, true);
    if (!is_array($j)) {
        help_ai_log("AI bad json head=" . str_replace(["\n", "\r"], ['\\n', '\\r'], $head));
        return [
            'ok' => false,
            'error' => 'bad_openai_json',
            'http' => $httpCode,
            'raw_head' => $head,
        ];
    }

    $answer = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    if ($answer === '') {
        help_ai_log("AI empty answer head=" . str_replace(["\n", "\r"], ['\\n', '\\r'], $head));
        return [
            'ok' => false,
            'error' => 'empty_ai_text',
            'http' => $httpCode,
            'raw_head' => $head,
        ];
    }

    help_ai_log('OK answer_head=' . mb_substr($answer, 0, 120));
    return ['ok' => true, 'answer' => $answer];
}
