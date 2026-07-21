-- RS Connect ZIP 36.2 — Agenda conversacional: alternativas, escolha e pré-reserva
-- Execute após as migrations 029, 030, 040 e 041.

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

-- Mensagens configuráveis usadas no diálogo com o cliente.
CALL rs_add_column_if_missing(
    'tenant_pre_schedule_settings',
    'availability_options_message',
    'text COLLATE utf8mb4_unicode_ci NULL AFTER reschedule_message'
);
CALL rs_add_column_if_missing(
    'tenant_pre_schedule_settings',
    'slot_selected_message',
    'text COLLATE utf8mb4_unicode_ci NULL AFTER availability_options_message'
);
CALL rs_add_column_if_missing(
    'tenant_pre_schedule_settings',
    'no_availability_message',
    'text COLLATE utf8mb4_unicode_ci NULL AFTER slot_selected_message'
);
CALL rs_add_column_if_missing(
    'tenant_pre_schedule_settings',
    'invalid_slot_message',
    'text COLLATE utf8mb4_unicode_ci NULL AFTER no_availability_message'
);

-- Estado idempotente da apresentação e escolha das opções.
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'availability_options_request_id',
    'bigint unsigned NULL AFTER availability_slot_count'
);
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'availability_options_sent_at',
    'datetime NULL AFTER availability_options_request_id'
);
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'availability_options_message_id',
    'varchar(255) COLLATE utf8mb4_unicode_ci NULL AFTER availability_options_sent_at'
);
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'availability_selection_expires_at',
    'datetime NULL AFTER availability_options_message_id'
);
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'availability_selected_at',
    'datetime NULL AFTER availability_selection_expires_at'
);
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'availability_selected_by',
    'varchar(30) COLLATE utf8mb4_unicode_ci NULL AFTER availability_selected_at'
);
CALL rs_add_index_if_missing(
    'calendar_appointments',
    'idx_calendar_waiting_contact_selection',
    '(tenant_id, availability_status, availability_selection_expires_at, conversation_id)'
);

-- Preserva a mesma numeração enviada ao WhatsApp.
CALL rs_add_column_if_missing(
    'calendar_availability_slots',
    'suggestion_position',
    'smallint unsigned NULL AFTER event_state'
);
CALL rs_add_column_if_missing(
    'calendar_availability_slots',
    'suggested_at',
    'datetime NULL AFTER suggestion_position'
);
CALL rs_add_index_if_missing(
    'calendar_availability_slots',
    'idx_calendar_slot_suggestion_position',
    '(request_id, suggestion_position)'
);

UPDATE tenant_pre_schedule_settings
SET availability_options_message = COALESCE(NULLIF(availability_options_message, ''),
        'O horário solicitado não está disponível. Encontrei estas opções:\n\n{{opcoes}}\n\nResponda com o número ou com o horário que prefere.'),
    slot_selected_message = COALESCE(NULLIF(slot_selected_message, ''),
        'Perfeito. Pré-reservei {{data}} às {{hora}}. O horário está aguardando validação da profissional. Você receberá a confirmação por aqui.'),
    no_availability_message = COALESCE(NULLIF(no_availability_message, ''),
        'Não encontrei horários disponíveis para essa preferência. Pode me informar outro dia ou período?'),
    invalid_slot_message = COALESCE(NULLIF(invalid_slot_message, ''),
        'Não consegui identificar uma dessas opções. Responda com o número ou com o horário desejado:');

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
DROP PROCEDURE IF EXISTS rs_add_index_if_missing;
