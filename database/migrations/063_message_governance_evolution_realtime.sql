USE rs_connect;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS whatsapp_display_name VARCHAR(100) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS whatsapp_role_label VARCHAR(100) NULL AFTER whatsapp_display_name,
    ADD COLUMN IF NOT EXISTS whatsapp_signature_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER whatsapp_role_label;

ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS whatsapp_human_signature_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER company_notes,
    ADD COLUMN IF NOT EXISTS whatsapp_human_signature_format ENUM('name','name_role','name_company') NOT NULL DEFAULT 'name_role' AFTER whatsapp_human_signature_enabled,
    ADD COLUMN IF NOT EXISTS message_retention_mode ENUM('complete','reduced','ephemeral') NOT NULL DEFAULT 'reduced' AFTER whatsapp_human_signature_format,
    ADD COLUMN IF NOT EXISTS message_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 90 AFTER message_retention_mode,
    ADD COLUMN IF NOT EXISTS message_raw_payload_days SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER message_retention_days,
    ADD COLUMN IF NOT EXISTS message_ephemeral_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER message_raw_payload_days,
    ADD COLUMN IF NOT EXISTS message_retention_last_run_at DATETIME NULL AFTER message_ephemeral_hours;

ALTER TABLE conversation_messages
    ADD COLUMN IF NOT EXISTS delivered_content TEXT NULL AFTER content,
    ADD COLUMN IF NOT EXISTS sender_display_name VARCHAR(100) NULL AFTER sender_user_id,
    ADD COLUMN IF NOT EXISTS sender_role_label VARCHAR(100) NULL AFTER sender_display_name,
    ADD COLUMN IF NOT EXISTS content_purged_at DATETIME NULL AFTER raw_payload_json,
    ADD COLUMN IF NOT EXISTS raw_payload_purged_at DATETIME NULL AFTER content_purged_at;

SET @db = DATABASE();
SET @has_idx_messages_retention = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db AND table_name = 'conversation_messages' AND index_name = 'idx_messages_retention'
);
SET @sql_idx_messages_retention = IF(
    @has_idx_messages_retention = 0,
    'ALTER TABLE conversation_messages ADD INDEX idx_messages_retention (tenant_id, sent_at, content_purged_at)',
    'SELECT 1'
);
PREPARE stmt_idx_messages_retention FROM @sql_idx_messages_retention;
EXECUTE stmt_idx_messages_retention;
DEALLOCATE PREPARE stmt_idx_messages_retention;

SET @has_idx_messages_raw_retention = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db AND table_name = 'conversation_messages' AND index_name = 'idx_messages_raw_retention'
);
SET @sql_idx_messages_raw_retention = IF(
    @has_idx_messages_raw_retention = 0,
    'ALTER TABLE conversation_messages ADD INDEX idx_messages_raw_retention (tenant_id, sent_at, raw_payload_purged_at)',
    'SELECT 1'
);
PREPARE stmt_idx_messages_raw_retention FROM @sql_idx_messages_raw_retention;
EXECUTE stmt_idx_messages_raw_retention;
DEALLOCATE PREPARE stmt_idx_messages_raw_retention;

ALTER TABLE evolution_instances
    ADD COLUMN IF NOT EXISTS connection_reason VARCHAR(255) NULL AFTER connection_state,
    ADD COLUMN IF NOT EXISTS connection_updated_at DATETIME NULL AFTER connection_reason,
    ADD COLUMN IF NOT EXISTS last_webhook_at DATETIME NULL AFTER connection_updated_at,
    ADD COLUMN IF NOT EXISTS profile_name VARCHAR(150) NULL AFTER last_webhook_at,
    ADD COLUMN IF NOT EXISTS profile_phone VARCHAR(40) NULL AFTER profile_name,
    ADD COLUMN IF NOT EXISTS profile_picture_url VARCHAR(500) NULL AFTER profile_phone,
    ADD COLUMN IF NOT EXISTS qrcode_base64 MEDIUMTEXT NULL AFTER profile_picture_url,
    ADD COLUMN IF NOT EXISTS qrcode_updated_at DATETIME NULL AFTER qrcode_base64,
    ADD COLUMN IF NOT EXISTS qrcode_expires_at DATETIME NULL AFTER qrcode_updated_at;

CREATE TABLE IF NOT EXISTS evolution_connection_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    evolution_instance_id BIGINT UNSIGNED NOT NULL,
    event_name VARCHAR(80) NOT NULL,
    connection_state VARCHAR(60) NULL,
    connection_reason VARCHAR(255) NULL,
    profile_name VARCHAR(150) NULL,
    profile_phone VARCHAR(40) NULL,
    metadata_json JSON NULL,
    occurred_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_evolution_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_evolution_events_instance FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE CASCADE,
    INDEX idx_evolution_events_instance_date (evolution_instance_id, occurred_at),
    INDEX idx_evolution_events_tenant_date (tenant_id, occurred_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_retention_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,
    source ENUM('manual','cron','n8n','system') NOT NULL DEFAULT 'system',
    status ENUM('success','warning','error') NOT NULL DEFAULT 'success',
    messages_purged INT UNSIGNED NOT NULL DEFAULT 0,
    payloads_purged INT UNSIGNED NOT NULL DEFAULT 0,
    previews_purged INT UNSIGNED NOT NULL DEFAULT 0,
    details_json JSON NULL,
    error_message VARCHAR(500) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_retention_runs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    INDEX idx_retention_runs_tenant_date (tenant_id, started_at)
) ENGINE=InnoDB;

UPDATE conversation_messages
SET delivered_content = content
WHERE delivered_content IS NULL AND content IS NOT NULL;

SELECT 'Migration 063 aplicada: assinatura humana, retenção de mensagens e status Evolution em tempo real.' AS result;
