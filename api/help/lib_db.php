<?php

declare(strict_types=1);

/**
 * ✅ DB 接続（ヘルプAPI用）
 * - 既存の db.php / api/lib/db.php がある前提なら、そちらを呼ぶだけでもOK
 * - 今回は「helpdb_get_pdo() が未定義」で死ぬのを止めるための“確実に動く逃げ道”を用意
 *
 * ⚠️ 必ずあなたの環境のDB接続情報に合わせて調整してください
 * - すでに共通の PDO 生成関数があるなら、そこへ寄せてください
 */

function helpdb_get_pdo(): PDO
{
    // ✅ 既存の共通DB関数があるならそれを優先して使う
    // 例: function db(): PDO; などがプロジェクトに存在する場合
    if (function_exists('db')) {
        /** @var PDO $pdo */
        $pdo = db();
        return $pdo;
    }

    // ✅ 既存の db.php を探して読み込む（あなたの構成に合わせて増やしてOK）
    $candidates = [
        __DIR__ . '/../lib/db.php',
        __DIR__ . '/../../lib/db.php',
        __DIR__ . '/../api/lib/db.php',
        __DIR__ . '/../../api/lib/db.php',
    ];
    foreach ($candidates as $f) {
        if (is_file($f)) {
            require_once $f;

            // 読み込んだ結果、db() が生えたらそれを使う
            if (function_exists('db')) {
                /** @var PDO $pdo */
                $pdo = db();
                return $pdo;
            }
        }
    }

    // ✅ 最終手段：このファイル単体でPDOを作る
    // ---- ここはあなたの環境に合わせて必ず設定してください ----
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';
    // -------------------------------------------------------

    if ($name === '' || $user === '') {
        throw new RuntimeException('helpdb_get_pdo(): DB_NAME / DB_USER が未設定です（環境変数 or 共通db.phpを用意してください）');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}