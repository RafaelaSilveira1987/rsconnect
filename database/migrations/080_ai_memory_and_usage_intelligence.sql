-- RS Connect 36.19.0 — Memória progressiva + inteligência de consumo
-- 1) Resumo progressivo por conversa para reduzir histórico reenviado.
-- 2) Memória estruturada do contato/conversa em JSON.
-- 3) Configuração por assistente da frequência e tamanho da memória.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_progressive_memory_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_exact_cache_ttl_hours',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_progressive_memory_enabled'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_memory_refresh_messages SMALLINT UNSIGNED NOT NULL DEFAULT 8 AFTER ai_progressive_memory_enabled',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_memory_refresh_messages'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_memory_max_chars SMALLINT UNSIGNED NOT NULL DEFAULT 2200 AFTER ai_memory_refresh_messages',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_memory_max_chars'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS conversation_ai_memory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    agent_id BIGINT UNSIGNED NULL,
    summary_text TEXT NULL,
    facts_json JSON NULL,
    source_message_id BIGINT UNSIGNED NULL,
    source_message_count INT UNSIGNED NOT NULL DEFAULT 0,
    refresh_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_provider VARCHAR(40) NULL,
    last_model VARCHAR(120) NULL,
    last_input_tokens INT UNSIGNED NULL,
    last_output_tokens INT UNSIGNED NULL,
    last_refreshed_at DATETIME NULL,
    status ENUM('active','error') NOT NULL DEFAULT 'active',
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conversation_ai_memory (conversation_id),
    KEY idx_conversation_ai_memory_tenant (tenant_id, updated_at),
    KEY idx_conversation_ai_memory_contact (contact_id, updated_at),
    KEY idx_conversation_ai_memory_agent (agent_id, updated_at),
    CONSTRAINT fk_conversation_ai_memory_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_ai_memory_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_ai_memory_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_conversation_ai_memory_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE SET NULL,
    CONSTRAINT fk_conversation_ai_memory_message FOREIGN KEY (source_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Memória de longo prazo por contato. Recebe somente fatos/resumo já consolidados
-- pela memória progressiva e permite continuidade quando uma nova conversa é aberta.
CREATE TABLE IF NOT EXISTS contact_ai_memory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    last_conversation_id BIGINT UNSIGNED NULL,
    agent_id BIGINT UNSIGNED NULL,
    summary_text TEXT NULL,
    facts_json JSON NULL,
    refresh_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_refreshed_at DATETIME NULL,
    status ENUM('active','error') NOT NULL DEFAULT 'active',
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contact_ai_memory (tenant_id, contact_id),
    KEY idx_contact_ai_memory_updated (tenant_id, updated_at),
    KEY idx_contact_ai_memory_agent (agent_id, updated_at),
    CONSTRAINT fk_contact_ai_memory_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_ai_memory_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_ai_memory_conversation FOREIGN KEY (last_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_contact_ai_memory_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
