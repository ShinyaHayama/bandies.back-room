<?php

declare(strict_types=1);

/**
 * ✅ Intent Router（質問 → intent）
 * - ここは“育つ”ために、最初はルール＋辞書でOK
 * - 将来、ログが貯まったら embeddings などに差し替え可能
 */

function ha_detect_intent(string $q): string
{
    $q = mb_strtolower(trim($q), 'UTF-8');

    // ✅ 代表的な状態依存（判定系）
    if (mb_strpos($q, '給与') !== false && (mb_strpos($q, '確定') !== false || mb_strpos($q, '締め') !== false)) {
        return 'payroll_can_finalize_check';
    }
    if (mb_strpos($q, '未打刻') !== false || (mb_strpos($q, '出勤') !== false && mb_strpos($q, 'ない') !== false)) {
        return 'missing_punch_check';
    }

    // ✅ 操作案内（状態不要）
    if (mb_strpos($q, 'pdf') !== false || mb_strpos($q, '印刷') !== false) return 'howto_export_pdf';
    if (mb_strpos($q, '従業員') !== false && (mb_strpos($q, '追加') !== false || mb_strpos($q, '登録') !== false)) return 'staff_add';
    if (mb_strpos($q, '時給') !== false && (mb_strpos($q, '変更') !== false || mb_strpos($q, '修正') !== false)) return 'wage_change';
    if (mb_strpos($q, 'エラー') !== false || mb_strpos($q, 'unknown_action') !== false) return 'error_fix';

    return 'general_help';
}