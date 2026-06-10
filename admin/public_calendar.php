<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../lib/app_url.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function ensure_public_calendar_schema(PDO $pdo): bool
{
    $cols = $pdo->query("SHOW COLUMNS FROM stores")->fetchAll(PDO::FETCH_COLUMN, 0);
    $colSet = array_flip($cols);
    if (!isset($colSet['public_calendar_enabled'])) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN public_calendar_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!isset($colSet['public_calendar_token'])) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN public_calendar_token VARCHAR(64) NULL");
        try {
            $pdo->exec("ALTER TABLE stores ADD UNIQUE KEY uniq_public_calendar_token (public_calendar_token)");
        } catch (Throwable $e) {
        }
    }
    if (!isset($colSet['public_calendar_title'])) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN public_calendar_title VARCHAR(120) NULL");
    }
    if (!isset($colSet['public_calendar_code'])) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN public_calendar_code VARCHAR(16) NULL");
        try {
            $pdo->exec("ALTER TABLE stores ADD UNIQUE KEY uniq_public_calendar_code (public_calendar_code)");
        } catch (Throwable $e) {
        }
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS store_public_calendar_months (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                store_id INT NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                confirmed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_store_month (tenant_id, store_id, `year_month`),
                KEY idx_store_month_status (tenant_id, store_id, `year_month`, is_confirmed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function public_calendar_code(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    for ($try = 0; $try < 20; $try++) {
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM stores WHERE public_calendar_code = :code");
        $stmt->execute([':code' => $code]);
        if ((int)($stmt->fetch()['cnt'] ?? 0) === 0) return $code;
    }
    return bin2hex(random_bytes(8));
}

$monthFeatureAvailable = ensure_public_calendar_schema($pdo);

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    header('Location: /admin/login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

$storesStmt = $pdo->prepare("
    SELECT id, name, public_calendar_enabled, public_calendar_token, public_calendar_title, public_calendar_code
    FROM stores
    WHERE tenant_id=:t
    ORDER BY id ASC
");
$storesStmt->execute([':t' => $tenantId]);
$stores = $storesStmt->fetchAll();
if (!$stores) exit('storesなし');

$storeIds = array_map(fn($s) => (int)$s['id'], $stores);
$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0 || !in_array($storeId, $storeIds, true)) $storeId = (int)$stores[0]['id'];
$baseDate = (string)($_GET['date'] ?? date('Y-m-d'));
$base = DateTimeImmutable::createFromFormat('Y-m-d', $baseDate) ?: new DateTimeImmutable('today');
$monthFirst = $base->modify('first day of this month');
$monthYm = $monthFirst->format('Y-m');
$prevMonthDate = $monthFirst->modify('-1 month')->format('Y-m-d');
$nextMonthDate = $monthFirst->modify('+1 month')->format('Y-m-d');

$currentStore = [];
foreach ($stores as $s) {
    if ((int)$s['id'] === $storeId) {
        $currentStore = $s;
        break;
    }
}
$storeName = (string)($currentStore['name'] ?? '');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        $error = 'CSRFトークンが不正です。';
    } else {
        try {
            if ($action === 'toggle_public_calendar') {
                $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
                $token = (string)($currentStore['public_calendar_token'] ?? '');
                if ($token === '') $token = bin2hex(random_bytes(24));
                $code = (string)($currentStore['public_calendar_code'] ?? '');
                if ($code === '') $code = public_calendar_code($pdo);
                $upd = $pdo->prepare("
                    UPDATE stores
                    SET public_calendar_enabled = :enabled,
                        public_calendar_token = :token,
                        public_calendar_code = :code
                    WHERE tenant_id = :t AND id = :s
                    LIMIT 1
                ");
                $upd->execute([
                    ':enabled' => $enabled,
                    ':token' => $token,
                    ':code' => $code,
                    ':t' => $tenantId,
                    ':s' => $storeId,
                ]);
                header('Location: /admin/public_calendar.php?store_id=' . $storeId . '&date=' . rawurlencode($base->format('Y-m-d')));
                exit;
            }
            if ($action === 'update_public_calendar_settings') {
                $calendarTitle = trim((string)($_POST['public_calendar_title'] ?? ''));
                $calendarTitle = function_exists('mb_substr')
                    ? mb_substr($calendarTitle, 0, 120, 'UTF-8')
                    : substr($calendarTitle, 0, 120);
                $upd = $pdo->prepare("
                    UPDATE stores
                    SET public_calendar_title = :title
                    WHERE tenant_id = :t AND id = :s
                    LIMIT 1
                ");
                $upd->execute([
                    ':title' => $calendarTitle !== '' ? $calendarTitle : null,
                    ':t' => $tenantId,
                    ':s' => $storeId,
                ]);
                $message = '公開タイトルを保存しました。';
            }
            if ($action === 'set_month_confirmed') {
                if (!$monthFeatureAvailable) {
                    throw new RuntimeException('公開確定月の保存テーブルを作成できませんでした。');
                }
                $postedYm = (string)($_POST['year_month'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}$/', $postedYm)) {
                    throw new RuntimeException('対象月が不正です。');
                }
                $confirmed = (int)($_POST['is_confirmed'] ?? 0) === 1 ? 1 : 0;
                $up = $pdo->prepare("
                    INSERT INTO store_public_calendar_months
                      (tenant_id, store_id, `year_month`, is_confirmed, confirmed_at, created_at, updated_at)
                    VALUES
                      (:t, :s, :ym, :confirmed, CASE WHEN :confirmed_at = 1 THEN NOW() ELSE NULL END, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                      is_confirmed = VALUES(is_confirmed),
                      confirmed_at = VALUES(confirmed_at),
                      updated_at = NOW()
                ");
                $up->execute([
                    ':t' => $tenantId,
                    ':s' => $storeId,
                    ':ym' => $postedYm,
                    ':confirmed' => $confirmed,
                    ':confirmed_at' => $confirmed,
                ]);
                $base = DateTimeImmutable::createFromFormat('Y-m-d', $postedYm . '-01') ?: $base;
                $monthFirst = $base->modify('first day of this month');
                $monthYm = $monthFirst->format('Y-m');
                $prevMonthDate = $monthFirst->modify('-1 month')->format('Y-m-d');
                $nextMonthDate = $monthFirst->modify('+1 month')->format('Y-m-d');
                $message = $confirmed ? 'この月を公開確定にしました。' : 'この月を未確定に戻しました。';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage() !== '' ? $e->getMessage() : '保存に失敗しました。';
        }
    }
    $storesStmt->execute([':t' => $tenantId]);
    $stores = $storesStmt->fetchAll();
    foreach ($stores as $s) {
        if ((int)$s['id'] === $storeId) {
            $currentStore = $s;
            $storeName = (string)$s['name'];
            break;
        }
    }
}

if ((string)($currentStore['public_calendar_token'] ?? '') === '') {
    $token = bin2hex(random_bytes(24));
    $pdo->prepare("UPDATE stores SET public_calendar_token = :token WHERE tenant_id = :t AND id = :s LIMIT 1")
        ->execute([':token' => $token, ':t' => $tenantId, ':s' => $storeId]);
    $currentStore['public_calendar_token'] = $token;
}
if ((string)($currentStore['public_calendar_code'] ?? '') === '') {
    $code = public_calendar_code($pdo);
    $pdo->prepare("UPDATE stores SET public_calendar_code = :code WHERE tenant_id = :t AND id = :s LIMIT 1")
        ->execute([':code' => $code, ':t' => $tenantId, ':s' => $storeId]);
    $currentStore['public_calendar_code'] = $code;
}

$publicCalendarEnabled = (int)($currentStore['public_calendar_enabled'] ?? 0) === 1;
$publicCalendarTitle = (string)($currentStore['public_calendar_title'] ?? '');
$publicCalendarCode = (string)($currentStore['public_calendar_code'] ?? '');
$publicCalendarHost = app_public_base_url();
$publicCalendarUrl = $publicCalendarHost . '/c/' . rawurlencode($publicCalendarCode);

$monthStatus = [];
if ($monthFeatureAvailable) {
    $monthStatusStmt = $pdo->prepare("
        SELECT is_confirmed, confirmed_at
        FROM store_public_calendar_months
        WHERE tenant_id = :t AND store_id = :s AND `year_month` = :ym
        LIMIT 1
    ");
    $monthStatusStmt->execute([':t' => $tenantId, ':s' => $storeId, ':ym' => $monthYm]);
    $monthStatus = $monthStatusStmt->fetch() ?: [];
}
$monthConfirmed = (int)($monthStatus['is_confirmed'] ?? 0) === 1;
$monthConfirmedAt = (string)($monthStatus['confirmed_at'] ?? '');
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>公開カレンダー設定</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #fff; color: #444; font-size: 13px; }
        .wrap { padding: 24px; padding-bottom: 64px; }
        .panel { background: #fff; border: 1px solid #ddd; border-radius: 12px; padding: 16px; }
        h1 { margin: 0; font-size: 22px; color: #222; }
        .sub { margin-top: 4px; color: #777; font-weight: 700; }
        .grid { display: grid; gap: 12px; margin-top: 16px; max-width: 960px; }
        .row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .status { font-weight: 900; color: <?= $publicCalendarEnabled ? '#166534' : '#6b7280' ?>; white-space: nowrap; }
        .monthStatus { font-weight: 900; color: <?= $monthConfirmed ? '#166534' : '#92400e' ?>; white-space: nowrap; }
        label { font-weight: 900; color: #374151; min-width: 92px; }
        input[type="text"] { flex: 1 1 360px; min-width: 0; height: 38px; padding: 0 12px; border: 1px solid #dfe3ea; border-radius: 10px; background: #fff; color: #374151; font-weight: 700; }
        input[readonly] { background: #f8fafc; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 0 16px; border-radius: 999px; border: 1px solid #ddd; background: #fff; color: #555; font-weight: 900; text-decoration: none; cursor: pointer; white-space: nowrap; }
        .btn.primary { background: #111; border-color: #111; color: #fff; }
        .alert { padding: 10px 12px; border-radius: 10px; font-weight: 800; }
        .ok { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .ng { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .monthTitle { min-width: 130px; text-align: center; font-size: 16px; font-weight: 900; color: #111827; }
        .hint { color: #6b7280; font-size: 12px; font-weight: 700; line-height: 1.6; }
        form { margin: 0; }
        @media (max-width: 760px) { .wrap { padding: 16px; } label { min-width: 100%; } }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/_header.php'; ?>
<div class="shiftNavTabsHost">
    <?php require_once __DIR__ . '/_shift_nav_tabs.php'; ?>
</div>
<main class="wrap">
    <section class="panel">
        <h1>公開カレンダー設定</h1>
        <div class="sub"><?= h($storeName) ?> / お客様向けカレンダーの公開URLとタイトル</div>

        <div class="grid">
            <?php if ($message !== ''): ?><div class="alert ok"><?= h($message) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert ng"><?= h($error) ?></div><?php endif; ?>
            <?php if (!$monthFeatureAvailable): ?>
                <div class="alert ng">公開確定月の保存テーブルを作成できませんでした。DB権限またはテーブル定義を確認してください。</div>
            <?php endif; ?>

            <div class="row">
                <span class="status">外部公開: <?= $publicCalendarEnabled ? 'ON' : 'OFF' ?></span>
                <input type="text" value="<?= h($publicCalendarUrl) ?>" readonly onclick="this.select();">
                <?php if ($publicCalendarEnabled): ?>
                    <a class="btn" href="<?= h($publicCalendarUrl) ?>" target="_blank" rel="noopener">公開ページを開く</a>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="toggle_public_calendar">
                    <input type="hidden" name="enabled" value="<?= $publicCalendarEnabled ? '0' : '1' ?>">
                    <button class="btn <?= $publicCalendarEnabled ? '' : 'primary' ?>" type="submit">
                        <?= $publicCalendarEnabled ? '公開をOFF' : '公開をON' ?>
                    </button>
                </form>
            </div>

            <form class="row" method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="update_public_calendar_settings">
                <label for="publicCalendarTitle">公開タイトル</label>
                <input id="publicCalendarTitle" type="text" name="public_calendar_title"
                    value="<?= h($publicCalendarTitle) ?>" maxlength="120"
                    placeholder="<?= h($storeName) ?> 営業日カレンダー">
                <button class="btn" type="submit">タイトル保存</button>
            </form>

            <div class="row">
                <label>公開確定月</label>
                <a class="btn" href="/admin/public_calendar.php?store_id=<?= (int)$storeId ?>&date=<?= h(rawurlencode($prevMonthDate)) ?>">前月</a>
                <div class="monthTitle"><?= h($monthFirst->format('Y年n月')) ?></div>
                <a class="btn" href="/admin/public_calendar.php?store_id=<?= (int)$storeId ?>&date=<?= h(rawurlencode($nextMonthDate)) ?>">翌月</a>
                <span class="monthStatus"><?= $monthConfirmed ? '公開確定済み' : '未確定' ?></span>
                <?php if ($monthConfirmedAt !== ''): ?>
                    <span class="hint">確定日時: <?= h($monthConfirmedAt) ?></span>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="set_month_confirmed">
                    <input type="hidden" name="year_month" value="<?= h($monthYm) ?>">
                    <input type="hidden" name="is_confirmed" value="<?= $monthConfirmed ? '0' : '1' ?>">
                    <button class="btn <?= $monthConfirmed ? '' : 'primary' ?>" type="submit" <?= $monthFeatureAvailable ? '' : 'disabled' ?>>
                        <?= $monthConfirmed ? '未確定に戻す' : 'この月を公開確定' ?>
                    </button>
                </form>
            </div>
            <div class="hint">
                未確定月は、シフトが入っていない日を「未定」と表示します。公開確定済みの月は、シフトが入っていない日を「休み」と表示します。
            </div>
        </div>
    </section>
</main>
</body>
</html>
