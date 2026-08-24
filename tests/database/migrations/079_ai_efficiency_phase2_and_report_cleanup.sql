-- RS Connect 36.18.0 — Economia de IA fase 2 e telemetria de chamadas evitadas
-- 1) Respostas locais configuráveis para mensagens curtas e inequívocas.
-- 2) Cache exato opcional, invalidado por mudanças no prompt/base/modelo.
-- 3) Registro de chamadas ao provedor evitadas, sem consumir a franquia RS.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_local_replies_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_selective_knowledge',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_local_replies_enabled'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_greeting_reply VARCHAR(500) NULL AFTER ai_local_replies_enabled',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_greeting_reply'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_gratitude_reply VARCHAR(500) NULL AFTER ai_greeting_reply',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_gratitude_reply'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_farewell_reply VARCHAR(500) NULL AFTER ai_gratitude_reply',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_farewell_reply'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_menu_reply TEXT NULL AFTER ai_farewell_reply',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_menu_reply'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_exact_cache_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_menu_reply',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_exact_cache_enabled'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_exact_cache_ttl_hours SMALLINT UNSIGNED NOT NULL DEFAULT 168 AFTER ai_exact_cache_enabled',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_exact_cache_ttl_hours'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ai_response_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    question_hash CHAR(64) NOT NULL,
    context_hash CHAR(64) NOT NULL,
    normalized_question VARCHAR(500) NOT NULL,
    response TEXT NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_response_cache_context (tenant_id, agent_id, question_hash, context_hash),
    KEY idx_ai_response_cache_expiration (tenant_id, agent_id, expires_at),
    CONSTRAINT fk_ai_response_cache_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_response_cache_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN execution_strategy ENUM("provider_ai","local_rule","exact_cache") NOT NULL DEFAULT "provider_ai" AFTER efficiency_mode',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND COLUMN_NAME = 'execution_strategy'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_usage_events ADD COLUMN provider_calls_avoided INT UNSIGNED NOT NULL DEFAULT 0 AFTER provider_calls',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND COLUMN_NAME = 'provider_calls_avoided'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_usage_events' AND INDEX_NAME = 'idx_ai_usage_strategy'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE ai_usage_events ADD KEY idx_ai_usage_strategy (tenant_id, execution_strategy, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
