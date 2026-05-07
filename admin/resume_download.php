<?php

declare(strict_types=1);

/**
 * ✅ ファイル名: /admin/resume_download.php
 * ✅ 書き込み場所: 新規作成
 *
 * 目的:
 * - 履歴書は直URLで公開しない
 * - 管理者ログイン + tenant/store/employee一致を必ず検証してから返す
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

require_once __DIR__ . '/_tenant_context.php';
$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) {
    http_response_code(403);
    echo 'tenant missing';
    exit;
}

require_once __DIR__ . '/../api/lib/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$storeId = (int)($_GET['store_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);

if ($storeId <= 0 || $employeeId <= 0) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

// employees から履歴書情報を取得（tenant/store/employee一致必須）
$st = $pdo->prepare("
    SELECT id, tenant_id, store_id, resume_path, resume_original_name
    FROM employees
    WHERE tenant_id=:t AND store_id=:s AND id=:e
    LIMIT 1
");
$st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
$row = $st->fetch();

if (!$row) {
    http_response_code(404);
    echo 'not found';
    exit;
}

$resumePath = (string)($row['resume_path'] ?? '');
$origName = (string)($row['resume_original_name'] ?? 'resume');

if ($resumePath === '') {
    http_response_code(404);
    echo 'resume not found';
    exit;
}

/**
 * ✅ 保存の基準ディレクトリ（非公開）
 * employee_edit.php と揃えること
 */
$baseDir = realpath(__DIR__ . '/../_private/resumes');
if ($baseDir === false) {
    http_response_code(500);
    echo 'storage not found';
    exit;
}

// resume_path は例: _private/resumes/t1/s1/e3/xxxx.pdf
// 先頭の "_private/resumes/" を取り除いて結合する
$prefix = '_private/resumes/';
$rel = $resumePath;
if (strpos($rel, $prefix) === 0) {
    $rel = substr($rel, strlen($prefix));
}
$abs = $baseDir . DIRECTORY_SEPARATOR . str_replace(['..', '\\'], ['', '/'], $rel);

// パストラバーサル対策：realpathで基準配下を強制
$real = realpath($abs);
if ($real === false || strpos($real, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
    http_response_code(404);
    echo 'file not found';
    exit;
}

// MIME推定
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($real);
if ($mime === '') $mime = 'application/octet-stream';

// ファイル名を安全化（CRLFなど除去）
$safeName = preg_replace('/[\r\n]+/', ' ', $origName) ?? 'resume';
if ($safeName === '') $safeName = 'resume';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($real));
// 画面表示も可能だが、基本はダウンロードでOK
header('Content-Disposition: inline; filename="' . rawurlencode($safeName) . '"');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

readfile($real);
exit;