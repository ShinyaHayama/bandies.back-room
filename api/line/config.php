<?php
// /api/line/config.php
declare(strict_types=1);

/**
 * ✅ LINE Channel Secret
 * - スクショに写ったsecretは再発行推奨（漏洩扱い）
 * - ここは必ず新しいsecretに差し替える
 */
return [
    'channel_secret' => '847fc0a128b331705e6e1359c2435e55',

    // 追加：Messaging API の Channel access token（長期トークン）
    'channel_access_token' => 'gwNom1T15NQmkGbazkuU0YLfG0R1XQW5LhYCDLYizS9nrtsWW8uVNC+WXGxyWmPj9TXiqGDhqu0EM9d1zjXPpLnpRRr6dsctUr90GLkcak40rxQL1Ob70dxIbomdAvIAprO0skS5Rcp1UV5GxQ2wTAdB04t89/1O/w1cDnyilFU=',

    'tenant_id' => 1,

    // ✅ 従業員に store_id が入っていない場合のフォールバック
    'default_tenant_id' => 1,
    'default_store_id'  => 1,

    // ログ
    'log_file' => __DIR__ . '/line_webhook.log',

    // ログ（運用で肥大化するので、動作確認できたらDB化推奨）
    'log_file' => __DIR__ . '/line_webhook.log',
];