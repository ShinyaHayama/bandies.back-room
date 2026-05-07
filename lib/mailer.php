<?php
declare(strict_types=1);

function mailer_load_env_once(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    if (getenv('SMTP_HOST')) return;

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    if (class_exists('Dotenv\\Dotenv')) {
        $paths = [
            dirname(__DIR__),
            dirname(__DIR__, 2),
            dirname(__DIR__, 3),
        ];
        foreach ($paths as $path) {
            if (!is_file($path . '/.env')) continue;
            try {
                $dotenv = Dotenv\Dotenv::createUnsafeImmutable($path);
                $dotenv->safeLoad();
                if (getenv('SMTP_HOST')) return;
            } catch (Throwable $e) {
            }
        }
    }
}

function env_get(string $key): string
{
    $v = getenv($key);
    if ($v !== false && $v !== null && $v !== '') return (string)$v;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    return '';
}

function mailer_smtp_send(string $to, string $subject, string $body, array $headers, array $cfg, string &$lastError = ''): bool
{
    $host = (string)$cfg['host'];
    $port = (int)$cfg['port'];
    $user = (string)$cfg['user'];
    $pass = (string)$cfg['pass'];

    if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
        $lastError = 'smtp_config_missing';
        return false;
    }

    $secure = (string)($cfg['secure'] ?? '');
    if ($secure === '') {
        $secure = ($port === 465) ? 'ssl' : 'tls';
    }
    $remote = ($secure === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $socket = @stream_socket_client($remote, $errno, $errstr, 10);
    if (!$socket) {
        $lastError = 'socket_failed:' . $errstr;
        return false;
    }
    stream_set_timeout($socket, 10);

    $read = function () use ($socket): array {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int)substr($data, 0, 3);
        return [$code, $data];
    };
    $write = function (string $line) use ($socket): void {
        fwrite($socket, $line . "\r\n");
    };
    $expect = function (int $code) use ($read): bool {
        [$c, ] = $read();
        return $c === $code;
    };

    if (!$expect(220)) {
        $lastError = 'greeting_failed';
        fclose($socket);
        return false;
    }

    $hostName = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $write('EHLO ' . $hostName);
    [$c, $ehloResp] = $read();
    if ($c !== 250) {
        $write('HELO ' . $hostName);
        if (!$expect(250)) {
            $lastError = 'ehlo_failed';
            fclose($socket);
            return false;
        }
    } elseif ($secure === 'tls' && stripos($ehloResp, 'STARTTLS') !== false) {
        $write('STARTTLS');
        if (!$expect(220)) {
            $lastError = 'starttls_failed';
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $lastError = 'starttls_crypto_failed';
            fclose($socket);
            return false;
        }
        $write('EHLO ' . $hostName);
        if (!$expect(250)) {
            $lastError = 'ehlo_after_starttls_failed';
            fclose($socket);
            return false;
        }
    }

    $write('AUTH LOGIN');
    if (!$expect(334)) {
        $lastError = 'auth_login_failed';
        fclose($socket);
        return false;
    }
    $write(base64_encode($user));
    if (!$expect(334)) {
        $lastError = 'auth_user_failed';
        fclose($socket);
        return false;
    }
    $write(base64_encode($pass));
    if (!$expect(235)) {
        $lastError = 'auth_pass_failed';
        fclose($socket);
        return false;
    }

    $write('MAIL FROM:<' . $user . '>');
    if (!$expect(250)) {
        $lastError = 'mail_from_failed';
        fclose($socket);
        return false;
    }

    $tos = array_filter(array_map('trim', explode(',', $to)));
    foreach ($tos as $addr) {
        $write('RCPT TO:<' . $addr . '>');
        if (!$expect(250)) {
            $lastError = 'rcpt_to_failed:' . $addr;
            fclose($socket);
            return false;
        }
    }

    $write('DATA');
    if (!$expect(354)) {
        $lastError = 'data_failed';
        fclose($socket);
        return false;
    }

    $lines = [];
    $lines[] = 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8');
    $safeTo = implode(', ', array_filter(array_map('trim', explode(',', $to))));
    if ($safeTo !== '') {
        $lines[] = 'To: ' . $safeTo;
    }
    foreach ($headers as $h) $lines[] = $h;
    $lines[] = 'MIME-Version: 1.0';
    $lines[] = 'Content-Type: text/plain; charset=UTF-8';
    $lines[] = 'Content-Transfer-Encoding: 8bit';
    $lines[] = '';

    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $bodyLines = explode("\n", $body);
    foreach ($bodyLines as $l) {
        $lines[] = (isset($l[0]) && $l[0] === '.') ? ('.' . $l) : $l;
    }

    fwrite($socket, implode("\r\n", $lines) . "\r\n.\r\n");
    if (!$expect(250)) {
        $lastError = 'data_end_failed';
        fclose($socket);
        return false;
    }

    $write('QUIT');
    fclose($socket);
    return true;
}

function send_mail(string $to, string $subject, string $body, string $fromName = 'SHIMENABI', string $replyTo = ''): bool
{
    mailer_load_env_once();

    $host = env_get('SMTP_HOST');
    $port = (int)(env_get('SMTP_PORT') !== '' ? env_get('SMTP_PORT') : 465);
    $user = env_get('SMTP_USER');
    $pass = env_get('SMTP_PASS');

    $from = $user !== '' ? $user : 'info@shimenavi.com';

    $headers = [];
    $headers[] = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>';
    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $lastError = '';
    $ok = mailer_smtp_send($to, $subject, $body, $headers, [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
        'secure' => env_get('SMTP_SECURE'),
    ], $lastError);

    if (!$ok && getenv('SMTP_DEBUG')) {
        error_log('[SMTP] send failed: ' . $lastError . ' to=' . $to);
    }
    return $ok;
}

function send_mail_with_error(string $to, string $subject, string $body, string $fromName, string $replyTo): array
{
    mailer_load_env_once();

    $host = env_get('SMTP_HOST');
    $port = (int)(env_get('SMTP_PORT') !== '' ? env_get('SMTP_PORT') : 465);
    $user = env_get('SMTP_USER');
    $pass = env_get('SMTP_PASS');
    $secure = env_get('SMTP_SECURE');

    $from = $user !== '' ? $user : 'info@shimenavi.com';

    $headers = [];
    $headers[] = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>';
    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $lastError = '';
    $ok = mailer_smtp_send($to, $subject, $body, $headers, [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
        'secure' => $secure,
    ], $lastError);

    return [$ok, $lastError];
}
