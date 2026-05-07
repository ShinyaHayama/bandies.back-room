<?php

declare(strict_types=1);

/**
 * ✅ missログ専用（必ず “グローバル関数” として定義）
 * - help_ai_core.php から require_once される前提
 * - 失敗しても絶対に本処理を落とさない
 */

if (!function_exists('ha_ensure_miss_table')) {
    function ha_ensure_miss_table(PDO $pdo): void
    {
        // ✅ 文字コード固定（落ちてもOK）
        try {
            $pdo->exec("SET NAMES utf8mb4");
        } catch (Throwable $e) {
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_ai_misses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                store_id BIGINT UNSIGNED NOT NULL,
                question TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_store_created (store_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('ha_log_miss')) {
    function ha_log_miss(PDO $pdo, int $storeId, string $question, bool $debug): void
    {
        try {
            ha_ensure_miss_table($pdo);

            $st = $pdo->prepare("
                INSERT INTO help_ai_misses (store_id, question)
                VALUES (:sid, :q)
            ");
            $st->execute([
                ':sid' => $storeId,
                ':q'   => $question,
            ]);

            if ($debug && function_exists('hapilog')) {
                hapilog("[AI_MISS] inserted id=" . (string)$pdo->lastInsertId() . " store_id={$storeId}");
            }
        } catch (Throwable $e) {
            if ($debug && function_exists('hapilog')) {
                hapilog("[AI_MISS] insert failed: " . $e->getMessage());
            }
            // ✅ 失敗しても落とさない
        }
    }
}

/**
 * ✅ 任意：全質問ログ（必要になったら help_ai_core.php の $LOG_ALL_QUESTIONS=true にする）
 */
if (!function_exists('ha_ensure_all_table')) {
    function ha_ensure_all_table(PDO $pdo): void
    {
        try {
            $pdo->exec("SET NAMES utf8mb4");
        } catch (Throwable $e) {
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_ai_all_questions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                store_id BIGINT UNSIGNED NOT NULL,
                question TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_store_created (store_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('ha_log_all_question')) {
    function ha_log_all_question(PDO $pdo, int $storeId, string $question, bool $debug): void
    {
        try {
            ha_ensure_all_table($pdo);

            $st = $pdo->prepare("
                INSERT INTO help_ai_all_questions (store_id, question)
                VALUES (:sid, :q)
            ");
            $st->execute([
                ':sid' => $storeId,
                ':q'   => $question,
            ]);

            if ($debug && function_exists('hapilog')) {
                hapilog("[AI_ALL] inserted id=" . (string)$pdo->lastInsertId() . " store_id={$storeId}");
            }
        } catch (Throwable $e) {
            if ($debug && function_exists('hapilog')) {
                hapilog("[AI_ALL] insert failed: " . $e->getMessage());
            }
        }
    }
}
