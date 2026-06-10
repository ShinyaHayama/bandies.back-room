<?php
// /api/bootstrap.php
declare(strict_types=1);

function app_env_file_value(string $envFile, string $key): string
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

        if (!str_starts_with($line, $key . '=')) {
            continue;
        }

        $value = trim(substr($line, strlen($key) + 1));
        $quote = $value[0] ?? '';
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    return '';
}

// Load the project root .env when it exists. This intentionally avoids
// phpdotenv here so a missing .env cannot emit file_get_contents warnings
// on shared hosting.
$envDir = dirname(__DIR__);
$envFile = $envDir . '/.env';

// Normalize server-level environment variables into $_ENV for callers that
// read only $_ENV.
foreach (['OPENAI_API_KEY'] as $key) {
    if (!empty($_ENV[$key])) {
        continue;
    }

    $value = getenv($key);
    if ($value !== false && $value !== '') {
        $_ENV[$key] = $value;
        continue;
    }

    if (!empty($_SERVER[$key])) {
        $_ENV[$key] = (string)$_SERVER[$key];
        continue;
    }

    $value = app_env_file_value($envFile, $key);
    if ($value !== '') {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}
