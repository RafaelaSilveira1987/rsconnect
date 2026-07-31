-- RS Connect 36.8.0 — Agenda opcional por profissional
-- Compatível com versões de MySQL/MariaDB sem ADD COLUMN IF NOT EXISTS.
-- Pode ser executada mais de uma vez.

SET @db = DATABASE();

SET @has_professional_calendar_enabled = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'professional_calendar_enabled'
);
SET @sql = IF(@has_professional_calendar_enabled = 0,
    'ALTER TABLE tenants ADD COLUMN professional_calendar_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_professional_calendar_require_owner = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'professional_calendar_require_owner'
);
SET @sql = IF(@has_professional_calendar_require_owner = 0,
    'ALTER TABLE tenants ADD COLUMN professional_calendar_require_owner TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_professional_calendar_auto_from_conversation = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'professional_calendar_auto_from_conversation'
);
SET @sql = IF(@has_professional_calendar_auto_from_conversation = 0,
    'ALTER TABLE tenants ADD COLUMN professional_calendar_auto_from_conversation TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS user_calendar_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    accepting_appointments TINYINT(1) NOT NULL DEFAULT 1,
    timezone VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/Sao_Paulo',
    google_calendar_id VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    slot_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    min_notice_hours SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    search_days_ahead SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    max_suggestions SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    workdays_json JSON NULL,
    working_hours_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_user_calendar_profiles_tenant (tenant_id, accepting_appointments),
    CONSTRAINT fk_user_calendar_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_calendar_profiles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Migration 065 aplicada: agenda por profissional opcional; seleção automática continua desativada por padrão.' AS resultado;
