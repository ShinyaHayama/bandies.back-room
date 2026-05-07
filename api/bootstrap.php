<?php
// /api/bootstrap.php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// getenv() を効かせたいので unsafe を使う（PutenvAdapter が有効になる）
$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();