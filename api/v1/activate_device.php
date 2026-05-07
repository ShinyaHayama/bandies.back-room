<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

function json_exit(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function pick_db_file(string $dir): ?string
{
    $candidates = [
        $dir . '/../lib/db.php',          // /api/v1 -> /api/lib/db.php
        $dir . '/../../api/lib/db.php',   // fallback
        $dir . '/../lib/db.php',
        $dir . '/../../lib/db.php',
    ];
    foreach ($candidates as $p) {
        if (is_file($p)) return $p;
    }
    return null;
}

function table_columns(PDO $pdo, string $table): array
{
    $st = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
    ");
    $st->execute([':t' => $table]);
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $cols[(string)$c] = true;
    return $cols;
}

function safe_update(PDO $pdo, string $table, array $data, string $whereSql, array $whereParams): void
{
    $cols = table_columns($pdo, $table);
    $set = [];
    $params = [];
    foreach ($data as $k => $v) {
        if (!isset($cols[$k])) continue;
        $set[] = "{$k} = :set_{$k}";
        $params[":set_{$k}"] = $v;
    }
    if (!$set) return;
    $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params + $whereParams);
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
    $st = $pdo->prepare($sql);
    foreach ($ins as $k => $v) $st->bindValue(':' . $k, $v);
    $st->execute();
    return (int)$pdo->lastInsertId();
}

// ---- method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_exit(405, ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
}

// ---- parse JSON
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    json_exit(400, ['ok' => false, 'error' => 'INVALID_JSON']);
}

$devKey = trim((string)($body['dev_key'] ?? ''));
$activationCode = strtoupper(trim((string)($body['activation_code'] ?? '')));

if ($devKey === '' || strlen($devKey) < 8) {
    json_exit(400, ['ok' => false, 'error' => 'DEV_KEY_REQUIRED']);
}
if ($activationCode === '' || strlen($activationCode) < 4) {
    json_exit(400, ['ok' => false, 'error' => 'ACTIVATION_CODE_REQUIRED']);
}

$deviceKeyHash = hash('sha256', $devKey);

// ---- DB
$dbFile = pick_db_file(__DIR__);
if (!$dbFile) {
    json_exit(500, ['ok' => false, 'error' => 'DB_FILE_NOT_FOUND']);
}
require_once $dbFile;

if (!function_exists('db')) {
    json_exit(500, ['ok' => false, 'error' => 'DB_FUNCTION_NOT_FOUND']);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

try {
    $pdo->beginTransaction();

    // 1) activation code をロックして検証（1回だけ使う）
    //    device_activation_codes: (code, tenant_id, store_id, expires_at, created_at) がある前提
    $st = $pdo->prepare("
        SELECT *
        FROM device_activation_codes
        WHERE code = :code
        LIMIT 1
        FOR UPDATE
    ");
    $st->execute([':code' => $activationCode]);
    $row = $st->fetch();

    if (!$row) {
        $pdo->rollBack();
        json_exit(404, ['ok' => false, 'error' => 'CODE_NOT_FOUND']);
    }

    $tenantId = (int)($row['tenant_id'] ?? 0);
    $storeId  = (int)($row['store_id'] ?? 0);
    $expiresAt = (string)($row['expires_at'] ?? '');

    if ($tenantId <= 0 || $storeId <= 0) {
        $pdo->rollBack();
        json_exit(500, ['ok' => false, 'error' => 'CODE_ROW_INVALID']);
    }

    if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
        $pdo->rollBack();
        json_exit(410, ['ok' => false, 'error' => 'CODE_EXPIRED']);
    }

    // 2) devices を「冪等」登録（同じdevice_key_hashなら増殖させず更新する）
    //    まず既存を探す（同一端末）
    $st = $pdo->prepare("
        SELECT id, tenant_id, store_id, status
        FROM devices
        WHERE device_key_hash = :h
        LIMIT 1
        FOR UPDATE
    ");
    $st->execute([':h' => $deviceKeyHash]);
    $dev = $st->fetch();

    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $deviceName = 'iPad'; // 必要ならアプリから送って反映もOK

    if ($dev) {
        // ✅ 既存端末：別テナントに紐付いてたら拒否（事故防止）
        $existingTenantId = (int)$dev['tenant_id'];
        if ($existingTenantId !== $tenantId) {
            $pdo->rollBack();
            json_exit(409, [
                'ok' => false,
                'error' => 'DEVICE_ALREADY_BOUND_OTHER_TENANT',
            ]);
        }

        // ✅ 同じ端末＝更新（store変更や再アクティベートを許容）
        safe_update($pdo, 'devices', [
            'store_id' => $storeId,
            'device_name' => $deviceName,
            'status' => 'active',
            'updated_at' => $now,
            // dev_key も最新を入れておく（列があるのでOK）
            'dev_key' => $devKey,
        ], 'id = :id', [':id' => (int)$dev['id']]);

        $deviceId = (int)$dev['id'];
    } else {
        // ✅ 新規端末：INSERT
        $deviceId = safe_insert($pdo, 'devices', [
            'dev_key' => $devKey,
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'device_name' => $deviceName,
            'device_key_hash' => $deviceKeyHash,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // 3) code を「使用済み」にする
    //    カラム差異に備えて、あれば更新／なければ削除
    $codeCols = table_columns($pdo, 'device_activation_codes');
    if (isset($codeCols['used_at']) || isset($codeCols['used_device_id']) || isset($codeCols['status'])) {
        safe_update($pdo, 'device_activation_codes', [
            'used_at' => $now,
            'used_device_id' => $deviceId,
            'status' => 'used',
        ], 'code = :code', [':code' => $activationCode]);
    } else {
        // 1回だけ使用にしたいなら削除が一番安全
        $del = $pdo->prepare("DELETE FROM device_activation_codes WHERE code = :code");
        $del->execute([':code' => $activationCode]);
    }

    $pdo->commit();

    json_exit(200, [
        'ok' => true,
        'tenant_id' => $tenantId,
        'store_id' => $storeId,
        'device_id' => $deviceId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_exit(500, [
        'ok' => false,
        'error' => 'SERVER_ERROR',
        'message' => $e->getMessage(),
    ]);
}