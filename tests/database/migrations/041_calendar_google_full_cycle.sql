-- RS Connect ZIP 35.0 — Ciclo completo do Google Agenda e manutenção automática
-- Execute após as migrations 029, 030 e 040.

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS rs_add_column_if_missing$$
CREATE PROCEDURE rs_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS rs_add_index_if_missing$$
CREATE PROCEDURE rs_add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- Integração específica para criar, atualizar e remover eventos confirmados.
CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'calendar_event_webhook_url_encrypted',
    'text COLLATE utf8mb4_unicode_ci NULL AFTER marked_events_webhook_url_encrypted'
);
CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'create_google_event_on_confirm',
    'tinyint(1) NOT NULL DEFAULT 1 AFTER restore_on_cancel'
);
CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'require_google_sync_on_confirm',
    'tinyint(1) NOT NULL DEFAULT 1 AFTER create_google_event_on_confirm'
);
CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'update_google_event_on_reschedule',
    'tinyint(1) NOT NULL DEFAULT 1 AFTER require_google_sync_on_confirm'
);
CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'delete_google_event_on_cancel',
    'tinyint(1) NOT NULL DEFAULT 1 AFTER update_google_event_on_reschedule'
);
CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'maintenance_enabled',
    'tinyint(1) NOT NULL DEFAULT 1 AFTER delete_google_event_on_cancel'
);
CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'maintenance_interval_minutes',
    'smallint unsigned NOT NULL DEFAULT 10 AFTER maintenance_enabled'
);
CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'max_sync_attempts',
    'smallint unsigned NOT NULL DEFAULT 3 AFTER maintenance_interval_minutes'
);
CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'maintenance_last_run_at',
    'datetime NULL AFTER max_sync_attempts'
);

-- Estado idempotente e auditável do compromisso no Google.
CALL rs_add_column_if_missing('calendar_appointments', 'google_sync_key', "varchar(120) COLLATE utf8mb4_unicode_ci NULL AFTER google_hold_expires_at");
CALL rs_add_column_if_missing('calendar_appointments', 'google_last_operation', "varchar(40) COLLATE utf8mb4_unicode_ci NULL AFTER google_sync_key");
CALL rs_add_column_if_missing('calendar_appointments', 'google_sync_attempts', 'int unsigned NOT NULL DEFAULT 0 AFTER google_last_operation');
CALL rs_add_column_if_missing('calendar_appointments', 'google_last_sync_at', 'datetime NULL AFTER google_sync_attempts');
CALL rs_add_column_if_missing('calendar_appointments', 'google_last_sync_error', "varchar(700) COLLATE utf8mb4_unicode_ci NULL AFTER google_last_sync_at");
CALL rs_add_column_if_missing('calendar_appointments', 'google_event_created_at', 'datetime NULL AFTER google_last_sync_error');
CALL rs_add_column_if_missing('calendar_appointments', 'google_synced_starts_at', 'datetime NULL AFTER google_event_created_at');
CALL rs_add_column_if_missing('calendar_appointments', 'google_synced_ends_at', 'datetime NULL AFTER google_synced_starts_at');
CALL rs_add_column_if_missing('calendar_appointments', 'google_event_updated_at', 'datetime NULL AFTER google_synced_ends_at');
CALL rs_add_column_if_missing('calendar_appointments', 'google_event_cancelled_at', 'datetime NULL AFTER google_event_updated_at');
CALL rs_add_column_if_missing('calendar_appointments', 'maintenance_last_checked_at', 'datetime NULL AFTER google_event_cancelled_at');
CALL rs_add_index_if_missing('calendar_appointments', 'idx_calendar_google_sync_key', '(tenant_id, google_sync_key)');
CALL rs_add_index_if_missing('calendar_appointments', 'idx_calendar_maintenance', '(tenant_id, google_event_state, google_hold_expires_at, status)');

CREATE TABLE IF NOT EXISTS calendar_maintenance_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NULL,
    origin VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cron',
    status ENUM('running','success','partial','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
    expired_holds_found INT UNSIGNED NOT NULL DEFAULT 0,
    expired_holds_released INT UNSIGNED NOT NULL DEFAULT 0,
    syncs_retried INT UNSIGNED NOT NULL DEFAULT 0,
    google_events_created INT UNSIGNED NOT NULL DEFAULT 0,
    google_events_updated INT UNSIGNED NOT NULL DEFAULT 0,
    google_events_deleted INT UNSIGNED NOT NULL DEFAULT 0,
    stale_requests_closed INT UNSIGNED NOT NULL DEFAULT 0,
    errors_count INT UNSIGNED NOT NULL DEFAULT 0,
    result_json JSON NULL,
    error_message VARCHAR(700) COLLATE utf8mb4_unicode_ci NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_calendar_maintenance_run_tenant (tenant_id, started_at),
    KEY idx_calendar_maintenance_run_status (status, started_at),
    CONSTRAINT fk_calendar_maintenance_run_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Define uma chave estável para compromissos existentes sem alterar vínculos já criados.
UPDATE calendar_appointments
SET google_sync_key = CONCAT('rsconnect-', tenant_id, '-', id)
WHERE google_sync_key IS NULL OR google_sync_key = '';

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
DROP PROCEDURE IF EXISTS rs_add_index_if_missing;
