USE rs_connect;

-- Compatível com versões que não aceitam ADD COLUMN IF NOT EXISTS.
-- Pode ser executada mais de uma vez.

SET @db = DATABASE();

SET @has_users_whatsapp_display_name = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'users' AND column_name = 'whatsapp_display_name'
);
SET @sql_users_whatsapp_display_name = IF(
    @has_users_whatsapp_display_name = 0,
    'ALTER TABLE `users` ADD COLUMN `whatsapp_display_name` VARCHAR(100) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_users_whatsapp_display_name;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_users_whatsapp_role_label = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'users' AND column_name = 'whatsapp_role_label'
);
SET @sql_users_whatsapp_role_label = IF(
    @has_users_whatsapp_role_label = 0,
    'ALTER TABLE `users` ADD COLUMN `whatsapp_role_label` VARCHAR(100) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_users_whatsapp_role_label;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_users_whatsapp_signature_enabled = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'users' AND column_name = 'whatsapp_signature_enabled'
);
SET @sql_users_whatsapp_signature_enabled = IF(
    @has_users_whatsapp_signature_enabled = 0,
    'ALTER TABLE `users` ADD COLUMN `whatsapp_signature_enabled` TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1'
);
PREPARE stmt FROM @sql_users_whatsapp_signature_enabled;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tenants_whatsapp_human_signature_enabled = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'whatsapp_human_signature_enabled'
);
SET @sql_tenants_whatsapp_human_signature_enabled = IF(
    @has_tenants_whatsapp_human_signature_enabled = 0,
    'ALTER TABLE `tenants` ADD COLUMN `whatsapp_human_signature_enabled` TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql_tenants_whatsapp_human_signature_enabled;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tenants_whatsapp_human_signature_format = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'whatsapp_human_signature_format'
);
SET @sql_tenants_whatsapp_human_signature_format = IF(
    @has_tenants_whatsapp_human_signature_format = 0,
    'ALTER TABLE `tenants` ADD COLUMN `whatsapp_human_signature_format` ENUM(''name'',''name_role'',''name_company'') NOT NULL DEFAULT ''name_role''',
    'SELECT 1'
);
PREPARE stmt FROM @sql_tenants_whatsapp_human_signature_format;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tenants_message_retention_mode = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'message_retention_mode'
);
SET @sql_tenants_message_retention_mode = IF(
    @has_tenants_message_retention_mode = 0,
    'ALTER TABLE `tenants` ADD COLUMN `message_retention_mode` ENUM(''complete'',''reduced'',''ephemeral'') NOT NULL DEFAULT ''reduced''',
    'SELECT 1'
);
PREPARE stmt FROM @sql_tenants_message_retention_mode;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tenants_message_retention_days = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'message_retention_days'
);
SET @sql_tenants_message_retention_days = IF(
    @has_tenants_message_retention_days = 0,
    'ALTER TABLE `tenants` ADD COLUMN `message_retention_days` SMALLINT UNSIGNED NOT NULL DEFAULT 90',
    'SELECT 1'
);
PREPARE stmt FROM @sql_tenants_message_retention_days;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tenants_message_raw_payload_days = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'message_raw_payload_days'
);
SET @sql_tenants_message_raw_payload_days = IF(
    @has_tenants_message_raw_payload_days = 0,
    'ALTER TABLE `tenants` ADD COLUMN `message_raw_payload_days` SMALLINT UNSIGNED NOT NULL DEFAULT 30',
    'SELECT 1'
);
PREPARE stmt FROM @sql_tenants_message_raw_payload_days;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tenants_message_ephemeral_hours = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'message_ephemeral_hours'
);
SET @sql_tenants_message_ephemeral_hours = IF(
    @has_tenants_message_ephemeral_hours = 0,
    'ALTER TABLE `tenants` ADD COLUMN `message_ephemeral_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 24',
    'SELECT 1'
);
PREPARE stmt FROM @sql_tenants_message_ephemeral_hours;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tenants_message_retention_last_run_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'message_retention_last_run_at'
);
SET @sql_tenants_message_retention_last_run_at = IF(
    @has_tenants_message_retention_last_run_at = 0,
    'ALTER TABLE `tenants` ADD COLUMN `message_retention_last_run_at` DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_tenants_message_retention_last_run_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_conversation_messages_delivered_content = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'conversation_messages' AND column_name = 'delivered_content'
);
SET @sql_conversation_messages_delivered_content = IF(
    @has_conversation_messages_delivered_content = 0,
    'ALTER TABLE `conversation_messages` ADD COLUMN `delivered_content` TEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_conversation_messages_delivered_content;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_conversation_messages_sender_display_name = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'conversation_messages' AND column_name = 'sender_display_name'
);
SET @sql_conversation_messages_sender_display_name = IF(
    @has_conversation_messages_sender_display_name = 0,
    'ALTER TABLE `conversation_messages` ADD COLUMN `sender_display_name` VARCHAR(100) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_conversation_messages_sender_display_name;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_conversation_messages_sender_role_label = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'conversation_messages' AND column_name = 'sender_role_label'
);
SET @sql_conversation_messages_sender_role_label = IF(
    @has_conversation_messages_sender_role_label = 0,
    'ALTER TABLE `conversation_messages` ADD COLUMN `sender_role_label` VARCHAR(100) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_conversation_messages_sender_role_label;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_conversation_messages_content_purged_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'conversation_messages' AND column_name = 'content_purged_at'
);
SET @sql_conversation_messages_content_purged_at = IF(
    @has_conversation_messages_content_purged_at = 0,
    'ALTER TABLE `conversation_messages` ADD COLUMN `content_purged_at` DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_conversation_messages_content_purged_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_conversation_messages_raw_payload_purged_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'conversation_messages' AND column_name = 'raw_payload_purged_at'
);
SET @sql_conversation_messages_raw_payload_purged_at = IF(
    @has_conversation_messages_raw_payload_purged_at = 0,
    'ALTER TABLE `conversation_messages` ADD COLUMN `raw_payload_purged_at` DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_conversation_messages_raw_payload_purged_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_evolution_instances_connection_reason = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'evolution_instances' AND column_name = 'connection_reason'
);
SET @sql_evolution_instances_connection_reason = IF(
    @has_evolution_instances_connection_reason = 0,
    'ALTER TABLE `evolution_instances` ADD COLUMN `connection_reason` VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_evolution_instances_connection_reason;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_evolution_instances_connection_updated_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'evolution_instances' AND column_name = 'connection_updated_at'
);
SET @sql_evolution_instances_connection_updated_at = IF(
    @has_evolution_instances_connection_updated_at = 0,
    'ALTER TABLE `evolution_instances` ADD COLUMN `connection_updated_at` DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_evolution_instances_connection_updated_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_evolution_instances_last_webhook_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'evolution_instances' AND column_name = 'last_webhook_at'
);
SET @sql_evolution_instances_last_webhook_at = IF(
    @has_evolution_instances_last_webhook_at = 0,
    'ALTER TABLE `evolution_instances` ADD COLUMN `last_webhook_at` DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_evolution_instances_last_webhook_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_evolution_instances_profile_name = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'evolution_instances' AND column_name = 'profile_name'
);
SET @sql_evolution_instances_profile_name = IF(
    @has_evolution_instances_profile_name = 0,
    'ALTER TABLE `evolution_instances` ADD COLUMN `profile_name` VARCHAR(150) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_evolution_instances_profile_name;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_evolution_instances_profile_phone = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'evolution_instances' AND column_name = 'profile_phone'
);
SET @sql_evolution_instances_profile_phone = IF(
    @has_evolution_instances_profile_phone = 0,
    'ALTER TABLE `evolution_instances` ADD COLUMN `profile_phone` VARCHAR(40) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_evolution_instances_profile_phone;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_evolution_instances_profile_picture_url = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'evolution_instances' AND column_name = 'profile_picture_url'
);
SET @sql_evolution_instances_profile_picture_url = IF(
    @has_evolution_instances_profile_picture_url = 0,
    'ALTER TABLE `evolution_instances` ADD COLUMN `profile_picture_url` VARCHAR(500) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_evolution_instances_profile_picture_url;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_evolution_instances_qrcode_base64 = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'evolution_instances' AND column_name = 'qrcode_base64'
);
SET @sql_evolution_instances_qrcode_base64 = IF(
    @has_evolution_instances_qrcode_base64 = 0,
    'ALTER TABLE `evolution_instances` ADD COLUMN `qrcode_base64` MEDIUMTEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_evolution_instances_qrcode_base64;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_evolution_instances_qrcode_updated_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'evolution_instances' AND column_name = 'qrcode_updated_at'
);
SET @sql_evolution_instances_qrcode_updated_at = IF(
    @has_evolution_instances_qrcode_updated_at = 0,
    'ALTER TABLE `evolution_instances` ADD COLUMN `qrcode_updated_at` DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_evolution_instances_qrcode_updated_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_evolution_instances_qrcode_expires_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'evolution_instances' AND column_name = 'qrcode_expires_at'
);
SET @sql_evolution_instances_qrcode_expires_at = IF(
    @has_evolution_instances_qrcode_expires_at = 0,
    'ALTER TABLE `evolution_instances` ADD COLUMN `qrcode_expires_at` DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_evolution_instances_qrcode_expires_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx_messages_retention = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db AND table_name = 'conversation_messages' AND index_name = 'idx_messages_retention'
);
SET @sql_idx_messages_retention = IF(
    @has_idx_messages_retention = 0,
    'ALTER TABLE conversation_messages ADD INDEX idx_messages_retention (tenant_id, sent_at, content_purged_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_idx_messages_retention;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx_messages_raw_retention = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db AND table_name = 'conversation_messages' AND index_name = 'idx_messages_raw_retention'
);
SET @sql_idx_messages_raw_retention = IF(
    @has_idx_messages_raw_retention = 0,
    'ALTER TABLE conversation_messages ADD INDEX idx_messages_raw_retention (tenant_id, sent_at, raw_payload_purged_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_idx_messages_raw_retention;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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
