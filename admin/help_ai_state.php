<?php

declare(strict_types=1);

/**
 * ✅ State Provider（intentごとに必要な証拠をDBから取る）
 * - ここが「正確性保証」の本丸
 * - “取れない”なら unknown を残し、断言させない
 */

function ha_state_for_intent(PDO $pdo, int $storeId, string $intent): array
{
    switch ($intent) {
        case 'payroll_can_finalize_check':
            return ha_state_payroll_can_finalize($pdo, $storeId);

        case 'missing_punch_check':
            return ha_state_missing_punch($pdo, $storeId);

        default:
            return [
                'facts' => [],
                'unknowns' => [],
                'meta' => ['store_id' => $storeId, 'intent' => $intent],
            ];
    }
}

/**
 * ✅ 例：給与確定チェック用の“証拠”
 * ※ここはあなたの実テーブルに合わせて拡張する（最初は存在確認から）
 */
function ha_state_payroll_can_finalize(PDO $pdo, int $storeId): array
{
    $state = [
        'facts' => [],
        'unknowns' => [],
        'meta' => ['store_id' => $storeId, 'intent' => 'payroll_can_finalize_check'],
    ];

    // ✅ ここでは「どのテーブルで判定するか」が未確定なので、存在確認を集める
    $candidates = [
        'time_punches',
        'employee_profiles',
        'payroll_runs',
        'payrolls',
        'payslips',
    ];

    foreach ($candidates as $t) {
        if (ha_tbl_exists($pdo, $t)) $state['facts'][] = "table_exists: {$t}=YES";
        else $state['unknowns'][] = "table_exists: {$t}=NO";
    }

    // ✅ 本当の保証判定は「対象期間」「未打刻数」「計算済み」などが取れてから
    // ここは“育てる”段階で、実テーブル確定後にSQLを追加する

    return $state;
}

function ha_state_missing_punch(PDO $pdo, int $storeId): array
{
    $state = [
        'facts' => [],
        'unknowns' => [],
        'meta' => ['store_id' => $storeId, 'intent' => 'missing_punch_check'],
    ];

    if (!ha_tbl_exists($pdo, 'time_punches')) {
        $state['unknowns'][] = 'time_punches が無いので未打刻判定ができない';
        return $state;
    }

    // ✅ 最小：直近30日件数（“ある/ない”のヒントではなく、事実として提示）
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM time_punches WHERE store_id=:sid AND punched_at >= (NOW() - INTERVAL 30 DAY)");
        $st->execute([':sid' => $storeId]);
        $cnt = (int)$st->fetchColumn();
        $state['facts'][] = "time_punches: last30days_count={$cnt}";
    } catch (Throwable $e) {
        $state['unknowns'][] = 'time_punches 集計に失敗';
    }

    return $state;
}