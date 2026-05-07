<?php
// /super/stores.php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_super_admin_login();
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/../admin/lib/social_insurance.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$meId = (int)($_SESSION['super_admin_user_id'] ?? 0);

// ===== 監査ログ =====
function audit(PDO $pdo, int $meId, string $action, array $payload = []): void
{
    if ($meId <= 0) return;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO super_audit_logs (super_admin_user_id, action, payload)
            VALUES (:uid, :action, :payload)
        ");
        $stmt->execute([
            ':uid' => $meId,
            ':action' => $action,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // ignore
    }
}

function ensureStoreAdminColumns(PDO $pdo): void
{
    si_ensure_schema($pdo);
    $cols = si_table_columns($pdo, 'stores');
    $changes = [];
    if (!in_array('insurance_rounding', $cols, true)) {
        $changes[] = "ADD COLUMN insurance_rounding VARCHAR(10) NOT NULL DEFAULT 'floor'";
    }
    if ($changes) {
        $pdo->exec("ALTER TABLE stores " . implode(', ', $changes));
    }
}

$err = null;
$ok = null;

ensureStoreAdminColumns($pdo);

// ===== tenant 一覧 =====
$hasDeletedAt = (bool)$pdo->query("
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tenants'
      AND COLUMN_NAME = 'deleted_at'
    LIMIT 1
")->fetchColumn();
$tenantWhere = $hasDeletedAt ? "WHERE deleted_at IS NULL" : "";

$tenants = $pdo->query("
    SELECT id, name
    FROM tenants
    {$tenantWhere}
    ORDER BY id DESC
    LIMIT 500
")->fetchAll();

if (!$tenants) {
    http_response_code(400);
    echo 'tenants がありません';
    exit;
}

$tenantId = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? (int)$tenants[0]['id']);
$validTenantIds = array_map(fn($t) => (int)$t['id'], $tenants);
if (!in_array($tenantId, $validTenantIds, true)) {
    $tenantId = (int)$tenants[0]['id'];
}

$tenantLabel = '';
foreach ($tenants as $t) {
    if ((int)$t['id'] === $tenantId) {
        $tenantLabel = (int)$t['id'] . ' / ' . (string)$t['name'];
        break;
    }
}

// ===== 追加処理 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'toggle_back') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        $enabled = (int)($_POST['back_enabled'] ?? 0) === 1 ? 1 : 0;
        if ($storeId <= 0) {
            $err = 'store_id が不正です';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE stores
                       SET back_enabled = :enabled
                     WHERE id = :store_id
                       AND tenant_id = :tenant_id
                     LIMIT 1
                ");
                $stmt->execute([
                    ':enabled' => $enabled,
                    ':store_id' => $storeId,
                    ':tenant_id' => $tenantId,
                ]);

                audit($pdo, $meId, 'store.back_enabled', [
                    'store_id' => $storeId,
                    'back_enabled' => $enabled,
                ]);

                header('Location: /super/stores.php?tenant_id=' . $tenantId);
                exit;
            } catch (Throwable $e) {
                $err = '更新に失敗しました: ' . $e->getMessage();
            }
        }
    }
    if ($act === 'create_store') {
        $storeName = trim((string)($_POST['store_name'] ?? ''));
        if ($storeName === '') {
            $err = '店舗名は必須です';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO stores (tenant_id, name)
                    VALUES (:tenant_id, :name)
                ");
                $stmt->execute([
                    ':tenant_id' => $tenantId,
                    ':name' => $storeName,
                ]);
                $newStoreId = (int)$pdo->lastInsertId();

                audit($pdo, $meId, 'store.create', [
                    'tenant_id' => $tenantId,
                    'store_id' => $newStoreId,
                    'name' => $storeName,
                ]);

                $ok = "店舗を追加しました（store_id={$newStoreId}）";
            } catch (Throwable $e) {
                $err = '追加に失敗しました: ' . $e->getMessage();
            }
        }
    }
    if ($act === 'save_store_insurance') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        $prefectureCode = trim((string)($_POST['prefecture_code'] ?? ''));
        $employmentType = strtolower(trim((string)($_POST['employment_insurance_business_type'] ?? 'general')));
        $rounding = trim((string)($_POST['insurance_rounding'] ?? 'floor'));

        if ($storeId <= 0) {
            $err = 'store_id が不正です';
        } elseif ($prefectureCode !== '' && !preg_match('/^\d{2}$/', $prefectureCode)) {
            $err = '都道府県コードは2桁で入力してください';
        } elseif (!in_array($employmentType, ['general', 'agri', 'construction'], true)) {
            $err = '雇用保険事業区分が不正です';
        } elseif (!in_array($rounding, ['floor', 'round', 'ceil'], true)) {
            $err = '丸め区分が不正です';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE stores
                    SET prefecture_code = :prefecture_code,
                        employment_insurance_business_type = :employment_type,
                        insurance_rounding = :rounding
                    WHERE id = :store_id
                      AND tenant_id = :tenant_id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':prefecture_code' => ($prefectureCode === '' ? null : $prefectureCode),
                    ':employment_type' => $employmentType,
                    ':rounding' => $rounding,
                    ':store_id' => $storeId,
                    ':tenant_id' => $tenantId,
                ]);

                audit($pdo, $meId, 'store.insurance_profile', [
                    'store_id' => $storeId,
                    'prefecture_code' => $prefectureCode,
                    'employment_insurance_business_type' => $employmentType,
                    'insurance_rounding' => $rounding,
                ]);

                header('Location: /super/stores.php?tenant_id=' . $tenantId);
                exit;
            } catch (Throwable $e) {
                $err = '社保設定の保存に失敗しました: ' . $e->getMessage();
            }
        }
    }
}

