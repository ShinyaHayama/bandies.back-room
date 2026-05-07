<?php

/**
 * ✅ ファイル名: /admin/shift_templates.php
 * ✅ 書き込み場所: このファイルを「丸ごと置き換え」
 */

declare(strict_types=1);

/**
 * ✅ /admin/shift_templates.php（深夜対応版）
 * 曜日固定の「シフトテンプレ」を登録/編集/削除する最小UI
 *
 * 深夜対応:
 * - 終了 <= 開始 でもOK（end_next_day=1 として扱う）
 * - UIに「翌日まで」チェックを追加（自動判定もする）
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';

require_once __DIR__ . '/../api/lib/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];
function isValidCsrf(string $t): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $t);
}

// store
$storeId = (int)($_GET['store_id'] ?? 0);
$storesStmt = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:t ORDER BY id ASC");
$storesStmt->execute([':t' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (!$stores) {
    http_response_code(400);
    echo "storesなし";
    exit;
}
$validStoreIds = array_map(fn($s) => (int)$s['id'], $stores);
if ($storeId <= 0 || !in_array($storeId, $validStoreIds, true)) $storeId = (int)$stores[0]['id'];

$storeName = '';
foreach ($stores as $st) if ((int)$st['id'] === $storeId) $storeName = (string)$st['name'];

$employeeIdFilter = (int)($_GET['employee_id'] ?? 0);
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');

// employees
$empStmt = $pdo->prepare("
  SELECT id, display_name, employment_status
  FROM employees
  WHERE tenant_id=:t AND store_id=:s
  ORDER BY sort_order ASC, id ASC
");
$empStmt->execute([':t' => $tenantId, ':s' => $storeId]);
$employees = $empStmt->fetchAll();
$empMap = [];
foreach ($employees as $e) $empMap[(int)$e['id']] = $e;

$wdayJa = ['日', '月', '火', '水', '木', '金', '土'];

$errors = [];
$success = null;

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!isValidCsrf($token)) {
        $errors[] = 'CSRF不正（再読み込みしてください）';
    } else {
        $action = (string)($_POST['action'] ?? '');

        try {
            if ($action === 'upsert') {
                $templateId = (int)($_POST['template_id'] ?? 0);
                $eid = (int)($_POST['employee_id'] ?? 0);
                $wdayRaw = $_POST['wday'] ?? [];
                if (!is_array($wdayRaw)) $wdayRaw = [$wdayRaw];
                $wdayList = [];
                foreach ($wdayRaw as $w) {
                    $wi = (int)$w;
                    if (!in_array($wi, $wdayList, true)) $wdayList[] = $wi;
                }
                $start = trim((string)($_POST['start_time'] ?? ''));
                $end   = trim((string)($_POST['end_time'] ?? ''));
                $br    = (int)($_POST['break_minutes'] ?? 0);
                $note  = trim((string)($_POST['note'] ?? ''));

                // ✅ UIチェック（手動指定）: on => 1
                $endNextDay = !empty($_POST['end_next_day']) ? 1 : 0;

                if ($eid <= 0 || !isset($empMap[$eid])) $errors[] = '従業員が不正です';
                if (empty($wdayList)) {
                    $errors[] = '曜日が不正です';
                } else {
                    foreach ($wdayList as $w) {
                        if ($w < 0 || $w > 6) {
                            $errors[] = '曜日が不正です';
                            break;
                        }
                    }
                }
                if ($start === '' || !preg_match('/^\d{2}:\d{2}$/', $start)) $errors[] = '開始時刻が不正です';
                if ($end === '' || !preg_match('/^\d{2}:\d{2}$/', $end)) $errors[] = '終了時刻が不正です';
                if ($br < 0) $br = 0;
                if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

                if (!$errors) {
                    // ✅ 自動判定：終了<=開始 なら翌日扱い
                    if (strtotime($end) <= strtotime($start)) $endNextDay = 1;

                    // ✅ 同時に「開始=終了」は不可（24hシフトは今回は不許可）
                    if ($start === $end) $errors[] = '開始と終了が同じです（24時間扱いは未対応）';
                }

                if (!$errors) {
                    if ($templateId > 0) {
                        $oldStmt = $pdo->prepare("
                          SELECT employee_id, start_time, end_time, end_next_day, break_minutes, note
                            FROM shift_templates
                           WHERE tenant_id=:t AND store_id=:s AND id=:id AND deleted_at IS NULL
                           LIMIT 1
                        ");
                        $oldStmt->execute([
                            ':t' => $tenantId,
                            ':s' => $storeId,
                            ':id' => $templateId,
                        ]);
                        $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$old) throw new RuntimeException('更新できませんでした（対象なし）');

                        $oldEmp = (int)$old['employee_id'];
                        $oldNote = $old['note'];

                        $grpStmt = $pdo->prepare("
                          SELECT id, wday
                            FROM shift_templates
                           WHERE tenant_id=:t AND store_id=:s AND employee_id=:eid
                             AND start_time=:st AND end_time=:et AND break_minutes=:br
                             AND COALESCE(end_next_day, 0)=:nd
                             AND (note <=> :note)
                             AND deleted_at IS NULL
                        ");
                        $grpStmt->execute([
                            ':t' => $tenantId,
                            ':s' => $storeId,
                            ':eid' => $oldEmp,
                            ':st' => (string)$old['start_time'],
                            ':et' => (string)$old['end_time'],
                            ':br' => (int)$old['break_minutes'],
                            ':nd' => (int)($old['end_next_day'] ?? 0),
                            ':note' => $oldNote,
                        ]);
                        $groupRows = $grpStmt->fetchAll(PDO::FETCH_ASSOC);
                        $groupByWday = [];
                        foreach ($groupRows as $gr) {
                            $gw = (int)($gr['wday'] ?? -1);
                            if ($gw >= 0 && $gw <= 6) $groupByWday[$gw] = (int)$gr['id'];
                        }

                        if ($oldEmp === $eid) {
                            foreach ($wdayList as $w) {
                                if (isset($groupByWday[$w])) {
                                    $u = $pdo->prepare("
                                      UPDATE shift_templates
                                         SET employee_id=:eid, wday=:w, start_time=:st, end_time=:et,
                                             end_next_day=:nd, break_minutes=:br, note=:note,
                                             updated_at=NOW(), deleted_at=NULL
                                       WHERE tenant_id=:t AND store_id=:s AND id=:id
                                       LIMIT 1
                                    ");
                                    $u->execute([
                                        ':eid' => $eid,
                                        ':w' => $w,
                                        ':st' => $start,
                                        ':et' => $end,
                                        ':nd' => $endNextDay,
                                        ':br' => $br,
                                        ':note' => ($note !== '' ? $note : null),
                                        ':t' => $tenantId,
                                        ':s' => $storeId,
                                        ':id' => $groupByWday[$w],
                                    ]);
                                } else {
                                    $ins = $pdo->prepare("
                                      INSERT INTO shift_templates
                                        (tenant_id, store_id, employee_id, wday, start_time, end_time, end_next_day, break_minutes, note, created_at, updated_at)
                                      VALUES
                                        (:t,:s,:eid,:w,:st,:et,:nd,:br,:note,NOW(),NOW())
                                    ");
                                    $ins->execute([
                                        ':t' => $tenantId,
                                        ':s' => $storeId,
                                        ':eid' => $eid,
                                        ':w' => $w,
                                        ':st' => $start,
                                        ':et' => $end,
                                        ':nd' => $endNextDay,
                                        ':br' => $br,
                                        ':note' => ($note !== '' ? $note : null),
                                    ]);
                                }
                            }

                            foreach ($groupByWday as $w => $gid) {
                                if (!in_array($w, $wdayList, true)) {
                                    $d = $pdo->prepare("
                                      UPDATE shift_templates
                                         SET deleted_at=NOW(), updated_at=NOW()
                                       WHERE tenant_id=:t AND store_id=:s AND id=:id
                                       LIMIT 1
                                    ");
                                    $d->execute([
                                        ':t' => $tenantId,
                                        ':s' => $storeId,
                                        ':id' => $gid,
                                    ]);
                                }
                            }
                        } else {
                            foreach ($groupByWday as $gid) {
                                $d = $pdo->prepare("
                                  UPDATE shift_templates
                                     SET deleted_at=NOW(), updated_at=NOW()
                                   WHERE tenant_id=:t AND store_id=:s AND id=:id
                                   LIMIT 1
                                ");
                                $d->execute([
                                    ':t' => $tenantId,
                                    ':s' => $storeId,
                                    ':id' => $gid,
                                ]);
                            }
                            foreach ($wdayList as $w) {
                                $stmt = $pdo->prepare("
                                  INSERT INTO shift_templates
                                    (tenant_id, store_id, employee_id, wday, start_time, end_time, end_next_day, break_minutes, note, created_at, updated_at)
                                  VALUES
                                    (:t,:s,:eid,:w,:st,:et,:nd,:br,:note,NOW(),NOW())
                                ");
                                $stmt->execute([
                                    ':t' => $tenantId,
                                    ':s' => $storeId,
                                    ':eid' => $eid,
                                    ':w' => $w,
                                    ':st' => $start,
                                    ':et' => $end,
                                    ':nd' => $endNextDay,
                                    ':br' => $br,
                                    ':note' => ($note !== '' ? $note : null),
                                ]);
                            }
                        }

                        $success = 'テンプレを更新しました';
                    } else {
                        foreach ($wdayList as $w) {
                            $stmt = $pdo->prepare("
                              INSERT INTO shift_templates
                                (tenant_id, store_id, employee_id, wday, start_time, end_time, end_next_day, break_minutes, note, created_at, updated_at)
                              VALUES
                                (:t,:s,:eid,:w,:st,:et,:nd,:br,:note,NOW(),NOW())
                            ");
                            $stmt->execute([
                                ':t' => $tenantId,
                                ':s' => $storeId,
                                ':eid' => $eid,
                                ':w' => $w,
                                ':st' => $start,
                                ':et' => $end,
                                ':nd' => $endNextDay,
                                ':br' => $br,
                                ':note' => ($note !== '' ? $note : null),
                            ]);
                        }
                        $success = 'テンプレを追加しました';
                    }
                }
            } elseif ($action === 'delete') {
                $templateId = (int)($_POST['template_id'] ?? 0);
                if ($templateId <= 0) $errors[] = 'template_id不正';
                if (!$errors) {
                    $baseStmt = $pdo->prepare("
                      SELECT employee_id, start_time, end_time, end_next_day, break_minutes, note
                        FROM shift_templates
                       WHERE tenant_id=:t AND store_id=:s AND id=:id AND deleted_at IS NULL
                       LIMIT 1
                    ");
                    $baseStmt->execute([':t' => $tenantId, ':s' => $storeId, ':id' => $templateId]);
                    $base = $baseStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$base) throw new RuntimeException('削除できませんでした（対象なし）');

                    $stmt = $pdo->prepare("
                      UPDATE shift_templates
                         SET deleted_at=NOW(), updated_at=NOW()
                       WHERE tenant_id=:t
                         AND store_id=:s
                         AND employee_id=:eid
                         AND start_time=:st
                         AND end_time=:et
                         AND COALESCE(end_next_day, 0)=:nd
                         AND break_minutes=:br
                         AND (note <=> :note)
                         AND deleted_at IS NULL
                    ");
                    $stmt->execute([
                        ':t' => $tenantId,
                        ':s' => $storeId,
                        ':eid' => (int)$base['employee_id'],
                        ':st' => (string)$base['start_time'],
                        ':et' => (string)$base['end_time'],
                        ':nd' => (int)($base['end_next_day'] ?? 0),
                        ':br' => (int)$base['break_minutes'],
                        ':note' => $base['note'],
                    ]);
                    $deletedCount = (int)$stmt->rowCount();
                    if ($deletedCount === 0) throw new RuntimeException('削除できませんでした（対象なし）');
                    $success = 'テンプレを削除しました（' . $deletedCount . '件）';
                }
            } else {
                $errors[] = 'action不正';
            }
        } catch (Throwable $e) {
            $errors[] = 'エラー: ' . $e->getMessage();
        }
    }
}

// list templates（✅ end_next_day を取得）
$params = [':t' => $tenantId, ':s' => $storeId];
$whereEmp = '';
if ($employeeIdFilter > 0) {
    $whereEmp = ' AND employee_id = :eid ';
    $params[':eid'] = $employeeIdFilter;
}

$listStmt = $pdo->prepare("
  SELECT id, employee_id, wday, start_time, end_time, end_next_day, break_minutes, note, updated_at
  FROM shift_templates
  WHERE tenant_id=:t AND store_id=:s AND deleted_at IS NULL
  {$whereEmp}
  ORDER BY employee_id ASC, wday ASC, start_time ASC
");
$listStmt->execute($params);
$templates = $listStmt->fetchAll();

$editId = (int)($_GET['edit_id'] ?? 0);
$editRow = null;
$editWdays = [];

$templateGroups = [];
foreach ($templates as $r) {
    $key = (int)$r['employee_id'] . '|' . (string)$r['start_time'] . '|' . (string)$r['end_time'] . '|' . (int)($r['end_next_day'] ?? 0) . '|' . (int)$r['break_minutes'] . '|' . (string)($r['note'] ?? '');
    if (!isset($templateGroups[$key])) {
        $templateGroups[$key] = [
            'rep' => $r,
            'wdays' => [],
            'ids' => [],
            'updated_at' => (string)($r['updated_at'] ?? ''),
        ];
    }
    $templateGroups[$key]['wdays'][] = (int)$r['wday'];
    $templateGroups[$key]['ids'][] = (int)$r['id'];
    if ((string)($r['updated_at'] ?? '') > $templateGroups[$key]['updated_at']) {
        $templateGroups[$key]['rep'] = $r;
        $templateGroups[$key]['updated_at'] = (string)($r['updated_at'] ?? '');
    }
}

$templateGroupList = array_values($templateGroups);
foreach ($templateGroupList as &$g) {
    $g['wdays'] = array_values(array_unique($g['wdays']));
    sort($g['wdays']);
}
unset($g);

if ($editId > 0) {
    foreach ($templateGroupList as $g) {
        if (in_array($editId, $g['ids'], true)) {
            $editRow = $g['rep'];
            $editWdays = $g['wdays'];
            break;
        }
    }
}

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/shift_templates.php', PHP_URL_PATH) ?: '/admin/shift_templates.php';
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>シフトテンプレ管理</title>
    <style>
    body {
        margin: 0;
        font-family: system-ui, -apple-system, sans-serif;
        background: #ffffff;
        color: #444;
        font-size: 13px;
    }

    .page {
        padding: 24px;
        padding-bottom: 64px
    }

    .grid {
        display: grid;
        grid-template-columns: 420px 1fr;
        gap: 16px;
        align-items: start
    }

    @media (max-width:980px) {
        .grid {
            grid-template-columns: 1fr
        }
    }

    .card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 16px
    }

    .muted {
        color: #777;
        font-size: 12px
    }

    label {
        display: block;
        margin-top: 12px;
        font-weight: 700;
        color: #555;
        font-size: 12px;
    }

    input,
    select,
    textarea {
        width: 100%;
        padding: 10px;
        margin-top: 6px;
        border: 1px solid #ddd;
        border-radius: 10px;
        box-sizing: border-box
    }

    textarea {
        min-height: 70px
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 34px;
        padding: 0 12px;
        border-radius: 10px;
        border: 1px solid #e5e7eb;
        background: #fff;
        text-decoration: none;
        color: #555;
        font-weight: 700;
        box-shadow: 0 1px 0 rgba(0, 0, 0, .02)
    }

    .btn.primary {
        border-color: rgba(0, 0, 0, .14);
        background: #111;
        color: #fff
    }

    .btn.danger {
        border-color: #ffb3b3;
        background: #ffecec;
        color: #b00020
    }

    .filterForm {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }

    .filterForm select {
        width: auto;
        min-width: 220px;
        margin-top: 0;
        height: 34px;
        padding: 0 12px;
        border-radius: 14px;
    }

    .filterForm .btn {
        margin-top: 0;
        border-radius: 14px;
    }

    .row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap
    }

    .subCard {
        margin-top: 12px;
        background: #fbfbfb;
        border: 1px solid #eee;
        border-radius: 10px;
        padding: 10px 12px
    }

    .subCard summary {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        list-style: none;
        cursor: pointer;
        user-select: none;
        color: #555;
        font-weight: 700;
        font-size: 13px
    }

    .subCard summary::-webkit-details-marker {
        display: none
    }

    .subGrid {
        margin-top: 10px;
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 8px;
        align-items: end
    }

    @media (max-width:980px) {
        .subGrid {
            grid-template-columns: 1fr
        }
    }

    .subActions {
        margin-top: 10px;
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: center
    }

    .miniBtn {
        height: 30px;
        padding: 0 10px;
        border-radius: 10px;
        border: 1px solid #ddd;
        background: #fff;
        color: #555;
        font-weight: 700;
        cursor: pointer
    }

    .applyBtn {
        height: 30px;
        padding: 0 10px;
        border-radius: 10px;
        border: 1px solid #111;
        background: #111;
        color: #fff;
        font-weight: 800;
        cursor: pointer
    }

    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0
    }

    th,
    td {
        border-bottom: 1px solid #eee;
        padding: 10px;
        vertical-align: top;
        font-size: 12px;
    }

    th {
        background: #fff;
        position: sticky;
        top: 0;
        z-index: 1;
        color: #555;
        font-weight: 700
    }

    .pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #eee;
        border-radius: 999px;
        padding: 3px 8px;
        background: #fafafa;
        font-size: 12px;
        color: #666
    }

    .ok {
        background: #eaffea;
        border: 1px solid #9be59b;
        padding: 10px;
        border-radius: 10px;
        margin-top: 10px
    }

    .err {
        background: #ffecec;
        border: 1px solid #ffb3b3;
        padding: 10px;
        border-radius: 10px;
        margin-top: 10px
    }

    .checkRow {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px
    }

    .checkRow input {
        width: auto;
        margin: 0
    }

    /* ✅ 自動判定の見た目（任意） */
    .checkRow.auto-on {
        background: #f6fff6;
        border: 1px solid #bde5bd;
        padding: 8px 10px;
        border-radius: 10px;
    }
    </style>
