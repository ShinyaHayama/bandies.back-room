<?php
// /api/line/webhook.php
declare(strict_types=1);

/**
 * LINE Webhook（1公式LINEでマルチテナント運用）
 *
 * - 署名検証OK → events処理
 * - 未紐付け → 「紐付けトークン」を要求（管理画面で発行した文字列）
 * - 紐付け済み → 「出勤」「退勤」で time_punches に記録（punch_service.php）
 */

date_default_timezone_set('Asia/Tokyo');

// ===== config =====
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'config.php not found';
    exit;
}
$cfg = require $configPath;

$channelSecret = (string)($cfg['channel_secret'] ?? '');
$channelAccessToken = (string)($cfg['channel_access_token'] ?? '');
$logFile  = (string)($cfg['log_file'] ?? (__DIR__ . '/line_webhook.log'));

if ($channelSecret === '' || $channelAccessToken === '') {
    http_response_code(500);
    echo 'config not set';
    exit;
}

// ===== punch service =====
require_once __DIR__ . '/punch_service.php';

// ===== DB =====
$paths = [
    __DIR__ . '/../../api/lib/db.php',
    __DIR__ . '/../../lib/db.php',
    __DIR__ . '/../api/lib/db.php',
    __DIR__ . '/../lib/db.php',
    __DIR__ . '/../../../../api/lib/db.php',
    __DIR__ . '/../../../../lib/db.php',
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
    echo 'db.php not found';
    exit;
}
require_once $dbFile;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ===== raw body =====
$body = file_get_contents('php://input');
if ($body === false) $body = '';

// ===== signature =====
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
$signature = is_string($signature) ? trim($signature) : '';

if (!verifyLineSignature($body, $signature, $channelSecret)) {
    logLine($logFile, 'SIGN_NG', $body);
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

logLine($logFile, 'SIGN_OK', $body);

// ===== parse =====
$data = json_decode($body, true);
if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
    http_response_code(200);
    echo 'OK';
    exit;
}

// 先に 200（再送対策）
http_response_code(200);

// ===== handle events =====
foreach ($data['events'] as $ev) {
    if (!is_array($ev)) continue;

    $type = (string)($ev['type'] ?? '');
    if ($type !== 'message') continue;

    $replyToken = (string)($ev['replyToken'] ?? '');
    $source = $ev['source'] ?? [];
    $lineUserId = (string)($source['userId'] ?? '');

    $message = $ev['message'] ?? [];
    $msgType = (string)($message['type'] ?? '');

    if ($replyToken === '' || $lineUserId === '') continue;

    // テキスト以外
    if ($msgType !== 'text') {
        lineReplyText($channelAccessToken, $replyToken, "テキストで「出勤」または「退勤」と送ってください。");
        continue;
    }

    $text = normalizeText((string)($message['text'] ?? ''));

    try {
        $token = extractBindToken($text);
        if ($token !== null) {
            $res = bindLineUserByToken($pdo, $lineUserId, $token);
            if (!$res['ok']) {
                lineReplyText($channelAccessToken, $replyToken, "⚠️ " . $res['msg']);
                continue;
            }
            $name = (string)$res['employee']['display_name'];
            lineReplyText($channelAccessToken, $replyToken, "✅ {$name} さんで紐付けました。\nこの後は「出勤」または「退勤」と送ってください。");
            continue;
        }

        // 1) 紐付け済みか？（全テナント横断で検索）
        $emp = findEmployeeByLineUserIdGlobal($pdo, $lineUserId);

        // 2) 未紐付けなら トークン を促す
        if (!$emp) {
            lineReplyText(
                $channelAccessToken,
                $replyToken,
                "はじめに紐付けが必要です。\n管理画面で発行された「LINE紐付けコード（トークン）」を送ってください。"
            );
            continue;
        }

        // 3) 打刻（employee の tenant/store を使う）
        $tenantId   = (int)$emp['tenant_id'];
        $storeId    = (int)$emp['store_id'];
        $employeeId = (int)$emp['id'];
        $name       = (string)($emp['display_name'] ?? '従業員');
        $nowJst     = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));

        if ($text === '出勤') {
            $msg = punchClockIn($pdo, $tenantId, $storeId, $employeeId, $nowJst);
            lineReplyText($channelAccessToken, $replyToken, $name . "さん\n" . $msg);
            continue;
        }

        if ($text === '退勤') {
            $msg = punchClockOut($pdo, $tenantId, $storeId, $employeeId, $nowJst);
            lineReplyText($channelAccessToken, $replyToken, $name . "さん\n" . $msg);
            continue;
        }
        // ✅ 休憩（break_in / break_out）
        // ※ $nowJst を使う（$now は存在しない）
        // ※ 返信は lineReplyText を使う（replyText は存在しない）

        if ($text === '休憩開始' || $text === '休憩') {
            $msg = punchBreakIn($pdo, $tenantId, $storeId, $employeeId, $nowJst);
            lineReplyText($channelAccessToken, $replyToken, $name . "さん\n" . $msg);
            continue;
        }

        if ($text === '休憩終了' || $text === '休憩終' || $text === '休憩終了') {
            $msg = punchBreakOut($pdo, $tenantId, $storeId, $employeeId, $nowJst);
            lineReplyText($channelAccessToken, $replyToken, $name . "さん\n" . $msg);
            continue;
        }



        lineReplyText(
            $channelAccessToken,
            $replyToken,
            $name . "さん\n使い方:\n・出勤\n・退勤"
        );
    } catch (Throwable $e) {
        logLine($logFile, 'ERROR', $e->getMessage() . "\n" . $e->getTraceAsString());
        lineReplyText(
            $channelAccessToken,
            $replyToken,
            "⚠️ エラーで処理できませんでした。\n管理者に連絡してください。\n（ログに記録済み）"
        );
        continue;
    }
}

