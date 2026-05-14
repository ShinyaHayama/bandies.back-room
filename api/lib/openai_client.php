<?php
// /api/lib/openai_client.php
declare(strict_types=1);

/**
 * OpenAI Responses API を叩く薄いクライアント
 * - 呼び出し側で /api/bootstrap.php を先に require して .env をロードする前提
 *
 * Responses API: https://api.openai.com/v1/responses :contentReference[oaicite:1]{index=1}
 */

function openai_env_candidate_files(): array
{
    $files = [
        dirname(__DIR__, 2) . '/.env',
    ];

    $cwd = getcwd();
    if (is_string($cwd) && $cwd !== '') {
        $files[] = $cwd . '/.env';
    }

    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docRoot !== '') {
        $files[] = rtrim($docRoot, '/\\') . '/.env';
    }

    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script !== '') {
        $files[] = dirname($script, 2) . '/.env';
        $files[] = dirname($script) . '/.env';
    }

    return array_values(array_unique($files));
}

function openai_read_api_key_from_file(string $envFile): string
{
    if (!is_file($envFile) || !is_readable($envFile)) {
        return '';
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return '';
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_starts_with($line, 'OPENAI_API_KEY=')) {
            continue;
        }

        $value = trim(substr($line, strlen('OPENAI_API_KEY=')));
        $quote = $value[0] ?? '';
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
        }

        return trim($value);
    }

    return '';
}

function openai_env_file_states(): string
{
    $states = [];
    foreach (openai_env_candidate_files() as $file) {
        if (is_file($file)) {
            $state = is_readable($file) ? 'readable' : 'not-readable';
        } else {
            $state = 'missing';
        }
        $states[] = $file . '=' . $state;
    }
    return implode('; ', $states);
}

function openai_api_key_from_env(): string
{
    $candidates = [
        $_ENV['OPENAI_API_KEY'] ?? '',
        getenv('OPENAI_API_KEY') ?: '',
        $_SERVER['OPENAI_API_KEY'] ?? '',
    ];

    foreach ($candidates as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            return $value;
        }
    }

    foreach (openai_env_candidate_files() as $envFile) {
        $value = openai_read_api_key_from_file($envFile);
        if ($value !== '') {
            $_ENV['OPENAI_API_KEY'] = $value;
            $_SERVER['OPENAI_API_KEY'] = $value;
            putenv('OPENAI_API_KEY=' . $value);
            return $value;
        }
    }

    return '';
}

function openai_responses(string $model, string $inputText, int $timeoutSec = 25): array
{
    $apiKey = openai_api_key_from_env();
    if ($apiKey === '') {
        throw new RuntimeException('OPENAI_API_KEY is not set (.env paths: ' . openai_env_file_states() . ')');
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
