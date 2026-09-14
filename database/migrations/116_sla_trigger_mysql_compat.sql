-- RS Connect 36.34.2 — Hotfix de compatibilidade MySQL do trigger de SLA
-- Corrige o trigger AFTER INSERT de conversation_messages criado na migration 115.
-- O UPDATE multi-tabela com LEFT JOIN + ORDER BY/LIMIT é inválido no MySQL
-- (SQLSTATE HY000 / erro 1221) e fazia MESSAGES_UPSERT falhar antes de persistir.
-- A estratégia abaixo seleciona explicitamente o ciclo ativo mais recente e
-- atualiza somente essa linha, sem ORDER BY/LIMIT no UPDATE.

DELIMITER $$

DROP TRIGGER IF EXISTS trg_rs_messages_after_insert_metrics$$
CREATE TRIGGER trg_rs_messages_after_insert_metrics
AFTER INSERT ON conversation_messages
FOR EACH ROW
BEGIN
    DECLARE next_cycle_number INT UNSIGNED DEFAULT 1;
    DECLARE active_cycle_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_target_minutes SMALLINT UNSIGNED DEFAULT 30;
    DECLARE v_warning_percent TINYINT UNSIGNED DEFAULT 80;
    DECLARE v_count_outside_business_hours TINYINT UNSIGNED DEFAULT 0;
    DECLARE v_timezone VARCHAR(80) DEFAULT 'America/Sao_Paulo';
    DECLARE v_business_hours_json LONGTEXT DEFAULT NULL;

    IF NOT EXISTS (
        SELECT 1
        FROM conversation_service_cycles active_cycle
        WHERE active_cycle.conversation_id = NEW.conversation_id
          AND active_cycle.tenant_id = NEW.tenant_id
          AND active_cycle.cycle_status = 'active'
    ) THEN
        SELECT COALESCE(MAX(existing_cycle.cycle_number), 0) + 1
          INTO next_cycle_number
        FROM conversation_service_cycles existing_cycle
        WHERE existing_cycle.conversation_id = NEW.conversation_id;

        INSERT IGNORE INTO conversation_service_cycles
            (tenant_id, conversation_id, cycle_number, opened_at,
             first_incoming_at, last_incoming_at,
             cycle_status, source,
             sla_target_minutes, sla_warning_percent, sla_count_outside_business_hours,
             sla_timezone, sla_business_hours_json)
        SELECT
            c.tenant_id,
            c.id,
            next_cycle_number,
            COALESCE(c.opened_at, c.created_at, NEW.sent_at, UTC_TIMESTAMP()),
            CASE WHEN NEW.direction = 'incoming' THEN NEW.sent_at ELSE c.first_incoming_at END,
            CASE WHEN NEW.direction = 'incoming' THEN NEW.sent_at ELSE c.last_incoming_at END,
            'active',
            'message_cycle_recovery',
            COALESCE(ss.target_minutes, 30),
            COALESCE(ss.warning_percent, 80),
            COALESCE(ss.count_outside_business_hours, 0),
            COALESCE(NULLIF(ss.timezone, ''), NULLIF(os.business_timezone, ''), 'America/Sao_Paulo'),
            COALESCE(ss.business_hours_json, os.business_hours_json,
                JSON_OBJECT('days', JSON_ARRAY('mon','tue','wed','thu','fri'), 'start', '08:00', 'end', '18:00'))
        FROM conversations c
        LEFT JOIN tenant_sla_settings ss ON ss.tenant_id = c.tenant_id
        LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = c.tenant_id
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id;
    END IF;

    IF NEW.direction = 'incoming' THEN
        UPDATE conversations c
        SET c.first_incoming_at = COALESCE(c.first_incoming_at, NEW.sent_at),
            c.last_incoming_at = NEW.sent_at
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id;

        SET active_cycle_id = NULL;
        SELECT active_cycle.id
          INTO active_cycle_id
        FROM conversation_service_cycles active_cycle
        WHERE active_cycle.conversation_id = NEW.conversation_id
          AND active_cycle.tenant_id = NEW.tenant_id
          AND active_cycle.cycle_status = 'active'
        ORDER BY active_cycle.cycle_number DESC
        LIMIT 1;

        IF active_cycle_id IS NOT NULL THEN
            SELECT
                COALESCE(ss.target_minutes, 30),
                COALESCE(ss.warning_percent, 80),
                COALESCE(ss.count_outside_business_hours, 0),
                COALESCE(NULLIF(ss.timezone, ''), NULLIF(os.business_timezone, ''), 'America/Sao_Paulo'),
                COALESCE(ss.business_hours_json, os.business_hours_json,
                    JSON_OBJECT('days', JSON_ARRAY('mon','tue','wed','thu','fri'), 'start', '08:00', 'end', '18:00'))
              INTO v_target_minutes,
                   v_warning_percent,
                   v_count_outside_business_hours,
                   v_timezone,
                   v_business_hours_json
            FROM conversations c
            LEFT JOIN tenant_sla_settings ss ON ss.tenant_id = c.tenant_id
            LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = c.tenant_id
            WHERE c.id = NEW.conversation_id
              AND c.tenant_id = NEW.tenant_id
            LIMIT 1;

            UPDATE conversation_service_cycles
            SET first_incoming_at = COALESCE(first_incoming_at, NEW.sent_at),
                last_incoming_at = NEW.sent_at,
                sla_target_minutes = COALESCE(sla_target_minutes, v_target_minutes, 30),
                sla_warning_percent = COALESCE(sla_warning_percent, v_warning_percent, 80),
                sla_count_outside_business_hours = COALESCE(sla_count_outside_business_hours, v_count_outside_business_hours, 0),
                sla_timezone = COALESCE(NULLIF(sla_timezone, ''), NULLIF(v_timezone, ''), 'America/Sao_Paulo'),
                sla_business_hours_json = COALESCE(sla_business_hours_json, v_business_hours_json,
                    JSON_OBJECT('days', JSON_ARRAY('mon','tue','wed','thu','fri'), 'start', '08:00', 'end', '18:00'))
            WHERE id = active_cycle_id
              AND tenant_id = NEW.tenant_id;
        END IF;

    ELSEIF NEW.direction = 'outgoing' AND NEW.sender_type = 'user' THEN
        UPDATE conversations c
        SET c.first_response_user_id = COALESCE(c.first_response_user_id, NEW.sender_user_id),
            c.first_response_at = COALESCE(c.first_response_at, NEW.sent_at)
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id
          AND c.first_response_at IS NULL
          AND c.first_incoming_at IS NOT NULL
          AND c.first_incoming_at <= NEW.sent_at;

        SET active_cycle_id = NULL;
        SELECT active_cycle.id
          INTO active_cycle_id
        FROM conversation_service_cycles active_cycle
        WHERE active_cycle.conversation_id = NEW.conversation_id
          AND active_cycle.tenant_id = NEW.tenant_id
          AND active_cycle.cycle_status = 'active'
          AND active_cycle.first_response_at IS NULL
          AND active_cycle.first_incoming_at IS NOT NULL
          AND active_cycle.first_incoming_at <= NEW.sent_at
        ORDER BY active_cycle.cycle_number DESC
        LIMIT 1;

        IF active_cycle_id IS NOT NULL THEN
            UPDATE conversation_service_cycles
            SET first_response_user_id = NEW.sender_user_id,
                first_response_at = NEW.sent_at
            WHERE id = active_cycle_id
              AND tenant_id = NEW.tenant_id;
        END IF;
    END IF;
END$$

DELIMITER ;

SELECT 'Migration 116 aplicada: trigger de SLA compatível com MySQL e recebimento Evolution restaurado.' AS resultado;
