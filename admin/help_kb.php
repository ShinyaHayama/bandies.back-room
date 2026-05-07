<?php
declare(strict_types=1);

/**
 * ✅ Help KB 管理（keywords 即編集UI / CSRF対応）
 * - 一覧表示
 * - keywords をインライン編集 → 即保存（AJAX）
 * - CSRF トークンを付与して csrf_invalid を根絶
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_db.php';
$pdo = $pdo ?? (function_exists('db') ? db() : $pdo);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// CSRF token（既存の仕組みが無くても動く保険）
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_token'];

$rows = $pdo->query("
    SELECT id, title, keywords, updated_at
    FROM help_kb
    ORDER BY updated_at DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>Help KB 管理</title>
    <style>
    table {
        border-collapse: collapse;
        width: 100%;
    }

    th,
    td {
        border: 1px solid #ddd;
        padding: 8px;
    }

    th {
        background: #f4f4f4;
    }

    input.keywords {
        width: 100%;
        padding: 6px;
        border: 1px solid #ccc;
        border-radius: 6px;
    }

    .saved {
        background: #e6fffa;
    }

    .error {
        background: #fee2e2;
    }

    .small {
        font-size: 12px;
        color: #666;
    }
    </style>
</head>

<body>
    <h1>Help KB 管理</h1>
    <div class="small">keywords を編集すると 0.6秒後に自動保存します。</div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>タイトル</th>
                <th>keywords（即編集）</th>
                <th>更新日</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= h((string)$r['title']) ?></td>
                <td>
                    <input class="keywords" data-id="<?= (int)$r['id'] ?>"
                        value="<?= h((string)($r['keywords'] ?? '')) ?>" placeholder="例：出勤, 打刻, 出社, 勤務開始">
                </td>
                <td><?= h((string)($r['updated_at'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <script>
    const CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;

    document.querySelectorAll('.keywords').forEach(el => {
        let timer = null;

        el.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => save(el), 600);
        });

        // Enterでも即保存
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(timer);
                save(el);
            }
        });
    });

    function save(input) {
        const id = input.dataset.id;
        const keywords = input.value;

        fetch('/admin/help_kb_api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF
                },
                body: JSON.stringify({
                    action: 'update_keywords',
                    id: id,
                    keywords: keywords
                })
            })
            .then(r => r.json())
            .then(j => {
                if (j && j.ok) {
                    input.classList.remove('error');
                    input.classList.add('saved');
                    setTimeout(() => input.classList.remove('saved'), 800);
                } else {
                    input.classList.add('error');
                }
            })
            .catch(() => input.classList.add('error'));
    }
    </script>
</body>

</html>