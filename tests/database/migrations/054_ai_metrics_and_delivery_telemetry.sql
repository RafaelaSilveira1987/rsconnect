-- RS Connect 36.6.12 — Métricas completas de IA e franquia RS
-- Separa uso comercial (interações entregues) de telemetria técnica (chamadas, tokens e custo estimado).

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN delivery_status ENUM("pending","delivered","not_delivered","not_applicable") NOT NULL DEFAULT "pending" AFTER status',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_usage_events'
      AND COLUMN_NAME = 'delivery_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN provider_calls INT UNSIGNED NOT NULL DEFAULT 0 AFTER plan_billable',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_usage_events'
      AND COLUMN_NAME = 'provider_calls'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN total_tokens INT UNSIGNED NULL AFTER output_tokens',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_usage_events'
      AND COLUMN_NAME = 'total_tokens'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN cached_tokens INT UNSIGNED NULL AFTER total_tokens',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_usage_events'
      AND COLUMN_NAME = 'cached_tokens'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN estimated_cost_currency CHAR(3) NULL AFTER estimated_cost',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_usage_events'
      AND COLUMN_NAME = 'estimated_cost_currency'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Amplia os tipos técnicos sem alterar a regra comercial: somente auto_reply entregue pode consumir franquia.
ALTER TABLE ai_usage_events
    MODIFY COLUMN usage_type ENUM(
        'auto_reply','suggestion','summary','classification','extraction',
        'intent_detection','scheduling','other'
    ) NOT NULL DEFAULT 'auto_reply';

-- Compatibilidade com eventos já gravados antes desta versão.
UPDATE ai_usage_events
SET delivery_status = CASE
        WHEN usage_type = 'auto_reply' AND status = 'success' THEN 'delivered'
        WHEN usage_type = 'auto_reply' AND status IN ('failed','cancelled') THEN 'not_delivered'
        WHEN usage_type <> 'auto_reply' THEN 'not_applicable'
        ELSE 'pending'
    END
WHERE delivery_status = 'pending';

UPDATE ai_usage_events
SET provider_calls = 1
WHERE provider_calls = 0
  AND status = 'success'
  AND usage_type IN ('auto_reply','suggestion','summary','classification','extraction','intent_detection','scheduling','other');

UPDATE ai_usage_events
SET total_tokens = COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0)
WHERE total_tokens IS NULL
  AND (input_tokens IS NOT NULL OR output_tokens IS NOT NULL);

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_usage_events'
      AND INDEX_NAME = 'idx_ai_usage_technical_period'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE ai_usage_events ADD KEY idx_ai_usage_technical_period (tenant_id, created_at, provider, model)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