echo 'OK';
exit;

/* =========================================================
 * 下は “このファイル内に同梱” する共通関数
 * ======================================================= */

function logLine(string $logFile, string $tag, string $text): void
{
    @file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . " [{$tag}]\n" . $text . "\n\n",
        FILE_APPEND
    );
}

function verifyLineSignature(string $body, string $signature, string $channelSecret): bool
{
    if ($signature === '' || $channelSecret === '') return false;
    $hash = hash_hmac('sha256', $body, $channelSecret, true);
    $expected = base64_encode($hash);
    return hash_equals($expected, $signature);
}

function normalizeText(string $s): string
{
    $s = trim($s);
    $s = str_replace("\xE3\x80\x80", ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

    if ($s === '出社') $s = '出勤';
    if ($s === '退社') $s = '退勤';

    return $s;
}

/**
 * 管理画面で発行したトークンを拾う
 * - 例: "AB12CD34EF" / "token AB12CD34EF" など
 */
function extractBindToken(string $text): ?string
{
    $t = trim($text);

    // token: XXXXX / TOKEN XXXXX
    if (preg_match('/^(TOKEN|token)\s*[: ]\s*([A-Za-z0-9]{6,32})$/', $t, $m)) {
        return (string)$m[2];
    }

    // 任意の文中に 12桁の16進トークンが含まれている場合
    if (preg_match('/([A-Fa-f0-9]{12})/', $t, $m)) {
        return strtolower((string)$m[1]);
    }

    // 単体で 6〜32 文字の英数
    if (preg_match('/^[A-Za-z0-9]{6,32}$/', $t)) {
        return $t;
    }

    return null;
}

/**
 * employees から line_user_id を全テナント横断で検索
 */
function findEmployeeByLineUserIdGlobal(PDO $pdo, string $lineUserId): ?array
{
    $st = $pdo->prepare("
        SELECT id, tenant_id, store_id, display_name, line_user_id
        FROM employees
        WHERE line_user_id = :uid
          AND employment_status = 'active'
        LIMIT 1
    ");
    $st->execute([':uid' => $lineUserId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

/**
 * トークンで従業員を特定して line_user_id をセット（使い捨て）
 */
function bindLineUserByToken(PDO $pdo, string $lineUserId, string $token): array
{
    // 同一トークンが複数従業員に存在する場合は安全のため停止
    $dup = $pdo->prepare("
        SELECT COUNT(*) AS cnt, COUNT(DISTINCT employee_id) AS emp_cnt
        FROM line_bind_tokens
        WHERE token = :token
          AND used_at IS NULL
          AND expires_at > NOW()
    ");
    $dup->execute([':token' => $token]);
    $dupRow = $dup->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'emp_cnt' => 0];
    if ((int)$dupRow['emp_cnt'] > 1) {
        return [
            'ok' => false,
            'msg' => '同じ紐付けコードが複数の従業員に存在します。管理画面で再発行してください。',
        ];
    }

    // 有効なトークンを取得（未使用 + 期限内）
    $st = $pdo->prepare("
        SELECT t.id AS token_id, t.tenant_id, t.store_id, t.employee_id,
               e.display_name, e.line_user_id
        FROM line_bind_tokens t
        INNER JOIN employees e ON e.id = t.employee_id
        WHERE t.token = :token
          AND t.used_at IS NULL
          AND t.expires_at > NOW()
        ORDER BY t.id DESC
        LIMIT 1
    ");
    $st->execute([':token' => $token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'msg' => '紐付けコードが無効です（期限切れ/使用済み/誤り）。管理画面で再発行してください。'];
    }

    // すでに別のLINEに紐付いている場合
    if (!empty($row['line_user_id']) && (string)$row['line_user_id'] !== $lineUserId) {
        return ['ok' => false, 'msg' => 'この従業員は既に別のLINEに紐付いています（管理者に解除依頼してください）'];
    }

    $tenantId = (int)$row['tenant_id'];
    $storeId  = (int)$row['store_id'];
    $employeeId = (int)$row['employee_id'];
    $tokenId = (int)$row['token_id'];

    // このLINEが既に別従業員に紐付いていないか（二重紐付け防止）
    $st2 = $pdo->prepare("
        SELECT id, display_name
        FROM employees
        WHERE line_user_id = :uid
        LIMIT 1
    ");
    $st2->execute([':uid' => $lineUserId]);
    $already = $st2->fetch(PDO::FETCH_ASSOC);
    if ($already && (int)$already['id'] !== $employeeId) {
        return ['ok' => false, 'msg' => 'このLINEは既に別の従業員に紐付いています（管理者に解除依頼してください）'];
    }

    // トランザクションで「token使用済み + employee更新」を同時に
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("
            UPDATE employees
            SET line_user_id = :uid, updated_at = NOW()
            WHERE id = :id
              AND tenant_id = :t
              AND store_id = :s
            LIMIT 1
        ");
        $upd->execute([
            ':uid' => $lineUserId,
            ':id' => $employeeId,
            ':t'  => $tenantId,
            ':s'  => $storeId,
        ]);

        $upd2 = $pdo->prepare("
            UPDATE line_bind_tokens
            SET used_at = NOW()
            WHERE id = :token_id
              AND used_at IS NULL
            LIMIT 1
        ");
        $upd2->execute([':token_id' => $tokenId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // 取り直し
    $st3 = $pdo->prepare("SELECT id, tenant_id, store_id, display_name, line_user_id FROM employees WHERE id = :id LIMIT 1");
    $st3->execute([':id' => $employeeId]);
    $newEmp = $st3->fetch(PDO::FETCH_ASSOC);

    return ['ok' => true, 'msg' => 'OK', 'employee' => $newEmp ?: $row];
}

function lineReplyText(string $channelAccessToken, string $replyToken, string $text): void
{
    $url = 'https://api.line.me/v2/bot/message/reply';

    $payload = [
        'replyToken' => $replyToken,
        'messages' => [
            ['type' => 'text', 'text' => $text],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $channelAccessToken,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 10,
    ]);

    curl_exec($ch);
    curl_close($ch);
}
