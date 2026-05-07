<?php

declare(strict_types=1);

/**
 * ✅ /admin/help_ai_core.php
 *
 * ✅ 今回の修正（あなたの依頼）
 * - 「テッピーの全出勤の数は？」のような “出勤数” をDBから自動算出できるように追加
 * - 推測禁止：勤怠テーブル/列が特定できない場合は pending（確認質問）に回す
 *
 * ✅ 追加/置換ポイント
 * 1) 追記：ha_parse_attendance_question()
 * 2) 追記：ha_guess_attendance_table()
 * 3) 追記：ha_count_attendance_days()
 * 4) 追記：ha_answer_attendance_count_from_db()
 * 5) 置換：ha_build_investigation_plan()（出勤数対応 plan を追加）
 * 6) 置換：ha_scan_db_by_plan()（出勤系スキャンを追加）
 * 7) 追記：help_ai_answer_from_kb() 内で「給与より前」に出勤数回答を呼ぶ
 *
 * ✅ 補足（あなたの質問の件）
 * - あなたが貼ったAI回答は「DB_STATE_HINTSだけでは勤怠テーブルが確認できない」ので “確認質問に回す” という意味で概ね正しいです。
 * - ただし今回の修正で、DBスキーマ（help_ai_schema_cache）から “time_punches等” を推測ではなく「存在確認」して、
 *   可能ならその場で出勤数まで回答します。
 */

/* =========================================================
 * ✅ ログ関数（未定義でも壊さない）
 * ========================================================= */
if (!function_exists('hapilog')) {
    function hapilog(string $msg): void
    {
        // プロジェクト側で上書きされる想定
    }
}

/* =========================================================
 * ✅ 共通：安全ユーティリティ（存在チェックなど）
 * ========================================================= */
