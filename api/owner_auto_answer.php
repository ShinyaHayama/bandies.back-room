<?php

declare(strict_types=1);

/**
 * ✅ エンドポイント
 * - /api/owner_auto_answer.php
 * - POST: q=質問文
 *
 * ✅ 目的
 * - あなたの環境では fl_effective_tenant_id / fl_effective_store_id / fl_db / pdo / OpenAIClient 等が「存在しない」ため、
 *   IDEでも実行時でも落ちないように「文字列で探索して call_user_func / Reflection で呼ぶ」方式に寄せる。
 *
 * ✅ 重要
 * - tenant_id は推測しない：取れない場合はエラーで返す（POST tenant_id で渡してもらう）
 * - store_id は任意：取れない場合は null のまま
 */

header('Content-Type: application/json; charset=utf-8');

// ------------------------------------------------------------
// 0) bootstrap（あれば）
// ------------------------------------------------------------
$bootstrap = dirname(__DIR__) . '/bootstrap.php';
if (is_file($bootstrap)) {
    require_once $bootstrap;
}

// ------------------------------------------------------------
// 1) 依存ファイル（存在するものだけ安全に）
// ------------------------------------------------------------
$dbFile = __DIR__ . '/../lib/db.php';
if (is_file($dbFile)) {
    require_once $dbFile;
} else {
    echo json_encode(['ok' => false, 'error' => 'db.php not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$authFile = __DIR__ . '/../lib/auth.php';
if (is_file($authFile)) {
    require_once $authFile;
}

$authDeviceFile = __DIR__ . '/../lib/auth_device.php';
if (is_file($authDeviceFile)) {
    require_once $authDeviceFile;
}

$openaiClientFile = __DIR__ . '/../lib/openai_client.php';
if (is_file($openaiClientFile)) {
    require_once $openaiClientFile;
}

$loopFile = __DIR__ . '/ai_loop/OwnerAutoAnswerLoop.php';
if (is_file($loopFile)) {
    require_once $loopFile;
} else {
    echo json_encode(['ok' => false, 'error' => 'OwnerAutoAnswerLoop.php not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// 2) 入力
// ------------------------------------------------------------
$q = trim((string)($_POST['q'] ?? ''));
if ($q === '') {
    echo json_encode(['ok' => false, 'error' => 'q is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// 3) tenant_id / store_id（推測しない）
// ------------------------------------------------------------
$resolveTenantId = static function (): int {
    // (A) 既存関数があるならそれを使う（関数名を直接書かない＝IDE/静的解析に優しい）
    $fn = 'fl_effective_tenant_id';
    if (function_exists($fn)) {
        try {
            $v = call_user_func($fn);
            return (int)$v;
        } catch (Throwable $e) {
            // 例外でも落とさない
        }
    }

    // (B) POST 明示
    if (isset($_POST['tenant_id']) && is_scalar($_POST['tenant_id'])) {
        return (int)$_POST['tenant_id'];
    }

    // (C) SESSION
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (isset($_SESSION['tenant_id'])) return (int)$_SESSION['tenant_id'];
        if (isset($_SESSION['tenantId']))  return (int)$_SESSION['tenantId'];
    }

    return 0; // ✅ 不明なら 0（推測しない）
};

$resolveStoreId = static function (): ?int {
    $fn = 'fl_effective_store_id';
    if (function_exists($fn)) {
        try {
            $v = call_user_func($fn);
            $sid = (int)$v;
            return ($sid > 0) ? $sid : null;
        } catch (Throwable $e) {
        }
    }

    if (isset($_POST['store_id']) && is_scalar($_POST['store_id'])) {
        $sid = (int)$_POST['store_id'];
        return ($sid > 0) ? $sid : null;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (isset($_SESSION['store_id'])) {
            $sid = (int)$_SESSION['store_id'];
            return ($sid > 0) ? $sid : null;
        }
        if (isset($_SESSION['storeId'])) {
            $sid = (int)$_SESSION['storeId'];
            return ($sid > 0) ? $sid : null;
        }
    }

    return null;
};

$tenantId = $resolveTenantId();
$storeId  = $resolveStoreId();

if ($tenantId <= 0) {
    echo json_encode([
        'ok' => false,
        'error' => 'tenant_id_missing',
        'hint' => 'この環境では tenant_id を自動取得できません。POST で tenant_id=1 のように渡してください。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// 4) PDO 解決（関数名を「直接呼ばない」）
// ------------------------------------------------------------
$resolvePdo = static function (): ?PDO {
    // よくある候補（あなたの環境に合わせて「存在するやつ」だけが呼ばれる）
    $candidates = [
        'fl_db',
        'db',
        'get_db',
        'get_pdo',
        'pdo',
        'connect_db',
    ];

    foreach ($candidates as $fn) {
        if (!function_exists($fn)) continue;

        try {
            $pdo = call_user_func($fn);
            if ($pdo instanceof PDO) return $pdo;
        } catch (Throwable $e) {
            // 次へ
        }
    }

    // グローバル保険
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    return null;
};

$pdo = $resolvePdo();
if (!($pdo instanceof PDO)) {
    echo json_encode([
        'ok' => false,
        'error' => 'pdo_missing',
        'hint' => 'PDO接続を返す関数が見つかりません。/lib/db.php 内の「PDOを返す関数名」を確認してください。',
        'debug_candidates' => ['fl_db', 'db', 'get_db', 'get_pdo', 'pdo', 'connect_db'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// 5) AI クライアント生成（クラス名を決め打ちしない）
// ------------------------------------------------------------
$makeAiClient = static function () {
    // (A) 既存の factory 関数がある場合（関数名は文字列で）
    foreach (['ai_client', 'openai_client', 'get_openai_client', 'make_openai_client'] as $fn) {
        if (!function_exists($fn)) continue;
        try {
            return call_user_func($fn);
        } catch (Throwable $e) {
        }
    }

    // (B) 既存クラスを探索して new（優先順：それっぽい名前）
    $classCandidates = [
        'AiClient',
        'OpenAIClient',
        'OpenAI',
        'OpenAIResponsesClient',
        'OpenAIClientV1',
    ];

    foreach ($classCandidates as $cn) {
        if (!class_exists($cn)) continue;

        try {
            $ref = new ReflectionClass($cn);
            if (!$ref->isInstantiable()) continue;

            $ctor = $ref->getConstructor();
            if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
                return $ref->newInstance();
            }

            // required 引数があるクラスは、ここでは推測して渡さない（落とさない）
            continue;
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
};

$ai = $makeAiClient();
if ($ai === null) {
    echo json_encode([
        'ok' => false,
        'error' => 'ai_client_missing',
        'hint' => '/lib/openai_client.php に「引数なしで new できるクラス」または「ai_client() のようなfactory関数」を用意してください。',
        'debug_tried_classes' => ['AiClient', 'OpenAIClient', 'OpenAI', 'OpenAIResponsesClient', 'OpenAIClientV1'],
        'debug_tried_factories' => ['ai_client', 'openai_client', 'get_openai_client', 'make_openai_client'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// 6) Loop 実行
// ------------------------------------------------------------
try {
    $loop = new OwnerAutoAnswerLoop($pdo, $ai);
    $res  = $loop->run($q, $tenantId, $storeId);

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'exception',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}