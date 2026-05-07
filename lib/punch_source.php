<?php

declare(strict_types=1);

function punch_source_ensure_column(PDO $pdo): void
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `time_punches` LIKE :col");
        $st->execute([':col' => 'punch_source']);
        if ($st->fetch(PDO::FETCH_ASSOC)) return;
        $pdo->exec("ALTER TABLE time_punches ADD COLUMN punch_source VARCHAR(20) NULL AFTER device_id");
    } catch (Throwable $e) {
        // 既存環境を壊さない
    }
}

function punch_source_normalize(?string $source): string
{
    $v = strtolower(trim((string)$source));
    return match ($v) {
        'line', 'ipad', 'mypage', 'admin' => $v,
        default => '',
    };
}

function punch_source_label(?string $source): string
{
    return match (punch_source_normalize($source)) {
        'line' => 'LINE',
        'ipad' => 'iPad',
        'mypage' => 'マイページ',
        'admin' => '管理画面',
        default => '-',
    };
}

function punch_source_infer_from_row(array $row): string
{
    $explicit = punch_source_normalize((string)($row['punch_source'] ?? ''));
    return $explicit;
}

function punch_source_summary(?string $clockInSource, ?string $clockOutSource): string
{
    $in = punch_source_normalize($clockInSource);
    $out = punch_source_normalize($clockOutSource);

    if ($in !== '' && $out !== '') {
        if ($in === $out) return punch_source_label($in);
        return '出: ' . punch_source_label($in) . ' / 退: ' . punch_source_label($out);
    }
    if ($in !== '') return '出: ' . punch_source_label($in);
    if ($out !== '') return '退: ' . punch_source_label($out);
    return '-';
}
