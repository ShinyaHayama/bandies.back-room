<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $host = 'mysql80-3.lolipop.lan';
    $httpHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $httpHost = preg_replace('/:\d+$/', '', $httpHost) ?: $httpHost;
    $db   = ($httpHost === 'dev.shimenavi.com') ? 'LAA1686629-devshimenav' : 'LAA1686629-azure';
    $user = 'LAA1686629';
    $pass = 'ftpaiwebf0918';

    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
