<?php

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

if (!function_exists('hmoney')) {
    function hmoney($v): string
    {
        return htmlspecialchars(number_format((float)$v, 4, '.', ''), ENT_QUOTES, 'UTF-8');
    }
}

$meId = (int)($_SESSION['super_admin_user_id'] ?? 0);

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
    }
}

/**
 * @return array{scheme_code:string, scope_type:string, scope_key:?string, effective_from:string, effective_to:?string, employee_rate:float, employer_rate:float, note:?string}
 */
function normalize_rate_input(array $src): array
{
    $schemeCode = trim((string)($src['scheme_code'] ?? ''));
    $scopeType = trim((string)($src['scope_type'] ?? ''));
    $scopeKey = trim((string)($src['scope_key'] ?? ''));
    $effectiveFrom = trim((string)($src['effective_from'] ?? ''));
    $effectiveTo = trim((string)($src['effective_to'] ?? ''));
    $employeeRate = (float)($src['employee_rate'] ?? 0);
    $employerRate = (float)($src['employer_rate'] ?? 0);
    $note = trim((string)($src['note'] ?? ''));

    return [
        'scheme_code' => $schemeCode,
        'scope_type' => $scopeType,
        'scope_key' => ($scopeKey === '' ? null : $scopeKey),
        'effective_from' => $effectiveFrom,
        'effective_to' => ($effectiveTo === '' ? null : $effectiveTo),
        'employee_rate' => $employeeRate,
        'employer_rate' => $employerRate,
        'note' => ($note === '' ? null : $note),
    ];
}

function validate_rate_input(array &$data): ?string
{
    if (!in_array($data['scheme_code'], ['health', 'care', 'pension', 'employment', 'childcare'], true)) {
        return '制度区分が不正です';
    }
    if (!in_array($data['scope_type'], ['national', 'prefecture', 'business_type'], true)) {
        return '適用範囲が不正です';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['effective_from'])) {
        return '適用開始日は YYYY-MM-DD 形式で入力してください';
    }
    if ($data['effective_to'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['effective_to'])) {
        return '適用終了日は YYYY-MM-DD 形式で入力してください';
    }

    if ($data['scope_type'] === 'national') {
        $data['scope_key'] = null;
    } elseif ($data['scope_type'] === 'prefecture') {
        if (!preg_match('/^\d{2}$/', (string)($data['scope_key'] ?? ''))) {
            return '都道府県コードは2桁で入力してください';
        }
    } elseif (!in_array((string)($data['scope_key'] ?? ''), ['general', 'agri', 'construction'], true)) {
        return '事業区分は「一般」「農林水産・清酒製造」「建設」のいずれかです';
    }

    return null;
}

