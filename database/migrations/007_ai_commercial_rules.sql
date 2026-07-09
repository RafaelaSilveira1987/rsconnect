-- RS Connect ZIP 06 — IA Comercial, Credenciais por Cliente e Regras de Atendimento
-- Execute uma única vez após a migration 005_ai_automations.sql.

SET @database_name = DATABASE();

CREATE TABLE IF NOT EXISTS ai_provider_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED DEFAULT NULL,
    label VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL,
    provider ENUM('openai','google','custom') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'openai',
    api_key_encrypted TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
    base_url VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    default_model VARCHAR(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    status ENUM('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
    is_default TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_credentials_tenant_provider (tenant_id, provider, status),
    KEY idx_ai_credentials_agent (agent_id, status),
    CONSTRAINT fk_ai_credentials_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_credentials_agent FOREIGN KEY (agent_id) REFERENCES ai_agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN business_hours_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER n8n_webhook_url',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'business_hours_enabled'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN business_timezone VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "America/Sao_Paulo" AFTER business_hours_enabled',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'business_timezone'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN business_hours_json JSON DEFAULT NULL AFTER business_timezone',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'business_hours_json'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN after_hours_message VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER business_hours_json',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'after_hours_message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN human_handoff_message VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER after_hours_message',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'human_handoff_message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN handoff_action ENUM("paused","human") COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "paused" AFTER human_handoff_message',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'handoff_action'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN cooldown_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 15 AFTER handoff_action',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'cooldown_seconds'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('ai_credentials.manage', 'Gerenciar credenciais de IA', 'Cadastrar credenciais de IA por empresa/agente no painel RS.', 'Inteligência artificial');

-- Permissão técnica fica disponível na matriz, mas a rota continua restrita a super_admin.
