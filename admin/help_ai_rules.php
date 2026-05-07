<?php

declare(strict_types=1);

/**
 * ✅ Rule Engine（断言はここだけ）
 * - LLMは禁止：ここがOK/NG/判定不可を返す
 */

function ha_rule_judge(string $intent, array $state): array
{
    // 返却：status = ok / ng / unknown
    // ok/ng の根拠を reasons[] に入れる（UIにそのまま出せる）
    switch ($intent) {
        case 'payroll_can_finalize_check':
            // ✅ 現時点では十分な証拠が無い設計にしておく（事故防止）
            if (!empty($state['unknowns'])) {
                return ['status' => 'unknown', 'reasons' => $state['unknowns']];
            }
            return ['status' => 'unknown', 'reasons' => ['判定に必要な証拠（対象期間/未打刻/計算済み等）が未実装']];

        case 'missing_punch_check':
            if (!empty($state['unknowns'])) {
                return ['status' => 'unknown', 'reasons' => $state['unknowns']];
            }
            // ✅ ここも本当は「未打刻」を数えるロジックが必要。今は断言しない。
            return ['status' => 'unknown', 'reasons' => ['未打刻の定義（出勤のみ/退勤のみ等）が未実装']];

        default:
            return ['status' => 'unknown', 'reasons' => ['このintentは判定対象外']];
    }
}