<?php

declare(strict_types=1);

require_once __DIR__ . '/AiClient.php';
require_once __DIR__ . '/SqlPolicy.php';

/**
 * ✅ 全自動反復ループ
 * AI→DB調査→AI分析→（不足なら）次の調査… を安全に繰り返す
 */
final class OwnerAutoAnswerLoop
{
    private PDO $pdo;
    private AiClient $ai;

    public function __construct(PDO $pdo, AiClient $ai)
    {
        $this->pdo = $pdo;
        $this->ai  = $ai;
    }

    /**
     * @return array{ok:bool, answer:string, debug?:array<string,mixed>}
     */
    public function run(string $question, int $tenantId, ?int $storeId = null): array
    {
        // ===== ガード（無限は禁止）=====
        $MAX_STEPS = 5;         // 反復回数上限
        $MAX_DB_QUERIES = 8;    // DBクエリ上限
        $DEADLINE_MS = 3000;    // 時間上限
        $startedMs = (int)(microtime(true) * 1000);

        $facts = [];            // AIに渡す「確定事実」
        $dbQueries = 0;

        // ===== “解決するまで” の安全反復 =====
        for ($step = 1; $step <= $MAX_STEPS; $step++) {

            // ⏱ 時間ガード
            $nowMs = (int)(microtime(true) * 1000);
            if (($nowMs - $startedMs) > $DEADLINE_MS) {
                break;
            }

            // 1) AIに「次に何を調べるか」をJSONで出させる
            $plan = $this->ai->callJson([
                'mode' => 'make_plan',
                'question' => $question,
                'facts' => $facts,
                'constraints' => [
                    'owner_level' => true,
                    'no_user_questions' => true,
                    'json_only' => true,
                    'allowed_tables' => SchemaRegistry::allowedTables(),
                ],
            ]);

            if (empty($plan['ok'])) {
                // AIが壊れたら停止（推測しない）
                return [
                    'ok' => false,
                    'answer' => "結論：自動調査に失敗しました。次のいずれか1つだけください：\n1) 該当画面のスクショ\n2) 該当者のプロフィールURL\n3) 集計期間",
                    'debug' => ['ai_error' => $plan],
                ];
            }

            // plan例：
            // {
            //   ok: true,
            //   done: false,
            //   queries: [{sql:"SELECT ... WHERE tenant_id=:tenant_id ...", params:{...}}, ...],
            //   need: null | {type:"period"|...}
            // }

            // 2) 必要ならDBクエリを実行してfactsを増やす
            $queries = $plan['queries'] ?? [];
            foreach ($queries as $q) {
                if ($dbQueries >= $MAX_DB_QUERIES) {
                    break 2; // DB上限で停止
                }

                $sql = (string)($q['sql'] ?? '');
                /** @var array<string,mixed> $params */
                $params = (array)($q['params'] ?? []);

                // tenantスコープ強制
                $params['tenant_id'] = $tenantId;

                SqlPolicy::assertSelectOnly($sql);
                SqlPolicy::assertAllowedTables($sql);
                SqlPolicy::assertTenantScope($sql);

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($this->normalizeParams($params));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $facts[] = [
                    'sql_id' => (string)($q['id'] ?? ('step' . $step . '_q' . $dbQueries)),
                    'rows' => $rows,
                ];
                $dbQueries++;
            }

            // 3) AIにfactsを渡して「回答できるか」を判定し、できるなら回答生成
            $ans = $this->ai->callJson([
                'mode' => 'make_answer',
                'question' => $question,
                'facts' => $facts,
                'rules' => [
                    'owner_level' => true,
                    'format' => 'conclusion_then_action_then_notes',
                    'no_guess' => true,
                ],
            ]);

            if (!empty($ans['ok']) && !empty($ans['done']) && is_string($ans['answer'] ?? null)) {
                return ['ok' => true, 'answer' => (string)$ans['answer']];
            }

            // 4) doneにならない場合、次stepへ（AIが次の調査を出す）
        }

        // ===== 安全停止：最短で追加入力を要求 =====
        return [
            'ok' => false,
            'answer' => "結論：自動調査の範囲では確定できませんでした。次のうち1つだけください：\n"
                . "1) 該当画面のスクショ\n"
                . "2) 該当者のプロフィールURL\n"
                . "3) 集計期間（例：2026-01-01〜2026-01-31）",
            'debug' => ['facts_count' => count($facts)],
        ];
    }

    /**
     * PDOに渡す型を整える（null/boolean等）
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function normalizeParams(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_bool($v)) $params[$k] = $v ? 1 : 0;
        }
        return $params;
    }
}