<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/_db.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 *
 * ✅ 目的（あなたの現状に合わせて最小で安定化）
 * - /api/lib/db.php の db():PDO を /admin 側から共通で呼べるようにする
 * - admin 側の「$pdo を参照する実装」を壊さない
 * - pay_slip_pdf.php 側が「function_exists('db')」で拾えるようにする（重要）
 *
 * ✅ ポイント
 * - あなたのプロジェクトには az_db() は存在しない → 使わない
 * - 正解は db()（/api/lib/db.php で定義されている）
 * - 互換として AdminDb::pdo() / $pdo / $GLOBALS['pdo'] を全部用意
 */

require_once dirname(__DIR__) . '/api/lib/db.php';

final class AdminDb
{
    public static function pdo(): PDO
    {
        // ✅ 正式なPDO取得関数（api側）
        return db();
    }
}

/**
 * ✅ 既存 admin 画面互換
 * - $pdo を期待しているコードがあるため、必ず用意
 * - さらに「別ファイルから $GLOBALS['pdo'] を拾う」ケースもあるため入れておく
 */
$pdo = AdminDb::pdo();
$GLOBALS['pdo'] = $pdo;

/**
 * ✅ 追加互換（任意だが安全）
 * - /admin 側で AdminDb を知らないコードが "db()" を呼んでしまう事故を避ける
 * - ※ api/lib/db.php に既に db() があるので、ここでは何もしない（定義しない）
 */