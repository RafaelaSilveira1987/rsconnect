-- RS Connect 36.30.4 — identidade autorizada e recuperação das instâncias Evolution.
-- Idempotente para permitir retomada segura em ambientes já parcialmente atualizados.

SET @db := DATABASE();

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='authorized_phone');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN authorized_phone VARCHAR(40) NULL AFTER profile_phone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='identity_status');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN identity_status ENUM("unknown","verified","mismatch") NOT NULL DEFAULT "unknown" AFTER authorized_phone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='identity_mismatch_at');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN identity_mismatch_at DATETIME NULL AFTER identity_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='auto_recovery_enabled');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN auto_recovery_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER identity_mismatch_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='recovery_attempts');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN recovery_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER auto_recovery_enabled', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='last_recovery_attempt_at');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN last_recovery_attempt_at DATETIME NULL AFTER recovery_attempts', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='last_recovery_success_at');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN last_recovery_success_at DATETIME NULL AFTER last_recovery_attempt_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='recovery_state');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN recovery_state VARCHAR(60) NULL AFTER last_recovery_success_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND INDEX_NAME='idx_instances_recovery');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD INDEX idx_instances_recovery (tenant_id, auto_recovery_enabled, status, last_recovery_attempt_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Aproveita números já confirmados pelo webhook sem sobrescrever escolhas existentes.
UPDATE evolution_instances
SET authorized_phone = NULLIF(profile_phone, ''),
    identity_status = CASE WHEN NULLIF(profile_phone, '') IS NULL THEN 'unknown' ELSE 'verified' END
WHERE authorized_phone IS NULL;
