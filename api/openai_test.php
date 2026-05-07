<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/openai_client.php';

$resp = openai_responses('gpt-4.1-mini', 'テストです。短く挨拶して');
$text = openai_extract_text($resp);

header('Content-Type: text/plain; charset=utf-8');
echo $text;