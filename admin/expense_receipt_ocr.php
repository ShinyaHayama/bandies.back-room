<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/_auth.php';
require_admin_login();
require_once __DIR__ . '/_tenant_context.php';
require_once __DIR__ . '/../api/bootstrap.php';

$tenantId = (int)($tenantId ?? 0);
if ($tenantId <= 0) out(['ok' => false, 'error' => 'ログイン情報が不正です。']);

$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || !isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    out(['ok' => false, 'error' => 'CSRFトークンが不正です。']);
}

$storeId = (int)($_POST['store_id'] ?? 0);
if ($storeId <= 0) out(['ok' => false, 'error' => '店舗が不正です。']);

if (!isset($_FILES['receipt_image']) || !is_array($_FILES['receipt_image'])) {
    out(['ok' => false, 'error' => '画像を選択してください。']);
}

$file = $_FILES['receipt_image'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    out(['ok' => false, 'error' => '画像アップロードに失敗しました。']);
}

$tmp = (string)($file['tmp_name'] ?? '');
$size = (int)($file['size'] ?? 0);
if ($tmp === '' || !is_uploaded_file($tmp)) {
    out(['ok' => false, 'error' => '画像を確認できませんでした。']);
}
if ($size <= 0 || $size > 8 * 1024 * 1024) {
    out(['ok' => false, 'error' => '画像サイズは8MB以内にしてください。']);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmp);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    out(['ok' => false, 'error' => '対応形式は JPG / PNG / WebP です。']);
}

$apiKey = (string)($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '');
if ($apiKey === '') {
    out(['ok' => false, 'error' => 'OPENAI_API_KEY が未設定です。']);
}

$bytes = file_get_contents($tmp);
if ($bytes === false || $bytes === '') {
    out(['ok' => false, 'error' => '画像を読み込めませんでした。']);
}

$prompt = <<<TXT
この画像は日本のレシートです。
店名、購入日、合計金額を読み取ってください。
必ずJSONだけを返してください。説明文やMarkdownは禁止です。

形式:
{
  "vendor": string|null,
  "date": "YYYY-MM-DD"|null,
  "total_yen": integer|null
}

ルール:
- total_yen は「合計」「税込合計」「お買上げ合計」「ご請求額」「クレジット売上」など、最終支払金額に最も近い金額を選んでください。
- 税抜小計ではなく、税込の最終支払金額を優先してください。
- 金額が複数あり判断できない場合は、最終支払額らしいものを選んでください。
- 読み取れない項目は null にしてください。
TXT;

$payload = [
    'model' => 'gpt-4.1-mini',
    'input' => [[
        'role' => 'user',
        'content' => [
            ['type' => 'input_text', 'text' => $prompt],
            ['type' => 'input_image', 'image_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)],
        ],
    ]],
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
]);
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$err = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno) out(['ok' => false, 'error' => 'OCR通信に失敗しました: ' . $err]);
if ($raw === false || $raw === '') out(['ok' => false, 'error' => 'OCRの応答が空でした。']);

$resp = json_decode($raw, true);
if (!is_array($resp)) out(['ok' => false, 'error' => 'OCR応答の解析に失敗しました。']);
if ($status < 200 || $status >= 300) {
    $msg = (string)($resp['error']['message'] ?? ('HTTP ' . $status));
    out(['ok' => false, 'error' => 'OCRエラー: ' . $msg]);
}

$text = '';
if (isset($resp['output'][0]['content']) && is_array($resp['output'][0]['content'])) {
    foreach ($resp['output'][0]['content'] as $content) {
        if (isset($content['text']) && is_string($content['text'])) {
            $text .= $content['text'] . "\n";
        }
    }
}
if ($text === '' && isset($resp['output_text']) && is_string($resp['output_text'])) {
    $text = $resp['output_text'];
}
$text = trim($text);
$text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
$text = preg_replace('/\s*```$/', '', $text) ?? $text;
$parsed = json_decode(trim($text), true);
if (!is_array($parsed)) {
    out(['ok' => false, 'error' => 'レシート内容をJSONとして読み取れませんでした。']);
}

$vendor = trim((string)($parsed['vendor'] ?? ''));
$date = trim((string)($parsed['date'] ?? ''));
$total = isset($parsed['total_yen']) ? (int)$parsed['total_yen'] : 0;
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
if ($total <= 0) {
    out(['ok' => false, 'error' => '合計金額を読み取れませんでした。手入力してください。']);
}

out([
    'ok' => true,
    'vendor' => $vendor !== '' ? $vendor : null,
    'date' => $date !== '' ? $date : null,
    'total_yen' => $total,
    'name' => $vendor !== '' ? $vendor : 'レシート経費',
    'memo' => 'レシート' . ($date !== '' ? (' ' . $date) : ''),
]);
