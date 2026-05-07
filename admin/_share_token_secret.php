<?php

declare(strict_types=1);

/**
 * ✅ /admin/_share_token_secret.php（新規）
 * サーバー内だけに置く秘密鍵
 * - 公開しない
 * - Git管理しない
 */

return [
    // 例: ランダムで長い文字列にして下さい（最低32文字推奨）
    'secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET_32CHARS_MIN',
];