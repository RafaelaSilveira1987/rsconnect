-- ZIP 28 — Google Agenda: espaços livres + eventos VAGO
-- Pacote incremental para ser executado depois da migration 029.
-- Compatível com MySQL 8 / MariaDB recentes.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS smart_calendar_google_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    availability_mode ENUM('free_slots','marked_events') NOT NULL DEFAULT 'free_slots',
    google_calendar_id VARCHAR(255) NOT NULL DEFAULT 'primary',
    google_timezone VARCHAR(80) NOT NULL DEFAULT 'America/Sao_Paulo',
    google_utc_offset VARCHAR(6) NOT NULL DEFAULT '-03:00',
    free_slots_webhook_url VARCHAR(500) DEFAULT NULL,
    marked_events_webhook_url VARCHAR(500) DEFAULT NULL,
    ignore_transparent_events TINYINT(1) NOT NULL DEFAULT 1,
    marked_require_transparent TINYINT(1) NOT NULL DEFAULT 1,
    marked_online_title VARCHAR(190) NOT NULL DEFAULT 'VAGO — ONLINE',
    marked_in_person_title VARCHAR(190) NOT NULL DEFAULT 'VAGO — PRESENCIAL',
    marked_hold_prefix VARCHAR(120) NOT NULL DEFAULT 'PRÉ-RESERVADO',
    marked_confirmed_prefix VARCHAR(120) NOT NULL DEFAULT 'AGENDADO',
    hold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    revalidate_before_update TINYINT(1) NOT NULL DEFAULT 1,
    restore_on_cancel TINYINT(1) NOT NULL DEFAULT 1,
    callback_token_encrypted TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_smart_calendar_google_settings_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_google_event_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    request_id VARCHAR(120) DEFAULT NULL,
    entity_type VARCHAR(60) NOT NULL DEFAULT 'pre_appointment',
    entity_id BIGINT UNSIGNED DEFAULT NULL,
    appointment_id BIGINT UNSIGNED DEFAULT NULL,
    conversation_id BIGINT UNSIGNED DEFAULT NULL,
    contact_id BIGINT UNSIGNED DEFAULT NULL,
    google_calendar_id VARCHAR(255) NOT NULL DEFAULT 'primary',
    google_event_id VARCHAR(255) NOT NULL,
    availability_source ENUM('google_free_slots','google_marked_slots') NOT NULL,
    modality ENUM('online','presencial','indefinida') NOT NULL DEFAULT 'indefinida',
    slot_start DATETIME DEFAULT NULL,
    slot_end DATETIME DEFAULT NULL,
    original_summary VARCHAR(255) DEFAULT NULL,
    current_summary VARCHAR(255) DEFAULT NULL,
    original_transparency ENUM('opaque','transparent') DEFAULT NULL,
    current_transparency ENUM('opaque','transparent') DEFAULT NULL,
    state ENUM('available','held','confirmed','released','cancelled','expired','error') NOT NULL DEFAULT 'available',
    hold_expires_at DATETIME DEFAULT NULL,
    metadata_json JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendar_google_event_tenant (tenant_id, google_event_id),
    KEY idx_calendar_google_links_entity (tenant_id, entity_type, entity_id),
    KEY idx_calendar_google_links_state (tenant_id, state, hold_expires_at),
    KEY idx_calendar_google_links_appointment (tenant_id, appointment_id),
    CONSTRAINT fk_calendar_google_event_links_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_google_sync_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    request_id VARCHAR(120) DEFAULT NULL,
    google_event_id VARCHAR(255) DEFAULT NULL,
    operation ENUM('search','hold','confirm','release','callback','error') NOT NULL,
    status ENUM('success','warning','error') NOT NULL DEFAULT 'success',
    request_json JSON DEFAULT NULL,
    response_json JSON DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_calendar_google_sync_logs_tenant_date (tenant_id, created_at),
    KEY idx_calendar_google_sync_logs_event (tenant_id, google_event_id),
    CONSTRAINT fk_calendar_google_sync_logs_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS zip28_add_column_if_missing;
DELIMITER $$
CREATE PROCEDURE zip28_add_column_if_missing(
    IN p_table VARCHAR(128),
    IN p_column VARCHAR(128),
    IN p_definition TEXT
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
    ) THEN
        SET @zip28_sql = CONCAT(
            'ALTER TABLE `', REPLACE(p_table, '`', '``'),
            '` ADD COLUMN `', REPLACE(p_column, '`', '``'),
            '` ', p_definition
        );
        PREPARE zip28_stmt FROM @zip28_sql;
        EXECUTE zip28_stmt;
        DEALLOCATE PREPARE zip28_stmt;
    END IF;
END$$
DELIMITER ;

