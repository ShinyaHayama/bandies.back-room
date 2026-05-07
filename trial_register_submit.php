<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

/**
 * ✅ 1) まず /kintai 側セッションで CSRF を検証する
 * （trial_register.php と同じセッションを使う必要がある）
 */
session_start();

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function isValidCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}
function uuidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
function exit409(string $msg): void
{
    http_response_code(409);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($msg);
}

/**
 * ✅ DB 接続探索（/kintai 配下でも見つかるように）
 */
$paths = [
    __DIR__ . '/api/lib/db.php',
    __DIR__ . '/lib/db.php',
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
    __DIR__ . '/../admin/../api/lib/db.php',
    __DIR__ . '/../admin/../lib/db.php',
];
$dbFile = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        $dbFile = $p;
        break;
    }
}
if (!$dbFile) {
    http_response_code(500);
    exit('db.php not found');
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /kintai/trial.php');
    exit;
}
if (!isValidCsrf((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    exit('CSRF invalid');
}

$token = (string)($_POST['t'] ?? '');
if ($token === '') {
    http_response_code(400);
    exit('token missing');
}
$tokenHash = hash('sha256', $token);

$tenantName = trim((string)($_POST['tenant_name'] ?? ''));
$storeName  = trim((string)($_POST['store_name'] ?? '本店'));
$pw         = (string)($_POST['password'] ?? '');
$pwConfirm  = (string)($_POST['password_confirm'] ?? '');

if ($tenantName === '') {
    http_response_code(400);
    exit('tenant_name required');
}
if ($storeName === '') {
    $storeName = '本店';
}
if (strlen($pw) < 8) {
    http_response_code(400);
    exit('password too short');
}
if ($pwConfirm === '' || !hash_equals($pw, $pwConfirm)) {
    http_response_code(400);
    exit('password confirmation mismatch');
}

function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
    ");
    $stmt->execute([':t' => $table]);
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) $cols[(string)$c] = true;
    return $cols;
}

function ensureTrialColumns(PDO $pdo): void
{
    $cols = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('trial_started_at', $cols, true)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN trial_started_at DATETIME NULL");
    }
    if (!in_array('trial_ends_at', $cols, true)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN trial_ends_at DATETIME NULL");
    }
}
function safe_insert(PDO $pdo, string $table, array $data): int
{
    $cols = table_columns($pdo, $table);
    $ins = [];
    foreach ($data as $k => $v) {
        if (isset($cols[$k])) $ins[$k] = $v;
    }
    if (!$ins) throw new RuntimeException("no insertable columns for {$table}");

    $fields = array_keys($ins);
    $params = array_map(fn($f) => ':' . $f, $fields);

    $sql = "INSERT INTO {$table} (" . implode(',', $fields) . ") VALUES (" . implode(',', $params) . ")";
    $stmt = $pdo->prepare($sql);
    foreach ($ins as $k => $v) $stmt->bindValue(':' . $k, $v);
    $stmt->execute();
    return (int)$pdo->lastInsertId();
}

$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$trialEnd = (new DateTimeImmutable('now +30 days'))->format('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    // トークン検証（ロック）
    $stmt = $pdo->prepare("SELECT * FROM trial_requests WHERE token_hash=:h LIMIT 1 FOR UPDATE");
    $stmt->execute([':h' => $tokenHash]);
    $req = $stmt->fetch();

    if (!$req) {
        $pdo->rollBack();
        http_response_code(404);
        exit('token not found');
    }
    if ((string)$req['status'] !== 'pending') {
        $pdo->rollBack();
        http_response_code(410);
        exit('token already used');
    }

    if (strtotime((string)$req['expires_at']) < time()) {
        $pdo->prepare("UPDATE trial_requests SET status='expired' WHERE id=:id")->execute([':id' => $req['id']]);
        $pdo->commit();
        http_response_code(410);
        exit('token expired');
    }

    $email = (string)$req['email'];

    // ✅ email_norm（UNI）基準で存在チェック（大小/空白も同一扱い）
    $chk = $pdo->prepare("
        SELECT id, tenant_id
        FROM tenant_admin_users
        WHERE email_norm = LOWER(TRIM(:email))
          AND status = 'active'
        LIMIT 1
        FOR UPDATE
    ");
    $chk->execute([':email' => $email]);
    if ($chk->fetch()) {
        $pdo->prepare("UPDATE trial_requests SET status='expired' WHERE id=:id")->execute([':id' => $req['id']]);
        $pdo->commit();
        exit409('このメールアドレスは既に登録されています。ログインまたはパスワード再発行をご利用ください。');
    }

    // 1) tenants
    ensureTrialColumns($pdo);
    $tenantId = safe_insert($pdo, 'tenants', [
        'name' => $tenantName,
        'status' => 'active',
        'trial_started_at' => $now,
        'trial_ends_at' => $trialEnd,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // 2) stores
    $storeId = safe_insert($pdo, 'stores', [
        'tenant_id' => $tenantId,
        'name' => $storeName,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // 3) tenant_admin_users
    $passwordHash = password_hash($pw, PASSWORD_BCRYPT);

    try {
        $adminUserId = safe_insert($pdo, 'tenant_admin_users', [
            'tenant_id' => $tenantId,
            'email' => $email,
            'password_hash' => $passwordHash,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            // ✅ 中途半端な tenants/stores を残さない
            $pdo->rollBack();
            exit409('このメールアドレスは既に登録されています。ログインまたはパスワード再発行をご利用ください。');
        }
        throw $e;
    }

    // 4) devices
    $deviceKey = uuidv4();
    $deviceKeyHash = hash('sha256', $deviceKey);
    safe_insert($pdo, 'devices', [
        'tenant_id' => $tenantId,
        'store_id' => $storeId,
        'device_name' => 'Trial iPad',
        'device_key_hash' => $deviceKeyHash,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // 5) trial_requests used
    $pdo->prepare("
        UPDATE trial_requests
        SET status='used', used_at=:used_at
        WHERE id=:id
    ")->execute([
        ':used_at' => $now,
        ':id' => $req['id'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "register failed\n";
    echo h($e->getMessage());
    exit;
}

/**
 * ✅ 2) 登録が成功した後だけ /admin セッション（ADMINSESSID）を開始して自動ログイン
 * - ここが “/kintai と /admin の分離” の肝
 */
session_write_close();

session_name('ADMINSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

$_SESSION['admin_auth'] = 1;
$_SESSION['tenant_id'] = (int)$tenantId;
$_SESSION['tenant_admin_user_id'] = (int)$adminUserId;
session_regenerate_id(true);

header('Location: /admin/index.php');
exit;
