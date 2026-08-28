-- RS Connect v36.21.0 — Automação opcional do funil comercial por conversa
-- Adiciona configuração por empresa, sugestões auditáveis e proteção para movimentações manuais.

SET @database_name = DATABASE();

CREATE TABLE IF NOT EXISTS tenant_crm_automation_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    mode ENUM('suggest','automatic') NOT NULL DEFAULT 'suggest',
    classifier_engine ENUM('smart_rules','ai_context') NOT NULL DEFAULT 'smart_rules',
    confidence_threshold DECIMAL(4,3) NOT NULL DEFAULT 0.850,
    allow_backward_movement TINYINT(1) NOT NULL DEFAULT 0,
    notify_on_action TINYINT(1) NOT NULL DEFAULT 1,
    pipeline_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_crm_automation_pipeline (pipeline_id),
    KEY idx_crm_automation_updated_by (updated_by_user_id),
    CONSTRAINT fk_crm_automation_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_automation_pipeline FOREIGN KEY (pipeline_id) REFERENCES crm_pipelines(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_automation_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_automation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    lead_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NULL,
    incoming_message_id BIGINT UNSIGNED NULL,
    previous_stage_id BIGINT UNSIGNED NULL,
    target_stage_id BIGINT UNSIGNED NULL,
    action ENUM('suggested','moved','approved','rejected','kept','blocked','error','manual') NOT NULL,
    confidence DECIMAL(4,3) NULL,
    reason VARCHAR(500) NOT NULL,
    excerpt VARCHAR(700) NULL,
    classifier_engine VARCHAR(30) NOT NULL DEFAULT 'smart_rules',
    metadata_json JSON NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_automation_events_tenant_date (tenant_id, created_at),
    KEY idx_crm_automation_events_lead_date (lead_id, created_at),
    KEY idx_crm_automation_events_pending (tenant_id, action, reviewed_at),
    KEY idx_crm_automation_events_conversation (conversation_id, created_at),
    CONSTRAINT fk_crm_auto_event_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_auto_event_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_auto_event_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_auto_event_message FOREIGN KEY (incoming_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_auto_event_previous_stage FOREIGN KEY (previous_stage_id) REFERENCES crm_stages(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_auto_event_target_stage FOREIGN KEY (target_stage_id) REFERENCES crm_stages(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_auto_event_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE crm_leads ADD COLUMN automation_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER closed_at',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'crm_leads' AND COLUMN_NAME = 'automation_locked'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE crm_leads ADD COLUMN automation_snoozed_until DATETIME NULL AFTER automation_locked',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'crm_leads' AND COLUMN_NAME = 'automation_snoozed_until'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE crm_leads ADD KEY idx_crm_leads_automation (tenant_id, automation_locked, automation_snoozed_until)',
        'DO 0'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'crm_leads' AND INDEX_NAME = 'idx_crm_leads_automation'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
