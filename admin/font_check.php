<?php

declare(strict_types=1);
require_once __DIR__ . '/_auth.php';

header('Content-Type: text/plain; charset=utf-8');

$regular = __DIR__ . '/fonts/NotoSansJP-Regular.ttf';
$bold    = __DIR__ . '/fonts/NotoSansJP-Bold.ttf';

foreach (['Regular' => $regular, 'Bold' => $bold] as $name => $path) {
    echo "== {$name} ==\n";
    echo "path: {$path}\n";
    if (!is_file($path)) {
        echo "NG: file not found\n\n";
        continue;
    }

    $size = filesize($path);
    echo "size: {$size}\n";
    if ($size === false || $size < 100000) { // 100KB未満はまず異常
        echo "NG: file too small (maybe broken)\n\n";
        continue;
    }

    $fp = fopen($path, 'rb');
    $head = $fp ? fread($fp, 4) : '';
    if ($fp) fclose($fp);

    echo "head: " . bin2hex($head) . " (expected 00010000 or 4f54544f)\n";
    echo "OK\n\n";
}