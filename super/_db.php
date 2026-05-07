<?php

declare(strict_types=1);

$paths = [
    __DIR__ . '/../lib/db.php',
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../../lib/db.php',
];

$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if ($dbFile === null) {
    throw new RuntimeException("db.php not found");
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}