if (!function_exists('ha_tbl_exists')) {
    function ha_tbl_exists(PDO $pdo, string $table): bool
    {
        $table = trim($table);
        if ($table === '') return false;

        try {
            $st = $pdo->prepare("
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                LIMIT 1
            ");
            $st->execute([':t' => $table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            // information_schema を見れない環境向けのフォールバック
            try {
                $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
                return true;
            } catch (Throwable $e2) {
                return false;
            }
        }
    }
}

if (!function_exists('ha_col_exists')) {
    function ha_col_exists(PDO $pdo, string $table, string $col): bool
    {
        $table = trim($table);
        $col = trim($col);
        if ($table === '' || $col === '') return false;

        try {
            $st = $pdo->prepare("
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c
                LIMIT 1
            ");
            $st->execute([':t' => $table, ':c' => $col]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false; // 見えないなら推測しない
        }
    }
}

if (!function_exists('ha_get_cols')) {
    function ha_get_cols(PDO $pdo, string $table): array
    {
        if (!ha_tbl_exists($pdo, $table)) return [];

        try {
            $st = $pdo->prepare("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                ORDER BY ordinal_position
            ");
            $st->execute([':t' => $table]);
            $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_map('strval', $cols);
        } catch (Throwable $e) {
            // information_schema を見れない環境向けのフォールバック
            try {
                $st = $pdo->query("DESCRIBE `{$table}`");
                $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                $cols = [];
                foreach ($rows as $r) {
                    if (isset($r['Field'])) $cols[] = (string)$r['Field'];
                }
                return $cols;
            } catch (Throwable $e2) {
                return [];
            }
        }
    }
}

if (!function_exists('as_text')) {
    function as_text(mixed $v): string
    {
        if ($v === null) return '';
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v) || is_bool($v)) return (string)$v;

        $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '';
    }
}

/* =========================================================
 * ✅ miss ログ（既存維持）
 * ========================================================= */
if (!function_exists('ha_miss_cols')) {
    function ha_miss_cols(PDO $pdo): array
    {
        try {
            $st = $pdo->prepare("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'help_ai_misses'
            ");
            $st->execute();
            $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_map('strval', $cols);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('ha_ensure_miss_table')) {
    function ha_ensure_miss_table(PDO $pdo): void
    {
        $cols = ha_miss_cols($pdo);
        if (!empty($cols)) return;

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
    function ha_log_miss(PDO $pdo, int $storeId, string $question, bool $debug = false): void
    {
        try {
            ha_ensure_miss_table($pdo);

            $cols = ha_miss_cols($pdo);
            $hasStore = in_array('store_id', $cols, true);
            $hasQ     = in_array('question', $cols, true);

            if (!$hasQ) {
                hapilog("[AI_CORE] miss log skipped: help_ai_misses has no 'question'");
                return;
            }

            if ($hasStore) {
                $st = $pdo->prepare("INSERT INTO help_ai_misses (store_id, question) VALUES (:sid, :q)");
                $st->execute([':sid' => $storeId, ':q' => $question]);
            } else {
                $st = $pdo->prepare("INSERT INTO help_ai_misses (question) VALUES (:q)");
                $st->execute([':q' => $question]);
            }
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] miss log failed: " . $e->getMessage());
        }
    }
}

/* =========================================================
 * ✅ .env / OpenAI（既存維持）
 * ========================================================= */
if (!function_exists('ha_load_env_once')) {
    function ha_load_env_once(?string $path, bool $debug): array
    {
        static $cache = null;
        if (is_array($cache)) return $cache;

        $cache = [];
        if (!$path) return $cache;

        if (!is_file($path)) {
            if ($debug) hapilog("[AI_CORE] env: .env not found path={$path}");
            return $cache;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            if ($debug) hapilog("[AI_CORE] env: .env read failed path={$path}");
            return $cache;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));

            if ($v !== '' && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
                $v = substr($v, 1, -1);
            }

            if ($k !== '') $cache[$k] = $v;
        }

        if ($debug) hapilog("[AI_CORE] env: loaded .env path={$path} loaded_kv=" . count($cache));
        return $cache;
    }
}

if (!function_exists('ha_env')) {
    function ha_env(string $key, bool $debug): string
    {
        $v = getenv($key);
        if (is_string($v) && $v !== '') return $v;

        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];

        $path = dirname(__DIR__) . '/.env';
        $kv = ha_load_env_once($path, $debug);
        if (isset($kv[$key]) && $kv[$key] !== '') return (string)$kv[$key];

        if ($debug) hapilog("[AI_CORE] env: missing key={$key}");
        return '';
    }
}

/* =========================================================
 * ✅ 文字化け復元（既存維持）
 * ========================================================= */
if (!function_exists('ha_force_utf8')) {
    function ha_force_utf8(string $s, bool $debug): string
    {
        $s = (string)$s;
        if ($s === '') return '';

        if (!function_exists('mb_check_encoding') || !function_exists('mb_convert_encoding')) {
            if ($debug) hapilog("[AI_CORE] enc: mbstring_not_available");
            return $s;
        }

        $score_mojibake = function (string $x): int {
            $score = 0;
            $score += 10 * preg_match_all('/[縺繧]/u', $x, $m1);
            $score += 5  * preg_match_all('/[逵諤]/u', $x, $m2);
            $score += 20 * substr_count($x, "�");
            return $score;
        };

        $isUtf8 = @mb_check_encoding($s, 'UTF-8');
        $baseScore = $score_mojibake($s);

        if ($isUtf8 && $baseScore > 0) {
            $best = $s;
            $bestScore = $baseScore;

            foreach (['SJIS-win', 'CP932', 'EUC-JP', 'ISO-2022-JP'] as $from) {
                $t = @mb_convert_encoding($s, 'UTF-8', $from);
                if (!is_string($t) || $t === '') continue;
                if (!@mb_check_encoding($t, 'UTF-8')) continue;

                $sc = $score_mojibake($t);
                if ($sc < $bestScore) {
                    $best = $t;
                    $bestScore = $sc;
                }
            }

            if ($best !== $s && $debug) {
                hapilog("[AI_CORE] enc: recovered (utf8-but-garbled) score {$baseScore}=>{$bestScore}");
            }
            return $best;
        }

        if (!$isUtf8) {
            foreach (['SJIS-win', 'CP932', 'EUC-JP', 'ISO-2022-JP'] as $from) {
                $t = @mb_convert_encoding($s, 'UTF-8', $from);
                if (is_string($t) && $t !== '' && @mb_check_encoding($t, 'UTF-8')) {
                    if ($debug) hapilog("[AI_CORE] enc: recovered from={$from}");
                    return $t;
                }
            }
            if ($debug) hapilog("[AI_CORE] enc: could_not_recover");
            return $s;
        }

        return $s;
    }
}

/* =========================================================
 * ✅ Responses APIの返り値抽出（既存維持）
 * ========================================================= */
if (!function_exists('ha_pick_output_text')) {
    function ha_pick_output_text(array $j): string
    {
        if (isset($j['output_text']) && is_string($j['output_text'])) {
            return trim($j['output_text']);
        }

        if (isset($j['output']) && is_array($j['output'])) {
            foreach ($j['output'] as $o) {
                if (!is_array($o)) continue;

                if (isset($o['content']) && is_array($o['content'])) {
                    foreach ($o['content'] as $c) {
                        if (!is_array($c)) continue;

                        if (
                            isset($c['type']) && $c['type'] === 'output_text'
                            && isset($c['text']) && is_string($c['text'])
                        ) {
                            $t = trim($c['text']);
                            if ($t !== '') return $t;
                        }

                        if (isset($c['text']) && is_string($c['text'])) {
                            $t = trim($c['text']);
                            if ($t !== '') return $t;
                        }
                    }
                }
            }
        }

        if (isset($j['text']) && is_string($j['text'])) {
            return trim($j['text']);
        }

        return '';
    }
}

/* =========================================================
 * ✅ NEW：スキーマキャッシュ / pending / facts（既存維持 + 追加テーブル）
 * ========================================================= */
if (!function_exists('ha_ensure_schema_cache_tables')) {
    function ha_ensure_schema_cache_tables(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_ai_schema_cache (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                schema_name VARCHAR(128) NOT NULL,
                captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                payload_json MEDIUMTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY idx_schema_time (schema_name, captured_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_ai_pending (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                store_id BIGINT UNSIGNED NOT NULL,
                original_question TEXT NOT NULL,
                followup_question TEXT NOT NULL,
                meta_json TEXT NULL,
                resolved_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_store_resolved (store_id, resolved_at),
                KEY idx_store_time (store_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_ai_facts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                store_id BIGINT UNSIGNED NOT NULL,
                fact_key VARCHAR(190) NOT NULL,
                fact_value TEXT NOT NULL,
                source_pending_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_store_key_time (store_id, fact_key, created_at),
                KEY idx_pending (source_pending_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ✅ 追加：DBスキャン詳細（state_detail）
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_ai_state_detail (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                store_id BIGINT UNSIGNED NOT NULL,
                question TEXT NOT NULL,
                plan_json MEDIUMTEXT NOT NULL,
                result_json MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_store_time (store_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ✅ 追加：自動提案の保管（任意：管理画面で後で見返せる）
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_ai_fact_suggestions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                store_id BIGINT UNSIGNED NOT NULL,
                suggestion_key VARCHAR(190) NOT NULL,
                suggestion_text TEXT NOT NULL,
                evidence_json MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_store_time (store_id, created_at),
                KEY idx_store_key_time (store_id, suggestion_key, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('ha_get_current_schema_name')) {
    function ha_get_current_schema_name(PDO $pdo): string
    {
        try {
            $st = $pdo->query("SELECT DATABASE() AS db");
            $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
            $db = is_array($r) ? (string)($r['db'] ?? '') : '';
            return trim($db);
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('ha_capture_schema_snapshot')) {
    function ha_capture_schema_snapshot(PDO $pdo, bool $debug): void
    {
        try {
            ha_ensure_schema_cache_tables($pdo);

            $schema = ha_get_current_schema_name($pdo);
            if ($schema === '' || $schema === 'information_schema') {
                if ($debug) hapilog("[AI_CORE] schema snapshot skipped: schema={$schema}");
                return;
            }

            $st = $pdo->prepare("
                SELECT payload_json
                FROM help_ai_schema_cache
                WHERE schema_name=:s
                  AND captured_at >= (NOW() - INTERVAL 30 DAY)
                ORDER BY captured_at DESC
                LIMIT 1
            ");
            $st->execute([':s' => $schema]);
            $exists = (string)($st->fetchColumn() ?: '');
            if ($exists !== '') return;

            $payload = [
                'schema' => $schema,
                'tables' => [],
            ];

            $tables = [];
            try {
                $st = $pdo->prepare("
                    SELECT table_name, table_type, engine, table_comment
                    FROM information_schema.tables
                    WHERE table_schema = :s
                    ORDER BY table_name
                ");
                $st->execute([':s' => $schema]);
                $tables = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                try {
                    $st = $pdo->query("SHOW TABLES");
                    $rows = $st ? ($st->fetchAll(PDO::FETCH_NUM) ?: []) : [];
                    foreach ($rows as $r) {
                        $tables[] = ['table_name' => (string)($r[0] ?? '')];
                    }
                } catch (Throwable $e2) {
                    $tables = [];
                }
            }

            $maxTables = 500;
            $count = 0;

            foreach ($tables as $t) {
                $count++;
                if ($count > $maxTables) break;

                $tn = trim((string)($t['table_name'] ?? ''));
                if ($tn === '') continue;

                $cols = [];
                try {
                    $stc = $pdo->prepare("
                        SELECT column_name, column_type, is_nullable, column_key, extra
                        FROM information_schema.columns
                        WHERE table_schema = :s
                          AND table_name = :t
                        ORDER BY ordinal_position
                    ");
                    $stc->execute([':s' => $schema, ':t' => $tn]);
                    $cols = $stc->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable $e) {
                    try {
                        $stc = $pdo->query("DESCRIBE `{$tn}`");
                        $rows = $stc ? ($stc->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                        foreach ($rows as $r) {
                            $cols[] = [
                                'column_name' => (string)($r['Field'] ?? ''),
                                'column_type' => (string)($r['Type'] ?? ''),
                                'is_nullable' => (string)($r['Null'] ?? ''),
                                'column_key'  => (string)($r['Key'] ?? ''),
                                'extra'       => (string)($r['Extra'] ?? ''),
                            ];
                        }
                    } catch (Throwable $e2) {
                        $cols = [];
                    }
                }

                $payload['tables'][$tn] = [
                    'meta' => [
                        'table_type' => (string)($t['table_type'] ?? ''),
                        'engine' => (string)($t['engine'] ?? ''),
                        'comment' => (string)($t['table_comment'] ?? ''),
                    ],
                    'columns' => $cols,
                ];
            }

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || $json === '') return;

            $ins = $pdo->prepare("
                INSERT INTO help_ai_schema_cache (schema_name, payload_json)
                VALUES (:s, :j)
            ");
            $ins->execute([':s' => $schema, ':j' => $json]);
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] schema snapshot failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('ha_load_latest_schema_snapshot')) {
    function ha_load_latest_schema_snapshot(PDO $pdo, bool $debug): array
    {
        try {
            ha_ensure_schema_cache_tables($pdo);

            $schema = ha_get_current_schema_name($pdo);
            if ($schema === '' || $schema === 'information_schema') return [];

            $st = $pdo->prepare("
                SELECT payload_json
                FROM help_ai_schema_cache
                WHERE schema_name=:s
                ORDER BY captured_at DESC
                LIMIT 1
            ");
            $st->execute([':s' => $schema]);
            $json = (string)($st->fetchColumn() ?: '');
            if ($json === '') return [];

            $arr = json_decode($json, true);
            return is_array($arr) ? $arr : [];
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] schema load failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('ha_schema_to_compact_text')) {
    function ha_schema_to_compact_text(array $snap, int $maxTables = 60): string
    {
        $tables = (array)($snap['tables'] ?? []);
        if (!$tables) return '';

        $out = [];
        $out[] = "DB_SCHEMA:";
        $i = 0;

        foreach ($tables as $tn => $info) {
            $i++;
            if ($i > $maxTables) {
                $out[] = "…（省略：テーブルが多いため）";
                break;
            }

            $cols = (array)($info['columns'] ?? []);
            $names = [];
            foreach ($cols as $c) {
                if (!is_array($c)) continue;
                $cn = (string)($c['column_name'] ?? '');
                if ($cn === '') continue;
                $names[] = $cn;
                if (count($names) >= 18) break;
            }
            $out[] = "- {$tn}: " . implode(", ", $names);
        }

        return implode("\n", $out);
    }
}

/* =========================================================
 * ✅ NEW：DB状態キャッシュ（既存の軽量スナップショット）
 * ========================================================= */
if (!function_exists('ha_ensure_state_cache_table')) {
    function ha_ensure_state_cache_table(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS help_ai_state_cache (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                store_id BIGINT UNSIGNED NOT NULL,
                captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                payload_json MEDIUMTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY idx_store_time (store_id, captured_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('ha_safe_scalar')) {
    function ha_safe_scalar(mixed $v): string
    {
        if ($v === null) return '';
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v) || is_bool($v)) return (string)$v;
        return '';
    }
}

if (!function_exists('ha_capture_state_snapshot')) {
    function ha_capture_state_snapshot(PDO $pdo, int $storeId, bool $debug): void
    {
        try {
            if ($storeId <= 0) return;
            ha_ensure_state_cache_table($pdo);

            $st = $pdo->prepare("
                SELECT 1
                FROM help_ai_state_cache
                WHERE store_id=:sid
                  AND captured_at >= (NOW() - INTERVAL 1 DAY)
                LIMIT 1
            ");
            $st->execute([':sid' => $storeId]);
            if ((int)$st->fetchColumn() === 1) return;

            $payload = [
                'store_id' => $storeId,
                'captured_at' => date('c'),
                'hints' => [],
            ];

            if (ha_tbl_exists($pdo, 'stores')) {
                $cols = ha_get_cols($pdo, 'stores');
                $pick = [];

                $wantCols = [
                    'business_day_cutoff_time',
                    'payroll_round_unit_minutes',
                    'payroll_closing_day',
                    'payroll_payment_day',
                    'payroll_type',
                ];

                $select = ['id'];
                foreach ($wantCols as $c) {
                    if (in_array($c, $cols, true)) $select[] = "`{$c}`";
                }

                try {
                    $sql = "SELECT " . implode(", ", $select) . " FROM stores WHERE id=:sid LIMIT 1";
                    $st = $pdo->prepare($sql);
                    $st->execute([':sid' => $storeId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                    foreach ($row as $k => $v) {
                        if ($k === 'id') continue;
                        $pick[$k] = ha_safe_scalar($v);
                    }
                } catch (Throwable $e) {
                    // noop
                }

                if ($pick) $payload['hints']['stores'] = $pick;
            }

            foreach (['employees', 'employee_profiles'] as $t) {
                if (!ha_tbl_exists($pdo, $t)) continue;

                try {
                    $cols = ha_get_cols($pdo, $t);
                    $hasStore = in_array('store_id', $cols, true);

                    $sql = "SELECT COUNT(*) FROM `{$t}`" . ($hasStore ? " WHERE store_id=:sid" : "");
                    $st = $pdo->prepare($sql);
                    $st->execute($hasStore ? [':sid' => $storeId] : []);
                    $cnt = (int)$st->fetchColumn();
                    $payload['hints'][$t] = ['count' => $cnt];
                } catch (Throwable $e) {
                    // noop
                }
            }

            if (ha_tbl_exists($pdo, 'pay_periods')) {
                try {
                    $cols = ha_get_cols($pdo, 'pay_periods');
                    $hasStore = in_array('store_id', $cols, true);
                    $idCol = in_array('id', $cols, true) ? 'id' : '';
                    if ($idCol !== '') {
                        $startCol = in_array('start_date', $cols, true) ? 'start_date'
                            : (in_array('period_start', $cols, true) ? 'period_start'
                                : (in_array('from_date', $cols, true) ? 'from_date' : ''));

                        $endCol = in_array('end_date', $cols, true) ? 'end_date'
                            : (in_array('period_end', $cols, true) ? 'period_end'
                                : (in_array('to_date', $cols, true) ? 'to_date' : ''));

                        $tmCol = in_array('target_month', $cols, true) ? 'target_month'
                            : (in_array('year_month', $cols, true) ? 'year_month'
                                : (in_array('ym', $cols, true) ? 'ym' : ''));

                        $select = ["`{$idCol}` AS id"];
                        if ($startCol !== '') $select[] = "`{$startCol}` AS p_from";
                        if ($endCol !== '') $select[] = "`{$endCol}` AS p_to";
                        if ($tmCol !== '') $select[] = "`{$tmCol}` AS target_month";
                        if ($hasStore) $select[] = "store_id";

                        $sql = "SELECT " . implode(", ", $select)
                            . " FROM pay_periods"
                            . ($hasStore ? " WHERE store_id=:sid" : "")
                            . " ORDER BY `{$idCol}` DESC LIMIT 1";

                        $st = $pdo->prepare($sql);
                        $st->execute($hasStore ? [':sid' => $storeId] : []);
                        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

                        if ($row) {
                            $payload['hints']['pay_periods_latest'] = [
                                'id' => (int)($row['id'] ?? 0),
                                'from' => ha_safe_scalar($row['p_from'] ?? ''),
                                'to' => ha_safe_scalar($row['p_to'] ?? ''),
                                'target_month' => ha_safe_scalar($row['target_month'] ?? ''),
                                'store_id' => isset($row['store_id']) ? (int)$row['store_id'] : null,
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    // noop
                }
            }

            if (ha_tbl_exists($pdo, 'pay_slips')) {
                try {
                    $cols = ha_get_cols($pdo, 'pay_slips');
                    $hasStore = in_array('store_id', $cols, true);

                    $sql = "SELECT COUNT(*) FROM pay_slips" . ($hasStore ? " WHERE store_id=:sid" : "");
                    $st = $pdo->prepare($sql);
                    $st->execute($hasStore ? [':sid' => $storeId] : []);
                    $cnt = (int)$st->fetchColumn();
                    $payload['hints']['pay_slips'] = ['count' => $cnt];
                } catch (Throwable $e) {
                    // noop
                }
            }

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || $json === '') return;

            $ins = $pdo->prepare("INSERT INTO help_ai_state_cache (store_id, payload_json) VALUES (:sid, :j)");
            $ins->execute([':sid' => $storeId, ':j' => $json]);
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] state snapshot failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('ha_load_latest_state_snapshot')) {
    function ha_load_latest_state_snapshot(PDO $pdo, int $storeId, bool $debug): array
    {
        try {
            if ($storeId <= 0) return [];
            if (!ha_tbl_exists($pdo, 'help_ai_state_cache')) return [];

            $st = $pdo->prepare("
                SELECT payload_json
                FROM help_ai_state_cache
                WHERE store_id=:sid
                ORDER BY captured_at DESC
                LIMIT 1
            ");
            $st->execute([':sid' => $storeId]);
            $json = (string)($st->fetchColumn() ?: '');
            if ($json === '') return [];

            $arr = json_decode($json, true);
            return is_array($arr) ? $arr : [];
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] state load failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('ha_state_to_compact_text')) {
    function ha_state_to_compact_text(array $snap): string
    {
        $h = (array)($snap['hints'] ?? []);
        if (!$h) return '';

        $out = [];
        $out[] = "DB_STATE_HINTS:";
        foreach ($h as $k => $v) {
            $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($j)) $j = '';
            $out[] = "- {$k}: {$j}";
        }
        return implode("\n", $out);
    }
}

/* =========================================================
 * ✅ NEW：対話で育つ（pending / facts）
 * ========================================================= */
if (!function_exists('ha_parse_user_answer')) {
    function ha_parse_user_answer(string $q): ?array
    {
        $q = trim($q);
        if ($q === '') return null;

        if (!preg_match('/^回答\s*#\s*(\d+)\s*:\s*(.+)$/u', $q, $m)) return null;

        $pid = (int)$m[1];
        $ans = trim((string)$m[2]);
        if ($pid <= 0 || $ans === '') return null;

        return ['pending_id' => $pid, 'answer' => $ans];
    }
}

if (!function_exists('ha_save_fact_from_answer')) {
    function ha_save_fact_from_answer(PDO $pdo, int $storeId, int $pendingId, string $answer, bool $debug): bool
    {
        try {
            ha_ensure_schema_cache_tables($pdo);

            $st = $pdo->prepare("
                SELECT id, original_question, followup_question, meta_json, resolved_at
                FROM help_ai_pending
                WHERE id=:id AND store_id=:sid
                LIMIT 1
            ");
            $st->execute([':id' => $pendingId, ':sid' => $storeId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return false;

            if (!empty($p['resolved_at'])) return true;

            $meta = [];
            $mj = (string)($p['meta_json'] ?? '');
            if ($mj !== '') {
                $tmp = json_decode($mj, true);
                if (is_array($tmp)) $meta = $tmp;
            }

            $factKey = (string)($meta['fact_key'] ?? '');
            if ($factKey === '') $factKey = "pending#{$pendingId}";

            $ins = $pdo->prepare("
                INSERT INTO help_ai_facts (store_id, fact_key, fact_value, source_pending_id)
                VALUES (:sid, :k, :v, :pid)
            ");
            $ins->execute([
                ':sid' => $storeId,
                ':k' => $factKey,
                ':v' => $answer,
                ':pid' => $pendingId,
            ]);

            $upd = $pdo->prepare("
                UPDATE help_ai_pending
                SET resolved_at=NOW()
                WHERE id=:id AND store_id=:sid AND resolved_at IS NULL
            ");
            $upd->execute([':id' => $pendingId, ':sid' => $storeId]);

            if ($debug) hapilog("[AI_CORE] fact saved key={$factKey} pending={$pendingId}");
            return true;
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] save_fact failed: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ha_get_recent_facts_text')) {
    function ha_get_recent_facts_text(PDO $pdo, int $storeId, int $limit = 30): string
    {
        try {
            if (!ha_tbl_exists($pdo, 'help_ai_facts')) return '';

            $limit = max(1, min(200, (int)$limit));

            $st = $pdo->prepare("
                SELECT fact_key, fact_value
                FROM help_ai_facts
                WHERE store_id=:sid
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $st->execute([':sid' => $storeId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rows) return '';

            $out = [];
            $out[] = "FACTS:";
            foreach ($rows as $r) {
                $k = (string)($r['fact_key'] ?? '');
                $v = (string)($r['fact_value'] ?? '');
                if ($k === '' || $v === '') continue;
                $out[] = "- {$k}: {$v}";
            }
            return implode("\n", $out);
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('ha_create_pending')) {
    function ha_create_pending(PDO $pdo, int $storeId, string $originalQuestion, string $followupQuestion, array $meta, bool $debug): int
    {
        try {
            ha_ensure_schema_cache_tables($pdo);

            $mj = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($mj)) $mj = '';

            $st = $pdo->prepare("
                INSERT INTO help_ai_pending (store_id, original_question, followup_question, meta_json)
                VALUES (:sid, :oq, :fq, :mj)
            ");
            $st->execute([
                ':sid' => $storeId,
                ':oq' => $originalQuestion,
                ':fq' => $followupQuestion,
                ':mj' => $mj,
            ]);

            $id = (int)$pdo->lastInsertId();
            if ($debug) hapilog("[AI_CORE] pending created id={$id}");
            return $id;
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] pending create failed: " . $e->getMessage());
            return 0;
        }
    }
}

/* =========================================================
 * ✅ NEW：質問 → 調査プラン作成 → DBスキャン → state_detail保存
 * ========================================================= */

/**
 * ✅ 追記：出勤数の質問をパース
 * - 例：「テッピーの全出勤の数は？」「テッピーの出勤回数」
 * - 推測しない：期間指定は "全" を既定扱い（指定がなければ全期間）
 */
if (!function_exists('ha_parse_attendance_question')) {
    function ha_parse_attendance_question(string $q): ?array
    {
        $q = trim($q);
        if ($q === '') return null;

        // ✅ 末尾や途中に emp_id=123 があれば拾う（指定があるなら推測しない）
        $empId = 0;
        if (preg_match('/\(?\s*emp_id\s*=\s*(\d+)\s*\)?/iu', $q, $m)) {
            $empId = (int)$m[1];
            $q = preg_replace('/\(?\s*emp_id\s*=\s*\d+\s*\)?/iu', '', $q);
            $q = trim((string)$q);
        }

        // ✅ 「◯◯の出勤」「◯◯の全出勤」「◯◯の出勤回数」等
        if (!preg_match('/^(.+?)の\s*(全)?\s*(出勤|勤務)\s*(回数|数)?/u', $q, $m)) return null;

        $name = trim((string)($m[1] ?? ''));
        if ($name === '') return null;

        // ✅ 期間：現状は「全」だけ扱う（期間指定の自然文は推測禁止なので未対応）
        $scope = (!empty($m[2])) ? 'all' : 'all';

        return [
            'name' => $name,
            'scope' => $scope,
            'emp_id' => $empId,
        ];
    }
}

/**
 * ✅ 追記：勤怠テーブル推定（推測ではなく “候補を列要件で確定”）
 * - よくある候補名：time_punches / attendance / attendances / work_records / shifts など
 * - “出勤日数” を数えるのに必要な列の最小要件を満たすものだけ採用
 */
if (!function_exists('ha_guess_attendance_table')) {
    function ha_guess_attendance_table(PDO $pdo, bool $debug): ?array
    {
        // ✅ 最優先候補（あなたのプロダクト文脈）
        $candidates = [
            'time_punches',
            'attendance',
            'attendances',
            'work_records',
            'work_shifts',
            'shifts',
            'shift_assignments',
        ];

        $best = null;

        foreach ($candidates as $t) {
            if (!ha_tbl_exists($pdo, $t)) continue;

            $cols = ha_get_cols($pdo, $t);
            if (!$cols) continue;

            // ✅ employee を示す列
            $empCol = in_array('employee_id', $cols, true) ? 'employee_id'
                : (in_array('emp_id', $cols, true) ? 'emp_id' : '');

            // ✅ 日付/日時を示す列（出勤日キーを作れること）
            $dtCol = in_array('punched_at', $cols, true) ? 'punched_at'
                : (in_array('worked_at', $cols, true) ? 'worked_at'
                    : (in_array('clock_in_at', $cols, true) ? 'clock_in_at'
                        : (in_array('start_at', $cols, true) ? 'start_at'
                            : (in_array('start_time', $cols, true) ? 'start_time'
                                : (in_array('work_date', $cols, true) ? 'work_date' : '')))));

            // ✅ store を示す列（あれば絞れる）
            $storeCol = in_array('store_id', $cols, true) ? 'store_id' : '';

            if ($empCol === '' || $dtCol === '') {
                // ✅ 要件を満たさないものは採用しない（推測禁止）
                continue;
            }

            // ✅ 最初に要件を満たしたものを採用（複数ある場合は今後スコアリング可能）
            $best = [
                'table' => $t,
                'emp_col' => $empCol,
                'dt_col' => $dtCol,
                'store_col' => $storeCol,
                'cols' => $cols, // デバッグ/根拠用
            ];
            break;
        }

        if (!$best && $debug) hapilog("[AI_CORE] attendance table not found in known candidates");
        return $best;
    }
}

/**
 * ✅ 追記：出勤日数（全出勤数）を数える
 * - 方針：employee_id で絞って、日付単位に DISTINCT して COUNT
 * - business_day_cutoff_time を考慮する場合は「営業日キー」生成が必要だが、
 *   推測禁止なので “stores.business_day_cutoff_time が存在し、dt_colがdatetime” の場合のみ適用する（後で拡張）
 */
if (!function_exists('ha_count_attendance_days')) {
    function ha_count_attendance_days(PDO $pdo, int $storeId, int $empId, array $att, bool $debug): ?array
    {
        $table = (string)($att['table'] ?? '');
        $empCol = (string)($att['emp_col'] ?? '');
        $dtCol = (string)($att['dt_col'] ?? '');
        $storeCol = (string)($att['store_col'] ?? '');

        if ($table === '' || $empCol === '' || $dtCol === '') return null;
        if ($empId <= 0) return null;
        if (!ha_tbl_exists($pdo, $table)) return null;

        // ✅ store の絞り込みは “列が存在する場合のみ” 行う（推測禁止）
        $where = " WHERE `{$empCol}`=:eid";
        $params = [':eid' => $empId];

        if ($storeId > 0 && $storeCol !== '') {
            $where .= " AND `{$storeCol}`=:sid";
            $params[':sid'] = $storeId;
        }

        // ✅ dtCol が日付か日時か分からないので、DATE() にかける（MySQLではDATE(date)もOK）
        // ⚠️ index は効きにくいが、まずは確実性優先（必要なら別途集計テーブル化）
        $sql = "SELECT COUNT(DISTINCT DATE(`{$dtCol}`)) AS days_cnt FROM `{$table}`{$where}";

        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $cnt = (int)$st->fetchColumn();

            return [
                'count' => $cnt,
                'table' => $table,
                'emp_col' => $empCol,
                'dt_col' => $dtCol,
                'store_col' => $storeCol,
                'sql_kind' => 'count_distinct_date',
            ];
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] attendance count failed: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * ✅ 追記：出勤数QをDBから回答（推測なし）
 * - 従業員特定：既存の ha_find_employee_candidates() を流用
 * - emp_id 指定があればそれに絞る
 * - 勤怠テーブルが見つからない/列要件を満たさないなら pending に回す（ここでは文字列で返す）
 */
if (!function_exists('ha_answer_attendance_count_from_db')) {
    function ha_answer_attendance_count_from_db(PDO $pdo, int $storeId, string $question, bool $debug): ?string
    {
        $p = ha_parse_attendance_question($question);
        if (!$p) return null;

        $name = (string)$p['name'];
        $hintEmpId = (int)($p['emp_id'] ?? 0);

        // ✅ 1) 従業員候補
        if (!function_exists('ha_find_employee_candidates')) {
            // ここに来ることは通常ない（この後の定義が存在するため）
            return null;
        }

        $emps = ha_find_employee_candidates($pdo, $storeId, $name, $debug);

        // ✅ emp_id 指定があるなら推測せず絞る
        if ($hintEmpId > 0) {
            $emps = array_values(array_filter($emps, fn($e) => (int)($e['emp_id'] ?? 0) === $hintEmpId));
            if (count($emps) === 0) {
                return "emp_id={$hintEmpId} が見つかりませんでした。\n"
                    . "（確認）employees / employee_profiles にそのIDが存在するか確認してください。";
            }
        }

        if (count($emps) === 0) {
            return "DBを確認しましたが、従業員名「{$name}」に一致する人が見つかりませんでした。\n"
                . "（確認）employees / employee_profiles に登録名があるか確認してください。";
        }

        if (count($emps) >= 2) {
            $lines = [];
            $lines[] = "同じ名前に複数一致したため、どの「{$name}」か特定できません。";
            $lines[] = "候補：";
            foreach ($emps as $e) {
                $lines[] = "・emp_id=" . (int)$e['emp_id'] . " / name=" . (string)$e['emp_name'] . " / table=" . (string)$e['table'];
            }
            $lines[] = "";
            $lines[] = "どの人か「emp_id」を教えてください。";
            $lines[] = "例）{$name}の全出勤の数は？（emp_id=123）";
            return implode("\n", $lines);
        }

        $emp = $emps[0];
        $empId = (int)$emp['emp_id'];
        $empName = (string)$emp['emp_name'];

        // ✅ 2) 勤怠テーブル特定（推測ではなく “存在+列要件” で確定）
        $att = ha_guess_attendance_table($pdo, $debug);
        if (!$att) {
            return "出勤数を数えるための勤怠テーブルがDB内で特定できませんでした。\n"
                . "（確認）\n"
                . "1) 出勤記録が入っているテーブル名（例：time_punches 等）\n"
                . "2) 従業員ID列名（employee_id 等）\n"
                . "3) 出勤日時/日付列名（punched_at 等）\n"
                . "返信形式：回答#<id>: table=..., emp_col=..., dt_col=...";
        }

        // ✅ 3) 出勤日数の算出
        $cnt = ha_count_attendance_days($pdo, $storeId, $empId, $att, $debug);
        if (!$cnt) {
            return "{$empName}（emp_id={$empId}）の出勤数をDBから集計できませんでした。\n"
                . "（確認）勤怠テーブルの列名が想定と違う可能性があります。\n"
                . "検出：table=" . (string)($att['table'] ?? '') . " / emp_col=" . (string)($att['emp_col'] ?? '')
                . " / dt_col=" . (string)($att['dt_col'] ?? '');
        }

        $lines = [];
        $lines[] = "{$empName}（emp_id={$empId}）の全出勤の数は、DB上は **{$cnt['count']}回** でした。";
        $lines[] = "（根拠）table=" . (string)$cnt['table'] . " / DISTINCT DATE(" . (string)$cnt['dt_col'] . ")";
        if (!empty($cnt['store_col'])) {
            $lines[] = "（絞り込み）store_id=" . (int)$storeId . " / " . (string)$cnt['store_col'];
        }
        return implode("\n", $lines);
    }
}

/**
 * ✅ 置換：調査プラン
 * - 出勤数（attendance_count）を追加
 */
if (!function_exists('ha_build_investigation_plan')) {
    function ha_build_investigation_plan(PDO $pdo, int $storeId, string $question, bool $debug): array
    {
        $q = trim($question);

        $plan = [
            'kind' => 'generic',
            'steps' => [],
        ];

        // ✅ 0) 出勤数（全出勤の数 / 出勤回数）
        if (ha_parse_attendance_question($q)) {
            $plan['kind'] = 'attendance_count';

            // ✅ まず従業員候補
            $plan['steps'][] = [
                'table' => 'employees',
                'where_hint' => 'name like ? and store_id=? (if exists)',
                'fields_hint' => 'id, name/nickname/display_name',
                'limit_hint' => 20,
            ];
            $plan['steps'][] = [
                'table' => 'employee_profiles',
                'where_hint' => 'name like ? and store_id=? (if exists)',
                'fields_hint' => 'id/employee_id, name/nickname/display_name',
                'limit_hint' => 20,
            ];

            // ✅ 勤怠候補（存在した場合のみ scan で拾う）
            foreach (['time_punches', 'attendance', 'attendances', 'work_records', 'work_shifts', 'shifts', 'shift_assignments'] as $t) {
                $plan['steps'][] = [
                    'table' => $t,
                    'where_hint' => 'employee_id and date/datetime',
                    'fields_hint' => 'employee_id + punched_at/work_date etc',
                    'limit_hint' => 3,
                ];
            }

            return $plan;
        }

        // ✅ 1) 給与（○月の給料/給与）
        if (preg_match('/^(.+?)の\s*(\d{1,2})\s*月.*(給料|給与)/u', $q)) {
            $plan['kind'] = 'payroll_lookup';
            $plan['steps'][] = [
                'table' => 'employees',
                'where_hint' => 'name like ? and store_id=? (if exists)',
                'fields_hint' => 'id, name/nickname/display_name',
                'limit_hint' => 20,
            ];
            $plan['steps'][] = [
                'table' => 'employee_profiles',
                'where_hint' => 'name like ? and store_id=? (if exists)',
                'fields_hint' => 'id/employee_id, name/nickname/display_name',
                'limit_hint' => 20,
            ];
            $plan['steps'][] = [
                'table' => 'pay_periods',
                'where_hint' => 'target_month or month(start/end)',
                'fields_hint' => 'id, target_month, start_date/end_date',
                'limit_hint' => 5,
            ];
            $plan['steps'][] = [
                'table' => 'pay_slips',
                'where_hint' => 'employee_id and pay_period_id (and store_id if exists)',
                'fields_hint' => 'id, net/gross/tax/deduction',
                'limit_hint' => 5,
            ];
            return $plan;
        }

        // ✅ 2) 従業員の特定（「◯◯って誰」）
        if (preg_match('/^(.*?)(って|は)\s*誰/u', $q)) {
            $plan['kind'] = 'employee_identity';
            $plan['steps'][] = [
                'table' => 'employees',
                'where_hint' => 'name like ? and store_id=? (if exists)',
                'fields_hint' => 'id, name/nickname/display_name',
                'limit_hint' => 30,
            ];
            $plan['steps'][] = [
                'table' => 'employee_profiles',
                'where_hint' => 'name like ? and store_id=? (if exists)',
                'fields_hint' => 'id/employee_id, name/nickname/display_name',
                'limit_hint' => 30,
            ];
            return $plan;
        }

        // ✅ 3) 締め日/支払日（ストア設定）
        if (preg_match('/(締め日|支払日|支給日|勤怠の締め)/u', $q)) {
            $plan['kind'] = 'store_settings';
            $plan['steps'][] = [
                'table' => 'stores',
                'where_hint' => 'id = store_id',
                'fields_hint' => 'payroll_* / business_day_cutoff_time / round_unit',
                'limit_hint' => 1,
            ];
            return $plan;
        }

        // ✅ 4) 既定：state_cacheのヒントに寄せる（軽量）
        $plan['kind'] = 'generic';
        $plan['steps'][] = [
            'table' => 'stores',
            'where_hint' => 'id = store_id (if exists)',
            'fields_hint' => 'common settings columns',
            'limit_hint' => 1,
        ];
        $plan['steps'][] = [
            'table' => 'employees',
            'where_hint' => 'count by store_id (if exists)',
            'fields_hint' => 'count(*)',
            'limit_hint' => 1,
        ];

        return $plan;
    }
}

/**
 * ✅ 置換：DBスキャン（出勤系を追加）
 */
if (!function_exists('ha_scan_db_by_plan')) {
    function ha_scan_db_by_plan(PDO $pdo, int $storeId, string $question, array $plan, bool $debug): array
    {
        $results = [
            'store_id' => $storeId,
            'question' => $question,
            'kind' => (string)($plan['kind'] ?? 'generic'),
            'steps' => [],
            'notes' => [],
        ];

        $steps = is_array($plan['steps'] ?? null) ? (array)$plan['steps'] : [];
        foreach ($steps as $idx => $s) {
            if (!is_array($s)) continue;

            $table = trim((string)($s['table'] ?? ''));
            if ($table === '') continue;

            $stepRes = [
                'table' => $table,
                'ok' => false,
                'summary' => '',
                'sample_rows' => [],
                'error' => '',
            ];

            if (!ha_tbl_exists($pdo, $table)) {
                $stepRes['error'] = 'table_not_found';
                $results['steps'][] = $stepRes;
                continue;
            }

            try {
                $cols = ha_get_cols($pdo, $table);

                // ✅ employees / employee_profiles：名前検索できそうなら候補を出す
                if (in_array($table, ['employees', 'employee_profiles'], true)) {
                    $name = '';

                    // ✅ 給与質問
                    if (preg_match('/^(.+?)の\s*\d{1,2}\s*月.*(給料|給与)/u', $question, $m)) {
                        $name = trim((string)$m[1]);
                    }

                    // ✅ だれ？
                    elseif (preg_match('/^(.*?)(って|は)\s*誰/u', $question, $m)) {
                        $name = trim((string)$m[1]);
                    }

                    // ✅ 出勤数
                    else {
                        $ap = ha_parse_attendance_question($question);
                        if ($ap) $name = trim((string)($ap['name'] ?? ''));
                    }

                    $nameCols = [];
                    foreach (['nickname', 'name', 'display_name', 'full_name', 'kana', 'label'] as $nc) {
                        if (in_array($nc, $cols, true)) $nameCols[] = $nc;
                    }
                    $idCol = in_array('id', $cols, true) ? 'id' : (in_array('employee_id', $cols, true) ? 'employee_id' : '');
                    $hasStore = in_array('store_id', $cols, true);

                    if ($name !== '' && $idCol !== '' && $nameCols) {
                        $nc = $nameCols[0];
                        $sql = "SELECT `{$idCol}` AS emp_id, `{$nc}` AS emp_name"
                            . ($hasStore ? ", store_id" : "")
                            . " FROM `{$table}`"
                            . " WHERE `{$nc}` LIKE :q"
                            . ($hasStore ? " AND store_id=:sid" : "")
                            . " ORDER BY `{$idCol}` ASC"
                            . " LIMIT 20";
                        $st = $pdo->prepare($sql);
                        $params = [':q' => '%' . $name . '%'];
                        if ($hasStore) $params[':sid'] = $storeId;
                        $st->execute($params);
                        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        $stepRes['ok'] = true;
                        $stepRes['summary'] = "matched=" . count($rows);
                        $stepRes['sample_rows'] = array_slice($rows, 0, 10);
                        $results['steps'][] = $stepRes;
                        continue;
                    }

                    // fallback：count
                    $hasStore = in_array('store_id', $cols, true);
                    $sql = "SELECT COUNT(*) AS cnt FROM `{$table}`" . ($hasStore ? " WHERE store_id=:sid" : "");
                    $st = $pdo->prepare($sql);
                    $st->execute($hasStore ? [':sid' => $storeId] : []);
                    $cnt = (int)$st->fetchColumn();
                    $stepRes['ok'] = true;
                    $stepRes['summary'] = "count={$cnt}";
                    $results['steps'][] = $stepRes;
                    continue;
                }

                // ✅ 出勤系テーブルの “列要件” を確認するだけ（重い探索はしない）
                if ((string)($plan['kind'] ?? '') === 'attendance_count') {
                    // ✅ 主要列の存在チェックだけ返す
                    $empCol = in_array('employee_id', $cols, true) ? 'employee_id' : (in_array('emp_id', $cols, true) ? 'emp_id' : '');
                    $dtCol = in_array('punched_at', $cols, true) ? 'punched_at'
                        : (in_array('worked_at', $cols, true) ? 'worked_at'
                            : (in_array('clock_in_at', $cols, true) ? 'clock_in_at'
                                : (in_array('start_at', $cols, true) ? 'start_at'
                                    : (in_array('work_date', $cols, true) ? 'work_date' : ''))));

                    $storeCol = in_array('store_id', $cols, true) ? 'store_id' : '';

                    $stepRes['ok'] = true;
                    $stepRes['summary'] = "cols: emp_col=" . ($empCol !== '' ? $empCol : 'none')
                        . " / dt_col=" . ($dtCol !== '' ? $dtCol : 'none')
                        . " / store_col=" . ($storeCol !== '' ? $storeCol : 'none');
                    $results['steps'][] = $stepRes;
                    continue;
                }

                // ✅ stores：1行（設定）
                if ($table === 'stores') {
                    $hasId = in_array('id', $cols, true);
                    if (!$hasId) {
                        $stepRes['error'] = 'stores_has_no_id';
                        $results['steps'][] = $stepRes;
                        continue;
                    }

                    $want = [];
                    foreach (
                        [
                            'business_day_cutoff_time',
                            'payroll_round_unit_minutes',
                            'payroll_closing_day',
                            'payroll_payment_day',
                            'payroll_type',
                        ] as $c
                    ) {
                        if (in_array($c, $cols, true)) $want[] = $c;
                    }

                    $select = ['id'];
                    foreach ($want as $c) $select[] = "`{$c}`";

                    $st = $pdo->prepare("SELECT " . implode(", ", $select) . " FROM stores WHERE id=:sid LIMIT 1");
                    $st->execute([':sid' => $storeId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                    $stepRes['ok'] = true;
                    $stepRes['summary'] = $row ? "found=1" : "found=0";
                    $stepRes['sample_rows'] = $row ? [$row] : [];
                    $results['steps'][] = $stepRes;
                    continue;
                }

                // ✅ pay_periods：最新数件
                if ($table === 'pay_periods') {
                    $idCol = in_array('id', $cols, true) ? 'id' : '';
                    $hasStore = in_array('store_id', $cols, true);
                    if ($idCol === '') {
                        $stepRes['error'] = 'pay_periods_has_no_id';
                        $results['steps'][] = $stepRes;
                        continue;
                    }

                    $select = ["`{$idCol}` AS id"];
                    foreach (['target_month', 'year_month', 'ym', 'start_date', 'end_date', 'period_start', 'period_end', 'from_date', 'to_date'] as $c) {
                        if (in_array($c, $cols, true)) $select[] = "`{$c}`";
                    }
                    if ($hasStore) $select[] = "store_id";

                    $sql = "SELECT " . implode(", ", $select)
                        . " FROM pay_periods"
                        . ($hasStore ? " WHERE store_id=:sid" : "")
                        . " ORDER BY `{$idCol}` DESC LIMIT 5";
                    $st = $pdo->prepare($sql);
                    $st->execute($hasStore ? [':sid' => $storeId] : []);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $stepRes['ok'] = true;
                    $stepRes['summary'] = "rows=" . count($rows);
                    $stepRes['sample_rows'] = $rows;
                    $results['steps'][] = $stepRes;
                    continue;
                }

                // ✅ pay_slips：countだけ
                if ($table === 'pay_slips') {
                    $hasStore = in_array('store_id', $cols, true);
                    $sql = "SELECT COUNT(*) AS cnt FROM pay_slips" . ($hasStore ? " WHERE store_id=:sid" : "");
                    $st = $pdo->prepare($sql);
                    $st->execute($hasStore ? [':sid' => $storeId] : []);
                    $cnt = (int)$st->fetchColumn();
                    $stepRes['ok'] = true;
                    $stepRes['summary'] = "count={$cnt}";
                    $results['steps'][] = $stepRes;
                    continue;
                }

                // ✅ generic：count
                $sql = "SELECT COUNT(*) AS cnt FROM `{$table}`";
                $st = $pdo->query($sql);
                $cnt = $st ? (int)$st->fetchColumn() : 0;
                $stepRes['ok'] = true;
                $stepRes['summary'] = "count={$cnt}";
                $results['steps'][] = $stepRes;
            } catch (Throwable $e) {
                $stepRes['error'] = $e->getMessage();
                $results['steps'][] = $stepRes;
            }
        }

        return $results;
    }
}

if (!function_exists('ha_save_state_detail')) {
    function ha_save_state_detail(PDO $pdo, int $storeId, string $question, array $plan, array $scanResult, bool $debug): int
    {
        try {
            ha_ensure_schema_cache_tables($pdo);

            $pj = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rj = json_encode($scanResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($pj) || $pj === '') $pj = '{}';
            if (!is_string($rj) || $rj === '') $rj = '{}';

            $st = $pdo->prepare("
                INSERT INTO help_ai_state_detail (store_id, question, plan_json, result_json)
                VALUES (:sid, :q, :pj, :rj)
            ");
            $st->execute([
                ':sid' => $storeId,
                ':q' => $question,
                ':pj' => $pj,
                ':rj' => $rj,
            ]);

            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] save_state_detail failed: " . $e->getMessage());
            return 0;
        }
    }
}

/* =========================================================
 * ✅ NEW：pendingランキング & 事実提案（既存維持）
 * ========================================================= */
if (!function_exists('ha_normalize_question_key')) {
    function ha_normalize_question_key(string $q): string
    {
        $q = trim($q);
        if ($q === '') return '';
        $q = preg_replace('/\s+/u', ' ', $q);
        $q = preg_replace('/[０-９]/u', '', $q);

        if (function_exists('mb_strtolower')) {
            $q = mb_strtolower($q, 'UTF-8');
        } else {
            $q = strtolower($q);
        }

        return $q;
    }
}

if (!function_exists('ha_get_pending_ranking')) {
    function ha_get_pending_ranking(PDO $pdo, int $storeId, int $days = 30, int $limit = 20, bool $unresolvedOnly = false): array
    {
        try {
            if ($storeId <= 0) return [];
            if (!ha_tbl_exists($pdo, 'help_ai_pending')) return [];

            $days = max(1, min(365, $days));
            $limit = max(1, min(200, $limit));

            $sql = "
                SELECT id, original_question, followup_question, meta_json, resolved_at, created_at
                FROM help_ai_pending
                WHERE store_id=:sid
                  AND created_at >= (NOW() - INTERVAL {$days} DAY)
            ";
            if ($unresolvedOnly) {
                $sql .= " AND resolved_at IS NULL";
            }
            $sql .= " ORDER BY id DESC LIMIT 5000";

            $st = $pdo->prepare($sql);
            $st->execute([':sid' => $storeId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rows) return [];

            $agg = [];
            foreach ($rows as $r) {
                $oq = (string)($r['original_question'] ?? '');
                $fq = (string)($r['followup_question'] ?? '');
                $mj = (string)($r['meta_json'] ?? '');
                $key = ha_normalize_question_key($oq);
                if ($key === '') $key = ha_normalize_question_key($fq);
                if ($key === '') $key = 'unknown';

                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'key' => $key,
                        'count' => 0,
                        'unresolved' => 0,
                        'last_at' => '',
                        'sample_original' => '',
                        'sample_followup' => '',
                        'fact_key' => '',
                        'meta_samples' => [],
                    ];
                }

                $agg[$key]['count']++;
                if (empty($r['resolved_at'])) $agg[$key]['unresolved']++;

                $ca = (string)($r['created_at'] ?? '');
                if ($agg[$key]['last_at'] === '' || $ca > $agg[$key]['last_at']) {
                    $agg[$key]['last_at'] = $ca;
                }

                if ($agg[$key]['sample_original'] === '' && $oq !== '') $agg[$key]['sample_original'] = $oq;
                if ($agg[$key]['sample_followup'] === '' && $fq !== '') $agg[$key]['sample_followup'] = $fq;

                $meta = [];
                if ($mj !== '') {
                    $tmp = json_decode($mj, true);
                    if (is_array($tmp)) $meta = $tmp;
                }
                $fk = (string)($meta['fact_key'] ?? '');
                if ($agg[$key]['fact_key'] === '' && $fk !== '') $agg[$key]['fact_key'] = $fk;

                if (count($agg[$key]['meta_samples']) < 3 && $mj !== '') {
                    $agg[$key]['meta_samples'][] = $mj;
                }
            }

            $list = array_values($agg);
            usort($list, function ($a, $b) {
                $c = (int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0);
                if ($c !== 0) return $c;
                return strcmp((string)($b['last_at'] ?? ''), (string)($a['last_at'] ?? ''));
            });

            return array_slice($list, 0, $limit);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('ha_make_fact_suggestions_from_pending')) {
    function ha_make_fact_suggestions_from_pending(PDO $pdo, int $storeId, array $ranking, bool $debug): array
    {
        $suggestions = [];

        foreach ($ranking as $r) {
            $factKey = (string)($r['fact_key'] ?? '');
            $sampleO = (string)($r['sample_original'] ?? '');
            $sampleF = (string)($r['sample_followup'] ?? '');
            $count = (int)($r['count'] ?? 0);
            $unresolved = (int)($r['unresolved'] ?? 0);

            if ($count <= 0) continue;

            if (str_starts_with($factKey, 'employee_identity:') || preg_match('/(って誰|どの従業員|特定できません)/u', $sampleF)) {
                $suggestions[] = [
                    'suggestion_key' => 'ADD_TABLE_employee_aliases',
                    'text' =>
                    "従業員の呼び名が原因の pending が多いです。\n"
                        . "提案：employee_aliases（store_id, alias, employee_id, created_at）を追加し、呼び名→従業員IDをDBで解決できるようにする。\n"
                        . "効果：\"◯◯って誰\" の確認質問が激減します。",
                    'evidence' => [
                        'fact_key' => $factKey,
                        'sample_original' => $sampleO,
                        'sample_followup' => $sampleF,
                        'count' => $count,
                        'unresolved' => $unresolved,
                    ],
                ];
                continue;
            }

            if (str_starts_with($factKey, 'store_setting:') || preg_match('/(締め日|支払日|支給日|設定名|どの画面)/u', $sampleF)) {
                $suggestions[] = [
                    'suggestion_key' => 'ADD_TABLE_settings_catalog',
                    'text' =>
                    "ストア設定の所在/文言が原因の pending が多いです。\n"
                        . "提案：settings_catalog（setting_key, ui_label, table_name, column_name, notes）を追加し、UI文言→DB保存先を検索できるようにする。\n"
                        . "効果：\"その設定はどの列？\" の確認質問が減ります。",
                    'evidence' => [
                        'fact_key' => $factKey,
                        'sample_original' => $sampleO,
                        'sample_followup' => $sampleF,
                        'count' => $count,
                        'unresolved' => $unresolved,
                    ],
                ];
                continue;
            }

            if ($factKey === 'openai_followup' || preg_match('/openai_followup/u', $factKey)) {
                $suggestions[] = [
                    'suggestion_key' => 'IMPROVE_KB_OR_DB_HINTS',
                    'text' =>
                    "OpenAIが確認質問を返すケースが多いです。\n"
                        . "提案：① その質問群を help_kb に追記する ② state_cache/state_detail に追加で拾うべき列を増やす（例：storesの給与設定、打刻調整設定、締めルール）。\n"
                        . "効果：OpenAIの確認質問が減り、DB回答率が上がります。",
                    'evidence' => [
                        'fact_key' => $factKey,
                        'sample_original' => $sampleO,
                        'sample_followup' => $sampleF,
                        'count' => $count,
                        'unresolved' => $unresolved,
                    ],
                ];
                continue;
            }
        }

        $uniq = [];
        foreach ($suggestions as $s) {
            $k = (string)($s['suggestion_key'] ?? '');
            if ($k === '') continue;
            if (isset($uniq[$k])) continue;
            $uniq[$k] = $s;
        }

        return array_values($uniq);
    }
}

if (!function_exists('ha_store_fact_suggestions')) {
    function ha_store_fact_suggestions(PDO $pdo, int $storeId, array $suggestions, bool $debug): void
    {
        try {
            if ($storeId <= 0) return;
            ha_ensure_schema_cache_tables($pdo);
            if (!$suggestions) return;

            foreach ($suggestions as $s) {
                if (!is_array($s)) continue;
                $key = (string)($s['suggestion_key'] ?? '');
                $txt = (string)($s['text'] ?? '');
                $ev  = $s['evidence'] ?? null;

                if ($key === '' || $txt === '') continue;

                $st = $pdo->prepare("
                    SELECT 1
                    FROM help_ai_fact_suggestions
                    WHERE store_id=:sid
                      AND suggestion_key=:k
                      AND created_at >= (NOW() - INTERVAL 7 DAY)
                    LIMIT 1
                ");
                $st->execute([':sid' => $storeId, ':k' => $key]);
                if ((int)$st->fetchColumn() === 1) continue;

                $evj = '';
                if ($ev !== null) {
                    $j = json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (is_string($j)) $evj = $j;
                }

                $ins = $pdo->prepare("
                    INSERT INTO help_ai_fact_suggestions (store_id, suggestion_key, suggestion_text, evidence_json)
                    VALUES (:sid, :k, :t, :e)
                ");
                $ins->execute([
                    ':sid' => $storeId,
                    ':k' => $key,
                    ':t' => $txt,
                    ':e' => $evj,
                ]);
            }
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] store suggestions failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('ha_render_pending_ranking_text')) {
    function ha_render_pending_ranking_text(array $ranking, int $days): string
    {
        if (!$ranking) return "pending はありません（直近{$days}日）。";

        $lines = [];
        $lines[] = "pending が多い質問ランキング（直近{$days}日）";
        $lines[] = "----------------------------------------";
        $i = 0;
        foreach ($ranking as $r) {
            $i++;
            $cnt = (int)($r['count'] ?? 0);
            $unr = (int)($r['unresolved'] ?? 0);
            $last = (string)($r['last_at'] ?? '');
            $oq = trim((string)($r['sample_original'] ?? ''));
            $fk = trim((string)($r['fact_key'] ?? ''));

            $lines[] = "{$i}. count={$cnt} / unresolved={$unr} / last={$last}";
            if ($fk !== '') $lines[] = "   fact_key={$fk}";
            if ($oq !== '') $lines[] = "   sample: " . $oq;
            $lines[] = "";
            if ($i >= 20) break;
        }

        return trim(implode("\n", $lines));
    }
}

if (!function_exists('ha_render_fact_suggestions_text')) {
    function ha_render_fact_suggestions_text(array $suggestions): string
    {
        if (!$suggestions) return "提案はありません（pendingの傾向が弱いか、既に対処済みの可能性があります）。";

        $lines = [];
        $lines[] = "DBに追加すべき事実（自動提案）";
        $lines[] = "----------------------------------------";
        $i = 0;

        foreach ($suggestions as $s) {
            $i++;
            $k = (string)($s['suggestion_key'] ?? '');
            $t = (string)($s['text'] ?? '');
            $lines[] = "{$i}. {$k}";
            $lines[] = $t;
            $lines[] = "";
        }

        return trim(implode("\n", $lines));
    }
}

/* =========================================================
 * ✅ NEW：AI管理コマンド（ヘルプチャット経由で見れる）
 * ========================================================= */
if (!function_exists('ha_parse_admin_command')) {
    function ha_parse_admin_command(string $q): ?array
    {
        $q = trim($q);
        if ($q === '') return null;

        if (!preg_match('/^AI管理\s*:\s*(pendingランキング|事実提案)\s*$/u', $q, $m)) return null;

        $cmd = (string)$m[1];
        return ['cmd' => $cmd];
    }
}

/* =========================================================
 * ✅ NEW：給与Q（あなたの版を維持しつつ “emp_id指定” を追加）
 * ========================================================= */
if (!function_exists('ha_parse_pay_question')) {
    function ha_parse_pay_question(string $q): ?array
    {
        $q = trim($q);
        if ($q === '') return null;

        $empId = 0;
        if (preg_match('/\(?\s*emp_id\s*=\s*(\d+)\s*\)?/iu', $q, $m)) {
            $empId = (int)$m[1];
            $q = preg_replace('/\(?\s*emp_id\s*=\s*\d+\s*\)?/iu', '', $q);
            $q = trim((string)$q);
        }

        if (!preg_match('/^(.+?)の\s*(\d{1,2})\s*月.*(給料|給与)/u', $q, $m)) {
            return null;
        }

        $name = trim((string)$m[1]);
        $month = (int)$m[2];
        if ($name === '' || $month < 1 || $month > 12) return null;

        return ['name' => $name, 'month' => $month, 'emp_id' => $empId];
    }
}

if (!function_exists('ha_find_employee_candidates')) {
    function ha_find_employee_candidates(PDO $pdo, int $storeId, string $name, bool $debug): array
    {
        $name = trim($name);
        if ($name === '') return [];

        $tables = ['employees', 'employee_profiles'];

        $cands = [];
        foreach ($tables as $t) {
            if (!ha_tbl_exists($pdo, $t)) continue;

            $cols = ha_get_cols($pdo, $t);
            if (!$cols) continue;

            $idCol = in_array('id', $cols, true) ? 'id' : (in_array('employee_id', $cols, true) ? 'employee_id' : '');
            if ($idCol === '') continue;

            $hasStore = in_array('store_id', $cols, true);

            $nameCols = [];
            foreach (['nickname', 'name', 'display_name', 'full_name', 'kana', 'label'] as $nc) {
                if (in_array($nc, $cols, true)) $nameCols[] = $nc;
            }
            if (!$nameCols) continue;

            foreach ($nameCols as $nc) {
                try {
                    $sql = "SELECT `{$idCol}` AS emp_id, `{$nc}` AS emp_name"
                        . ($hasStore ? ", store_id" : "")
                        . " FROM `{$t}`"
                        . " WHERE `{$nc}` LIKE :q"
                        . ($hasStore ? " AND store_id=:sid" : "")
                        . " ORDER BY `{$idCol}` ASC"
                        . " LIMIT 20";
                    $st = $pdo->prepare($sql);
                    $params = [':q' => '%' . $name . '%'];
                    if ($hasStore) $params[':sid'] = $storeId;
                    $st->execute($params);

                    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    foreach ($rows as $r) {
                        $cands[] = [
                            'table' => $t,
                            'emp_id' => (int)($r['emp_id'] ?? 0),
                            'emp_name' => (string)($r['emp_name'] ?? ''),
                            'store_id' => $hasStore ? (int)($r['store_id'] ?? 0) : null,
                            'name_col' => $nc,
                            'id_col' => $idCol,
                        ];
                    }
                } catch (Throwable $e) {
                    if ($debug) hapilog("[AI_CORE] employee search failed table={$t} col={$nc} err=" . $e->getMessage());
                }
            }
        }

        $cands = array_values(array_filter($cands, fn($x) => (int)($x['emp_id'] ?? 0) > 0));

        $seen = [];
        $uniq = [];
        foreach ($cands as $c) {
            $k = $c['table'] . '#' . $c['emp_id'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $uniq[] = $c;
        }

        return $uniq;
    }
}

if (!function_exists('ha_find_pay_period_for_month')) {
    function ha_find_pay_period_for_month(PDO $pdo, int $storeId, int $month, bool $debug): ?array
    {
        if ($month < 1 || $month > 12) return null;
        if (!ha_tbl_exists($pdo, 'pay_periods')) return null;

        $cols = ha_get_cols($pdo, 'pay_periods');
        if (!$cols) return null;

        $idCol = in_array('id', $cols, true) ? 'id' : '';
        if ($idCol === '') return null;

        $hasStore = in_array('store_id', $cols, true);

        $startCol = in_array('start_date', $cols, true) ? 'start_date'
            : (in_array('period_start', $cols, true) ? 'period_start'
                : (in_array('from_date', $cols, true) ? 'from_date' : ''));

        $endCol = in_array('end_date', $cols, true) ? 'end_date'
            : (in_array('period_end', $cols, true) ? 'period_end'
                : (in_array('to_date', $cols, true) ? 'to_date' : ''));

        $targetMonthCol = in_array('target_month', $cols, true) ? 'target_month'
            : (in_array('year_month', $cols, true) ? 'year_month'
                : (in_array('ym', $cols, true) ? 'ym'
                    : (in_array('month', $cols, true) ? 'month' : '')));

        $pickTop = function (array $rows) use ($startCol, $endCol): ?array {
            if (!$rows) return null;
            $r = $rows[0];
            return [
                'pay_period_id' => (int)($r['pid'] ?? 0),
                'from' => (string)($r['p_from'] ?? ''),
                'to' => (string)($r['p_to'] ?? ''),
                'target_month' => (string)($r['tmon'] ?? ''),
                'store_id' => isset($r['store_id']) ? (int)$r['store_id'] : null,
            ];
        };

        $run = function (string $sql, array $params) use ($pdo, $debug): array {
            try {
                $st = $pdo->prepare($sql);
                $st->execute($params);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                if ($debug) hapilog("[AI_CORE] pay_period query failed: " . $e->getMessage());
                return [];
            }
        };

        $selFrom = ($startCol !== '') ? ", `{$startCol}` AS p_from" : "";
        $selTo   = ($endCol !== '') ? ", `{$endCol}` AS p_to" : "";
        $selTmon = ($targetMonthCol !== '' && in_array($targetMonthCol, $cols, true)) ? ", `{$targetMonthCol}` AS tmon" : "";
        $selStore = $hasStore ? ", store_id" : "";

        $baseSelect = "SELECT `{$idCol}` AS pid{$selFrom}{$selTo}{$selTmon}{$selStore} FROM pay_periods";
        $storeParams = $hasStore ? [':sid' => $storeId] : [];

        $mm2 = str_pad((string)$month, 2, '0', STR_PAD_LEFT);

        if ($targetMonthCol === 'month') {
            $sql = "{$baseSelect} WHERE `{$targetMonthCol}`=:mm" . ($hasStore ? " AND store_id=:sid" : "") . " ORDER BY `{$idCol}` DESC LIMIT 3";
            $rows = $run($sql, array_merge([':mm' => $month], $storeParams));
            if ($rows) return $pickTop($rows);
        }

        if ($targetMonthCol !== '' && $targetMonthCol !== 'month') {
            $sqlA = "{$baseSelect} WHERE `{$targetMonthCol}` LIKE :m" . ($hasStore ? " AND store_id=:sid" : "") . " ORDER BY `{$idCol}` DESC LIMIT 3";
            $rowsA = $run($sqlA, array_merge([':m' => "%-{$mm2}%"], $storeParams));
            if ($rowsA) return $pickTop($rowsA);

            $sqlB = "{$baseSelect} WHERE RIGHT(`{$targetMonthCol}`,2)=:mm2" . ($hasStore ? " AND store_id=:sid" : "") . " ORDER BY `{$idCol}` DESC LIMIT 3";
            $rowsB = $run($sqlB, array_merge([':mm2' => $mm2], $storeParams));
            if ($rowsB) return $pickTop($rowsB);

            $sqlC = "{$baseSelect} WHERE `{$targetMonthCol}` LIKE :m" . ($hasStore ? " AND store_id=:sid" : "") . " ORDER BY `{$idCol}` DESC LIMIT 3";
            $rowsC = $run($sqlC, array_merge([':m' => "%/{$mm2}%"], $storeParams));
            if ($rowsC) return $pickTop($rowsC);
        }

        $dateCols = [];
        if ($startCol !== '') $dateCols[] = $startCol;
        if ($endCol !== '' && $endCol !== $startCol) $dateCols[] = $endCol;

        foreach ($dateCols as $dc) {
            $sql = "{$baseSelect} WHERE MONTH(`{$dc}`)=:mm" . ($hasStore ? " AND store_id=:sid" : "") . " ORDER BY `{$idCol}` DESC LIMIT 3";
            $rows = $run($sql, array_merge([':mm' => $month], $storeParams));
            if ($rows) return $pickTop($rows);
        }

        if ($debug) hapilog("[AI_CORE] pay_period not found month={$month}");
        return null;
    }
}

if (!function_exists('ha_get_pay_slip_amounts')) {
    function ha_get_pay_slip_amounts(PDO $pdo, int $storeId, int $empId, int $payPeriodId, bool $debug): ?array
    {
        if (!ha_tbl_exists($pdo, 'pay_slips')) return null;

        $cols = ha_get_cols($pdo, 'pay_slips');
        if (!$cols) return null;

        $empCol = in_array('employee_id', $cols, true) ? 'employee_id' : (in_array('emp_id', $cols, true) ? 'emp_id' : '');
        $ppCol  = in_array('pay_period_id', $cols, true) ? 'pay_period_id' : (in_array('period_id', $cols, true) ? 'period_id' : '');
        $hasStore = in_array('store_id', $cols, true);

        if ($empCol === '' || $ppCol === '') return null;

        $grossCols = array_values(array_filter(['gross_pay', 'gross_amount', 'total_gross', 'total_pay', 'payment_total', 'salary_total'], fn($c) => in_array($c, $cols, true)));
        $netCols   = array_values(array_filter(['net_pay', 'net_amount', 'take_home', 'takehome', 'payable_amount'], fn($c) => in_array($c, $cols, true)));
        $taxCols   = array_values(array_filter(['withholding_tax', 'tax_withholding', 'income_tax', 'tax'], fn($c) => in_array($c, $cols, true)));
        $dedCols   = array_values(array_filter(['total_deduction', 'deduction_total', 'deductions'], fn($c) => in_array($c, $cols, true)));

        $pickNet = $netCols[0] ?? '';
        $pickGross = $grossCols[0] ?? '';
        $pickTax = $taxCols[0] ?? '';
        $pickDed = $dedCols[0] ?? '';

        $select = [];
        $select[] = "id";
        if ($pickGross !== '') $select[] = "`{$pickGross}` AS gross";
        if ($pickNet   !== '') $select[] = "`{$pickNet}` AS net";
        if ($pickTax   !== '') $select[] = "`{$pickTax}` AS tax";
        if ($pickDed   !== '') $select[] = "`{$pickDed}` AS ded";
        if (in_array('created_at', $cols, true)) $select[] = "created_at";

        $sql = "SELECT " . implode(", ", $select)
            . " FROM pay_slips"
            . " WHERE `{$empCol}`=:eid AND `{$ppCol}`=:pid"
            . ($hasStore ? " AND store_id=:sid" : "")
            . " ORDER BY id DESC"
            . " LIMIT 1";

        try {
            $st = $pdo->prepare($sql);
            $params = [':eid' => $empId, ':pid' => $payPeriodId];
            if ($hasStore) $params[':sid'] = $storeId;
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;

            return [
                'pay_slip_id' => (int)($r['id'] ?? 0),
                'gross' => isset($r['gross']) ? (string)$r['gross'] : '',
                'net'   => isset($r['net'])   ? (string)$r['net']   : '',
                'tax'   => isset($r['tax'])   ? (string)$r['tax']   : '',
                'ded'   => isset($r['ded'])   ? (string)$r['ded']   : '',
                'picked' => [
                    'gross_col' => $pickGross,
                    'net_col' => $pickNet,
                    'tax_col' => $pickTax,
                    'ded_col' => $pickDed,
                    'emp_col' => $empCol,
                    'pay_period_col' => $ppCol,
                ],
            ];
        } catch (Throwable $e) {
            if ($debug) hapilog("[AI_CORE] pay_slips fetch failed err=" . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('ha_answer_pay_question_from_db')) {
    function ha_answer_pay_question_from_db(PDO $pdo, int $storeId, string $question, bool $debug): ?string
    {
        $p = ha_parse_pay_question($question);
        if (!$p) return null;

        $name = (string)$p['name'];
        $month = (int)$p['month'];
        $hintEmpId = (int)($p['emp_id'] ?? 0);

        $emps = ha_find_employee_candidates($pdo, $storeId, $name, $debug);

        if ($hintEmpId > 0) {
            $emps = array_values(array_filter($emps, fn($e) => (int)($e['emp_id'] ?? 0) === $hintEmpId));
            if (count($emps) === 0) {
                return "emp_id={$hintEmpId} が見つかりませんでした。\n"
                    . "（確認）employees / employee_profiles にそのIDが存在するか確認してください。";
            }
        }

        if (count($emps) === 0) {
            return "DBを確認しましたが、従業員名「{$name}」に一致する人が見つかりませんでした。\n"
                . "（確認）employees / employee_profiles に登録名があるか確認してください。";
        }
        if (count($emps) >= 2) {
            $lines = [];
            $lines[] = "同じ名前に複数一致したため、どの「{$name}」か特定できません。";
            $lines[] = "候補：";
            foreach ($emps as $e) {
                $lines[] = "・emp_id=" . (int)$e['emp_id'] . " / name=" . (string)$e['emp_name'] . " / table=" . (string)$e['table'];
            }
            $lines[] = "";
            $lines[] = "どの人か「emp_id」を教えてください。";
            $lines[] = "例）{$name}の{$month}月の給料は？（emp_id=123）";
            return implode("\n", $lines);
        }

        $emp = $emps[0];
        $empId = (int)$emp['emp_id'];
        $empName = (string)$emp['emp_name'];

        $pp = ha_find_pay_period_for_month($pdo, $storeId, $month, $debug);
        if (!$pp || (int)($pp['pay_period_id'] ?? 0) <= 0) {
            return "DBを確認しましたが、{$month}月に該当する pay_periods が見つかりませんでした。\n"
                . "（確認）pay_periods に{$month}月の期間が登録されているか確認してください。";
        }
        $payPeriodId = (int)$pp['pay_period_id'];

        $slip = ha_get_pay_slip_amounts($pdo, $storeId, $empId, $payPeriodId, $debug);
        if (!$slip) {
            $extra = "（確認ポイント）\n"
                . "・pay_slips に employee_id/pay_period_id で紐付くレコードがあるか\n"
                . "・pay_slips の金額列名（net_pay / gross_pay 等）が存在するか";
            return "{$empName}（emp_id={$empId}）の{$month}月の給与をDBから取得できませんでした。\n"
                . "理由：pay_slips から対象レコード（pay_period_id={$payPeriodId}）を特定できません。\n\n"
                . $extra;
        }

        $from = trim((string)($pp['from'] ?? ''));
        $to   = trim((string)($pp['to'] ?? ''));
        $periodStr = ($from !== '' || $to !== '') ? "（期間：{$from}〜{$to}）" : "";

        $gross = trim((string)($slip['gross'] ?? ''));
        $net   = trim((string)($slip['net'] ?? ''));
        $tax   = trim((string)($slip['tax'] ?? ''));
        $ded   = trim((string)($slip['ded'] ?? ''));

        $main = '';
        if ($net !== '') {
            $main = "手取り：{$net}";
        } elseif ($gross !== '') {
            $main = "総支給：{$gross}";
        } else {
            $picked = $slip['picked'] ?? [];
            return "{$empName}（emp_id={$empId}）の{$month}月の pay_slips は見つかりましたが、金額列を特定できませんでした。\n"
                . "（確認）pay_slips の列名が想定と違う可能性があります。\n"
                . "検出した列：gross_col=" . (string)($picked['gross_col'] ?? '') . " / net_col=" . (string)($picked['net_col'] ?? '');
        }

        $lines = [];
        $lines[] = "{$empName} の{$month}月の給料は、DB上は以下でした。{$periodStr}";
        $lines[] = "・{$main}";
        if ($gross !== '' && $net !== '') $lines[] = "・総支給：{$gross}";
        if ($ded !== '') $lines[] = "・控除合計：{$ded}";
        if ($tax !== '') $lines[] = "・源泉（税）：{$tax}";
        $lines[] = "（根拠）pay_slips.id=" . (int)($slip['pay_slip_id'] ?? 0) . " / pay_period_id={$payPeriodId}";

        return implode("\n", $lines);
    }
}

/* =========================================================
 * ✅ DBで特定できない質問に「逆質問」を返す（既存維持 + 出勤対応）
 * ========================================================= */
if (!function_exists('ha_try_answer_or_make_followup')) {
    function ha_try_answer_or_make_followup(PDO $pdo, int $storeId, string $question, bool $debug): ?array
    {
        $q = trim($question);
        if ($q === '') return null;

        // ✅ 出勤数：テーブル特定できない/従業員特定できない場合の pending をここでも用意（最終保険）
        $ap = ha_parse_attendance_question($q);
        if ($ap) {
            $name = (string)($ap['name'] ?? '');
            $follow = "「{$name}」さんの出勤数を出すために、DB上の “出勤記録テーブル” を特定する必要があります。\n"
                . "（確認）次のどれかを教えてください。\n"
                . "1) 出勤記録が入っているテーブル名（例：time_punches）\n"
                . "2) 従業員IDの列名（例：employee_id）\n"
                . "3) 出勤日時/日付の列名（例：punched_at / work_date）\n"
                . "返信形式：回答#<id>: table=..., emp_col=..., dt_col=...";

            return [
                'type' => 'pending',
                'followup' => $follow,
                'meta' => [
                    'fact_key' => "attendance_table_hint",
                ],
            ];
        }

        if (preg_match('/^(.*?)(って|は)\s*誰/u', $q, $m) || preg_match('/^(.+?)\s*は\s*どの従業員/u', $q, $m)) {
            $name = trim((string)($m[1] ?? ''));
            if ($name === '') $name = $q;

            $follow = "「{$name}」がDB上のどの従業員か特定できません。\n"
                . "employees / employee_profiles の id（emp_id）を教えてください。\n"
                . "返信形式：回答#<id>: employees.id=123 のように返信してください。";

            return [
                'type' => 'pending',
                'followup' => $follow,
                'meta' => [
                    'fact_key' => "employee_identity:{$name}",
                ],
            ];
        }

        if (preg_match('/(締め日|支払日|支給日|勤怠の締め)/u', $q)) {
            $follow = "その質問は「ストア設定（締め日/支払日）」の保存先テーブルと列がDB内で特定できませんでした。\n"
                . "（確認）\n"
                . "1) 締め日/支払日はどの画面のどの設定名ですか？（文言そのまま）\n"
                . "2) その設定は store_id ごとに変わりますか？\n"
                . "返信形式：回答#<id>: 〜〜";
            return [
                'type' => 'pending',
                'followup' => $follow,
                'meta' => [
                    'fact_key' => "store_setting:payroll_closing_payment",
                ],
            ];
        }

        return null;
    }
}


/* =========================================================
 * ✅ 置換：help_ai_answer_from_kb() の「途中で途切れていた箇所」以降を完成
 * - 回答#id: の処理を最後まで実装（元質問を取り出して再実行）
 * - 「給与より前」に出勤数DB回答を実行（要件 #7）
 * - pending をDBに作成して「回答#id:」形式で返す
 * ========================================================= */

if (!function_exists('help_ai_answer_from_kb')) {
    function help_ai_answer_from_kb(PDO $pdo, int $storeId, string $question, bool $debug = false): array
    {
        // ✅ 再入防止（pending回答→元質問再実行の無限ループ抑止）
        static $depth = 0;
        $depth++;
        if ($depth > 3) {
            $depth--;
            return [
                'ok' => false,
                'answer' => "処理がループする可能性があるため中断しました。（depth>3）",
                'kb_hits' => 0,
                'used_openai' => 0,
            ];
        }

        $questionRaw = (string)$question;
        $question = ha_force_utf8(trim($questionRaw), $debug);

        if ($storeId <= 0) {
            $depth--;
            return ['ok' => false, 'error' => 'store_id_missing', 'used_openai' => 0];
        }
        if ($question === '') {
            $depth--;
            return ['ok' => false, 'error' => 'empty_question', 'used_openai' => 0];
        }

        ha_ensure_schema_cache_tables($pdo);

        // ✅ 0) AI管理コマンド（ランキング/提案）
        $cmd = ha_parse_admin_command($question);
        if ($cmd) {
            $days = 30;

            if (($cmd['cmd'] ?? '') === 'pendingランキング') {
                $ranking = ha_get_pending_ranking($pdo, $storeId, $days, 20, false);
                $depth--;
                return [
                    'ok' => true,
                    'answer' => ha_render_pending_ranking_text($ranking, $days),
                    'kb_hits' => 0,
                    'used_openai' => 0,
                ];
            }

            if (($cmd['cmd'] ?? '') === '事実提案') {
                $ranking = ha_get_pending_ranking($pdo, $storeId, $days, 50, false);
                $suggestions = ha_make_fact_suggestions_from_pending($pdo, $storeId, $ranking, $debug);
                ha_store_fact_suggestions($pdo, $storeId, $suggestions, $debug);

                $depth--;
                return [
                    'ok' => true,
                    'answer' => ha_render_fact_suggestions_text($suggestions),
                    'kb_hits' => 0,
                    'used_openai' => 0,
                ];
            }
        }

        // ✅ スキーマ/状態スナップショット（壊さない）
        ha_capture_schema_snapshot($pdo, $debug);
        ha_capture_state_snapshot($pdo, $storeId, $debug);

        // ✅ 1) 質問 → 調査プラン作成 → DBスキャン → state_detail保存（毎回）
        $plan = ha_build_investigation_plan($pdo, $storeId, $question, $debug);
        $scan = ha_scan_db_by_plan($pdo, $storeId, $question, $plan, $debug);
        ha_save_state_detail($pdo, $storeId, $question, $plan, $scan, $debug);

        // ✅ 2) ユーザー回答（回答#id: ...）→ facts保存 → 元質問を再実行して回答を返す
        $ua = ha_parse_user_answer($question);
        if ($ua) {
            $pid = (int)($ua['pending_id'] ?? 0);
            $ans = (string)($ua['answer'] ?? '');

            $ok = ($pid > 0 && $ans !== '') ? ha_save_fact_from_answer($pdo, $storeId, $pid, $ans, $debug) : false;

            // ✅ 元質問を取得（存在すればそれを再実行）
            $origQ = '';
            try {
                if (ha_tbl_exists($pdo, 'help_ai_pending')) {
                    $st = $pdo->prepare("SELECT original_question FROM help_ai_pending WHERE id=:id AND store_id=:sid LIMIT 1");
                    $st->execute([':id' => $pid, ':sid' => $storeId]);
                    $origQ = (string)($st->fetchColumn() ?: '');
                }
            } catch (Throwable $e) {
                // noop
            }

            if ($origQ !== '') {
                // ✅ 元質問で再実行（depthガードあり）
                $res = help_ai_answer_from_kb($pdo, $storeId, $origQ, $debug);
                $depth--;
                return [
                    'ok' => true,
                    'answer' => ($ok ? "回答を登録しました。続けて元の質問に答えます。\n\n" : "回答の登録に失敗しましたが、元の質問に再挑戦します。\n\n")
                        . (string)($res['answer'] ?? ''),
                    'kb_hits' => (int)($res['kb_hits'] ?? 0),
                    'used_openai' => (int)($res['used_openai'] ?? 0),
                ];
            }

            $depth--;
            return [
                'ok' => $ok,
                'answer' => $ok
                    ? "回答を登録しました。（pending#{$pid}）\n※元の質問が特定できなかったため、もう一度質問文を送ってください。"
                    : "回答の登録に失敗しました。（pending#{$pid}）\nDB権限/テーブル存在を確認してください。",
                'kb_hits' => 0,
                'used_openai' => 0,
            ];
        }

        // ✅ 3) DBで即答できるもの（給与より前に “出勤数” を呼ぶ：要件 #7）
        $attAns = ha_answer_attendance_count_from_db($pdo, $storeId, $question, $debug);
        if (is_string($attAns) && trim($attAns) !== '') {
            $depth--;
            return [
                'ok' => true,
                'answer' => $attAns,
                'kb_hits' => 0,
                'used_openai' => 0,
            ];
        }

        $payAns = ha_answer_pay_question_from_db($pdo, $storeId, $question, $debug);
        if (is_string($payAns) && trim($payAns) !== '') {
            $depth--;
            return [
                'ok' => true,
                'answer' => $payAns,
                'kb_hits' => 0,
                'used_openai' => 0,
            ];
        }

        // ✅ 4) DBで特定できない質問 → pending を作って “回答#id:” を返す
        $fu = ha_try_answer_or_make_followup($pdo, $storeId, $question, $debug);
        if (is_array($fu) && ($fu['type'] ?? '') === 'pending') {
            $follow = (string)($fu['followup'] ?? '');
            $meta   = is_array($fu['meta'] ?? null) ? (array)$fu['meta'] : [];

            $pid = ha_create_pending($pdo, $storeId, $question, $follow, $meta, $debug);
            if ($pid <= 0) {
                // pending 作成自体が失敗した場合は、最低限 followup だけ返す
                $depth--;
                return [
                    'ok' => true,
                    'answer' => $follow,
                    'kb_hits' => 0,
                    'used_openai' => 0,
                ];
            }

            $depth--;
            return [
                'ok' => true,
                'answer' =>
                "確認が必要です。（pending#{$pid}）\n"
                    . "次の形式で返信してください：\n"
                    . "回答#{$pid}: （ここに回答）\n\n"
                    . $follow,
                'kb_hits' => 0,
                'used_openai' => 0,
            ];
        }

        // ✅ 5) （任意）KB検索：help_kb があれば LIKE で当てる（無ければスキップ）
        // ※ ここは “推測” をしないため、テーブル/列が無い場合は何もしません。
        try {
            if (ha_tbl_exists($pdo, 'help_kb')) {
                $cols = ha_get_cols($pdo, 'help_kb');

                // 想定列：keywords, answer（または title/body 等）
                $kwCol = in_array('keywords', $cols, true) ? 'keywords' : (in_array('keyword', $cols, true) ? 'keyword' : '');
                $ansCol = in_array('answer', $cols, true) ? 'answer' : (in_array('body', $cols, true) ? 'body' : (in_array('content', $cols, true) ? 'content' : ''));
                $hasStore = in_array('store_id', $cols, true);

                if ($kwCol !== '' && $ansCol !== '') {
                    $sql = "SELECT `{$ansCol}` AS ans"
                        . " FROM help_kb"
                        . " WHERE `{$kwCol}` LIKE :q"
                        . ($hasStore ? " AND store_id=:sid" : "")
                        . " ORDER BY id DESC"
                        . " LIMIT 1";
                    $st = $pdo->prepare($sql);
                    $params = [':q' => '%' . $question . '%'];
                    if ($hasStore) $params[':sid'] = $storeId;
                    $st->execute($params);
                    $hit = (string)($st->fetchColumn() ?: '');

                    if (trim($hit) !== '') {
                        $depth--;
                        return [
                            'ok' => true,
                            'answer' => $hit,
                            'kb_hits' => 1,
                            'used_openai' => 0,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // noop（KBは任意）
        }

        // ✅ 6) 最後：ミスログだけ残して “分からない” を返す（OpenAI呼び出しはこのファイル内では推測実装しない）
        ha_log_miss($pdo, $storeId, $question, $debug);

        $depth--;
        return [
            'ok' => true,
            'answer' =>
            "この質問は、DBとKBだけでは確実に答えを特定できませんでした。\n"
                . "（お願い）どの画面のどの機能の話か、もう少し具体的に教えてください。\n"
                . "例：画面名 / ボタン名 / 対象の従業員 / 対象の月 など。",
            'kb_hits' => 0,
            'used_openai' => 0,
        ];
    }
}


/* =========================
 * ✅ 追記：日付解決（今日/昨日/明日）
 * ========================= */
function ha_today_jst(): DateTimeImmutable
{
    // ✅ 「今日」をユーザーに聞かない：サーバ側JSTで確定
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
}

function ha_resolve_relative_day_key(string $word): string
{
    // ✅ "今日" などの相対語を YYYY-MM-DD に確定
    $now = ha_today_jst();

    $w = trim(mb_strtolower($word));
    if ($w === '今日' || $w === 'きょう' || $w === '本日') {
        return $now->format('Y-m-d');
    }
    if ($w === '昨日' || $w === 'きのう') {
        return $now->modify('-1 day')->format('Y-m-d');
    }
    if ($w === '明日' || $w === 'あした') {
        return $now->modify('+1 day')->format('Y-m-d');
    }

    // ✅ それ以外はそのまま返す（上位で検証）
    return $word;
}

/* =========================
 * ✅ 追記：DBメタ情報からテーブル/列を特定（推測しない）
 * ========================= */
function ha_find_table_and_columns(PDO $pdo, string $schema, array $tableCandidates, array $requiredColsAnyOf): array
{
    /**
     * requiredColsAnyOf 例：
     * [
     *   'staff_id' => ['staff_id','employee_id','user_id'],
     *   'punched_at' => ['punched_at','created_at','entered_at','punch_time'],
     *   'punch_type' => ['punch_type','type','kind','action'],
     * ]
     *
     * ✅ 返り値：
     * - ['ok'=>true,'table'=>'time_punches','cols'=>['staff_id'=>'staff_id',...]]
     * - ['ok'=>false,'error'=>'...','debug'=>[...] ]
     */

    $debug = [
        'schema' => $schema,
        'checked' => [],
        'candidates' => $tableCandidates,
        'requiredColsAnyOf' => $requiredColsAnyOf,
    ];

    $inQ = implode(',', array_fill(0, count($tableCandidates), '?'));
    $sql = "SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME IN ($inQ)";
    $params = array_merge([$schema], $tableCandidates);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [
            'ok' => false,
            'error' => '候補テーブルが見つかりませんでした（information_schemaで0件）',
            'debug' => $debug,
        ];
    }

    $byTable = [];
    foreach ($rows as $r) {
        $t = (string)$r['TABLE_NAME'];
        $c = (string)$r['COLUMN_NAME'];
        $byTable[$t][] = $c;
    }

    // ✅ required の各キーが「どれか1つ」存在するテーブルを採用
    foreach ($byTable as $table => $cols) {
        $map = [];
        $ok = true;

        foreach ($requiredColsAnyOf as $needKey => $alts) {
            $found = null;
            foreach ($alts as $alt) {
                if (in_array($alt, $cols, true)) {
                    $found = $alt;
                    break;
                }
            }
            if ($found === null) {
                $ok = false;
                break;
            }
            $map[$needKey] = $found;
        }

        $debug['checked'][] = ['table' => $table, 'cols' => $cols, 'mapped' => $map, 'ok' => $ok];

        if ($ok) {
            return ['ok' => true, 'table' => $table, 'cols' => $map, 'debug' => $debug];
        }
    }

    return [
        'ok' => false,
        'error' => '候補テーブルは見つかりましたが、必要列が揃うテーブルを特定できませんでした',
        'debug' => $debug,
    ];
}

/* =========================
 * ✅ 追記：未打刻一覧（氏名/状態/最終打刻）
 * ========================= */
function ha_list_undakoku_today(PDO $pdo, int $tenantId, int $storeId, string $schema, string $dayYmd): array
{
    /**
     * ✅ 「未打刻」の定義（最低限）
     * - time_punches から「当日最終打刻」が IN 系で終わっている → 退勤未打刻（疑い）
     * - 休憩系が最後 → 休憩戻り未打刻（疑い）
     *
     * ✅ DBに punch_type が無い場合は「最終打刻の時刻」までは出せるが状態分類は不可 → pending扱いで返す
     */

    // 1) time_punches を特定
    $tp = ha_find_table_and_columns(
        $pdo,
        $schema,
        ['time_punches', 'punches', 'timecards', 'attendance_punches'],
        [
            'staff_id'   => ['staff_id', 'employee_id', 'user_id'],
            'punched_at' => ['punched_at', 'punch_time', 'created_at', 'entered_at'],
            // punch_type が見つからなかったら「状態分類ができない」ので後で分岐する
        ]
    );
    if (!$tp['ok']) {
        return [
            'ok' => false,
            'error' => '未打刻一覧の起点（打刻テーブル）を特定できませんでした',
            'details' => $tp,
        ];
    }

    // punch_type は optional（あれば状態精度が上がる）
    $tpType = ha_find_table_and_columns(
        $pdo,
        $schema,
        [$tp['table']],
        [
            'punch_type' => ['punch_type', 'type', 'kind', 'action', 'punch_kind'],
        ]
    );
    $hasPunchType = (bool)$tpType['ok'];
    $punchTypeCol = $hasPunchType ? $tpType['cols']['punch_type'] : null;

    $tpTable = $tp['table'];
    $staffIdCol = $tp['cols']['staff_id'];
    $punchedAtCol = $tp['cols']['punched_at'];

    // 2) スタッフの氏名テーブルを特定（推測しない：候補から情報schemaで確定）
    $st = ha_find_table_and_columns(
        $pdo,
        $schema,
        ['staffs', 'staff', 'employees', 'employee_profiles', 'users', 'accounts'],
        [
            'id'   => ['id', 'staff_id', 'employee_id', 'user_id'],
            'name' => ['name', 'full_name', 'display_name', 'nickname', 'staff_name', 'employee_name'],
        ]
    );
    if (!$st['ok']) {
        return [
            'ok' => false,
            'error' => '氏名の取得元（スタッフテーブル）を特定できませんでした',
            'details' => $st,
        ];
    }

    $staffTable = $st['table'];
    $staffPkCol = $st['cols']['id'];
    $staffNameCol = $st['cols']['name'];

    // 3) 当日分の「スタッフごとの最終打刻」を取る
    // ✅ store_id / tenant_id が存在するかも不明なので、存在チェックしてからWHEREに入れる（推測しない）
    $colCheckSql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=:sc AND TABLE_NAME=:tn";
    $colStmt = $pdo->prepare($colCheckSql);
    $colStmt->execute([':sc' => $schema, ':tn' => $tpTable]);
    $tpCols = array_map(fn($r) => (string)$r['COLUMN_NAME'], $colStmt->fetchAll(PDO::FETCH_ASSOC));

    $where = [];
    $params = [];

    // 日付範囲（JSTの暦日で確定）
    $start = $dayYmd . ' 00:00:00';
    $end   = $dayYmd . ' 23:59:59';

    $where[] = "p.$punchedAtCol BETWEEN :start AND :end";
    $params[':start'] = $start;
    $params[':end'] = $end;

    // tenant_id / store_id があれば絞る
    if (in_array('tenant_id', $tpCols, true)) {
        $where[] = "p.tenant_id = :tenantId";
        $params[':tenantId'] = $tenantId;
    }
    if (in_array('store_id', $tpCols, true)) {
        $where[] = "p.store_id = :storeId";
        $params[':storeId'] = $storeId;
    }

    $whereSql = implode(' AND ', $where);

    // ✅ 最終打刻をサブクエリで取得
    $selectType = $hasPunchType ? ", p.$punchTypeCol AS punch_type" : ", NULL AS punch_type";

    $sql = "
        SELECT
            p.$staffIdCol AS staff_id,
            p.$punchedAtCol AS last_punched_at
            $selectType
        FROM $tpTable p
        INNER JOIN (
            SELECT
                $staffIdCol AS staff_id,
                MAX($punchedAtCol) AS max_punched_at
            FROM $tpTable
            WHERE $whereSql
            GROUP BY $staffIdCol
        ) x
          ON x.staff_id = p.$staffIdCol
         AND x.max_punched_at = p.$punchedAtCol
        ORDER BY p.$punchedAtCol DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lastRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$lastRows) {
        return [
            'ok' => true,
            'day' => $dayYmd,
            'items' => [],
            'note' => '本日の打刻データが0件でした（出勤者がいない/まだ誰も打刻していない等）',
            'meta' => [
                'punch_table' => $tpTable,
                'has_punch_type' => $hasPunchType,
            ],
        ];
    }

    // 4) 氏名をJOINして返す（JOINキーも推測しない：id列名で確定済み）
    // ✅ staff_id の型が違ってもJOINできるよう、CASTはしない（勝手に変換しない）
    $ids = [];
    foreach ($lastRows as $r) {
        $ids[] = $r['staff_id'];
    }

    // IN句（数が多い場合は分割も必要だが、まずは通常想定）
    $in = implode(',', array_fill(0, count($ids), '?'));
    $nameSql = "SELECT $staffPkCol AS id, $staffNameCol AS name FROM $staffTable WHERE $staffPkCol IN ($in)";
    $nameStmt = $pdo->prepare($nameSql);
    $nameStmt->execute($ids);
    $nameMap = [];
    foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $nr) {
        $nameMap[(string)$nr['id']] = (string)$nr['name'];
    }

    // 5) 状態判定（punch_type が無い場合は pending）
    $items = [];
    foreach ($lastRows as $r) {
        $sid = (string)$r['staff_id'];
        $name = $nameMap[$sid] ?? ('ID:' . $sid);

        $lastAt = (string)$r['last_punched_at'];
        $ptype = $r['punch_type'];

        $state = '判定不可';
        if ($hasPunchType) {
            $pt = mb_strtoupper(trim((string)$ptype));

            // ✅ よくあるパターンを広めに拾う（型の推測ではなく「値の正規化」）
            $inLike = ['IN', 'CLOCK_IN', 'START', 'ENTER', 'WORK_IN', '出勤', '入店'];
            $outLike = ['OUT', 'CLOCK_OUT', 'END', 'LEAVE', 'WORK_OUT', '退勤', '退店'];
            $breakInLike = ['BREAK_IN', 'BREAK_START', 'REST_IN', '休憩入り', '休憩開始'];
            $breakOutLike = ['BREAK_OUT', 'BREAK_END', 'REST_OUT', '休憩戻り', '休憩終了'];

            if (in_array($pt, $inLike, true)) {
                $state = '退勤未打刻の疑い（最終=出勤系）';
            } elseif (in_array($pt, $breakInLike, true)) {
                $state = '休憩戻り未打刻の疑い（最終=休憩入り）';
            } elseif (in_array($pt, $outLike, true) || in_array($pt, $breakOutLike, true)) {
                $state = '正常（最終=退勤/休憩戻り）';
            } else {
                $state = '要確認（最終打刻種別が未知: ' . (string)$ptype . '）';
            }
        } else {
            // ✅ punch_type 列が無いので分類できない（推測禁止）
            $state = '判定不可（punch_type列が特定できないため）';
        }

        // ✅ 「未打刻スタッフ」＝「疑い」だけに絞る（正常は除外）
        $isSuspicious = (str_contains($state, '未打刻') || str_contains($state, '判定不可') || str_contains($state, '要確認'));
        if (!$isSuspicious) {
            continue;
        }

        $items[] = [
            'name' => $name,
            'state' => $state,
            'last_punch' => $lastAt,
        ];
    }

    return [
        'ok' => true,
        'day' => $dayYmd,
        'items' => $items,
        'meta' => [
            'punch_table' => $tpTable,
            'punch_staff_id_col' => $staffIdCol,
            'punched_at_col' => $punchedAtCol,
            'has_punch_type' => $hasPunchType,
            'punch_type_col' => $punchTypeCol,
            'staff_table' => $staffTable,
            'staff_name_col' => $staffNameCol,
        ],
    ];
}

/* =========================
 * ✅ 差し込み：質問ハンドリング
 * （help_ai_answer_from_kb() の中の「給与/源泉/明細」より前など、早めに判定）
 * =========================
 *
 * 既存の help_ai_answer_from_kb($q, ...) の中で、
 * 「DBから答える系（出勤数など）」の分岐がある場所に、下の if を追加してください。
 *
 * 例）$qNorm = ...; の後あたり
 *
 * if (ha_is_undakoku_list_question($qNorm)) { ... }
 */
function ha_is_undakoku_list_question(string $q): bool
{
    $qq = trim($q);
    // ✅ 「今日」「本日」＋「未打刻」＋「一覧」あたりを拾う
    if ($qq === '') return false;
    $hasToday = (mb_strpos($qq, '今日') !== false) || (mb_strpos($qq, '本日') !== false) || (mb_strpos($qq, 'きょう') !== false);
    $hasUnda = (mb_strpos($qq, '未打刻') !== false) || (mb_strpos($qq, '打刻漏れ') !== false);
    $hasList = (mb_strpos($qq, '一覧') !== false) || (mb_strpos($qq, 'リスト') !== false) || (mb_strpos($qq, '出して') !== false);
    return $hasToday && $hasUnda && $hasList;
}

/**
 * ✅ ここは既存の help_ai_answer_from_kb() 内で呼ぶ想定の「実行部」。
 * - $pdo, $tenantId, $storeId, $schema は既存の文脈に合わせて渡してください。
 */
function ha_answer_undakoku_today(PDO $pdo, int $tenantId, int $storeId, string $schema, string $q): array
{
    $day = ha_resolve_relative_day_key('今日'); // ✅ 今日を確定（質問から抽出せずに固定でOK）
    // 念のため YYYY-MM-DD 形式チェック（形式が崩れることは通常ない）
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        return [
            'ok' => false,
            'error' => '日付解決に失敗しました（サーバ時刻）',
            'day' => $day,
        ];
    }

    $res = ha_list_undakoku_today($pdo, $tenantId, $storeId, $schema, $day);
    if (!$res['ok']) {
        // ✅ 「DBを駆け巡ったか？」への答え：debugも返す（管理者向け）
        return [
            'ok' => false,
            'answer' => "DBを探索しましたが、未打刻一覧に必要なテーブル/列を特定できませんでした。",
            'details' => $res,
        ];
    }

    // ✅ 返答をテキスト整形（氏名/状態/最終打刻）
    $lines = [];
    $lines[] = "対象日: {$res['day']}";
    if (empty($res['items'])) {
        $lines[] = "未打刻（疑い）スタッフ: 0人";
    } else {
        $lines[] = "未打刻（疑い）スタッフ: " . count($res['items']) . "人";
        $lines[] = "----";
        foreach ($res['items'] as $it) {
            $lines[] = "氏名: {$it['name']} / 状態: {$it['state']} / 最終打刻: {$it['last_punch']}";
        }
    }

    return [
        'ok' => true,
        'answer' => implode("\n", $lines),
        'meta' => $res['meta'],
    ];
}
