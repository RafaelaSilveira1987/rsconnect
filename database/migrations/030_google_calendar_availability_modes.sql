-- RS Connect ZIP 28 — Google Agenda: espaços livres + eventos VAGO
-- Execute uma vez após aplicar a migration 029.

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
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
    ) AND NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
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
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
    ) AND NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- Configuração por empresa dos dois modos de disponibilidade.
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'availability_mode', "enum('free_slots','marked_events') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'free_slots' AFTER enabled");
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'free_slots_webhook_url_encrypted', 'text COLLATE utf8mb4_unicode_ci NULL AFTER n8n_webhook_url_encrypted');
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'marked_events_webhook_url_encrypted', 'text COLLATE utf8mb4_unicode_ci NULL AFTER free_slots_webhook_url_encrypted');
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'google_calendar_id', "varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primary' AFTER secret_token_encrypted");
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'google_utc_offset', "varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '-03:00' AFTER timezone");
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'ignore_transparent_events', 'tinyint(1) NOT NULL DEFAULT 1 AFTER google_utc_offset');
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'marked_require_transparent', 'tinyint(1) NOT NULL DEFAULT 1 AFTER ignore_transparent_events');
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'marked_online_title', "varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'VAGO — ONLINE' AFTER marked_require_transparent");
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'marked_in_person_title', "varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'VAGO — PRESENCIAL' AFTER marked_online_title");
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'marked_hold_prefix', "varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PRÉ-RESERVADO' AFTER marked_in_person_title");
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'marked_confirmed_prefix', "varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'AGENDADO' AFTER marked_hold_prefix");
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'hold_minutes', 'smallint unsigned NOT NULL DEFAULT 30 AFTER marked_confirmed_prefix');
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'revalidate_before_update', 'tinyint(1) NOT NULL DEFAULT 1 AFTER hold_minutes');
CALL rs_add_column_if_missing('tenant_calendar_availability_settings', 'restore_on_cancel', 'tinyint(1) NOT NULL DEFAULT 1 AFTER revalidate_before_update');

-- Preserva automaticamente o webhook usado no ZIP 27 como webhook do modo espaços livres.
UPDATE tenant_calendar_availability_settings
SET free_slots_webhook_url_encrypted = COALESCE(free_slots_webhook_url_encrypted, n8n_webhook_url_encrypted)
WHERE free_slots_webhook_url_encrypted IS NULL;

-- Registra o modo utilizado em cada consulta.
CALL rs_add_column_if_missing('calendar_availability_requests', 'availability_mode', "varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'free_slots' AFTER origin");
CALL rs_add_column_if_missing('calendar_availability_requests', 'action_name', "varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'search' AFTER availability_mode");

-- Metadados do Google retornados pelos fluxos.
CALL rs_add_column_if_missing('calendar_availability_slots', 'google_calendar_id', "varchar(255) COLLATE utf8mb4_unicode_ci NULL AFTER source");
CALL rs_add_column_if_missing('calendar_availability_slots', 'google_event_id', "varchar(255) COLLATE utf8mb4_unicode_ci NULL AFTER google_calendar_id");
CALL rs_add_column_if_missing('calendar_availability_slots', 'google_event_etag', "varchar(255) COLLATE utf8mb4_unicode_ci NULL AFTER google_event_id");
CALL rs_add_column_if_missing('calendar_availability_slots', 'modality', "varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'indefinida' AFTER google_event_etag");
CALL rs_add_column_if_missing('calendar_availability_slots', 'event_summary', "varchar(255) COLLATE utf8mb4_unicode_ci NULL AFTER modality");
CALL rs_add_column_if_missing('calendar_availability_slots', 'event_transparency', "varchar(30) COLLATE utf8mb4_unicode_ci NULL AFTER event_summary");
CALL rs_add_column_if_missing('calendar_availability_slots', 'event_state', "varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available' AFTER event_transparency");
CALL rs_add_column_if_missing('calendar_availability_slots', 'hold_expires_at', 'datetime NULL AFTER event_state');
CALL rs_add_index_if_missing('calendar_availability_slots', 'idx_availability_slots_google_event', '(tenant_id, google_event_id)');
CALL rs_add_index_if_missing('calendar_availability_slots', 'idx_availability_slots_state', '(tenant_id, event_state, hold_expires_at)');

-- Vínculo do pré-agendamento com a reserva do Google.
CALL rs_add_column_if_missing('calendar_appointments', 'availability_source', "varchar(60) COLLATE utf8mb4_unicode_ci NULL AFTER availability_error");
CALL rs_add_column_if_missing('calendar_appointments', 'google_calendar_id', "varchar(255) COLLATE utf8mb4_unicode_ci NULL AFTER google_event_id");
CALL rs_add_column_if_missing('calendar_appointments', 'google_event_state', "varchar(30) COLLATE utf8mb4_unicode_ci NULL AFTER google_calendar_id");
CALL rs_add_column_if_missing('calendar_appointments', 'google_event_summary', "varchar(255) COLLATE utf8mb4_unicode_ci NULL AFTER google_event_state");
CALL rs_add_column_if_missing('calendar_appointments', 'appointment_modality', "varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'indefinida' AFTER google_event_summary");
CALL rs_add_column_if_missing('calendar_appointments', 'google_hold_expires_at', 'datetime NULL AFTER appointment_modality');
CALL rs_add_index_if_missing('calendar_appointments', 'idx_calendar_google_event_state', '(tenant_id, google_event_state, starts_at)');

CREATE TABLE IF NOT EXISTS calendar_google_sync_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    request_id BIGINT UNSIGNED NULL,
    slot_id BIGINT UNSIGNED NULL,
    operation VARCHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
    status VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
    google_calendar_id VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    google_event_id VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    request_json JSON NULL,
    response_json JSON NULL,
    error_message VARCHAR(700) COLLATE utf8mb4_unicode_ci NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_google_sync_tenant_date (tenant_id, created_at),
    KEY idx_google_sync_appointment (appointment_id, created_at),
    KEY idx_google_sync_event (tenant_id, google_event_id),
    CONSTRAINT fk_google_sync_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_google_sync_appointment FOREIGN KEY (appointment_id) REFERENCES calendar_appointments(id) ON DELETE SET NULL,
    CONSTRAINT fk_google_sync_request FOREIGN KEY (request_id) REFERENCES calendar_availability_requests(id) ON DELETE SET NULL,
    CONSTRAINT fk_google_sync_slot FOREIGN KEY (slot_id) REFERENCES calendar_availability_slots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
DROP PROCEDURE IF EXISTS rs_add_index_if_missing;
