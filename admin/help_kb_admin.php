<?php
declare(strict_types=1);

/**
 * ✅ /admin/help_kb_admin.php（keywords 編集UI）
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_db.php';
$pdo = null;
if (isset($GLOBALS['pdo']) && ($GLOBALS['pdo'] instanceof PDO)) $pdo = $GLOBALS['pdo'];
else {
    foreach (['db', 'fl_db', 'get_pdo', 'pdo'] as $fn) {
        if (function_exists($fn)) {
            $ret = $fn();
            if ($ret instanceof PDO) { $pdo = $ret; break; }
        }
    }
}
if (!($pdo instanceof PDO)) { http_response_code(500); echo 'DB Error'; exit; }

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// keywords 有無
$hasKeywords = false;
try {
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME='help_kb'
          AND COLUMN_NAME='keywords'
    ");
    $st->execute();
    $hasKeywords = ((int)$st->fetchColumn() > 0);
} catch (Throwable $e) { $hasKeywords = false; }

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $title = (string)($_POST['title'] ?? '');
    $body  = (string)($_POST['body'] ?? '');
    $keywords = (string)($_POST['keywords'] ?? '');

    if ($id <= 0) {
        $err = 'id が不正です';
    } else {
        try {
            if ($hasKeywords) {
                $st = $pdo->prepare("UPDATE help_kb SET title=:t, body=:b, keywords=:k, updated_at=NOW() WHERE id=:id");
                $st->execute([':t'=>$title, ':b'=>$body, ':k'=>$keywords, ':id'=>$id]);
            } else {
                $st = $pdo->prepare("UPDATE help_kb SET title=:t, body=:b, updated_at=NOW() WHERE id=:id");
                $st->execute([':t'=>$title, ':b'=>$body, ':id'=>$id]);
            }
            $ok = '更新しました';
        } catch (Throwable $e) {
            $err = '更新に失敗: ' . $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($q !== '') {
    $where = "WHERE (title LIKE :q OR body LIKE :q" . ($hasKeywords ? " OR keywords LIKE :q" : "") . ")";
    $params[':q'] = '%' . $q . '%';
}

$sql = "SELECT id, title, body, updated_at" . ($hasKeywords ? ", keywords" : ", NULL AS keywords") . " FROM help_kb {$where} ORDER BY updated_at DESC, id DESC LIMIT 200";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT id, title, body, updated_at" . ($hasKeywords ? ", keywords" : ", NULL AS keywords") . " FROM help_kb WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$editId]);
    $editRow = $st->fetch() ?: null;
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Help KB 管理</title>
    <style>
    body {
        font-family: system-ui;
        margin: 0;
        background: #f7f7fb;
        color: #111
    }

    .wrap {
        max-width: 1100px;
        margin: 0 auto;
        padding: 14px
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, .04);
        padding: 14px
    }

    table {
        width: 100%;
        border-collapse: collapse
    }

    th,
    td {
        border-bottom: 1px solid #e5e7eb;
        padding: 10px;
        font-size: 14px;
        vertical-align: top
    }

    th {
        background: #fafafa;
        text-align: left
    }

    .muted {
        color: #6b7280;
        font-size: 12px
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center
    }

    input[type=text] {
        padding: 10px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        min-width: 260px
    }

    textarea {
        width: 100%;
        min-height: 160px;
        padding: 10px;
        border: 1px solid #e5e7eb;
        border-radius: 12px
    }

    .btn {
        padding: 10px 12px;
        border: 0;
        border-radius: 12px;
        font-weight: 900;
        cursor: pointer
    }

    .btn1 {
        background: #365EAB;
        color: #fff
    }

    .btn2 {
        background: #e5e7eb;
        color: #111
    }

    .ok {
        background: #ecfccb;
        border: 1px solid #bef264;
        padding: 10px;
        border-radius: 12px
    }

    .err {
        background: #fee2e2;
        border: 1px solid #fecaca;
        padding: 10px;
        border-radius: 12px
    }

    code {
        background: #f3f4f6;
        padding: 2px 6px;
        border-radius: 8px
    }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="card">
            <div class="row">
                <h2 style="margin:0;">Help KB 管理</h2>
                <div class="muted">keywords: <?= $hasKeywords ? '有り' : '無し（ALTERで追加してください）' ?></div>
            </div>

            <?php if ($ok): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

            <form method="get" class="row" style="margin:12px 0;">
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="検索（title/body/keywords）">
                <button class="btn btn2" type="submit">検索</button>
                <a class="btn btn2" href="/admin/help_kb_admin.php">リセット</a>
            </form>

            <table>
                <thead>
                    <tr>
                        <th style="width:70px;">id</th>
                        <th>title</th>
                        <th style="width:220px;">updated_at</th>
                        <th style="width:260px;">keywords</th>
                        <th style="width:110px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= h((string)$r['title']) ?></td>
                        <td class="muted"><?= h((string)$r['updated_at']) ?></td>
                        <td class="muted"><?= h((string)($r['keywords'] ?? '')) ?></td>
                        <td><a href="/admin/help_kb_admin.php?edit=<?= (int)$r['id'] ?>">編集</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($editRow): ?>
            <hr style="border:0;border-top:1px solid #e5e7eb;margin:16px 0;">
            <h3 style="margin:0 0 10px;">編集: id=<?= (int)$editRow['id'] ?></h3>
            <form method="post">
                <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                <div class="muted">keywords は「半角スペース区切り」推奨。例：<code>line 連携 紐付け pin 出勤</code></div>

                <div style="margin:10px 0;">
                    <div class="muted">title</div>
                    <input type="text" name="title" value="<?= h((string)$editRow['title']) ?>"
                        style="width:100%;min-width:0;">
                </div>

                <div style="margin:10px 0;">
                    <div class="muted">keywords<?= $hasKeywords ? '' : '（未対応）' ?></div>
                    <input type="text" name="keywords" value="<?= h((string)($editRow['keywords'] ?? '')) ?>"
                        style="width:100%;min-width:0;" <?= $hasKeywords ? '' : 'disabled' ?>>
                </div>

                <div style="margin:10px 0;">
                    <div class="muted">body</div>
                    <textarea name="body"><?= h((string)$editRow['body']) ?></textarea>
                </div>

                <div class="row">
                    <button class="btn btn1" type="submit">保存</button>
                    <a class="btn btn2" href="/admin/help_kb_admin.php">閉じる</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
