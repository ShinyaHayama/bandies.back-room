<?php

declare(strict_types=1);

if (!defined('APP_PUBLIC_BASE_URL')) {
    define('APP_PUBLIC_BASE_URL', 'http://bandies.back-room.me');
}

if (!function_exists('app_public_base_url')) {
    function app_public_base_url(): string
    {
        return rtrim((string)APP_PUBLIC_BASE_URL, '/');
    }
}

if (!function_exists('app_public_url')) {
    function app_public_url(string $path = ''): string
    {
        $path = trim($path);
        if ($path === '') {
            return app_public_base_url();
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return app_public_base_url() . '/' . ltrim($path, '/');
    }
}
