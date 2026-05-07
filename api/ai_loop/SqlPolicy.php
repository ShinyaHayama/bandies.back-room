<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaRegistry.php';

/**
 * ✅ SQLポリシー
 * - SELECTのみ
 * - 対象テーブルは SchemaRegistry のみ
 * - tenant_id/store_id を必ず絞る（強制）
 */
final class SqlPolicy
{
    /**
     * SELECT文以外を拒否（超単純ガード）
     * @param string $sql
     */
    public static function assertSelectOnly(string $sql): void
    {
        $s = ltrim($sql);
        if (!preg_match('/^SELECT\b/i', $s)) {
            throw new RuntimeException('SQL must be SELECT only');
        }
        // 危険ワードを念のため禁止
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE|CREATE)\b/i', $s)) {
            throw new RuntimeException('Dangerous SQL keyword detected');
        }
    }

    /**
     * 対象テーブルが許可されているかチェック（簡易）
     * @param string $sql
     */
    public static function assertAllowedTables(string $sql): void
    {
        $allowed = SchemaRegistry::allowedTables();

        // FROM / JOIN のテーブル名を雑に拾う（実運用はSQLパーサ推奨）
        preg_match_all('/\bFROM\s+([a-zA-Z0-9_]+)|\bJOIN\s+([a-zA-Z0-9_]+)/i', $sql, $m);
        $tables = [];
        foreach ($m[1] as $t) if ($t) $tables[] = $t;
        foreach ($m[2] as $t) if ($t) $tables[] = $t;

        foreach ($tables as $t) {
            if (!in_array($t, $allowed, true)) {
                throw new RuntimeException('Table not allowed: ' . $t);
            }
        }
    }

    /**
     * tenant_id を必ず絞らせる（例：WHERE tenant_id=:tenant_id を含むこと）
     * @param string $sql
     */
    public static function assertTenantScope(string $sql): void
    {
        if (!preg_match('/\btenant_id\b\s*=\s*:/i', $sql)) {
            throw new RuntimeException('tenant scope missing');
        }
    }
}