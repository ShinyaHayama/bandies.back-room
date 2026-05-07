<?php

declare(strict_types=1);

/**
 * ✅ Answer Composer
 * - 断言（OK/NG）は rule_judge の結果だけ
 * - LLMは “言い回し” だけに限定（あなたの help_ai_core.php に委譲するならここで合成）
 */

function ha_compose_answer(string $intent, array $state, array $judge, string $question): string
{
    $lines = [];
    $lines[] = "（目的）" . $intent;
    $lines[] = "";

    if ($judge['status'] === 'ok') {
        $lines[] = "結論：OK（確定して進められます）";
    } elseif ($judge['status'] === 'ng') {
        $lines[] = "結論：NG（このまま確定は危険です）";
    } else {
        $lines[] = "結論：判定できません（DBの証拠が不足しています）";
    }

    $lines[] = "";
    $lines[] = "根拠：";
    foreach (($judge['reasons'] ?? []) as $r) $lines[] = "・" . (string)$r;

    if (!empty($state['facts'])) {
        $lines[] = "";
        $lines[] = "DBから取れた事実：";
        foreach ($state['facts'] as $f) $lines[] = "・" . (string)$f;
    }

    $lines[] = "";
    $lines[] = "次にやること（押す順番）：";
    $lines[] = "1) 未打刻チェック";
    $lines[] = "2) 明細→PDFで数字確認";
    $lines[] = "3) 問題があれば対象スタッフだけ修正";
    $lines[] = "";
    $lines[] = "（質問）" . trim($question);

    return implode("\n", $lines);
}