<?php

declare(strict_types=1);

/**
 * ✅ LLM呼び出しの薄いラッパ
 * - ここはあなたのOpenAI実装に置き換える
 * - 重要：返却形式はJSONに固定（パース失敗したら即停止）
 */
final class AiClient
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function callJson(array $payload): array
    {
        // TODO: OpenAI API を呼ぶ実装に差し替え
        // ここではダミー
        return [
            'ok' => false,
            'error' => 'AiClient not implemented',
        ];
    }
}