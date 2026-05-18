<?php

declare(strict_types=1);

function punch_capture_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $st->execute([':col' => $column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function punch_capture_ensure_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $defs = [
        'punch_face_photo_path' => "ALTER TABLE time_punches ADD COLUMN punch_face_photo_path VARCHAR(255) NULL",
        'punch_latitude' => "ALTER TABLE time_punches ADD COLUMN punch_latitude DECIMAL(10,7) NULL",
        'punch_longitude' => "ALTER TABLE time_punches ADD COLUMN punch_longitude DECIMAL(10,7) NULL",
        'punch_location_accuracy_m' => "ALTER TABLE time_punches ADD COLUMN punch_location_accuracy_m DECIMAL(10,2) NULL",
        'punch_location_captured_at' => "ALTER TABLE time_punches ADD COLUMN punch_location_captured_at DATETIME NULL",
        'punch_location_address' => "ALTER TABLE time_punches ADD COLUMN punch_location_address VARCHAR(255) NULL",
    ];

    foreach ($defs as $col => $sql) {
        if (!punch_capture_column_exists($pdo, 'time_punches', $col)) {
            $pdo->exec($sql);
        }
    }

    $done = true;
}

function punch_capture_private_root(): string
{
    return dirname(__DIR__) . '/_private/punch_photos';
}

function punch_capture_save_photo_from_data_url(string $dataUrl, int $tenantId, int $storeId, int $employeeId, string $capturedAt): string
{
    if (!preg_match('#^data:image/(jpeg|jpg|png);base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $m)) {
        throw new RuntimeException('顔写真の形式が不正です。');
    }

    $type = strtolower((string)$m[1]);
    $ext = ($type === 'png') ? 'png' : 'jpg';
    $raw = base64_decode(preg_replace('/\s+/', '', (string)$m[2]), true);
    if ($raw === false || strlen($raw) < 1000) {
        throw new RuntimeException('顔写真を読み取れませんでした。');
    }
    if (strlen($raw) > 5 * 1024 * 1024) {
        throw new RuntimeException('顔写真のサイズが大きすぎます。');
    }

    $dt = strtotime($capturedAt) ?: time();
    $relDir = sprintf('t%d/s%d/%s', $tenantId, $storeId, date('Y/m', $dt));
    $dir = punch_capture_private_root() . '/' . $relDir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('顔写真の保存先を作成できません。');
    }

    $name = sprintf(
        'emp%d_%s_%s.%s',
        $employeeId,
        date('Ymd_His', $dt),
        bin2hex(random_bytes(6)),
        $ext
    );
    $path = $dir . '/' . $name;
    if (file_put_contents($path, $raw, LOCK_EX) === false) {
        throw new RuntimeException('顔写真を保存できませんでした。');
    }

    return $relDir . '/' . $name;
}

function punch_capture_private_path(string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', trim($relativePath));
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== false) return null;
    if (!preg_match('#^[A-Za-z0-9_./-]+\.(jpg|jpeg|png)$#i', $relativePath)) return null;

    $root = realpath(punch_capture_private_root());
    if ($root === false) return null;

    $path = realpath($root . '/' . $relativePath);
    if ($path === false) return null;
    if (strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 && $path !== $root) return null;

    return $path;
}

function punch_capture_reverse_geocode(float $lat, float $lng): ?string
{
    static $cache = [];

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return null;
    }

    $key = number_format($lat, 5, '.', '') . ',' . number_format($lng, 5, '.', '');
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $query = http_build_query([
        'format' => 'jsonv2',
        'lat' => number_format($lat, 7, '.', ''),
        'lon' => number_format($lng, 7, '.', ''),
        'zoom' => '18',
        'addressdetails' => '1',
        'accept-language' => 'ja',
    ]);
    $url = 'https://nominatim.openstreetmap.org/reverse?' . $query;
    $json = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_USERAGENT => 'Shimenavi/1.0 (attendance location display)',
            ]);
            $json = curl_exec($ch);
            curl_close($ch);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 2.5,
                'header' => "User-Agent: Shimenavi/1.0 (attendance location display)\r\n",
            ],
        ]);

        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $json = file_get_contents($url, false, $context);
        } catch (Throwable $e) {
            $json = false;
        } finally {
            restore_error_handler();
        }
    }

    if (!is_string($json) || $json === '') {
        $cache[$key] = null;
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        $cache[$key] = null;
        return null;
    }

    $address = is_array($data['address'] ?? null) ? $data['address'] : [];
    $parts = [];
    foreach ([
        'state',
        'province',
        'city',
        'town',
        'village',
        'municipality',
        'city_district',
        'ward',
        'suburb',
        'quarter',
        'neighbourhood',
        'road',
        'house_number',
    ] as $field) {
        $value = trim((string)($address[$field] ?? ''));
        if ($value !== '' && !in_array($value, $parts, true)) {
            $parts[] = $value;
        }
    }

    $label = trim(implode('', $parts));
    if ($label === '') {
        $label = trim((string)($data['display_name'] ?? ''));
    }
    if ($label === '') {
        $cache[$key] = null;
        return null;
    }

    $label = preg_replace('/\s+/', ' ', $label) ?? $label;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($label, 'UTF-8') > 240) {
            $label = mb_substr($label, 0, 240, 'UTF-8') . '...';
        }
    } elseif (strlen($label) > 240) {
        $label = substr($label, 0, 240) . '...';
    }

    $cache[$key] = $label;
    return $label;
}
