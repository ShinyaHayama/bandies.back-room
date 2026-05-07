<?php
declare(strict_types=1);

/**
 * ✅ /super/help_kb.php（方針A：全テナント共通KB）
 *
 * 進め方（要点）:
 * 1) DB: help_kb から tenant_id を削除（ALTER）
 * 2) 画面: tenant_id / なりすまし依存を全部撤去
 * 3) 認証: /super のログインセッションキー(super_admin_ok等)で判定
 * 4) 500対策: ob_start + ログ + debug=1 で原因が必ず見える
 *
 * 使い方:
 * - /super/help_kb.php
 * - うまく表示できない時: /super/help_kb.php?debug=1
 */

$__dbg = (((string)($_GET['debug'] ?? '')) === '1');

// 先にバッファ開始（_db.php 側の余計な出力対策）
ob_start();

ini_set('display_errors', $__dbg ? '1' : '0');
ini_set('display_startup_errors', $__dbg ? '1' : '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

// ===== ログ =====
$__logDir = __DIR__ . '/logs';
@mkdir($__logDir, 0777, true);
$__logFile = $__logDir . '/help_kb.log';

function kb_log(string $msg): void
{
    global $__logFile;
    @file_put_contents($__logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function kb_die(string $msg, int $code = 500): void
{
    global $__dbg;
    kb_log('[DIE] ' . $msg);

    if (!headers_sent()) {
        http_response_code($code);
        if ($__dbg) header('Content-Type: text/plain; charset=utf-8');
    }

    echo $__dbg ? $msg : 'Internal Server Error';

    if (ob_get_level() > 0) @ob_end_flush();
    exit;
}

set_exception_handler(function (Throwable $e) {
    kb_log('[EXCEPTION] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    kb_die("EXCEPTION:\n" . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine(), 500);
});

register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e) return;
    $isFatal = in_array((int)($e['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    if (!$isFatal) return;

    kb_log('[FATAL] ' . ($e['message'] ?? '') . ' @ ' . ($e['file'] ?? '') . ':' . ($e['line'] ?? 0));
    kb_die("FATAL:\n" . ($e['message'] ?? '') . "\n" . ($e['file'] ?? '') . ':' . ($e['line'] ?? 0), 500);
});

kb_log('--- boot uri=' . (string)($_SERVER['REQUEST_URI'] ?? '') . ' dbg=' . ($__dbg ? '1' : '0'));

// ===== require =====
$authFile = __DIR__ . '/_auth.php';
$dbFile   = __DIR__ . '/_db.php';

if (!is_file($authFile)) kb_die("missing file: {$authFile}");
if (!is_file($dbFile))   kb_die("missing file: {$dbFile}");

require_once $authFile;
kb_log('loaded _auth.php');

require_once $dbFile;
kb_log('loaded _db.php');

// h() 二重定義対策（_db.php 側にある前提でも落ちない）
if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
    kb_log('defined h()');
}

// ===== session =====
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
    kb_log('session_start()');
}

// ===== auth（あなたのログより：super_admin_ok が鍵）=====
// あなたの debug 出力に出ていたキー:
// SESSION_KEYS=super_csrf_token,super_admin_ok,super_admin_login_at
$loggedIn = !empty($_SESSION['super_admin_ok']);
if (!$loggedIn) {
    kb_log('not logged in: super_admin_ok is empty -> redirect');
    if (!headers_sent()) {
        // ここはあなたの /super の仕様に合わせてください（tenants.phpに飛ぶならそこでもOK）
        header('Location: /super/login.php');
    }
    if (ob_get_level() > 0) @ob_end_flush();
    exit;
}
kb_log('auth ok');

// ===== PDO取得（環境差吸収）=====
$pdo = null;

// 1) /super/_db.php が $pdo を用意しているケース
if (isset($GLOBALS['pdo']) && ($GLOBALS['pdo'] instanceof PDO)) {
    $pdo = $GLOBALS['pdo'];
    kb_log('pdo = $GLOBALS["pdo"]');
} else {
    // 2) 関数で返すケース
    foreach (['fl_db', 'db', 'get_pdo', 'pdo'] as $fn) {
        if (function_exists($fn)) {
            try {
                $ret = call_user_func($fn);
                if ($ret instanceof PDO) {
                    $pdo = $ret;
                    kb_log("pdo = {$fn}()");
                    break;
                }
            } catch (Throwable $e) {
                kb_log("pdo try {$fn}(): exception " . $e->getMessage());
            }
        }
    }
}

if (!($pdo instanceof PDO)) {
    kb_die("PDO not found. /super/_db.php が \$pdo を用意しているか確認してください。");
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ===== CSRF =====
if (empty($_SESSION['super_csrf_token'])) {
    $_SESSION['super_csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['super_csrf_token'];

// ===== テーブル存在チェック =====
$hasKbTable = false;
try {
    $st = $pdo->query("SHOW TABLES LIKE 'help_kb'");
    $hasKbTable = (bool)$st->fetchColumn();
} catch (Throwable $e) {
    kb_log('SHOW TABLES error: ' . $e->getMessage());
}

// ===== 方針A: tenant_id 無し（カラム検査）=====
$cols = [];
try {
    if ($hasKbTable) {
        $c = $pdo->query("SHOW COLUMNS FROM help_kb");
        $cols = $c->fetchAll() ?: [];
    }
} catch (Throwable $e) {
    kb_log('SHOW COLUMNS error: ' . $e->getMessage());
}
$colNames = array_map(fn($r) => (string)($r['Field'] ?? ''), $cols);
$hasTenantId = in_array('tenant_id', $colNames, true); // これが残ってたらDB未移行

// ===== POST =====
$flash = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        kb_log('POST');
        if (!$hasKbTable) throw new RuntimeException('help_kb table missing');

        $postAction = (string)($_POST['action'] ?? '');
        $token = (string)($_POST['csrf'] ?? '');
        if (!hash_equals($csrf, $token)) throw new RuntimeException('CSRF mismatch');

        // ✅ 方針A: tenant_id を使わない INSERT/UPDATE/DELETE
        if ($postAction === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body  = trim((string)($_POST['body'] ?? ''));
            if ($title === '' || $body === '') throw new RuntimeException('title/body required');

            $ins = $pdo->prepare("INSERT INTO help_kb(title, body) VALUES(:t, :b)");
            $ins->execute([':t' => $title, ':b' => $body]);
            $flash = '登録しました';
        } elseif ($postAction === 'update') {
            $id    = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $body  = trim((string)($_POST['body'] ?? ''));
            if ($id <= 0) throw new RuntimeException('id invalid');
            if ($title === '' || $body === '') throw new RuntimeException('title/body required');

            $up = $pdo->prepare("UPDATE help_kb SET title=:t, body=:b WHERE id=:id LIMIT 1");
            $up->execute([':t' => $title, ':b' => $body, ':id' => $id]);
            $flash = '更新しました';
        } elseif ($postAction === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id invalid');

            $del = $pdo->prepare("DELETE FROM help_kb WHERE id=:id LIMIT 1");
            $del->execute([':id' => $id]);
            $flash = '削除しました';
        } else {
            throw new RuntimeException('unknown action=' . $postAction);
        }
    }
} catch (Throwable $e) {
    kb_log('[POST ERROR] ' . $e->getMessage());
    $flash = 'エラー: ' . $e->getMessage();
}

// ===== list =====
$items = [];
if ($hasKbTable) {
    // ✅ 方針A: 全件
    $ls = $pdo->query("SELECT id, title, body, updated_at FROM help_kb ORDER BY updated_at DESC, id DESC LIMIT 500");
    $items = $ls->fetchAll() ?: [];
}

kb_log('render start hasKbTable=' . ($hasKbTable ? '1' : '0') . ' items=' . count($items));

// ここでバッファを確定（_db.php の余計な出力を吸収済み）
if (ob_get_level() > 0) @ob_end_flush();
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Help KB（共通）</title>
    <style>
    body {
        font-family: system-ui;
        margin: 0;
        background: #f6f7fb;
        color: #111
    }

    .wrap {
        max-width: 1100px;
        margin: 0 auto;
        padding: 16px
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 16px;
        margin: 12px 0
    }

    .row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap
    }

    .pill {
        display: inline-block;
        padding: 6px 10px;
        border: 1px solid #e5e7eb;
        border-radius: 999px;
        background: #fff;
        font-weight: 900;
        font-size: 12px
    }

    input,
    textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        font-size: 14px
    }

    textarea {
        min-height: 140px;
        resize: vertical
    }

    button {
        padding: 10px 12px;
        border-radius: 12px;
        border: 0;
        font-weight: 900;
        cursor: pointer
    }

    .btn {
        background: #2563eb;
        color: #fff
    }

    .btn2 {
        background: #e5e7eb;
        color: #111
    }

    .danger {
        background: #ef4444;
        color: #fff
    }

    .hint {
        color: #6b7280;
        font-size: 13px
    }

    .table {
        width: 100%;
        border-collapse: collapse
    }

    .table th,
    .table td {
        border-top: 1px solid #e5e7eb;
        padding: 10px;
        vertical-align: top
    }

    .table th {
        background: #fafafa;
        text-align: left
    }

    pre.sql {
        margin: 0;
        padding: 12px;
        background: #111;
        color: #fff;
        border-radius: 12px;
        white-space: pre-wrap;
        word-break: break-word
    }

    .warn {
        border-color: #fecaca;
        background: #fff1f2
    }

    .ok {
        border-color: #bbf7d0;
        background: #f0fdf4
    }
    </style>
</head>

<body>

    <?php if (is_file(__DIR__ . '/_header.php')) require __DIR__ . '/_header.php'; ?>

    <div class="wrap">
        <div class="card <?= $hasKbTable ? 'ok':'warn' ?>">
            <div class="row" style="align-items:center;justify-content:space-between;">
                <div style="font-weight:900;font-size:20px;">📚 Help KB（全テナント共通）</div>
                <div class="row">
                    <span class="pill">table: <?= $hasKbTable ? 'OK':'NG' ?></span>
                    <span class="pill">tenant_id: <?= $hasTenantId ? '残ってる(要ALTER)' : 'なし(OK)' ?></span>
                    <span class="pill">log=/super/logs/help_kb.log</span>
                    <span class="pill">debug=<?= $__dbg ? '1':'0' ?></span>
                </div>
            </div>

            <?php if ($flash !== ''): ?>
            <div class="card" style="border-color:#fed7aa;background:#fff7ed;"><?= h($flash) ?></div>
            <?php endif; ?>

            <?php if (!$hasKbTable): ?>
            <div class="card warn">
                <div style="font-weight:900;margin-bottom:8px;">help_kb テーブルがありません</div>
                <div class="hint" style="margin-bottom:8px;">phpMyAdmin で下のSQLを実行してください。</div>
                <pre class="sql">CREATE TABLE IF NOT EXISTS help_kb (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
            </div>
            <?php endif; ?>

            <?php if ($hasKbTable && $hasTenantId): ?>
            <div class="card warn">
                <div style="font-weight:900;margin-bottom:8px;">DBがまだ方針Aに移行できていません（tenant_id が残っています）</div>
                <div class="hint" style="margin-bottom:8px;">phpMyAdmin で下のSQLを実行してください。</div>
                <pre class="sql">ALTER TABLE help_kb DROP COLUMN tenant_id;</pre>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($hasKbTable && !$hasTenantId): ?>
        <div class="card">
            <div style="font-weight:900;margin-bottom:8px;">➕ 新規登録</div>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <div class="hint">タイトル</div>
                <input name="title" placeholder="例：LINE紐付け手順" required>
                <div class="hint" style="margin-top:10px;">本文</div>
                <textarea name="body" placeholder="画面名・手順・注意点をそのまま" required></textarea>
                <div style="margin-top:10px;">
                    <button class="btn" type="submit">登録する</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div style="font-weight:900;margin-bottom:8px;">📄 登録済み一覧</div>

            <?php if (empty($items)): ?>
            <div class="hint">まだ登録がありません。</div>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th>タイトル / 本文</th>
                        <th style="width:180px;">更新日時</th>
                        <th style="width:240px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= (int)$it['id'] ?></td>
                        <td>
                            <div style="font-weight:900;"><?= h((string)$it['title']) ?></div>
                            <div class="hint" style="white-space:pre-wrap;word-break:break-word;margin-top:6px;">
                                <?= h((string)$it['body']) ?></div>
                        </td>
                        <td class="hint"><?= h((string)$it['updated_at']) ?></td>
                        <td>
                            <form method="post" style="margin-bottom:10px;">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <div class="hint">タイトル</div>
                                <input name="title" value="<?= h((string)$it['title']) ?>" required>
                                <div class="hint" style="margin-top:6px;">本文</div>
                                <textarea name="body" required><?= h((string)$it['body']) ?></textarea>
                                <div style="margin-top:8px;">
                                    <button class="btn2" type="submit">更新</button>
                                </div>
                            </form>

                            <form method="post" onsubmit="return confirm('本当に削除しますか？');">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button class="danger" type="submit">削除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</body>

</html>