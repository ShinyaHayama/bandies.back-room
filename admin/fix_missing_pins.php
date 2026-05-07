<?php
// /admin/fix_missing_pins.php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';

if (!isset($tenantId) || (int)$tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
$tenantId = (int)$tenantId;

// ===== DB =====
$paths = [
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if ($dbFile === null) {
    http_response_code(500);
    exit('db.php not found');
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function generate_unique_pin(PDO $pdo, int $tenantId, int $storeId): string
{
    $badPins = [
        '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999',
        '1234', '4321', '2580', '0123', '9876', '1357', '2468',
    ];

    $stmt = $pdo->prepare("
        SELECT 1
        FROM employees
        WHERE tenant_id = :tenant_id
          AND store_id  = :store_id
          AND auth_pin_code = :pin
        LIMIT 1
    ");

    for ($i = 0; $i < 2000; $i++) {
        $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        if (in_array($pin, $badPins, true)) continue;
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':store_id' => $storeId,
            ':pin' => $pin,
        ]);
        if (!(bool)$stmt->fetchColumn()) return $pin;
    }

    throw new RuntimeException('PIN生成に失敗しました（試行回数超過）');
}

function make_pin_hash_pair(string $pin): array
{
    $salt = random_bytes(16);
    $hash = hash('sha256', $salt . $pin, true);
    return [$salt, $hash];
}

$done = false;
$result = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rows = $pdo->query("
            SELECT id, tenant_id, store_id, display_name
            FROM employees
            WHERE tenant_id = {$tenantId}
              AND (auth_pin_code IS NULL OR auth_pin_code = '')
            ORDER BY tenant_id, store_id, id
        ")->fetchAll();

        if ($rows) {
            $pdo->beginTransaction();
            $upd = $pdo->prepare("
                UPDATE employees
                   SET auth_pin_code = :code,
                       auth_pin_salt = :salt,
                       auth_pin_hash = :hash,
                       auth_pin_set_at = NOW(),
                       updated_at = NOW()
                 WHERE id = :id
                 LIMIT 1
            ");

            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $tId = (int)$r['tenant_id'];
                $sId = (int)$r['store_id'];
                $name = (string)($r['display_name'] ?? '');

                $pin = generate_unique_pin($pdo, $tId, $sId);
                [$salt, $hash] = make_pin_hash_pair($pin);

                $upd->bindValue(':code', $pin, PDO::PARAM_STR);
                $upd->bindValue(':salt', $salt, PDO::PARAM_LOB);
                $upd->bindValue(':hash', $hash, PDO::PARAM_LOB);
                $upd->bindValue(':id', $id, PDO::PARAM_INT);
                $upd->execute();

                $result[] = [
                    'id' => $id,
                    'tenant_id' => $tId,
                    'store_id' => $sId,
                    'name' => $name,
                    'pin' => $pin,
                ];
            }

            $pdo->commit();
        }

        $done = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>PIN再発行（未設定のみ）</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif;
            margin: 0;
            background: #f6f7fb;
            color: #111827;
        }

        .page {
            padding: 18px;
        }

        .card {
            max-width: 760px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid rgba(17, 24, 39, .12);
            border-radius: 14px;
            padding: 16px;
        }

        h1 {
            font-size: 18px;
            margin: 0 0 8px;
        }

        .muted {
            color: rgba(17, 24, 39, .6);
            font-size: 13px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 36px;
            padding: 0 12px;
            border-radius: 10px;
            border: 1px solid rgba(17, 24, 39, .12);
            background: #111827;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 13px;
        }

        th,
        td {
            border-bottom: 1px solid rgba(17, 24, 39, .08);
            padding: 8px;
            text-align: left;
        }

        .error {
            background: #fff1f2;
            color: #9f1239;
            border: 1px solid rgba(190, 24, 93, .3);
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="page">
        <div class="card">
            <h1>PIN再発行（未設定のみ）</h1>
            <div class="muted">テナントID <?= (int)$tenantId ?> の未設定PINに対してのみ発行します。</div>

            <form method="post" style="margin-top:12px;">
                <button class="btn" type="submit">実行</button>
            </form>

            <?php if ($error !== ''): ?>
                <div class="error">エラー: <?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($done): ?>
                <?php if (empty($result)): ?>
                    <p class="muted" style="margin-top:12px;">未設定の従業員は見つかりませんでした。</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>店舗ID</th>
                                <th>名前</th>
                                <th>PIN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result as $r): ?>
                                <tr>
                                    <td><?= (int)$r['id'] ?></td>
                                    <td><?= (int)$r['store_id'] ?></td>
                                    <td><?= h((string)$r['name']) ?></td>
                                    <td><?= h((string)$r['pin']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
