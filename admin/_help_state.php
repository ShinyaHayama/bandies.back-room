<?php

declare(strict_types=1);

/**
 * ✅ /admin/_help_state.php
 *
 * 目的：
 * - 「DBの状態を見ないと正確に答えられない質問」に対して、
 *   ①DBから状態を取得し
 *   ②取得できた事実だけで回答させ
 *   ③取得できない場合は「判定不能」と必ず言わせる
 *
 * 方針（重要）：
 * - “推測でOKと言う” を禁止する（保証の担保）
 * - DBに必要情報が無い/取得できない場合は、回答を「手順提示」に倒す
 *
 * 使い方（help_api.php側で）：
 * - $state = help_state_snapshot($pdo, $tenantId, $storeId);
 * - $policy = help_state_policy_prompt($state);
 * - AIに投げる system prompt / instruction に $policy を必ず混ぜる
 */

/**
 * テーブル存在チェック（安全）
 */
function help_tbl_exists(PDO $pdo, string $table): bool
{
    try {
        // MySQL想定：information_schema参照（権限が無い場合はフォールバック）
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // フォールバック：軽いSELECTを試す（失敗したら存在しない扱い）
        try {
            $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
            return true;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

/**
 * カラム存在チェック（安全）
 */
function help_col_exists(PDO $pdo, string $table, string $col): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :t
              AND column_name = :c
            LIMIT 1
        ");
        $stmt->execute([':t' => $table, ':c' => $col]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // 権限等で見れない場合は「不明」とする（＝保証できない）
        return false;
    }
}

/**
 * ✅ “給与確定して大丈夫か” のような判定に必要な「状態スナップショット」を作る
 *
 * 注意：
 * - あなたのDBスキーマが不明なので、ここでは「存在するテーブル・カラムだけ」で保守的に集計します。
 * - 必要なテーブルが無い場合は、state の該当項目を unknown にして「判定不能」に倒します（これが保証）。
 */
function help_state_snapshot(PDO $pdo, int $tenantId, int $storeId): array
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

    $state = [
        'meta' => [
            'generated_at' => $now,
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
        ],

        // ✅ 給与確定の可否に絡みやすいチェック（存在する分だけ埋める）
        'payroll' => [
            'can_judge' => false,   // 最終的に「判定可能」になった場合だけ true にする
            'judgement' => 'unknown', // ok / ng / unknown
            'reasons' => [],
            'facts' => [],          // 取得できた“事実”だけ入れる
            'unknowns' => [],       // 取れないもの（＝ここがある限りOK/NGを断言しない）
        ],
    ];

    // -------------------------
    // 1) 従業員が存在するか（例：employee_profiles / employees など）
    // -------------------------
    $employeeTables = ['employee_profiles', 'employees', 'staff', 'users'];
    $empFound = false;
    foreach ($employeeTables as $t) {
        if (!help_tbl_exists($pdo, $t)) continue;

        // tenant/store のカラムがあるかを安全に見る
        $hasTid = help_col_exists($pdo, $t, 'tenant_id');
        $hasSid = help_col_exists($pdo, $t, 'store_id');

        try {
            if ($hasTid && $hasSid) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE tenant_id=:tid AND store_id=:sid");
                $stmt->execute([':tid' => $tenantId, ':sid' => $storeId]);
            } elseif ($hasTid) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE tenant_id=:tid");
                $stmt->execute([':tid' => $tenantId]);
            } else {
                // スコープできない＝保証できない
                $state['payroll']['unknowns'][] = "{$t} はあるが tenant_id/store_id で絞れない（判定保証不可）";
                continue;
            }

            $cnt = (int)$stmt->fetchColumn();
            $state['payroll']['facts'][] = "従業員テーブル候補={$t} / 件数={$cnt}";
            $empFound = true;
            break;
        } catch (Throwable $e) {
            $state['payroll']['unknowns'][] = "{$t} の従業員数取得に失敗";
        }
    }
    if (!$empFound) {
        $state['payroll']['unknowns'][] = "従業員テーブルが特定できない（employee_profiles/employees等が見つからない）";
    }

    // -------------------------
    // 2) 打刻（未打刻/二重打刻など）の元になるテーブルの存在確認
    // ※ あなたの環境では time_punches が存在する可能性が高い
    // -------------------------
    $punchTables = ['time_punches', 'punches', 'attendance_punches'];
    $punchFound = false;
    foreach ($punchTables as $t) {
        if (!help_tbl_exists($pdo, $t)) continue;

        $hasTid = help_col_exists($pdo, $t, 'tenant_id');
        $hasSid = help_col_exists($pdo, $t, 'store_id');
        $hasAt  = help_col_exists($pdo, $t, 'punched_at'); // 代表的
        $hasType = help_col_exists($pdo, $t, 'punch_type'); // 出勤/退勤など

        if (!$hasTid || !$hasSid || !$hasAt) {
            $state['payroll']['unknowns'][] = "{$t} はあるが tenant_id/store_id/punched_at が揃わず集計できない";
            continue;
        }

        try {
            // 直近30日だけ軽く数える（重くならない範囲）
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `{$t}`
                WHERE tenant_id=:tid AND store_id=:sid
                  AND punched_at >= (NOW() - INTERVAL 30 DAY)
            ");
            $stmt->execute([':tid' => $tenantId, ':sid' => $storeId]);
            $cnt = (int)$stmt->fetchColumn();

            $state['payroll']['facts'][] = "打刻テーブル候補={$t} / 直近30日打刻件数={$cnt}" . ($hasType ? " / punch_typeあり" : "");
            $punchFound = true;
            break;
        } catch (Throwable $e) {
            $state['payroll']['unknowns'][] = "{$t} の打刻件数取得に失敗";
        }
    }
    if (!$punchFound) {
        $state['payroll']['unknowns'][] = "打刻テーブルが特定できない（time_punches等が見つからない）";
    }

    // -------------------------
    // 3) “給与確定” の状態を表すテーブル（例：payroll_runs / payrolls / payslips 等）
    // -------------------------
    $payrollTables = ['payroll_runs', 'payrolls', 'payslips', 'salary_runs', 'salary_slips'];
    $payrollFound = false;

    foreach ($payrollTables as $t) {
        if (!help_tbl_exists($pdo, $t)) continue;

        $hasTid = help_col_exists($pdo, $t, 'tenant_id');
        $hasSid = help_col_exists($pdo, $t, 'store_id');

        // 確定を表す代表カラム候補
        $confirmCols = ['is_confirmed', 'confirmed_at', 'finalized_at', 'is_finalized', 'status'];
        $hasConfirm = false;
        foreach ($confirmCols as $c) {
            if (help_col_exists($pdo, $t, $c)) {
                $hasConfirm = true;
                break;
            }
        }

        if (!$hasTid || !$hasSid || !$hasConfirm) {
            $state['payroll']['unknowns'][] = "{$t} はあるが tenant_id/store_id/確定カラム候補 が揃わず判定できない";
            continue;
        }

        // 軽い事実だけ取る（「今月の確定済みがあるか」程度）
        try {
            // 月キーが無い場合もあるので、created_at / target_month など候補で探索
            $monthCols = ['target_month', 'month', 'pay_month'];
            $dateCols  = ['created_at', 'updated_at', 'confirmed_at', 'finalized_at'];

            $monthCol = null;
            foreach ($monthCols as $c) {
                if (help_col_exists($pdo, $t, $c)) {
                    $monthCol = $c;
                    break;
                }
            }

            // 今月の有無だけを “事実” として取る（列が無い場合は直近30日で代替）
            if ($monthCol) {
                $ym = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m');
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE tenant_id=:tid AND store_id=:sid AND `{$monthCol}`=:ym");
                $stmt->execute([':tid' => $tenantId, ':sid' => $storeId, ':ym' => $ym]);
                $cnt = (int)$stmt->fetchColumn();
                $state['payroll']['facts'][] = "給与テーブル候補={$t} / 今月({$ym})のレコード数={$cnt}（{$monthCol}）";
            } else {
                // created_at等が無い場合があるので、とりあえず直近30日で件数
                $dateCol = null;
                foreach ($dateCols as $c) {
                    if (help_col_exists($pdo, $t, $c)) {
                        $dateCol = $c;
                        break;
                    }
                }
                if ($dateCol) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE tenant_id=:tid AND store_id=:sid AND `{$dateCol}` >= (NOW() - INTERVAL 30 DAY)");
                    $stmt->execute([':tid' => $tenantId, ':sid' => $storeId]);
                    $cnt = (int)$stmt->fetchColumn();
                    $state['payroll']['facts'][] = "給与テーブル候補={$t} / 直近30日レコード数={$cnt}（{$dateCol}）";
                } else {
                    $state['payroll']['unknowns'][] = "{$t} の月/日付カラムが見つからず状態把握が弱い";
                }
            }

            $payrollFound = true;
            break;
        } catch (Throwable $e) {
            $state['payroll']['unknowns'][] = "{$t} の給与状態取得に失敗";
        }
    }

    if (!$payrollFound) {
        $state['payroll']['unknowns'][] = "給与確定状態のテーブルが特定できない（payroll_runs/payrolls等が見つからない）";
    }

    // -------------------------
    // 4) 最終判定：保証ポリシー
    // - unknown が1つでも残るなら “ok/ng を断言しない”
    // -------------------------
    if (count($state['payroll']['unknowns']) === 0 && ($empFound || $punchFound || $payrollFound)) {
        // ここで初めて「判定可能」とする（ただし本当のOK/NGロジックはあなたの仕様に合わせて追加が必要）
        $state['payroll']['can_judge'] = true;

        // ✅ 現時点では “断言できるロジック” を仕様不明で作れないので、judgementはunknownのまま
        //    ＝ ここをあなたの給与確定仕様（必要条件）に合わせて追加すれば「OK/NG保証」が可能になります。
        $state['payroll']['judgement'] = 'unknown';
        $state['payroll']['reasons'][] = 'DBから一定の事実は取得できたが、給与確定の合否条件（あなたの仕様）が未定義のため、断言はしない';
    } else {
        $state['payroll']['can_judge'] = false;
        $state['payroll']['judgement'] = 'unknown';
        $state['payroll']['reasons'][] = 'DBの必要情報が揃わないため、OK/NGを断言できない（保証のため）';
    }

    return $state;
}

/**
 * ✅ AIに必ず守らせる「保証ポリシー」
 * - state に無いことは断言禁止
 * - 判定不能なら「判定不能」と明言し、次に押すべき操作に誘導
 */
function help_state_policy_prompt(array $state): string
{
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return
        "あなたは勤怠/給与システムの業務ナビAIです。
次のルールを絶対に守ってください（重要：正確性保証）。

【ルール】
1) 以下のDBスナップショット(JSON)に書かれていない事実を、推測で断言しない。
2) 「大丈夫？」「確定してOK？」など“可否判定”は、DBスナップショットで必要条件が揃わない限り、必ず『判定できません』と言う。
3) 判定できない場合は、代わりに『確定前チェック項目（上から順）』と『どの画面で何を確認するか』を、短い箇条書きで提示する。
4) できる限り『次に押すボタン/見る画面』で案内し、説明を長くしない。

【DBスナップショット(JSON)】
{$json}
";
}