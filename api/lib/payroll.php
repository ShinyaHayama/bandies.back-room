<?php

declare(strict_types=1);

/**
 * ✅ /lib/payroll.php（不足している基盤関数の「互換レイヤー」）
 *
 * 目的：
 * - あなたの環境で「存在しない」と言われた関数を、既存実装を壊さずに補完する
 *   - fl_effective_tenant_id()
 *   - fl_effective_store_id()
 *   - get_pdo()
 *   - fl_db()
 *
 * 方針（推測しない）：
 * - 既に同名関数が存在する場合は「何もしない」（上書きしない）
 * - tenant_id は POST/SESSION から取れないなら 0 を返す（捏造しない）
 * - store_id は取れないなら 0 を返す（呼び出し側で null 変換できる）
 * - PDO は「db.php 側が提供している関数/グローバル」を探すだけ（勝手に接続しない）
 *
 * 使い方：
 * - require_once __DIR__ . '/payroll.php'; を auth/db の後に読み込むのがおすすめ
 */

if (!function_exists('fl_payroll_ensure_session')) {
    function fl_payroll_ensure_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}

/**
 * ✅ tenant_id を「確実に取れるルート」からだけ取得
 */
if (!function_exists('fl_effective_tenant_id')) {
    function fl_effective_tenant_id(): int
    {
        fl_payroll_ensure_session();

        // 1) POST 明示（API向け）
        if (isset($_POST['tenant_id']) && is_scalar($_POST['tenant_id'])) {
            return (int)$_POST['tenant_id'];
        }

        // 2) SESSION（管理画面ログイン等）
        if (isset($_SESSION['tenant_id'])) return (int)$_SESSION['tenant_id'];
        if (isset($_SESSION['tenantId']))  return (int)$_SESSION['tenantId'];

        // 3) GET（共有URL等で使う場合がある）
        if (isset($_GET['tenant_id']) && is_scalar($_GET['tenant_id'])) {
            return (int)$_GET['tenant_id'];
        }

        return 0; // ✅ 不明なら 0（推測しない）
    }
}

/**
 * ✅ store_id を「確実に取れるルート」からだけ取得
 */
if (!function_exists('fl_effective_store_id')) {
    function fl_effective_store_id(): int
    {
        fl_payroll_ensure_session();

        // 1) POST 明示
        if (isset($_POST['store_id']) && is_scalar($_POST['store_id'])) {
            return (int)$_POST['store_id'];
        }

        // 2) SESSION
        if (isset($_SESSION['store_id'])) return (int)$_SESSION['store_id'];
        if (isset($_SESSION['storeId']))  return (int)$_SESSION['storeId'];

        // 3) GET
        if (isset($_GET['store_id']) && is_scalar($_GET['store_id'])) {
            return (int)$_GET['store_id'];
        }

        return 0; // ✅ 不明なら 0（推測しない）
    }
}

/**
 * ✅ PDO を「既存のdb.php実装」からだけ取得する
 * - ここで勝手に接続を作らない（設定不明なので推測しない）
 */
if (!function_exists('get_pdo')) {
    function get_pdo(): ?PDO
    {
        // よくある関数名を順に探す（存在する場合のみ呼ぶ）
        foreach (['fl_db', 'db', 'pdo'] as $fn) {
            if (!function_exists($fn)) continue;
            try {
                $tmp = $fn();
                if ($tmp instanceof PDO) return $tmp;
            } catch (Throwable $e) {
                // 次へ
            }
        }

        // グローバルに置かれているケース
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        if (isset($GLOBALS['PDO']) && $GLOBALS['PDO'] instanceof PDO) return $GLOBALS['PDO'];

        return null;
    }
}

/**
 * ✅ fl_db が無い環境用の互換
 * - get_pdo() を返すだけ
 */
if (!function_exists('fl_db')) {
    function fl_db(): PDO
    {
        $pdo = get_pdo();
        if (!($pdo instanceof PDO)) {
            // ✅ ここは「原因が分かる」例外にする（握りつぶさない）
            throw new RuntimeException('PDO not available: check /lib/db.php implementation (db()/pdo() or $GLOBALS["pdo"])');
        }
        return $pdo;
    }
}