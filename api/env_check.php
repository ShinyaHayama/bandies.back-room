<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

var_dump(getenv('OPENAI_API_KEY')); // Aなら string(...) になる
var_dump($_ENV['OPENAI_API_KEY'] ?? null); // Bでも確認できる