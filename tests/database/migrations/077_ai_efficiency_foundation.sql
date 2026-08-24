-- RS Connect 36.17.0 — Fundação de eficiência de IA
-- Adiciona perfis de consumo por assistente e telemetria de contexto evitado.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_efficiency_mode ENUM("economy","balanced","quality") NOT NULL DEFAULT "balanced" AFTER max_context_messages',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_efficiency_mode'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_max_output_tokens SMALLINT UNSIGNED NULL AFTER ai_efficiency_mode',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_max_output_tokens'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_knowledge_budget_chars INT UNSIGNED NULL AFTER ai_max_output_tokens',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_knowledge_budget_chars'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_selective_knowledge TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_knowledge_budget_chars',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_selective_knowledge'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN efficiency_mode VARCHAR(20) NULL AFTER model',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND COLUMN_NAME = 'efficiency_mode'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN history_messages_total INT UNSIGNED NULL AFTER cached_tokens',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND COLUMN_NAME = 'history_messages_total'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN history_messages_sent INT UNSIGNED NULL AFTER history_messages_total',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND COLUMN_NAME = 'history_messages_sent'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN knowledge_chars_total INT UNSIGNED NULL AFTER history_messages_sent',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND COLUMN_NAME = 'knowledge_chars_total'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN knowledge_chars_sent INT UNSIGNED NULL AFTER knowledge_chars_total',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND COLUMN_NAME = 'knowledge_chars_sent'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN estimated_input_tokens_avoided INT UNSIGNED NOT NULL DEFAULT 0 AFTER knowledge_chars_sent',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND COLUMN_NAME = 'estimated_input_tokens_avoided'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND INDEX_NAME = 'idx_ai_usage_efficiency'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE ai_usage_events ADD KEY idx_ai_usage_efficiency (tenant_id, efficiency_mode, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