function find_duplicate_rate_set(PDO $pdo, array $data, int $excludeId = 0): ?int
{
    $sql = "
        SELECT id
        FROM insurance_rate_sets
        WHERE tenant_id IS NULL
          AND scheme_code = :scheme_code
          AND scope_type = :scope_type
          AND (
                (:scope_key IS NULL AND scope_key IS NULL)
                OR scope_key = :scope_key
              )
          AND effective_from = :effective_from
    ";
    if ($excludeId > 0) {
        $sql .= " AND id <> :exclude_id";
    }
    $sql .= " LIMIT 1";

    $st = $pdo->prepare($sql);
    $params = [
        ':scheme_code' => $data['scheme_code'],
        ':scope_type' => $data['scope_type'],
        ':scope_key' => $data['scope_key'],
        ':effective_from' => $data['effective_from'],
    ];
    if ($excludeId > 0) {
        $params[':exclude_id'] = $excludeId;
    }
    $st->execute($params);
    $id = (int)($st->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

si_ensure_schema($pdo);

$err = null;
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'create_rate_set') {
        $data = normalize_rate_input($_POST);
        $err = validate_rate_input($data);

        if ($err === null && find_duplicate_rate_set($pdo, $data) !== null) {
            $err = '同じ制度・適用範囲・識別子・適用開始日のマスタが既にあります';
        }

        if ($err === null) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO insurance_rate_sets
                        (tenant_id, scheme_code, scope_type, scope_key, effective_from, effective_to, employee_rate, employer_rate, note)
                    VALUES
                        (:tenant_id, :scheme_code, :scope_type, :scope_key, :effective_from, :effective_to, :employee_rate, :employer_rate, :note)
                ");
                $stmt->execute([
                    ':tenant_id' => null,
                    ':scheme_code' => $data['scheme_code'],
                    ':scope_type' => $data['scope_type'],
                    ':scope_key' => $data['scope_key'],
                    ':effective_from' => $data['effective_from'],
                    ':effective_to' => $data['effective_to'],
                    ':employee_rate' => $data['employee_rate'],
                    ':employer_rate' => $data['employer_rate'],
                    ':note' => $data['note'],
                ]);

                audit($pdo, $meId, 'insurance_rate_sets.create', [
                    'tenant_id' => null,
                    'scheme_code' => $data['scheme_code'],
                    'scope_type' => $data['scope_type'],
                    'scope_key' => $data['scope_key'],
                    'effective_from' => $data['effective_from'],
                ]);

                $ok = '料率マスタを追加しました';
            } catch (Throwable $e) {
                $err = '料率マスタの追加に失敗しました: ' . $e->getMessage();
            }
        }
    } elseif ($act === 'update_rate_set') {
        $id = (int)($_POST['id'] ?? 0);
        $data = normalize_rate_input($_POST);
        if ($id <= 0) {
            $err = '更新対象のIDが不正です';
        } else {
            $err = validate_rate_input($data);
        }

        if ($err === null && find_duplicate_rate_set($pdo, $data, $id) !== null) {
            $err = '同じ制度・適用範囲・識別子・適用開始日のマスタが既にあります';
        }

        if ($err === null) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE insurance_rate_sets
                    SET scheme_code = :scheme_code,
                        scope_type = :scope_type,
                        scope_key = :scope_key,
                        effective_from = :effective_from,
                        effective_to = :effective_to,
                        employee_rate = :employee_rate,
                        employer_rate = :employer_rate,
                        note = :note
                    WHERE id = :id
                      AND tenant_id IS NULL
                    LIMIT 1
                ");
                $stmt->execute([
                    ':scheme_code' => $data['scheme_code'],
                    ':scope_type' => $data['scope_type'],
                    ':scope_key' => $data['scope_key'],
                    ':effective_from' => $data['effective_from'],
                    ':effective_to' => $data['effective_to'],
                    ':employee_rate' => $data['employee_rate'],
                    ':employer_rate' => $data['employer_rate'],
                    ':note' => $data['note'],
                    ':id' => $id,
                ]);

                audit($pdo, $meId, 'insurance_rate_sets.update', [
                    'id' => $id,
                    'scheme_code' => $data['scheme_code'],
                    'scope_type' => $data['scope_type'],
                    'scope_key' => $data['scope_key'],
                    'effective_from' => $data['effective_from'],
                ]);

                $ok = '料率マスタを更新しました';
            } catch (Throwable $e) {
                $err = '料率マスタの更新に失敗しました: ' . $e->getMessage();
            }
        }
    } elseif ($act === 'delete_rate_set') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $err = '削除対象のIDが不正です';
        } else {
            try {
                $stmt = $pdo->prepare("
                    DELETE FROM insurance_rate_sets
                    WHERE id = :id
                      AND tenant_id IS NULL
                    LIMIT 1
                ");
                $stmt->execute([':id' => $id]);

                audit($pdo, $meId, 'insurance_rate_sets.delete', [
                    'id' => $id,
                ]);

                $ok = '料率マスタを削除しました';
            } catch (Throwable $e) {
                $err = '料率マスタの削除に失敗しました: ' . $e->getMessage();
            }
        }
    }
}

