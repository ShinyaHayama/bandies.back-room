<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $host = 'mysql80-3.lolipop.lan';
    $requestHosts = [
        (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''),
        (string)($_SERVER['HTTP_HOST'] ?? ''),
        (string)($_SERVER['SERVER_NAME'] ?? ''),
    ];
    $requestHosts = array_map(static function (string $v): string {
        $v = strtolower(trim(explode(',', $v)[0] ?? $v));
        return preg_replace('/:\d+$/', '', $v) ?: $v;
    }, $requestHosts);
    $isDevHost = in_array('dev.shimenavi.com', $requestHosts, true);
    $db   = $isDevHost ? 'LAA1686629-devshimenav' : 'LAA1686629-azure';
    $user = 'LAA1686629';
    $pass = 'ftpaiwebf0918';

    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
