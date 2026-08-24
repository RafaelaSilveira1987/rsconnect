-- RS Connect ZIP 34.5 — Fluxo de atendimento, grupos de contato e trava segura de pré-agendamento
-- Pode ser executada novamente com segurança.

SET @db_name = DATABASE();

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = @db_name AND table_name = 'contacts' AND column_name = 'contact_group') = 0,
    'ALTER TABLE contacts ADD COLUMN contact_group VARCHAR(40) NOT NULL DEFAULT ''unclassified'' AFTER status, ADD INDEX idx_contacts_tenant_group (tenant_id, contact_group)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS conversation_flow_states (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    stage VARCHAR(60) NOT NULL DEFAULT 'identifying_contact',
    demand_status ENUM('pending','collected','refused','not_required') NOT NULL DEFAULT 'pending',
    demand_summary TEXT NULL,
    is_existing_patient TINYINT(1) NOT NULL DEFAULT 0,
    last_intent VARCHAR(80) NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'platform',
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conversation_flow_state (conversation_id),
    KEY idx_conversation_flow_tenant_stage (tenant_id, stage, demand_status),
    KEY idx_conversation_flow_contact (contact_id),
    CONSTRAINT fk_conversation_flow_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_flow_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_flow_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_group_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    contact_group VARCHAR(40) NOT NULL,
    allow_pre_schedule TINYINT(1) NOT NULL DEFAULT 1,
    require_demand_before_pre_schedule TINYINT(1) NOT NULL DEFAULT 1,
    allow_reschedule_without_demand TINYINT(1) NOT NULL DEFAULT 0,
    instructions TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_group_rule (agent_id, contact_group),
    KEY idx_agent_group_rules_tenant (tenant_id, contact_group),
    CONSTRAINT fk_agent_group_rule_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_agent_group_rule_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Converte contatos já classificados como clientes em pacientes somente quando a tag deixa isso explícito.
UPDATE contacts
SET contact_group = 'patient'
WHERE contact_group = 'unclassified'
  AND JSON_SEARCH(tags_json, 'one', 'paciente') IS NOT NULL;

UPDATE contacts
SET contact_group = 'interested'
WHERE contact_group = 'unclassified'
  AND (
      JSON_SEARCH(tags_json, 'one', 'interessado') IS NOT NULL
      OR JSON_SEARCH(tags_json, 'one', 'lead') IS NOT NULL
  );