$rateSetsStmt = $pdo->prepare("
    SELECT id, scheme_code, scope_type, scope_key, effective_from, effective_to, employee_rate, employer_rate, note, updated_at
    FROM insurance_rate_sets
    WHERE tenant_id IS NULL
    ORDER BY effective_from DESC, id DESC
    LIMIT 500
");
$rateSetsStmt->execute();
$rateSets = $rateSetsStmt->fetchAll();
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>社会保険マスタ</title>
    <style>
    body { font-family: system-ui; padding: 18px; color: #111; }
    .card { border: 1px solid #ddd; border-radius: 12px; padding: 14px; max-width: 1600px; margin-bottom: 16px; }
    .row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    select, input, button { padding:10px; border:1px solid #ddd; border-radius:10px; }
    button { cursor:pointer; font-weight:700; }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th, td { border-bottom:1px solid #eee; padding:8px; font-size:13px; text-align:left; }
    th { background:#fafafa; }
    .err { background:#ffecec; border:1px solid #ffb3b3; padding:10px; border-radius:10px; margin:10px 0; }
    .ok { background:#eaffea; border:1px solid #9be59b; padding:10px; border-radius:10px; margin:10px 0; }
    .compactInput { width:100px; padding:8px; }
    .compactSelect { padding:8px; }
    .hint { color:#555; font-size:12px; }
    .rowForm { display: contents; }
    .actions { display:flex; gap:8px; }
    .danger { background:#fff3f3; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/_top.php'; ?>

    <div class="card">
        <h2 style="margin:0 0 10px;">社会保険マスタ</h2>
        <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>
        <p class="hint">全体共通の社会保険率マスタです。全国一律は識別子不要、都道府県別は2桁コード、事業区分別は区分選択で登録します。</p>

        <form method="post" class="row" style="margin-top:12px;">
            <input type="hidden" name="action" value="create_rate_set">
            <select name="scheme_code" class="compactSelect">
                <option value="health">健康保険</option>
                <option value="care">介護保険</option>
                <option value="pension">厚生年金</option>
                <option value="employment">雇用保険</option>
                <option value="childcare">こども支援金</option>
            </select>
            <select name="scope_type" class="compactSelect" id="scope_type">
                <option value="national">全国一律</option>
                <option value="prefecture">都道府県別</option>
                <option value="business_type">事業区分別</option>
            </select>
            <input name="scope_key" id="scope_key_prefecture" class="compactInput" placeholder="都道府県コード（例: 13）" disabled>
            <select name="scope_key" id="scope_key_business" class="compactSelect" disabled style="display:none;">
                <option value="general">一般</option>
                <option value="agri">農林水産・清酒製造</option>
                <option value="construction">建設</option>
            </select>
            <input value="全国一律のため不要" id="scope_key_national" class="compactInput" disabled style="display:none; width:180px;">
            <input type="date" name="effective_from" value="<?= h(date('Y-m-01')) ?>">
            <input type="date" name="effective_to">
            <input name="employee_rate" class="compactInput" placeholder="本人負担率">
            <input name="employer_rate" class="compactInput" placeholder="事業主負担率">
            <input name="note" style="min-width:220px;" placeholder="令和8年度 東京 健保">
            <button type="submit">マスタ追加</button>
        </form>
        <p class="hint">識別子の入力方法: 全国一律は不要 / 都道府県別は `13` のような2桁コード / 事業区分別はプルダウン選択</p>

        <table>
            <thead>
                <tr>
                    <th style="width:70px;">id</th>
                    <th style="width:120px;">制度</th>
                    <th style="width:120px;">適用範囲</th>
                    <th style="width:120px;">識別子</th>
                    <th style="width:120px;">適用開始</th>
                    <th style="width:120px;">適用終了</th>
                    <th style="width:100px;">本人負担率</th>
                    <th style="width:100px;">事業主負担率</th>
                    <th>備考</th>
                    <th style="width:180px;">更新日時</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rateSets as $r): ?>
                <tr>
                    <form method="post" class="rowForm">
                    <td>
                        <?= (int)$r['id'] ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    </td>
                    <td>
                        <select name="scheme_code" class="compactSelect">
                            <option value="health" <?= ((string)$r['scheme_code'] === 'health') ? 'selected' : '' ?>>健康保険</option>
                            <option value="care" <?= ((string)$r['scheme_code'] === 'care') ? 'selected' : '' ?>>介護保険</option>
                            <option value="pension" <?= ((string)$r['scheme_code'] === 'pension') ? 'selected' : '' ?>>厚生年金</option>
                            <option value="employment" <?= ((string)$r['scheme_code'] === 'employment') ? 'selected' : '' ?>>雇用保険</option>
                            <option value="childcare" <?= ((string)$r['scheme_code'] === 'childcare') ? 'selected' : '' ?>>こども支援金</option>
                        </select>
                    </td>
                    <td>
                        <select name="scope_type" class="compactSelect">
                            <option value="national" <?= ((string)$r['scope_type'] === 'national') ? 'selected' : '' ?>>全国一律</option>
                            <option value="prefecture" <?= ((string)$r['scope_type'] === 'prefecture') ? 'selected' : '' ?>>都道府県別</option>
                            <option value="business_type" <?= ((string)$r['scope_type'] === 'business_type') ? 'selected' : '' ?>>事業区分別</option>
                        </select>
                    </td>
                    <td><input name="scope_key" class="compactInput" value="<?= h((string)($r['scope_key'] ?? '')) ?>"></td>
                    <td><input type="date" name="effective_from" value="<?= h((string)$r['effective_from']) ?>"></td>
                    <td><input type="date" name="effective_to" value="<?= h((string)($r['effective_to'] ?? '')) ?>"></td>
                    <td><input name="employee_rate" class="compactInput" value="<?= hmoney($r['employee_rate']) ?>"></td>
                    <td><input name="employer_rate" class="compactInput" value="<?= hmoney($r['employer_rate']) ?>"></td>
                    <td><input name="note" value="<?= h((string)($r['note'] ?? '')) ?>" style="min-width:220px;"></td>
                    <td>
                        <div><?= h((string)$r['updated_at']) ?></div>
                        <div class="actions" style="margin-top:6px;">
                            <button type="submit" name="action" value="update_rate_set">更新</button>
                            <button type="submit" name="action" value="delete_rate_set" class="danger" onclick="return confirm('この料率マスタを削除しますか？');">削除</button>
                        </div>
                    </td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rateSets): ?>
                <tr>
                    <td colspan="10" style="color:#666;">まだ料率マスタがありません</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
    (function() {
        const scopeType = document.getElementById('scope_type');
        const prefecture = document.getElementById('scope_key_prefecture');
        const business = document.getElementById('scope_key_business');
        const national = document.getElementById('scope_key_national');
        if (!scopeType || !prefecture || !business || !national) return;

        const apply = () => {
            const v = scopeType.value;
            prefecture.disabled = true;
            business.disabled = true;
            prefecture.style.display = 'none';
            business.style.display = 'none';
            national.style.display = 'none';

            if (v === 'prefecture') {
                prefecture.disabled = false;
                prefecture.style.display = '';
            } else if (v === 'business_type') {
                business.disabled = false;
                business.style.display = '';
            } else {
                national.style.display = '';
            }
        };

        apply();
        scopeType.addEventListener('change', apply);
    })();
    </script>
</body>
</html>