-- Amplia a tabela de configuração criada pelo ZIP 27, caso ela exista.
CALL zip28_add_column_if_missing('smart_calendar_settings', 'availability_mode', 'ENUM(''free_slots'',''marked_events'') NOT NULL DEFAULT ''free_slots''');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'google_calendar_id', 'VARCHAR(255) NOT NULL DEFAULT ''primary''');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'google_timezone', 'VARCHAR(80) NOT NULL DEFAULT ''America/Sao_Paulo''');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'google_utc_offset', 'VARCHAR(6) NOT NULL DEFAULT ''-03:00''');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'free_slots_webhook_url', 'VARCHAR(500) NULL');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'marked_events_webhook_url', 'VARCHAR(500) NULL');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'marked_online_title', 'VARCHAR(190) NOT NULL DEFAULT ''VAGO — ONLINE''');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'marked_in_person_title', 'VARCHAR(190) NOT NULL DEFAULT ''VAGO — PRESENCIAL''');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'marked_hold_prefix', 'VARCHAR(120) NOT NULL DEFAULT ''PRÉ-RESERVADO''');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'marked_confirmed_prefix', 'VARCHAR(120) NOT NULL DEFAULT ''AGENDADO''');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'marked_require_transparent', 'TINYINT(1) NOT NULL DEFAULT 1');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'hold_minutes', 'SMALLINT UNSIGNED NOT NULL DEFAULT 30');
CALL zip28_add_column_if_missing('smart_calendar_settings', 'restore_on_cancel', 'TINYINT(1) NOT NULL DEFAULT 1');

-- Nomes alternativos encontrados em projetos que usam a mesma base.
CALL zip28_add_column_if_missing('calendar_availability_settings', 'availability_mode', 'ENUM(''free_slots'',''marked_events'') NOT NULL DEFAULT ''free_slots''');
CALL zip28_add_column_if_missing('calendar_availability_settings', 'google_calendar_id', 'VARCHAR(255) NOT NULL DEFAULT ''primary''');
CALL zip28_add_column_if_missing('calendar_availability_settings', 'free_slots_webhook_url', 'VARCHAR(500) NULL');
CALL zip28_add_column_if_missing('calendar_availability_settings', 'marked_events_webhook_url', 'VARCHAR(500) NULL');
CALL zip28_add_column_if_missing('calendar_availability_settings', 'marked_online_title', 'VARCHAR(190) NOT NULL DEFAULT ''VAGO — ONLINE''');
CALL zip28_add_column_if_missing('calendar_availability_settings', 'marked_in_person_title', 'VARCHAR(190) NOT NULL DEFAULT ''VAGO — PRESENCIAL''');

-- Guarda a origem e o evento do Google nos slots existentes do ZIP 27, caso a tabela exista.
CALL zip28_add_column_if_missing('calendar_availability_slots', 'source', 'VARCHAR(60) NOT NULL DEFAULT ''internal_fallback''');
CALL zip28_add_column_if_missing('calendar_availability_slots', 'google_calendar_id', 'VARCHAR(255) NULL');
CALL zip28_add_column_if_missing('calendar_availability_slots', 'google_event_id', 'VARCHAR(255) NULL');
CALL zip28_add_column_if_missing('calendar_availability_slots', 'modality', 'ENUM(''online'',''presencial'',''indefinida'') NOT NULL DEFAULT ''indefinida''');
CALL zip28_add_column_if_missing('calendar_availability_slots', 'metadata_json', 'JSON NULL');

-- Vínculo opcional com compromissos/pré-agendamentos existentes.
CALL zip28_add_column_if_missing('calendar_appointments', 'availability_source', 'VARCHAR(60) NULL');
CALL zip28_add_column_if_missing('calendar_appointments', 'google_calendar_id', 'VARCHAR(255) NULL');
CALL zip28_add_column_if_missing('calendar_appointments', 'google_event_id', 'VARCHAR(255) NULL');
CALL zip28_add_column_if_missing('calendar_appointments', 'google_event_state', 'VARCHAR(30) NULL');
CALL zip28_add_column_if_missing('calendar_appointments', 'appointment_modality', 'ENUM(''online'',''presencial'',''indefinida'') NOT NULL DEFAULT ''indefinida''');

CALL zip28_add_column_if_missing('calendar_pre_appointments', 'availability_source', 'VARCHAR(60) NULL');
CALL zip28_add_column_if_missing('calendar_pre_appointments', 'google_calendar_id', 'VARCHAR(255) NULL');
CALL zip28_add_column_if_missing('calendar_pre_appointments', 'google_event_id', 'VARCHAR(255) NULL');
CALL zip28_add_column_if_missing('calendar_pre_appointments', 'google_event_state', 'VARCHAR(30) NULL');
CALL zip28_add_column_if_missing('calendar_pre_appointments', 'appointment_modality', 'ENUM(''online'',''presencial'',''indefinida'') NOT NULL DEFAULT ''indefinida''');

DROP PROCEDURE IF EXISTS zip28_add_column_if_missing;

SET FOREIGN_KEY_CHECKS = 1;
