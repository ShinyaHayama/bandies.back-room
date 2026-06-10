<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_super_admin_login();
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/../lib/app_notices.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/app_url.php';

app_notices_ensure_schema($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];
$err = null;

function super_notice_csrf(string $csrf): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals($csrf, $token)) {
        throw new RuntimeException('CSRF token が不正です');
    }
}

function super_notice_admin_url(): string
{
    return app_public_url('/super/notices.php');
}

function super_notice_notify_created(array $notice): void
{
    $to = 'work@fader.group';
    $subject = '【SHIMENAVI】お知らせが登録されました';
    $statusLabel = ((string)($notice['status'] ?? '') === 'published') ? '公開' : '下書き';

    $body = "SaaSユーザー向けのお知らせが登録されました。\n\n";
    $body .= "タイトル:\n" . (string)($notice['title'] ?? '') . "\n\n";
    $body .= "状態:\n" . $statusLabel . "\n\n";
    $body .= "公開日時:\n" . (string)($notice['published_at'] ?? '') . "\n\n";
    $body .= "本文:\n" . (string)($notice['body'] ?? '') . "\n\n";
    $body .= "登録者 super_admin_user_id:\n" . (string)($_SESSION['super_admin_user_id'] ?? '') . "\n\n";
    $body .= "管理画面:\n" . super_notice_admin_url() . "\n";

    [$ok, $mailErr] = send_mail_with_error($to, $subject, $body, 'SHIMENAVI', '');
    if (!$ok) {
        error_log('[app_notice] internal notification mail failed: ' . $mailErr);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        super_notice_csrf($csrf);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $status = ((string)($_POST['status'] ?? 'published') === 'draft') ? 'draft' : 'published';
            $publishedAt = trim((string)($_POST['published_at'] ?? ''));
            if ($publishedAt === '') {
                $publishedAt = date('Y-m-d H:i:s');
            } else {
                $ts = strtotime($publishedAt);
                if ($ts === false) {
                    throw new RuntimeException('公開日時が不正です');
                }
                $publishedAt = date('Y-m-d H:i:s', $ts);
            }

            if ($title === '') {
                throw new RuntimeException('タイトルは必須です');
            }
            if ($body === '') {
                throw new RuntimeException('本文は必須です');
            }

            $st = $pdo->prepare("
                INSERT INTO app_notices (title, body, status, published_at, created_at, updated_at)
                VALUES (:title, :body, :status, :published_at, NOW(), NOW())
            ");
            $st->execute([
                ':title' => $title,
                ':body' => $body,
                ':status' => $status,
                ':published_at' => $publishedAt,
            ]);

            super_notice_notify_created([
                'title' => $title,
                'body' => $body,
                'status' => $status,
                'published_at' => $publishedAt,
            ]);

            header('Location: /super/notices.php');
            exit;
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id が不正です');

            $cur = $pdo->prepare("SELECT status FROM app_notices WHERE id = :id LIMIT 1");
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if (!$row) throw new RuntimeException('対象のお知らせが見つかりません');

            $next = ((string)$row['status'] === 'published') ? 'draft' : 'published';
            $st = $pdo->prepare("UPDATE app_notices SET status = :status, updated_at = NOW() WHERE id = :id");
            $st->execute([':status' => $next, ':id' => $id]);

            header('Location: /super/notices.php');
            exit;
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $status = ((string)($_POST['status'] ?? 'published') === 'draft') ? 'draft' : 'published';
            $publishedAt = trim((string)($_POST['published_at'] ?? ''));
            $ts = strtotime($publishedAt);
            if ($id <= 0) throw new RuntimeException('id が不正です');
            if ($title === '') throw new RuntimeException('タイトルは必須です');
            if ($body === '') throw new RuntimeException('本文は必須です');
            if ($publishedAt === '' || $ts === false) throw new RuntimeException('公開日時が不正です');

            $st = $pdo->prepare("
                UPDATE app_notices
                SET title = :title,
                    body = :body,
                    status = :status,
                    published_at = :published_at,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $st->execute([
                ':title' => $title,
                ':body' => $body,
                ':status' => $status,
                ':published_at' => date('Y-m-d H:i:s', $ts),
                ':id' => $id,
            ]);

            header('Location: /super/notices.php');
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id が不正です');

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM app_notice_reads WHERE notice_id = :id")->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM app_notices WHERE id = :id")->execute([':id' => $id]);
            $pdo->commit();

            header('Location: /super/notices.php');
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
    }
}

$notices = $pdo->query("
    SELECT id, title, body, status, published_at, created_at, updated_at
    FROM app_notices
    ORDER BY published_at DESC, id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$nowLocal = date('Y-m-d\TH:i');
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>お知らせ管理 - Super</title>
    <style>
    body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        margin: 24px;
        color: #111827;
        background: #f8fafc;
    }

    .wrap {
        max-width: 1080px;
        margin: 0 auto;
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 18px;
        margin-bottom: 16px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
    }

    h1 {
        font-size: 24px;
        margin: 0 0 6px;
    }

    .desc {
        margin: 0 0 16px;
        color: #64748b;
        font-size: 14px;
        font-weight: 700;
    }

    label {
        display: block;
        font-size: 13px;
        font-weight: 900;
        color: #374151;
        margin-bottom: 6px;
    }

    input,
    textarea,
    select {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #d1d5db;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 14px;
        background: #fff;
    }

    textarea {
        min-height: 130px;
        resize: vertical;
        line-height: 1.7;
    }

    .grid {
        display: grid;
        grid-template-columns: 1fr 180px 220px;
        gap: 12px;
        align-items: end;
    }

    .actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }

    button {
        border: 0;
        border-radius: 999px;
        background: #2563eb;
        color: #fff;
        font-weight: 900;
        padding: 10px 16px;
        cursor: pointer;
    }

    button.secondary {
        background: #f3f4f6;
        color: #111827;
        border: 1px solid #d1d5db;
    }

    button.danger {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    .notice {
        border-top: 1px solid #e5e7eb;
        padding: 14px 0;
    }

    .notice:first-child {
        border-top: 0;
        padding-top: 0;
    }

    .noticeHead {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
    }

    .title {
        font-weight: 900;
        font-size: 16px;
    }

    .meta {
        color: #64748b;
        font-size: 12px;
        font-weight: 800;
        margin-top: 4px;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 4px 9px;
        font-size: 12px;
        font-weight: 900;
        background: #dcfce7;
        color: #166534;
    }

    .badge.draft {
        background: #f3f4f6;
        color: #4b5563;
    }

    .body {
        white-space: pre-wrap;
        color: #374151;
        font-size: 14px;
        line-height: 1.7;
        margin: 10px 0 0;
    }

    details.editBox {
        margin-top: 12px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 10px 12px;
        background: #f9fafb;
    }

    details.editBox summary {
        cursor: pointer;
        font-weight: 900;
        color: #374151;
        font-size: 13px;
    }

    .err {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 14px;
        font-weight: 800;
    }

    @media (max-width: 760px) {
        body {
            margin: 14px;
        }

        .grid {
            grid-template-columns: 1fr;
        }

        .noticeHead {
            flex-direction: column;
        }
    }
    </style>
</head>

<body>
    <div class="wrap">
        <?php require __DIR__ . '/_top.php'; ?>

        <h1>お知らせ管理</h1>
        <p class="desc">機能追加や修正内容を全ユーザーの管理画面ヘッダーに通知します。</p>

        <?php if ($err): ?>
        <div class="err"><?= h($err) ?></div>
        <?php endif; ?>

        <section class="card">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="create">

                <div style="margin-bottom:12px;">
                    <label for="noticeTitle">タイトル</label>
                    <input id="noticeTitle" name="title" maxlength="160" required placeholder="例：シフトグラフ表示を追加しました">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="noticeBody">本文</label>
                    <textarea id="noticeBody" name="body" required placeholder="変更内容、使い方、影響範囲などを入力"></textarea>
                </div>

                <div class="grid">
                    <div>
                        <label for="noticePublishedAt">公開日時</label>
                        <input id="noticePublishedAt" type="datetime-local" name="published_at" value="<?= h($nowLocal) ?>">
                    </div>
                    <div>
                        <label for="noticeStatus">状態</label>
                        <select id="noticeStatus" name="status">
                            <option value="published">公開</option>
                            <option value="draft">下書き</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit">登録</button>
                    </div>
                </div>
            </form>
        </section>

        <section class="card">
            <?php if (!$notices): ?>
            <p class="desc" style="margin:0;">登録済みのお知らせはありません。</p>
            <?php else: ?>
            <?php foreach ($notices as $notice): ?>
            <article class="notice">
                <div class="noticeHead">
                    <div>
                        <div class="title"><?= h((string)$notice['title']) ?></div>
                        <div class="meta">
                            公開日時：<?= h(date('Y/m/d H:i', strtotime((string)$notice['published_at']))) ?>
                            / 更新：<?= h(date('Y/m/d H:i', strtotime((string)$notice['updated_at']))) ?>
                        </div>
                    </div>
                    <div class="actions">
                        <?php $isPublished = ((string)$notice['status'] === 'published'); ?>
                        <span class="badge <?= $isPublished ? '' : 'draft' ?>"><?= $isPublished ? '公開' : '下書き' ?></span>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$notice['id'] ?>">
                            <button class="secondary" type="submit"><?= $isPublished ? '下書きへ' : '公開する' ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('このお知らせを削除しますか？');">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$notice['id'] ?>">
                            <button class="danger" type="submit">削除</button>
                        </form>
                    </div>
                </div>
                <div class="body"><?= h((string)$notice['body']) ?></div>
                <details class="editBox">
                    <summary>編集</summary>
                    <form method="post" style="margin-top:12px;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int)$notice['id'] ?>">
                        <div style="margin-bottom:10px;">
                            <label>タイトル</label>
                            <input name="title" maxlength="160" required value="<?= h((string)$notice['title']) ?>">
                        </div>
                        <div style="margin-bottom:10px;">
                            <label>本文</label>
                            <textarea name="body" required><?= h((string)$notice['body']) ?></textarea>
                        </div>
                        <div class="grid">
                            <div>
                                <label>公開日時</label>
                                <input type="datetime-local" name="published_at"
                                    value="<?= h(date('Y-m-d\TH:i', strtotime((string)$notice['published_at']))) ?>">
                            </div>
                            <div>
                                <label>状態</label>
                                <select name="status">
                                    <option value="published" <?= ((string)$notice['status'] === 'published') ? 'selected' : '' ?>>公開</option>
                                    <option value="draft" <?= ((string)$notice['status'] === 'draft') ? 'selected' : '' ?>>下書き</option>
                                </select>
                            </div>
                            <div>
                                <button type="submit">更新</button>
                            </div>
                        </div>
                    </form>
                </details>
            </article>
            <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</body>

</html>
