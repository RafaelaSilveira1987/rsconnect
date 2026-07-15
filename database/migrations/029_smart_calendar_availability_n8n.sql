-- RS Connect ZIP 27 — Agenda inteligente e disponibilidade via n8n
-- Execute uma vez após aplicar o ZIP 27.

DELIMITER $$

DROP PROCEDURE IF EXISTS rs_add_column_if_missing$$
CREATE PROCEDURE rs_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
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
    IF NOT EXISTS (
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

CREATE TABLE IF NOT EXISTS tenant_calendar_availability_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    require_before_approval TINYINT(1) NOT NULL DEFAULT 1,
    auto_request_on_pre_schedule TINYINT(1) NOT NULL DEFAULT 1,
    use_n8n TINYINT(1) NOT NULL DEFAULT 1,
    use_internal_fallback TINYINT(1) NOT NULL DEFAULT 1,
    n8n_webhook_url_encrypted TEXT COLLATE utf8mb4_unicode_ci NULL,
    secret_token_encrypted TEXT COLLATE utf8mb4_unicode_ci NULL,
    timezone VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/Sao_Paulo',
    default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    slot_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    search_days_ahead SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    workdays_json JSON NULL,
    working_hours_json JSON NULL,
    min_notice_hours SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    max_suggestions SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_calendar_availability_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_availability_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    request_token VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
    origin VARCHAR(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
    status ENUM('pending','sent','received','empty','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
    preferred_day_text VARCHAR(120) COLLATE utf8mb4_unicode_ci NULL,
    preferred_time_text VARCHAR(120) COLLATE utf8mb4_unicode_ci NULL,
    search_start_at DATETIME NULL,
    search_end_at DATETIME NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    timezone VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/Sao_Paulo',
    requested_payload_json JSON NULL,
    response_payload_json JSON NULL,
    error_message VARCHAR(700) COLLATE utf8mb4_unicode_ci NULL,
    requested_at DATETIME NULL,
    sent_at DATETIME NULL,
    responded_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendar_availability_request_token (request_token),
    KEY idx_availability_requests_tenant_status (tenant_id, status, created_at),
    KEY idx_availability_requests_appointment (appointment_id),
    CONSTRAINT fk_calendar_availability_requests_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_availability_requests_appointment FOREIGN KEY (appointment_id) REFERENCES calendar_appointments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_availability_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    label VARCHAR(180) COLLATE utf8mb4_unicode_ci NULL,
    source VARCHAR(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'n8n',
    raw_json JSON NULL,
    selected_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_availability_slots_tenant_start (tenant_id, starts_at),
    KEY idx_availability_slots_request (request_id),
    KEY idx_availability_slots_appointment (appointment_id),
    CONSTRAINT fk_calendar_availability_slots_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_availability_slots_request FOREIGN KEY (request_id) REFERENCES calendar_availability_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_availability_slots_appointment FOREIGN KEY (appointment_id) REFERENCES calendar_appointments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL rs_add_column_if_missing('calendar_appointments', 'availability_status', "varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER approval_message_error");
CALL rs_add_column_if_missing('calendar_appointments', 'availability_request_id', 'bigint unsigned DEFAULT NULL AFTER availability_status');
CALL rs_add_column_if_missing('calendar_appointments', 'availability_slot_count', 'int unsigned NOT NULL DEFAULT 0 AFTER availability_request_id');
CALL rs_add_column_if_missing('calendar_appointments', 'chosen_availability_slot_id', 'bigint unsigned DEFAULT NULL AFTER availability_slot_count');
CALL rs_add_column_if_missing('calendar_appointments', 'availability_error', "varchar(700) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER chosen_availability_slot_id");

CALL rs_add_index_if_missing('calendar_appointments', 'idx_calendar_availability_status', '(tenant_id, availability_status, starts_at)');

INSERT IGNORE INTO tenant_calendar_availability_settings
    (tenant_id, enabled, require_before_approval, auto_request_on_pre_schedule, use_n8n, use_internal_fallback, workdays_json, working_hours_json)
SELECT id, 0, 1, 1, 1, 1, JSON_ARRAY(1,2,3,4,5), JSON_OBJECT('start','08:00','end','18:00')
FROM tenants;
DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
DROP PROCEDURE IF EXISTS rs_add_index_if_missing;
