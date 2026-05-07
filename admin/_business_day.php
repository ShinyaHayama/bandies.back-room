<?php

declare(strict_types=1);

/**
 * ✅ 営業日（business_date）を締め時刻で計算する
 * 例: 締め 05:00 の場合
 * - 2025-12-29 01:00 => 2025-12-28
 * - 2025-12-29 10:00 => 2025-12-29
 */

function normalize_cutoff_time(string $cutoff): string
{
    $cutoff = trim($cutoff);
    if ($cutoff === '') return '05:00:00';
    // "05:00" なら "05:00:00" にする
    if (preg_match('/^\d{1,2}:\d{2}$/', $cutoff)) return $cutoff . ':00';
    // "5:00:00" などを許容しつつ、最終的に HH:MM:SS を期待
    if (!preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $cutoff)) return '05:00:00';
    return $cutoff;
}

/**
 * @param DateTimeImmutable $dt 店舗TZの時刻
 * @param string $cutoffTime "05:00:00" など
 * @return string Y-m-d の business_date
 */
function business_date_from_datetime(DateTimeImmutable $dt, string $cutoffTime): string
{
    $cutoffTime = normalize_cutoff_time($cutoffTime);

    $ymd = $dt->format('Y-m-d');
    $tz  = $dt->getTimezone();

    $cutoffDt = new DateTimeImmutable($ymd . ' ' . $cutoffTime, $tz);

    // dt が締め時刻より前なら、営業日は前日
    if ($dt < $cutoffDt) {
        return $dt->modify('-1 day')->format('Y-m-d');
    }
    return $ymd;
}