// ===== 店舗一覧 =====
$stores = [];
$stmt = $pdo->prepare("
    SELECT id, tenant_id, name, created_at, updated_at,
           COALESCE(back_enabled, 1) AS back_enabled
         , COALESCE(prefecture_code, '') AS prefecture_code
         , COALESCE(employment_insurance_business_type, 'general') AS employment_insurance_business_type
         , COALESCE(insurance_rounding, 'floor') AS insurance_rounding
    FROM stores
    WHERE tenant_id = :tenant_id
    ORDER BY id DESC
    LIMIT 500
");
$stmt->execute([':tenant_id' => $tenantId]);
$stores = $stmt->fetchAll();

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>店舗管理</title>
    <style>
    body {
        font-family: system-ui;
        padding: 18px;
        color: #111;
    }

    .card {
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 14px;
        max-width: 1600px;
        margin-bottom: 16px;
    }

    .row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    select,
    input,
    button {
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 10px;
    }

    button {
        cursor: pointer;
        font-weight: 700;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    th,
    td {
        border-bottom: 1px solid #eee;
        padding: 8px;
        font-size: 13px;
        text-align: left;
    }

    th {
        background: #fafafa;
    }

    .err {
        background: #ffecec;
        border: 1px solid #ffb3b3;
        padding: 10px;
        border-radius: 10px;
        margin: 10px 0;
    }

    .ok {
        background: #eaffea;
        border: 1px solid #9be59b;
        padding: 10px;
        border-radius: 10px;
        margin: 10px 0;
    }

    a {
        color: #111;
    }

    .compactInput {
        width: 90px;
        padding: 8px;
    }

    .compactSelect {
        padding: 8px;
    }

    .hint {
        color: #555;
        font-size: 12px;
    }
    </style>
</head>

<body>
    <?php require __DIR__ . '/_top.php'; ?>

    <div class="card">
        <h2 style="margin:0 0 10px;">店舗管理</h2>
        <p>対象テナント: <?= h($tenantLabel !== '' ? $tenantLabel : (string)$tenantId) ?></p>
        <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>

        <!-- tenant 切替 -->
        <div class="row" style="margin-top:10px;">
            <a href="/super/tenants.php">← テナント一覧へ戻る</a>
        </div>

        <!-- store 追加 -->
        <form method="post" class="row" style="margin-top:12px;">
            <input type="hidden" name="action" value="create_store">
            <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
            <input name="store_name" placeholder="店舗名（例：2号店）" required style="min-width:260px;">
            <button type="submit">店舗を追加</button>
        </form>

        <p class="hint" style="margin-top:12px;">ここでは店舗属性だけ管理します。社会保険料率は「社会保険マスタ」画面で一括管理します。</p>

        <table>
            <thead>
                <tr>
                    <th style="width:90px;">店舗ID</th>
                    <th style="width:90px;">テナントID</th>
                    <th>店舗名</th>
                    <th style="width:80px;">都道府県</th>
                    <th style="width:120px;">雇用保険区分</th>
                    <th style="width:90px;">丸め</th>
                    <th style="width:140px;">バック機能</th>
                    <th style="width:90px;">保存</th>
                    <th style="width:220px;">created</th>
                    <th style="width:220px;">updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stores as $s): ?>
                <tr>
                    <form method="post">
                    <td><?= (int)$s['id'] ?></td>
                    <td><?= (int)$s['tenant_id'] ?></td>
                    <td>
                        <?= h((string)$s['name']) ?>
                        <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
                        <input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>">
                    </td>
                    <td><input class="compactInput" name="prefecture_code" value="<?= h((string)$s['prefecture_code']) ?>" placeholder="13"></td>
                    <td>
                        <select class="compactSelect" name="employment_insurance_business_type">
                            <option value="general" <?= ((string)$s['employment_insurance_business_type'] === 'general') ? 'selected' : '' ?>>一般</option>
                            <option value="agri" <?= ((string)$s['employment_insurance_business_type'] === 'agri') ? 'selected' : '' ?>>農林水産・清酒製造</option>
                            <option value="construction" <?= ((string)$s['employment_insurance_business_type'] === 'construction') ? 'selected' : '' ?>>建設</option>
                        </select>
                    </td>
                    <td>
                        <select class="compactSelect" name="insurance_rounding">
                            <option value="floor" <?= ((string)$s['insurance_rounding'] === 'floor') ? 'selected' : '' ?>>切り捨て</option>
                            <option value="round" <?= ((string)$s['insurance_rounding'] === 'round') ? 'selected' : '' ?>>四捨五入</option>
                            <option value="ceil" <?= ((string)$s['insurance_rounding'] === 'ceil') ? 'selected' : '' ?>>切り上げ</option>
                        </select>
                    </td>
                    <td>
                        <?php if ((int)$s['back_enabled'] === 1): ?>
                            <input type="hidden" name="back_enabled" value="0">
                            <button type="submit" name="action" value="toggle_back">ON → OFF</button>
                        <?php else: ?>
                            <input type="hidden" name="back_enabled" value="1">
                            <button type="submit" name="action" value="toggle_back">OFF → ON</button>
                        <?php endif; ?>
                    </td>
                    <td><button type="submit" name="action" value="save_store_insurance">保存</button></td>
                    <td><?= h((string)$s['created_at']) ?></td>
                    <td><?= h((string)$s['updated_at']) ?></td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if (!$stores): ?>
                <tr>
                    <td colspan="10" style="color:#666;">このtenantには店舗がありません</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>

</html>
