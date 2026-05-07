START TRANSACTION;

DELETE FROM insurance_rate_sets
WHERE tenant_id IS NULL
  AND (
    (scheme_code = 'health' AND scope_type = 'prefecture' AND effective_from = '2026-03-01')
    OR (scheme_code = 'care' AND scope_type = 'national' AND effective_from = '2026-03-01')
    OR (scheme_code = 'pension' AND scope_type = 'national' AND effective_from = '2026-03-01')
    OR (scheme_code = 'employment' AND scope_type = 'business_type' AND effective_from = '2026-04-01')
    OR (scheme_code = 'childcare' AND scope_type = 'national' AND effective_from = '2026-04-01')
  );

INSERT INTO insurance_rate_sets (
  tenant_id,
  scheme_code,
  scope_type,
  scope_key,
  effective_from,
  effective_to,
  employee_rate,
  employer_rate,
  note,
  created_at,
  updated_at
) VALUES
  (NULL, 'health', 'prefecture', '01', '2026-03-01', NULL, 5.1400, 5.1400, '令和8年度 協会けんぽ 健康保険料率 北海道', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '02', '2026-03-01', NULL, 4.9250, 4.9250, '令和8年度 協会けんぽ 健康保険料率 青森県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '03', '2026-03-01', NULL, 4.7550, 4.7550, '令和8年度 協会けんぽ 健康保険料率 岩手県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '04', '2026-03-01', NULL, 5.0500, 5.0500, '令和8年度 協会けんぽ 健康保険料率 宮城県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '05', '2026-03-01', NULL, 5.0050, 5.0050, '令和8年度 協会けんぽ 健康保険料率 秋田県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '06', '2026-03-01', NULL, 4.8750, 4.8750, '令和8年度 協会けんぽ 健康保険料率 山形県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '07', '2026-03-01', NULL, 4.7500, 4.7500, '令和8年度 協会けんぽ 健康保険料率 福島県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '08', '2026-03-01', NULL, 4.7600, 4.7600, '令和8年度 協会けんぽ 健康保険料率 茨城県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '09', '2026-03-01', NULL, 4.9100, 4.9100, '令和8年度 協会けんぽ 健康保険料率 栃木県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '10', '2026-03-01', NULL, 4.8400, 4.8400, '令和8年度 協会けんぽ 健康保険料率 群馬県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '11', '2026-03-01', NULL, 4.8350, 4.8350, '令和8年度 協会けんぽ 健康保険料率 埼玉県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '12', '2026-03-01', NULL, 4.8650, 4.8650, '令和8年度 協会けんぽ 健康保険料率 千葉県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '13', '2026-03-01', NULL, 4.9250, 4.9250, '令和8年度 協会けんぽ 健康保険料率 東京都', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '14', '2026-03-01', NULL, 4.9600, 4.9600, '令和8年度 協会けんぽ 健康保険料率 神奈川県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '15', '2026-03-01', NULL, 4.6050, 4.6050, '令和8年度 協会けんぽ 健康保険料率 新潟県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '16', '2026-03-01', NULL, 4.7950, 4.7950, '令和8年度 協会けんぽ 健康保険料率 富山県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '17', '2026-03-01', NULL, 4.8500, 4.8500, '令和8年度 協会けんぽ 健康保険料率 石川県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '18', '2026-03-01', NULL, 4.8550, 4.8550, '令和8年度 協会けんぽ 健康保険料率 福井県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '19', '2026-03-01', NULL, 4.7750, 4.7750, '令和8年度 協会けんぽ 健康保険料率 山梨県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '20', '2026-03-01', NULL, 4.8150, 4.8150, '令和8年度 協会けんぽ 健康保険料率 長野県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '21', '2026-03-01', NULL, 4.9000, 4.9000, '令和8年度 協会けんぽ 健康保険料率 岐阜県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '22', '2026-03-01', NULL, 4.8050, 4.8050, '令和8年度 協会けんぽ 健康保険料率 静岡県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '23', '2026-03-01', NULL, 4.9650, 4.9650, '令和8年度 協会けんぽ 健康保険料率 愛知県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '24', '2026-03-01', NULL, 4.8850, 4.8850, '令和8年度 協会けんぽ 健康保険料率 三重県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '25', '2026-03-01', NULL, 4.9400, 4.9400, '令和8年度 協会けんぽ 健康保険料率 滋賀県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '26', '2026-03-01', NULL, 4.9450, 4.9450, '令和8年度 協会けんぽ 健康保険料率 京都府', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '27', '2026-03-01', NULL, 5.0650, 5.0650, '令和8年度 協会けんぽ 健康保険料率 大阪府', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '28', '2026-03-01', NULL, 5.0600, 5.0600, '令和8年度 協会けんぽ 健康保険料率 兵庫県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '29', '2026-03-01', NULL, 4.9550, 4.9550, '令和8年度 協会けんぽ 健康保険料率 奈良県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '30', '2026-03-01', NULL, 5.0300, 5.0300, '令和8年度 協会けんぽ 健康保険料率 和歌山県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '31', '2026-03-01', NULL, 4.9300, 4.9300, '令和8年度 協会けんぽ 健康保険料率 鳥取県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '32', '2026-03-01', NULL, 4.9700, 4.9700, '令和8年度 協会けんぽ 健康保険料率 島根県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '33', '2026-03-01', NULL, 5.0250, 5.0250, '令和8年度 協会けんぽ 健康保険料率 岡山県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '34', '2026-03-01', NULL, 4.8900, 4.8900, '令和8年度 協会けんぽ 健康保険料率 広島県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '35', '2026-03-01', NULL, 5.0750, 5.0750, '令和8年度 協会けんぽ 健康保険料率 山口県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '36', '2026-03-01', NULL, 5.1200, 5.1200, '令和8年度 協会けんぽ 健康保険料率 徳島県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '37', '2026-03-01', NULL, 5.0100, 5.0100, '令和8年度 協会けんぽ 健康保険料率 香川県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '38', '2026-03-01', NULL, 4.9900, 4.9900, '令和8年度 協会けんぽ 健康保険料率 愛媛県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '39', '2026-03-01', NULL, 5.0250, 5.0250, '令和8年度 協会けんぽ 健康保険料率 高知県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '40', '2026-03-01', NULL, 5.0550, 5.0550, '令和8年度 協会けんぽ 健康保険料率 福岡県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '41', '2026-03-01', NULL, 5.2750, 5.2750, '令和8年度 協会けんぽ 健康保険料率 佐賀県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '42', '2026-03-01', NULL, 5.0300, 5.0300, '令和8年度 協会けんぽ 健康保険料率 長崎県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '43', '2026-03-01', NULL, 5.0400, 5.0400, '令和8年度 協会けんぽ 健康保険料率 熊本県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '44', '2026-03-01', NULL, 5.0400, 5.0400, '令和8年度 協会けんぽ 健康保険料率 大分県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '45', '2026-03-01', NULL, 4.8850, 4.8850, '令和8年度 協会けんぽ 健康保険料率 宮崎県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '46', '2026-03-01', NULL, 5.0650, 5.0650, '令和8年度 協会けんぽ 健康保険料率 鹿児島県', NOW(), NOW()),
  (NULL, 'health', 'prefecture', '47', '2026-03-01', NULL, 4.7200, 4.7200, '令和8年度 協会けんぽ 健康保険料率 沖縄県', NOW(), NOW()),
  (NULL, 'care', 'national', NULL, '2026-03-01', NULL, 0.8100, 0.8100, '令和8年度 介護保険料率 全国一律', NOW(), NOW()),
  (NULL, 'pension', 'national', NULL, '2026-03-01', NULL, 9.1500, 9.1500, '厚生年金保険料率 18.3% 固定（労使折半）', NOW(), NOW()),
  (NULL, 'employment', 'business_type', 'general', '2026-04-01', NULL, 0.5000, 0.8500, '令和8年度 雇用保険料率 一般の事業', NOW(), NOW()),
  (NULL, 'employment', 'business_type', 'agri', '2026-04-01', NULL, 0.6000, 0.9500, '令和8年度 雇用保険料率 農林水産・清酒製造の事業', NOW(), NOW()),
  (NULL, 'employment', 'business_type', 'construction', '2026-04-01', NULL, 0.6000, 1.0500, '令和8年度 雇用保険料率 建設の事業', NOW(), NOW()),
  (NULL, 'childcare', 'national', NULL, '2026-04-01', NULL, 0.1150, 0.1150, '令和8年度 子ども・子育て支援金率 全国一律', NOW(), NOW());

COMMIT;