</head>

<body data-mode="settings">
    <?php require_once __DIR__ . '/_header.php'; ?>

    <div class="shiftNavTabsHost">
        <?php require_once __DIR__ . '/_shift_nav_tabs.php'; ?>
    </div>
    <div class="page">
        <div class="grid">

            <div class="card">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                    <div>
                        <div style="font-weight:900;color:#111;font-size:16px;">シフトテンプレ管理</div>
                        <div class="muted">店舗：<?= h($storeName) ?>（store_id=<?= (int)$storeId ?>）</div>
                    </div>
                    <a class="btn" href="/admin/shifts.php?store_id=<?= (int)$storeId ?>">シフト表へ</a>
                </div>

                <?php if ($success): ?><div class="ok"><?= h($success) ?></div><?php endif; ?>
<?php if ($errors): ?>
                <div class="err"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div>
                <?php endif; ?>

                <form method="post" style="margin-top:10px;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="upsert">
                    <input type="hidden" name="template_id" value="<?= $editRow ? (int)$editRow['id'] : 0 ?>">

                    <label>従業員</label>
                    <select name="employee_id" required>
                        <option value="">選択してください</option>
                        <?php foreach ($employees as $e): ?>
                        <?php
                            $eid = (int)$e['id'];
                            $sel = $editRow && (int)$editRow['employee_id'] === $eid;
                            ?>
                        <option value="<?= $eid ?>" <?= $sel ? 'selected' : '' ?>>
                            <?= h((string)$e['display_name']) ?>（ID:<?= $eid ?>）
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <label>曜日</label>
                    <div class="row">
                        <?php for ($w = 0; $w <= 6; $w++): ?>
                        <?php $sel = $editRow && in_array($w, $editWdays, true); ?>
                        <label class="checkRow" style="margin-top:0;">
                            <input type="checkbox" name="wday[]" value="<?= $w ?>" <?= $sel ? 'checked' : '' ?>>
                            <span><?= h($wdayJa[$w]) ?></span>
                        </label>
                        <?php endfor; ?>
                    </div>

                    <div class="row" style="margin-top:10px;">
                        <div style="flex:1;min-width:160px;">
                            <label style="margin-top:0;">開始</label>
                            <input type="time" name="start_time"
                                value="<?= $editRow ? h(substr((string)$editRow['start_time'], 0, 5)) : '' ?>" required>
                        </div>
                        <div style="flex:1;min-width:160px;">
                            <label style="margin-top:0;">終了</label>
                            <input type="time" name="end_time"
                                value="<?= $editRow ? h(substr((string)$editRow['end_time'], 0, 5)) : '' ?>" required>
                        </div>
                    </div>

                    <!-- ✅ 深夜対応：翌日まで -->
                    <?php $checked = $editRow && !empty($editRow['end_next_day']); ?>
                    <div class="checkRow">
                        <input type="checkbox" id="end_next_day" name="end_next_day" value="1"
                            <?= $checked ? 'checked' : '' ?>>
                        <label for="end_next_day" class="muted" style="margin:0;font-weight:600;">
                            日をまたぐ場合（例：22:00〜05:00）
                        </label>
                    </div>

                    <label>休憩（分）</label>
                    <input type="number" name="break_minutes" min="0" step="1"
                        value="<?= $editRow ? (int)$editRow['break_minutes'] : 0 ?>">

                    <label>メモ</label>
                    <textarea name="note" maxlength="255"
                        placeholder="例：毎週固定 / 研修 など"><?= $editRow && $editRow['note'] !== null ? h((string)$editRow['note']) : '' ?></textarea>

                    <button class="btn primary" type="submit" style="width:100%;margin-top:14px;height:40px;">
                        <?= $editRow ? '更新' : '追加' ?>
                    </button>

                    <?php if ($editRow): ?>
                    <a class="btn" href="/admin/shift_templates.php?store_id=<?= (int)$storeId ?>"
                        style="margin-top:8px;width:100%;height:40px;">編集をやめる</a>
                    <?php endif; ?>

                    <!-- <div class="muted" style="margin-top:10px;">
                        ※深夜は「翌日まで」で保持され、例外計算の基礎になります
                    </div> -->
                </form>
            </div>

            <div class="card" style="overflow:auto;">
                <div class="subCard" style="margin-bottom:12px;">
                    <details>
                        <summary>
                            <span>固定出勤テンプレを反映</span>
                            <span style="display:flex;gap:8px;align-items:center;">
                                <span class="muted">必要なときだけ</span>
                                <span class="muted">▼</span>
                            </span>
                        </summary>

                        <form method="post" action="/admin/shift_templates_apply.php" style="margin-top:10px;"
                            data-preserve-scroll="1">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                            <input type="hidden" name="return_to"
                                value="<?= h('/admin/shifts.php?store_id=' . (int)$storeId) ?>">

                            <div class="subGrid">
                                <div>
                                    <label class="muted">開始日</label>
                                    <input type="date" name="date_from" value="<?= h($currentMonthStart) ?>" required>
                                </div>
                                <div>
                                    <label class="muted">終了日</label>
                                    <input type="date" name="date_to" value="<?= h($currentMonthEnd) ?>" required>
                                </div>
                                <div>
                                    <label class="muted">対象（空=全員）「表示中の50人対象」</label>
                                    <select name="employee_id">
                                        <option value="0">全員</option>
                                        <?php foreach ($employees as $e): ?>
                                            <option value="<?= (int)$e['id'] ?>">
                                                <?= h((string)$e['display_name']) ?>（ID:<?= (int)$e['id'] ?>）
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="subActions">
                                <button type="button" class="miniBtn" onclick="this.closest('details').open=false;">閉じる</button>
                                <button class="applyBtn" type="submit">反映する</button>
                            </div>
                        </form>
                    </details>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:900;color:#111;">テンプレ一覧</div>
                        <!-- <div class="muted">従業員で絞り込み可能</div> -->
                    </div>

                    <form method="get" action="<?= h($currentPath) ?>" class="filterForm" style="margin:0;">
                        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                        <select name="employee_id" style="min-width:220px;">
                            <option value="0">全員</option>
                            <?php foreach ($employees as $e): $eid = (int)$e['id']; ?>
                            <option value="<?= $eid ?>" <?= $employeeIdFilter === $eid ? 'selected' : '' ?>>
                                <?= h((string)$e['display_name']) ?>（ID:<?= $eid ?>）
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" type="submit">絞り込み</button>
                    </form>
                </div>

                <table style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th>従業員</th>
                            <th>曜日</th>
                            <th>時間</th>
                            <th>休憩</th>
                            <th>メモ</th>
                            <th style="width:220px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$templateGroupList): ?>
                        <tr>
                            <td colspan="6" class="muted">テンプレがありません</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($templateGroupList as $g): ?>
                        <?php
                                $r = $g['rep'];
                                $tid = (int)$r['id'];
                                $eid = (int)$r['employee_id'];
                                $ename = isset($empMap[$eid]) ? (string)$empMap[$eid]['display_name'] : ('ID:' . $eid);
                                $st = substr((string)$r['start_time'], 0, 5);
                                $et = substr((string)$r['end_time'], 0, 5);
                                $nd = !empty($r['end_next_day']);
                                $br = (int)$r['break_minutes'];
                                $wdays = $g['wdays'];
                                ?>
                        <tr>
                            <td>
                                <div style="font-weight:800;color:#333;"><?= h($ename) ?></div>
                                <div class="muted">ID:<?= $eid ?></div>
                            </td>
                            <td>
                                <?php foreach ($wdays as $w): ?>
                                    <span class="pill"><?= h($wdayJa[$w]) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <span class="pill"><b style="color:#333;"><?= h($st) ?>-<?= h($et) ?></b></span>
                                <?php if ($nd): ?><div class="muted" style="margin-top:4px;">翌日まで</div><?php endif; ?>
                            </td>
                            <td class="muted"><?= $br ?>分</td>
                            <td class="muted"><?= $r['note'] !== null ? h((string)$r['note']) : '' ?></td>
                            <td>
                                <div class="row">
                                    <a class="btn"
                                        href="/admin/shift_templates.php?store_id=<?= (int)$storeId ?>&edit_id=<?= $tid ?>">編集</a>
                                    <form method="post" style="margin:0;" onsubmit="return confirm('このテンプレを削除しますか？');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="template_id" value="<?= $tid ?>">
                                        <button class="btn danger" type="submit">削除</button>
                                    </form>
                                </div>
                                <div class="muted" style="margin-top:6px;">更新: <?= h((string)$r['updated_at']) ?></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- <div class="muted" style="margin-top:10px;">
                    ※一括反映は <b>シフト表（shifts.php）</b> 側で行います
                </div> -->
            </div>

        </div>
    </div>

    <footer
        style="position:fixed;left:0;right:0;bottom:0;text-align:center;padding:10px 0;font-size:12px;color:#777;background:rgba(255,255,255,.85);border-top:1px solid #eee;backdrop-filter:blur(6px)">
        &copy; AzureSystems by Fader
    </footer>
    <script>
    (function() {
        const startEl = document.querySelector('input[name="start_time"]');
        const endEl = document.querySelector('input[name="end_time"]');
        const chk = document.getElementById('end_next_day');
        const row = chk ? chk.closest('.checkRow') : null;

        if (!startEl || !endEl || !chk) return;

        // "HH:MM" -> 分
        function toMin(v) {
            if (!v || !/^\d{2}:\d{2}$/.test(v)) return null;
            const [h, m] = v.split(':').map(Number);
            return h * 60 + m;
        }

        // ✅ 終了 <= 開始 なら翌日チェックON（開始=終了はサーバ側で弾く想定）
        function autoNextDay() {
            const s = toMin(startEl.value);
            const e = toMin(endEl.value);

            // 未入力の間は何もしない（ユーザーの操作を邪魔しない）
            if (s === null || e === null) {
                if (row) row.classList.remove('auto-on');
                return;
            }

            const shouldNextDay = (e <= s);

            // 自動ONは上書きしてOK（ユーザーが戻したい場合は時間を変えればOFFになる）
            chk.checked = shouldNextDay;

            if (row) {
                if (shouldNextDay) row.classList.add('auto-on');
                else row.classList.remove('auto-on');
            }
        }

        // 入力のたびに自動判定
        startEl.addEventListener('input', autoNextDay);
        endEl.addEventListener('input', autoNextDay);
        startEl.addEventListener('change', autoNextDay);
        endEl.addEventListener('change', autoNextDay);

        // 初期表示（編集時も反映）
        autoNextDay();
    })();
    </script>

</body>

</html>
