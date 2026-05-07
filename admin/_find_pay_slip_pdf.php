<?php

declare(strict_types=1);

/**
 * ✅ 目的
 * サーバーで grep / find が叩けない環境でも、
 * ブラウザで「pay_slips を触ってるPHP」や「PDF出力っぽいPHP」を探せるようにする。
 *
 * ✅ 使い方
 * 1) /admin/_find_pay_slip_pdf.php にアップ
 * 2) 管理者ログイン済みでURLを開く
 * 3) 出てきた候補ファイルを開いて、PDF出力の直前に finalize を入れる
 *
 * ✅ 注意
 * - 公開したままは危険なので、調査後は削除してください
 */

require_once __DIR__ . '/_auth.php';
require_admin_login();

$root = __DIR__;
$maxFiles = 2000;   // 探索上限（暴走防止）
$maxHits  = 200;    // 表示上限（見やすさ）
$scanExt  = ['php']; // 対象拡張子

// 探したいキーワード（必要なら増やしてOK）
$needles = [
    'pay_slips',
    'tax_withholding',
    'withholding_tax',
    'dompdf',
    'tcpdf',
    'mpdf',
    'PDF',
    'pdf',
];

$filesScanned = 0;
$hits = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    if ($filesScanned >= $maxFiles) {
        break;
    }
    /** @var SplFileInfo $file */
    if (!$file->isFile()) continue;

    $ext = strtolower((string)$file->getExtension());
    if (!in_array($ext, $scanExt, true)) continue;

    $path = $file->getPathname();

    // でかいファイルは読み飛ばし（暴走防止）
    if ($file->getSize() > 2 * 1024 * 1024) { // 2MB
        $filesScanned++;
        continue;
    }

    $content = @file_get_contents($path);
    $filesScanned++;
    if ($content === false) continue;

    foreach ($needles as $needle) {
        $pos = strpos($content, $needle);
        if ($pos === false) continue;

        // 周辺の抜粋を作る
        $start = max(0, $pos - 80);
        $snippet = substr($content, $start, 200);
        $snippet = str_replace(["\r\n", "\r", "\n", "\t"], " ", $snippet);

        $hits[] = [
            'file' => $path,
            'needle' => $needle,
            'snippet' => $snippet,
        ];
        if (count($hits) >= $maxHits) break 2;
    }
}

header('Content-Type: text/html; charset=utf-8');

echo '<h2>pay_slips / PDF 関連ファイル探索</h2>';
echo '<div style="margin:8px 0;color:#555;">scanned: ' . htmlspecialchars((string)$filesScanned, ENT_QUOTES, 'UTF-8') . ' files</div>';

if (!$hits) {
    echo '<div style="padding:12px;border:1px solid #ccc;border-radius:8px;">ヒットなし。キーワードを増やすか、探索ディレクトリを変えてください。</div>';
    exit;
}

echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;">';
echo '<tr style="background:#f2f2f2;"><th>file</th><th>keyword</th><th>snippet</th></tr>';

foreach ($hits as $h) {
    echo '<tr>';
    echo '<td style="font-family:monospace;">' . htmlspecialchars($h['file'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($h['needle'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td style="font-family:monospace;white-space:pre-wrap;">' . htmlspecialchars($h['snippet'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '</tr>';
}

echo '</table>';

echo '<div style="margin-top:12px;color:#b00;font-weight:700;">※ 調査が終わったらこのファイルは削除してください。</div>';