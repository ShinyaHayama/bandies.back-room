<?php

declare(strict_types=1);

/**
 * ✅ スキーマ辞書（AIに “推測” をさせないための、唯一の真実）
 * - ここに「使ってよいテーブル/列」「意味」を登録する
 * - AIはこの辞書に無い列名/テーブル名を前提にしてはいけない
 */
final class SchemaRegistry
{
    /**
     * @return array<string, array{columns: array<string,string>, pii?: bool}>
     */
    public static function tables(): array
    {
        return [
            // 打刻（例）
            'time_punches' => [
                'columns' => [
                    'id'         => 'PK',
                    'tenant_id'  => 'テナントID',
                    'store_id'   => '店舗ID',
                    'user_id'    => 'ユーザーID',
                    'punched_at' => '打刻日時(UTC or JSTは実装に合わせて)',
                    'type'       => '打刻種別(in/out/break等、実装に合わせる)',
                ],
            ],
            // 店舗マスタ（例）
            'stores' => [
                'columns' => [
                    'id'                       => '店舗ID',
                    'tenant_id'                => 'テナントID',
                    'business_day_cutoff_time' => '営業日切替時刻(例 05:00:00)',
                ],
            ],

            // ※あなたの実DBに合わせて追加する：
            // 'shifts' => [...],
            // 'users'  => [...],
        ];
    }

    /**
     * @return array<string>
     */
    public static function allowedTables(): array
    {
        return array_keys(self::tables());
    }

    /**
     * @param string $table
     * @return array<string>
     */
    public static function allowedColumns(string $table): array
    {
        $t = self::tables()[$table] ?? null;
        if (!$t) return [];
        return array_keys($t['columns']);
    }
}