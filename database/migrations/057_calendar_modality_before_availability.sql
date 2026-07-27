-- RS Connect 36.6.24 — modalidade obrigatória antes da consulta de disponibilidade
-- Execute após a migration 056.

SET NAMES utf8mb4;

ALTER TABLE calendar_appointments
    MODIFY COLUMN location_type ENUM('indefinida','online','presencial','telefone')
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'indefinida';

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
    'modality_message',
    "varchar(800) COLLATE utf8mb4_unicode_ci DEFAULT 'Antes de consultar os horários, você prefere atendimento online ou presencial?' AFTER collect_message"
);

UPDATE tenant_pre_schedule_settings
SET modality_message = COALESCE(
    NULLIF(TRIM(modality_message), ''),
    'Antes de consultar os horários, você prefere atendimento online ou presencial?'
);

-- Backfill apenas quando a modalidade anterior é inequívoca.
UPDATE calendar_appointments
SET appointment_modality = 'presencial'
WHERE appointment_modality = 'indefinida'
  AND location_type = 'presencial';

UPDATE calendar_appointments
SET appointment_modality = 'online'
WHERE appointment_modality = 'indefinida'
  AND location_type = 'online'
  AND LOWER(COALESCE(location, '')) IN ('online', 'virtual', 'remoto');

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
