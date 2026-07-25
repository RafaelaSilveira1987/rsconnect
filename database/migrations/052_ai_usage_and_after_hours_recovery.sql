-- RS Connect 36.6.8 — Consumo de IA e recuperação pós-horário
-- 1) Converte o limite comercial de mensagens em interações automáticas de IA.
-- 2) Identifica quem custeia cada credencial (RS Connect ou cliente).
-- 3) Registra uso faturável/não faturável da IA.
-- 4) Preserva e recupera conversas recebidas fora do horário.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_provider_credentials ADD COLUMN credential_owner ENUM("rs_connect","tenant") NOT NULL DEFAULT "tenant" AFTER provider',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_provider_credentials'
      AND COLUMN_NAME = 'credential_owner'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Credenciais já cadastradas eram chaves próprias por empresa/agente na estrutura anterior.
-- O fallback por OPENAI_API_KEY/GEMINI_API_KEY do ambiente continua sendo tratado como RS Connect em código.
UPDATE ai_provider_credentials
SET credential_owner = COALESCE(NULLIF(credential_owner, ''), 'tenant');

CREATE TABLE IF NOT EXISTS ai_usage_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NULL,
    conversation_id BIGINT UNSIGNED NULL,
    incoming_message_id BIGINT UNSIGNED NULL,
    outgoing_message_id BIGINT UNSIGNED NULL,
    credential_id BIGINT UNSIGNED NULL,
    credential_owner ENUM('rs_connect','tenant') NOT NULL DEFAULT 'rs_connect',
    provider VARCHAR(40) NOT NULL,
    model VARCHAR(120) NULL,
    usage_type ENUM('auto_reply','suggestion','summary','classification','extraction','other') NOT NULL DEFAULT 'auto_reply',
    status ENUM('reserved','success','cancelled','failed') NOT NULL DEFAULT 'reserved',
    plan_billable TINYINT(1) NOT NULL DEFAULT 0,
    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    estimated_cost DECIMAL(14,6) NULL,
    error_message VARCHAR(500) NULL,
    reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_usage_tenant_period (tenant_id, plan_billable, usage_type, status, created_at),
    KEY idx_ai_usage_owner_period (credential_owner, usage_type, status, created_at),
    KEY idx_ai_usage_agent_period (agent_id, status, created_at),
    KEY idx_ai_usage_conversation (conversation_id, created_at),
    KEY idx_ai_usage_incoming (incoming_message_id, usage_type, created_at),
    KEY idx_ai_usage_outgoing (outgoing_message_id),
    CONSTRAINT fk_ai_usage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_usage_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE SET NULL,
    CONSTRAINT fk_ai_usage_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_ai_usage_incoming FOREIGN KEY (incoming_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_ai_usage_outgoing FOREIGN KEY (outgoing_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_ai_usage_credential FOREIGN KEY (credential_id) REFERENCES ai_provider_credentials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_usage_threshold_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    threshold_percent TINYINT UNSIGNED NOT NULL,
    used_count INT UNSIGNED NOT NULL,
    limit_count INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_usage_threshold (tenant_id, period_start, threshold_percent, limit_count),
    KEY idx_ai_usage_threshold_period (period_start, period_end),
    CONSTRAINT fk_ai_usage_threshold_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_after_hours_pending (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    first_message_id BIGINT UNSIGNED NULL,
    last_message_id BIGINT UNSIGNED NULL,
    first_received_at DATETIME NOT NULL,
    last_received_at DATETIME NOT NULL,
    status ENUM('pending','processing','blocked_plan','blocked_human','recovered','cancelled','error') NOT NULL DEFAULT 'pending',
    ack_sent_at DATETIME NULL,
    recovery_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    next_attempt_at DATETIME NULL,
    recovered_at DATETIME NULL,
    recovery_source VARCHAR(80) NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_after_hours_conversation (conversation_id),
    KEY idx_ai_after_hours_due (status, next_attempt_at, last_received_at),
    KEY idx_ai_after_hours_tenant (tenant_id, status, last_received_at),
    CONSTRAINT fk_ai_after_hours_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_after_hours_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_after_hours_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_after_hours_first_message FOREIGN KEY (first_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_ai_after_hours_last_message FOREIGN KEY (last_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O antigo limite messages_month era o volume comercial que o painel apresentava ao plano.
-- A partir da 36.6.8 ele representa somente respostas automáticas de IA custeadas pela RS Connect.
UPDATE saas_plans
SET limits_json = JSON_SET(
    JSON_REMOVE(COALESCE(limits_json, JSON_OBJECT()), '$.messages_month', '$.ai_replies_month'),
    '$.ai_interactions_month',
    CASE
        WHEN JSON_TYPE(JSON_EXTRACT(limits_json, '$.ai_interactions_month')) IN ('INTEGER','DOUBLE')
            THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json, '$.ai_interactions_month')) AS UNSIGNED)
        WHEN JSON_TYPE(JSON_EXTRACT(limits_json, '$.messages_month')) IN ('INTEGER','DOUBLE')
            THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json, '$.messages_month')) AS UNSIGNED)
        WHEN JSON_TYPE(JSON_EXTRACT(limits_json, '$.ai_replies_month')) IN ('INTEGER','DOUBLE')
            THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json, '$.ai_replies_month')) AS UNSIGNED)
        ELSE NULL
    END
);
