<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../lib/public_calendar_hours.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
public_calendar_hours_ensure_schema($pdo);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

$storesStmt = $pdo->prepare("SELECT id, name FROM stores WHERE tenant_id=:t ORDER BY id ASC");
$storesStmt->execute([':t' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (!$stores) exit('storesなし');
$storeIds = array_map(fn($s) => (int)$s['id'], $stores);
$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) $storeId = (int)$stores[0]['id'];
$storeName = '';
foreach ($stores as $s) if ((int)$s['id'] === $storeId) $storeName = (string)$s['name'];

$baseDate = (string)($_GET['date'] ?? date('Y-m-d'));
$base = DateTimeImmutable::createFromFormat('Y-m-d', $baseDate) ?: new DateTimeImmutable('today');
$monthStart = $base->modify('first day of this month')->format('Y-m-01');
$monthEnd = $base->modify('last day of this month')->format('Y-m-d');
$message = '';
$error = '';
$wdayJa = ['日', '月', '火', '水', '木', '金', '土'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        $error = 'CSRFトークンが不正です。';
    } else {
        try {
            if ($action === 'save_weekly') {
                $up = $pdo->prepare("
                    INSERT INTO store_public_hours
                      (tenant_id, store_id, weekday, is_closed, open_time, close_time, note, created_at, updated_at)
                    VALUES
                      (:t, :s, :w, :closed, :open, :close, :note, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                      is_closed = VALUES(is_closed),
                      open_time = VALUES(open_time),
                      close_time = VALUES(close_time),
                      note = VALUES(note),
                      updated_at = NOW()
                ");
                $delSlots = $pdo->prepare("DELETE FROM store_public_hour_slots WHERE tenant_id=:t AND store_id=:s AND weekday=:w");
                $insSlot = $pdo->prepare("
                    INSERT INTO store_public_hour_slots
                      (tenant_id, store_id, weekday, slot_no, open_time, close_time, created_at, updated_at)
                    VALUES
                      (:t, :s, :w, :slot, :open, :close, NOW(), NOW())
                ");
                for ($w = 0; $w <= 6; $w++) {
                    $closed = isset($_POST['closed'][$w]) ? 1 : 0;
                    $note = trim((string)($_POST['note'][$w] ?? ''));
                    $slots = [];
                    for ($slot = 1; $slot <= 3; $slot++) {
                        $open = trim((string)($_POST['slot_open'][$w][$slot] ?? ''));
                        $close = trim((string)($_POST['slot_close'][$w][$slot] ?? ''));
                        if (!public_calendar_hours_valid_time($open) || !public_calendar_hours_valid_time($close)) {
                            throw new RuntimeException('時刻は HH:MM で入力してください。');
                        }
                        if ($open !== '' || $close !== '') {
                            if ($open === '' || $close === '') throw new RuntimeException('営業時間枠は開店と閉店を両方入力してください。');
                            $slots[] = ['slot' => $slot, 'open' => $open, 'close' => $close];
                        }
                    }
                    $firstOpen = ($closed === 0 && isset($slots[0])) ? $slots[0]['open'] : null;
                    $lastClose = ($closed === 0 && $slots) ? $slots[count($slots) - 1]['close'] : null;
                    $up->execute([
                        ':t' => $tenantId,
                        ':s' => $storeId,
                        ':w' => $w,
                        ':closed' => $closed,
                        ':open' => $firstOpen,
                        ':close' => $lastClose,
                        ':note' => mb_substr($note, 0, 255),
                    ]);
                    $delSlots->execute([':t' => $tenantId, ':s' => $storeId, ':w' => $w]);
                    if ($closed === 0) {
                        foreach ($slots as $i => $slotRow) {
                            $insSlot->execute([
                                ':t' => $tenantId,
                                ':s' => $storeId,
                                ':w' => $w,
                                ':slot' => $i + 1,
                                ':open' => $slotRow['open'],
                                ':close' => $slotRow['close'],
                            ]);
                        }
                    }
                }
                $message = '通常営業時間を保存しました。';
            } elseif ($action === 'save_override') {
                $date = (string)($_POST['business_date'] ?? '');
                if (!public_calendar_hours_valid_date($date)) throw new RuntimeException('日付が不正です。');
                $closed = isset($_POST['is_closed']) ? 1 : 0;
                $note = trim((string)($_POST['note'] ?? ''));
                $slots = [];
                for ($slot = 1; $slot <= 3; $slot++) {
                    $open = trim((string)($_POST['override_slot_open'][$slot] ?? ''));
                    $close = trim((string)($_POST['override_slot_close'][$slot] ?? ''));
                    if (!public_calendar_hours_valid_time($open) || !public_calendar_hours_valid_time($close)) {
                        throw new RuntimeException('時刻は HH:MM で入力してください。');
                    }
                    if ($open !== '' || $close !== '') {
                        if ($open === '' || $close === '') throw new RuntimeException('営業時間枠は開店と閉店を両方入力してください。');
                        $slots[] = ['slot' => $slot, 'open' => $open, 'close' => $close];
                    }
                }
                $firstOpen = ($closed === 0 && isset($slots[0])) ? $slots[0]['open'] : null;
                $lastClose = ($closed === 0 && $slots) ? $slots[count($slots) - 1]['close'] : null;
                $up = $pdo->prepare("
                    INSERT INTO store_public_hour_overrides
                      (tenant_id, store_id, business_date, is_closed, open_time, close_time, note, created_at, updated_at)
                    VALUES
                      (:t, :s, :d, :closed, :open, :close, :note, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                      is_closed = VALUES(is_closed),
                      open_time = VALUES(open_time),
                      close_time = VALUES(close_time),
                      note = VALUES(note),
                      updated_at = NOW()
                ");
                $up->execute([
                    ':t' => $tenantId,
                    ':s' => $storeId,
                    ':d' => $date,
                    ':closed' => $closed,
                    ':open' => $firstOpen,
                    ':close' => $lastClose,
                    ':note' => mb_substr($note, 0, 255),
                ]);
                $pdo->prepare("DELETE FROM store_public_hour_override_slots WHERE tenant_id=:t AND store_id=:s AND business_date=:d")
                    ->execute([':t' => $tenantId, ':s' => $storeId, ':d' => $date]);
                if ($closed === 0) {
                    $insSlot = $pdo->prepare("
                        INSERT INTO store_public_hour_override_slots
                          (tenant_id, store_id, business_date, slot_no, open_time, close_time, created_at, updated_at)
                        VALUES
                          (:t, :s, :d, :slot, :open, :close, NOW(), NOW())
                    ");
                    foreach ($slots as $i => $slotRow) {
                        $insSlot->execute([
                            ':t' => $tenantId,
                            ':s' => $storeId,
                            ':d' => $date,
                            ':slot' => $i + 1,
                            ':open' => $slotRow['open'],
                            ':close' => $slotRow['close'],
                        ]);
                    }
                }
                $message = '日別例外を保存しました。';
                $base = DateTimeImmutable::createFromFormat('Y-m-d', $date) ?: $base;
                $monthStart = $base->modify('first day of this month')->format('Y-m-01');
                $monthEnd = $base->modify('last day of this month')->format('Y-m-d');
            } elseif ($action === 'delete_override') {
                $date = (string)($_POST['business_date'] ?? '');
                if (!public_calendar_hours_valid_date($date)) throw new RuntimeException('日付が不正です。');
                $pdo->prepare("DELETE FROM store_public_hour_override_slots WHERE tenant_id=:t AND store_id=:s AND business_date=:d")
                    ->execute([':t' => $tenantId, ':s' => $storeId, ':d' => $date]);
                $del = $pdo->prepare("DELETE FROM store_public_hour_overrides WHERE tenant_id=:t AND store_id=:s AND business_date=:d");
                $del->execute([':t' => $tenantId, ':s' => $storeId, ':d' => $date]);
                $message = '日別例外を削除しました。';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage() !== '' ? $e->getMessage() : '保存に失敗しました。';
        }
    }
}

$weekly = array_fill(0, 7, ['is_closed' => 1, 'open_time' => '', 'close_time' => '', 'note' => '']);
$st = $pdo->prepare("SELECT * FROM store_public_hours WHERE tenant_id=:t AND store_id=:s");
$st->execute([':t' => $tenantId, ':s' => $storeId]);
foreach ($st->fetchAll() as $r) {
    $weekly[(int)$r['weekday']] = $r;
}

$weeklySlots = array_fill(0, 7, []);
$slotStmt = $pdo->prepare("SELECT weekday, slot_no, open_time, close_time FROM store_public_hour_slots WHERE tenant_id=:t AND store_id=:s ORDER BY weekday ASC, slot_no ASC");
$slotStmt->execute([':t' => $tenantId, ':s' => $storeId]);
foreach ($slotStmt->fetchAll() as $r) {
    $weeklySlots[(int)$r['weekday']][(int)$r['slot_no']] = $r;
}

$ov = $pdo->prepare("
    SELECT * FROM store_public_hour_overrides
    WHERE tenant_id=:t AND store_id=:s AND business_date BETWEEN :a AND :b
    ORDER BY business_date ASC
");
$ov->execute([':t' => $tenantId, ':s' => $storeId, ':a' => $monthStart, ':b' => $monthEnd]);
$overrides = $ov->fetchAll();

$overrideSlots = [];
$os = $pdo->prepare("
    SELECT business_date, slot_no, open_time, close_time
    FROM store_public_hour_override_slots
    WHERE tenant_id=:t AND store_id=:s AND business_date BETWEEN :a AND :b
    ORDER BY business_date ASC, slot_no ASC
");
$os->execute([':t' => $tenantId, ':s' => $storeId, ':a' => $monthStart, ':b' => $monthEnd]);
foreach ($os->fetchAll() as $r) {
    $overrideSlots[(string)$r['business_date']][(int)$r['slot_no']] = $r;
}
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>公開営業時間設定</title>
    <style>
        body {
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            background: #ffffff;
            color: #444;
            font-size: 13px;
        }

        .wrap {
            padding: 24px;
            padding-bottom: 64px;
        }

        .top,
        .panel {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 16px;
        }

        .top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .panel {
            margin-top: 16px;
        }

        h1 {
            font-size: 16px;
            line-height: 1.35;
            color: #111;
            font-weight: 900;
            margin: 0;
        }

        h2 {
            font-size: 15px;
            line-height: 1.35;
            color: #111;
            font-weight: 900;
            margin: 0 0 12px;
        }

        .sub {
            color: #777;
            font-size: 12px;
            font-weight: 600;
            margin-top: 4px;
        }

        .wide {
            overflow: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        th,
        td {
            border-bottom: 1px solid #eee;
            padding: 10px;
            text-align: left;
            vertical-align: top;
            font-size: 12px;
        }

        th {
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 1;
            color: #555;
            font-weight: 700;
        }

        input[type=time],
        input[type=date],
        input[type=text],
        select {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 10px;
            font-size: 13px;
            box-sizing: border-box;
            background: #fff;
            color: #444;
        }

        input[type=time] {
            width: 112px;
            max-width: 100%;
        }

        input[type=date] {
            width: 160px;
        }

        input[type=text] {
            min-width: 180px;
        }

        label {
            font-weight: 700;
            color: #555;
            font-size: 12px;
        }

        label input[type=checkbox] {
            margin-right: 6px;
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
            color: #555;
            text-decoration: none;
            font-weight: 700;
            box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
            cursor: pointer;
        }

        .btn.primary {
            border-color: rgba(0, 0, 0, .14);
            background: #111;
            color: #fff;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .msg {
            background: #eaffea;
            border: 1px solid #9be59b;
            padding: 10px;
            border-radius: 10px;
            margin-top: 10px;
        }

        .err {
            background: #ffecec;
            border: 1px solid #ffb3b3;
            padding: 10px;
            border-radius: 10px;
            margin-top: 10px;
        }

        .slotCell {
            white-space: nowrap;
        }

        .saveRow {
            display: flex;
            justify-content: flex-end;
            margin-top: 12px;
        }

        @media(max-width:980px) {
            .wrap {
                padding: 16px;
                padding-bottom: 48px;
            }

            table {
                min-width: 860px;
            }
        }
    </style>
</head>
<body data-mode="settings">
<?php require_once __DIR__ . '/_header.php'; ?>
<div class="shiftNavTabsHost">
    <?php require_once __DIR__ . '/_shift_nav_tabs.php'; ?>
</div>
<main class="wrap">
    <div class="top">
        <div><h1>公開営業時間設定</h1><div class="sub"><?= h($storeName) ?> / お客様向けカレンダーに表示されます</div></div>
        <div class="actions">
            <a class="btn" href="/admin/shifts.php?store_id=<?= (int)$storeId ?>">シフト表へ戻る</a>
        </div>
    </div>
    <?php if ($message !== ''): ?><div class="msg"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

    <section class="panel">
        <h2>通常営業時間</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save_weekly">
            <div class="wide">
                <table>
                    <thead><tr><th>曜日</th><th>休業</th><th>営業時間枠1</th><th>営業時間枠2</th><th>営業時間枠3</th><th>表示メモ</th></tr></thead>
                    <tbody>
                    <?php for ($w = 0; $w <= 6; $w++): ?>
                        <?php $r = $weekly[$w]; ?>
                        <tr>
                            <td><?= h($wdayJa[$w]) ?></td>
                            <td><label><input type="checkbox" name="closed[<?= $w ?>]" value="1" <?= ((int)($r['is_closed'] ?? 1) === 1) ? 'checked' : '' ?>> 休業</label></td>
                            <?php for ($slot = 1; $slot <= 3; $slot++): ?>
                                <?php
                                $slotRow = $weeklySlots[$w][$slot] ?? null;
                                if (!$slotRow && $slot === 1 && (string)($r['open_time'] ?? '') !== '' && (string)($r['close_time'] ?? '') !== '') {
                                    $slotRow = ['open_time' => $r['open_time'], 'close_time' => $r['close_time']];
                                }
                                ?>
                                <td class="slotCell">
                                    <input type="time" name="slot_open[<?= $w ?>][<?= $slot ?>]" value="<?= h(substr((string)($slotRow['open_time'] ?? ''), 0, 5)) ?>">
                                    <span>-</span>
                                    <input type="time" name="slot_close[<?= $w ?>][<?= $slot ?>]" value="<?= h(substr((string)($slotRow['close_time'] ?? ''), 0, 5)) ?>">
                                </td>
                            <?php endfor; ?>
                            <td><input type="text" name="note[<?= $w ?>]" value="<?= h((string)($r['note'] ?? '')) ?>" placeholder="例: ランチのみ"></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <div class="saveRow"><button class="btn primary" type="submit">通常営業時間を保存</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>日別例外</h2>
        <form method="post" class="actions" style="margin-top:4px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save_override">
            <input type="date" name="business_date" value="<?= h($base->format('Y-m-d')) ?>" required>
            <label><input type="checkbox" name="is_closed" value="1"> 臨時休業</label>
            <span class="sub">枠1</span><input type="time" name="override_slot_open[1]"><span>-</span><input type="time" name="override_slot_close[1]">
            <span class="sub">枠2</span><input type="time" name="override_slot_open[2]"><span>-</span><input type="time" name="override_slot_close[2]">
            <span class="sub">枠3</span><input type="time" name="override_slot_open[3]"><span>-</span><input type="time" name="override_slot_close[3]">
            <input type="text" name="note" placeholder="例: 貸切営業">
            <button class="btn primary" type="submit">例外を保存</button>
        </form>
        <div class="wide">
            <table>
                <thead><tr><th>日付</th><th>状態</th><th>営業時間</th><th>メモ</th><th></th></tr></thead>
                <tbody>
                <?php if (!$overrides): ?><tr><td colspan="5">この月の日別例外はありません。</td></tr><?php endif; ?>
                <?php foreach ($overrides as $r): ?>
                    <tr>
                        <td><?= h((string)$r['business_date']) ?></td>
                        <td><?= ((int)$r['is_closed'] === 1) ? '休業' : '営業' ?></td>
                        <td>
                            <?php if ((int)$r['is_closed'] === 1): ?>
                                -
                            <?php else: ?>
                                <?php
                                $dateKey = (string)$r['business_date'];
                                $parts = [];
                                foreach (($overrideSlots[$dateKey] ?? []) as $slotRow) {
                                    $parts[] = substr((string)$slotRow['open_time'], 0, 5) . '-' . substr((string)$slotRow['close_time'], 0, 5);
                                }
                                if (!$parts && (string)($r['open_time'] ?? '') !== '' && (string)($r['close_time'] ?? '') !== '') {
                                    $parts[] = substr((string)$r['open_time'], 0, 5) . '-' . substr((string)$r['close_time'], 0, 5);
                                }
                                ?>
                                <?= h(implode(' / ', $parts)) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= h((string)($r['note'] ?? '')) ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="delete_override">
                                <input type="hidden" name="business_date" value="<?= h((string)$r['business_date']) ?>">
                                <button class="btn" type="submit">削除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
