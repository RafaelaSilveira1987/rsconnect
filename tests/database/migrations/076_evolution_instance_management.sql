-- RS Connect 36.16.0 — gerenciamento completo da Evolution pelo RS Connect
-- Criação remota, webhook, filtros de recebimento e configurações por instância.
-- Compatível com MySQL/MariaDB sem ADD COLUMN IF NOT EXISTS.

SET @db := DATABASE();

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='management_mode');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN management_mode ENUM("managed","external") NOT NULL DEFAULT "external" AFTER api_key_encrypted', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='integration');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN integration VARCHAR(50) NOT NULL DEFAULT "WHATSAPP-BAILEYS" AFTER management_mode', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='remote_instance_id');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN remote_instance_id VARCHAR(120) NULL AFTER integration', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='remote_hash_encrypted');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN remote_hash_encrypted TEXT NULL AFTER remote_instance_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='webhook_enabled');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN webhook_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER remote_hash_encrypted', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='webhook_events');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN webhook_events JSON NULL AFTER webhook_enabled', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='receive_messages');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN receive_messages TINYINT(1) NOT NULL DEFAULT 1 AFTER webhook_events', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='ignore_groups');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN ignore_groups TINYINT(1) NOT NULL DEFAULT 1 AFTER receive_messages', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='ignore_status');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN ignore_status TINYINT(1) NOT NULL DEFAULT 1 AFTER ignore_groups', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='ignore_broadcast');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN ignore_broadcast TINYINT(1) NOT NULL DEFAULT 1 AFTER ignore_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='ignore_newsletters');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN ignore_newsletters TINYINT(1) NOT NULL DEFAULT 1 AFTER ignore_broadcast', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='ignore_from_me');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN ignore_from_me TINYINT(1) NOT NULL DEFAULT 0 AFTER ignore_newsletters', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='reject_calls');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN reject_calls TINYINT(1) NOT NULL DEFAULT 0 AFTER ignore_from_me', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='reject_call_message');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN reject_call_message VARCHAR(255) NULL AFTER reject_calls', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='always_online');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN always_online TINYINT(1) NOT NULL DEFAULT 0 AFTER reject_call_message', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='read_messages');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN read_messages TINYINT(1) NOT NULL DEFAULT 0 AFTER always_online', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='read_status');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN read_status TINYINT(1) NOT NULL DEFAULT 0 AFTER read_messages', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='sync_full_history');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN sync_full_history TINYINT(1) NOT NULL DEFAULT 0 AFTER read_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='remote_created_at');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN remote_created_at DATETIME NULL AFTER sync_full_history', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='last_settings_sync_at');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN last_settings_sync_at DATETIME NULL AFTER remote_created_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE evolution_instances
SET webhook_events = JSON_ARRAY('MESSAGES_UPSERT','MESSAGES_UPDATE','CONNECTION_UPDATE','QRCODE_UPDATED','CONTACTS_UPSERT')
WHERE webhook_events IS NULL;

SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND INDEX_NAME='idx_instances_management');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD INDEX idx_instances_management (tenant_id, management_mode, webhook_enabled)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
