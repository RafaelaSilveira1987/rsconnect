-- RS Connect 36.27.25 — Agenda: escolha entre Prompt Studio e formulário de mensagens
-- Execute após a migration 101.

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
DELIMITER ;

CALL rs_add_column_if_missing(
    'tenant_pre_schedule_settings',
    'message_mode',
    "enum('form','prompt') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'form' AFTER default_duration_minutes"
);

CALL rs_add_column_if_missing(
    'tenant_pre_schedule_settings',
    'initial_collect_message',
    "varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT 'Claro! Qual o melhor dia e horário para você? E prefere atendimento online ou presencial?' AFTER message_mode"
);

UPDATE tenant_pre_schedule_settings
SET message_mode = COALESCE(NULLIF(TRIM(message_mode), ''), 'form'),
    initial_collect_message = COALESCE(
        NULLIF(TRIM(initial_collect_message), ''),
        'Claro! Qual o melhor dia e horário para você? E prefere atendimento online ou presencial?'
    ),
    default_message = CASE
        WHEN TRIM(COALESCE(default_message, '')) IN (
            '',
            'Vou registrar sua preferência e encaminhar para confirmação da profissional.',
            'Certo. Vou registrar sua preferência e encaminhar para confirmação da profissional.'
        ) THEN 'Perfeito. Vou verificar a disponibilidade para {{dia_preferido}} às {{horario_preferido}}.'
        ELSE default_message
    END,
    collect_message = CASE
        WHEN TRIM(COALESCE(collect_message, '')) IN (
            '',
            'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.'
        ) THEN 'Qual o melhor dia e horário para você?'
        ELSE collect_message
    END,
    modality_message = CASE
        WHEN TRIM(COALESCE(modality_message, '')) IN (
            '',
            'Antes de consultar os horários, você prefere atendimento online ou presencial?'
        ) THEN 'Você prefere atendimento online ou presencial?'
        ELSE modality_message
    END;

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
