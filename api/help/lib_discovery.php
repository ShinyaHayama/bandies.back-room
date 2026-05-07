<?php

declare(strict_types=1);

/**
 * 結論：
 * - 「DB上にある“あらゆる質問”」への全自動対応は、現実的にも安全面でも NG です。
 *   （意図しないテーブル/列へのアクセス、個人情報・金額・機密の漏洩、破壊系SQL混入など）
 *
 * なので実装方針はこうします：
 * 1) 質問を intent（=対応可能な“問いの型”）に分類
 * 2) intent ごとに
 *    - 参照して良いデータ範囲（テーブル/列/条件）を明示した「許可ルール」
 *    - SQLはテンプレ化（＝生成しない/固定する）
 *    - 値は必ずバインド
 * 3) 対応外は「対応していない」と即答し、必要なら “追加すべきintent” をログに残す
 *
 * 例：
 * - 「◯◯さんの全出勤数」=> attendance_count（OK）
 * - 「◯◯さんの給料は？」=> payroll_amount（OKにしたいなら intent を追加する必要がある）
 * - 「今月の売上推移を全部」=> sales_monthly_trend（OKにしたいなら intent 追加）
 * - 「DBにある全データを出して」=> NG（許可しない）
 */

/**
 * ✅ intent パーサ（拡張版）
 * - ここで「出勤数」「給料」などの型に分類する
 * - “あらゆる質問”ではなく、増やしていく方式
 */
function help_parse_intent(string $question): array
{
    $q = trim($question);

    // --- 出勤数 ---
    // 例: "テッピーの全出勤の数は？" / "テッピーさんの出勤回数は？"
    if (preg_match('/^(.+?)(さん)?の(全)?出勤(の)?(数|回数)は[？?]?$/u', $q, $m)) {
        return [
            'type' => 'attendance_count',
            'employee_name' => trim((string)$m[1]),
        ];
    }

    // --- 給料 ---
    // 例: "テッピーさんの給料は？" / "テッピーの給与はいくら？"
    if (preg_match('/^(.+?)(さん)?の(給料|給与)は(いくら)?[？?]?$/u', $q, $m)) {
        return [
            'type' => 'payroll_amount',
            'employee_name' => trim((string)$m[1]),
        ];
    }

    return [
        'type' => 'unknown',
        'employee_name' => '',
    ];
}

/**
 * ✅ intent ごとの TTL
 * - 変動する値は短期キャッシュ（=負荷軽減目的）
 * - “最新を保証したい”なら 0 にして常に再計算
 */
function help_intent_ttl_seconds(string $intentKey): int
{
    switch ($intentKey) {
        case 'attendance_count':
            return 120; // 出勤は増える
        case 'payroll_amount':
            // 給料は「締め確定後は固定」だが「月/期間」の概念があるので短め（または 0 推奨）
            return 120;
        default:
            return 0;
    }
}

function help_facts_is_fresh(array $row, int $ttlSeconds): bool
{
    if ($ttlSeconds <= 0) return false;
    $ts = (string)($row['updated_at'] ?? '');
    if ($ts === '') return false;
    $t = strtotime($ts);
    if ($t === false) return false;
    return (time() - $t) <= $ttlSeconds;
}

/**
 * ✅ 給料算出（自動探索）
 * - ここも「何でも」ではなく、決め打ちの候補から探索して当たったものだけ使う
 * - 例：payslips / payroll_runs / payroll_items のようなテーブルを候補にする
 *
 * 戻り値:
 * - null: 根拠テーブルを特定できない
 * - array: ['amount' => int, 'evidence' => array]
 *
 * 注意：
 * - 「給料」は “どの期間の？” が本来必要です（今月？先月？直近確定？）。
 * - 今回は最小対応として「直近確定の1件」を返す想定の探索にしています。
 */
function help_discover_and_get_latest_payroll_amount(PDO $pdo, int $tenantId, int $storeId, int $employeeId): ?array
{
    // 候補1: payroll_payslips (例)
    if (help_table_exists($pdo, 'payroll_payslips')) {
        // 必須っぽい列があるかチェック（なければ使わない）
        $needCols = ['tenant_id', 'store_id', 'employee_id', 'net_pay', 'created_at'];
        foreach ($needCols as $c) {
            if (!help_column_exists($pdo, 'payroll_payslips', $c)) {
                return null; // この候補は使えない
            }
        }

        $sql = "
            SELECT net_pay, created_at
            FROM payroll_payslips
            WHERE tenant_id=:t AND store_id=:s AND employee_id=:e
            ORDER BY created_at DESC
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $amt = (int)$row['net_pay'];
            return [
                'amount' => $amt,
                'evidence' => [
                    'source_table' => 'payroll_payslips',
                    'source' => 'latest_by_created_at',
                    'created_at' => (string)$row['created_at'],
                    'column' => 'net_pay',
                ],
            ];
        }
        // テーブルがあってもデータが無い場合は、他候補へ（下へ続く）
    }

    // 候補2: payroll_runs + payroll_items のような形（例）
    if (help_table_exists($pdo, 'payroll_items')) {
        // 必須列の軽いチェック
        $needCols = ['tenant_id', 'store_id', 'employee_id', 'amount', 'created_at'];
        foreach ($needCols as $c) {
            if (!help_column_exists($pdo, 'payroll_items', $c)) {
                return null;
            }
        }

        $sql = "
            SELECT amount, created_at
            FROM payroll_items
            WHERE tenant_id=:t AND store_id=:s AND employee_id=:e
            ORDER BY created_at DESC
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':t' => $tenantId, ':s' => $storeId, ':e' => $employeeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $amt = (int)$row['amount'];
            return [
                'amount' => $amt,
                'evidence' => [
                    'source_table' => 'payroll_items',
                    'source' => 'latest_by_created_at',
                    'created_at' => (string)$row['created_at'],
                    'column' => 'amount',
                ],
            ];
        }
    }

    return null;
}

/**
 * ✅ 既存の help_table_exists / help_column_exists が無い場合に備えた最低限実装例
 * - すでに別実装があるなら、ここは重複させず既存を使ってください
 */
function help_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SHOW TABLES LIKE :t");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

function help_column_exists(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
    $st->execute([':c' => $col]);
    return (bool)$st->fetchColumn();
}