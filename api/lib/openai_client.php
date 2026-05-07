<?php
// /api/lib/openai_client.php
declare(strict_types=1);

/**
 * OpenAI Responses API を叩く薄いクライアント
 * - 呼び出し側で /api/bootstrap.php を先に require して .env をロードする前提
 *
 * Responses API: https://api.openai.com/v1/responses :contentReference[oaicite:1]{index=1}
 */

function openai_responses(string $model, string $inputText, int $timeoutSec = 25): array
{
    $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
    if ($apiKey === '') {
        throw new RuntimeException('OPENAI_API_KEY is not set');
    }


    $payload = [
        'model' => $model,
        'input' => $inputText, // Responses API は input に文字列OK :contentReference[oaicite:2]{index=2}
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSec,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException("OpenAI curl error: {$err}");
    }
    if ($raw === false || $raw === '') {
        throw new RuntimeException('OpenAI empty response');
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('OpenAI invalid JSON');
    }
    if ($status < 200 || $status >= 300) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $status);
        throw new RuntimeException('OpenAI error: ' . $msg);
    }

    return $json;
}

/**
 * Responses API の返答テキストを雑に取り出す
 */
function openai_extract_text(array $resp): string
{
    // output[0].content[*].text を拾う
    if (isset($resp['output'][0]['content']) && is_array($resp['output'][0]['content'])) {
        $texts = [];
        foreach ($resp['output'][0]['content'] as $c) {
            if (isset($c['text']) && is_string($c['text'])) $texts[] = $c['text'];
        }
        if ($texts) return trim(implode("\n", $texts));
    }

    // フォールバック: output_text :contentReference[oaicite:3]{index=3}
    if (isset($resp['output_text']) && is_string($resp['output_text'])) {
        return trim($resp['output_text']);
    }

    return '';
}