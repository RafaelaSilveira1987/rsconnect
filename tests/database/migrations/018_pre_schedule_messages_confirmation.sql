-- RS Connect Hotfix/ZIP 18.1 — Mensagens configuráveis e confirmação de pré-agendamento
-- Execute após aplicar o ZIP 18.

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

DELIMITER ;

CALL rs_add_column_if_missing('tenant_pre_schedule_settings', 'send_approval_message', 'tinyint(1) NOT NULL DEFAULT 1 AFTER ai_can_confirm');
CALL rs_add_column_if_missing('tenant_pre_schedule_settings', 'collect_message', "varchar(800) COLLATE utf8mb4_unicode_ci DEFAULT 'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.' AFTER default_message");
CALL rs_add_column_if_missing('tenant_pre_schedule_settings', 'approved_message', "text COLLATE utf8mb4_unicode_ci NULL AFTER collect_message");
CALL rs_add_column_if_missing('tenant_pre_schedule_settings', 'rejected_message', "text COLLATE utf8mb4_unicode_ci NULL AFTER approved_message");
CALL rs_add_column_if_missing('tenant_pre_schedule_settings', 'reschedule_message', "text COLLATE utf8mb4_unicode_ci NULL AFTER rejected_message");

CALL rs_add_column_if_missing('calendar_appointments', 'approval_message_sent_at', 'datetime DEFAULT NULL AFTER approved_at');
CALL rs_add_column_if_missing('calendar_appointments', 'approval_message_error', "varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER approval_message_sent_at");

UPDATE tenant_pre_schedule_settings
SET
    collect_message = COALESCE(NULLIF(collect_message, ''), 'Certo. Me informe, por favor, o melhor dia e período ou horário para atendimento.'),
    approved_message = COALESCE(NULLIF(approved_message, ''), 'Seu agendamento foi confirmado para {{data}} às {{hora}}. {{local}}'),
    rejected_message = COALESCE(NULLIF(rejected_message, ''), 'No momento não conseguimos confirmar esse horário. Pode me enviar outra opção de dia ou período?'),
    reschedule_message = COALESCE(NULLIF(reschedule_message, ''), 'Precisamos ajustar sua preferência de horário. Pode me enviar outra opção de dia ou período?')
WHERE tenant_id > 0;

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
