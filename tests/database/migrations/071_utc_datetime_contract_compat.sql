-- RS Connect 36.10.4 — Contrato UTC para datas técnicas
-- Compatível com MySQL 8/9 e MariaDB modernos.
-- Pode ser executada mais de uma vez.
--
-- Datas técnicas (mensagens, ciclos, históricos e auditoria) passam a ser
-- persistidas em UTC. Horários de agenda continuam representando o horário
-- local do compromisso e mantêm a coluna timezone própria.

SET NAMES utf8mb4;
SET @rs_db = DATABASE();

CREATE TABLE IF NOT EXISTS rs_datetime_contract (
    id TINYINT UNSIGNED NOT NULL,
    storage_timezone VARCHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UTC',
    display_timezone VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/Sao_Paulo',
    cutover_at_utc DATETIME NULL,
    historical_normalized_at_utc DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rs_datetime_contract
    (id, storage_timezone, display_timezone, cutover_at_utc)
VALUES
    (1, 'UTC', 'America/Sao_Paulo', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    storage_timezone = 'UTC',
    cutover_at_utc = COALESCE(cutover_at_utc, UTC_TIMESTAMP());

SET @rs_normalize_history = (
    SELECT CASE WHEN historical_normalized_at_utc IS NULL THEN 1 ELSE 0 END
    FROM rs_datetime_contract WHERE id = 1
);

DROP TEMPORARY TABLE IF EXISTS tmp_rs_tenant_utc_offsets;
CREATE TEMPORARY TABLE tmp_rs_tenant_utc_offsets (
    tenant_id BIGINT UNSIGNED NOT NULL,
    utc_offset VARCHAR(6) NOT NULL DEFAULT '-03:00',
    PRIMARY KEY (tenant_id)
) ENGINE=InnoDB;

SET @rs_has_google_offset = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @rs_db
      AND TABLE_NAME = 'tenant_calendar_availability_settings'
      AND COLUMN_NAME = 'google_utc_offset'
);

SET @rs_offset_sql = IF(
    @rs_has_google_offset > 0,
    'INSERT INTO tmp_rs_tenant_utc_offsets (tenant_id, utc_offset)
     SELECT t.id, COALESCE(NULLIF(cas.google_utc_offset, ""), "-03:00")
     FROM tenants t
     LEFT JOIN tenant_calendar_availability_settings cas ON cas.tenant_id = t.id',
    'INSERT INTO tmp_rs_tenant_utc_offsets (tenant_id, utc_offset)
     SELECT id, "-03:00" FROM tenants'
);
PREPARE rs_offset_stmt FROM @rs_offset_sql;
EXECUTE rs_offset_stmt;
DEALLOCATE PREPARE rs_offset_stmt;

-- Normaliza apenas uma vez os DATETIME que eram gravados pelo PHP no fuso
-- local. TIMESTAMP e datas geradas pelo MySQL já estavam em UTC e não são
-- deslocados. CONVERT_TZ com offset numérico não depende das tabelas de fuso.
UPDATE conversation_messages message
INNER JOIN tmp_rs_tenant_utc_offsets offset_map ON offset_map.tenant_id = message.tenant_id
SET message.sent_at = CONVERT_TZ(message.sent_at, offset_map.utc_offset, '+00:00')
WHERE @rs_normalize_history = 1;
SET @rs_messages_normalized = ROW_COUNT();

UPDATE conversations conversation
INNER JOIN tmp_rs_tenant_utc_offsets offset_map ON offset_map.tenant_id = conversation.tenant_id
SET
    conversation.last_message_at = CASE WHEN conversation.last_message_at IS NULL THEN NULL ELSE CONVERT_TZ(conversation.last_message_at, offset_map.utc_offset, '+00:00') END,
    conversation.first_incoming_at = CASE WHEN conversation.first_incoming_at IS NULL THEN NULL ELSE CONVERT_TZ(conversation.first_incoming_at, offset_map.utc_offset, '+00:00') END,
    conversation.last_incoming_at = CASE WHEN conversation.last_incoming_at IS NULL THEN NULL ELSE CONVERT_TZ(conversation.last_incoming_at, offset_map.utc_offset, '+00:00') END,
    conversation.first_response_at = CASE WHEN conversation.first_response_at IS NULL THEN NULL ELSE CONVERT_TZ(conversation.first_response_at, offset_map.utc_offset, '+00:00') END
WHERE @rs_normalize_history = 1;
SET @rs_conversations_normalized = ROW_COUNT();

UPDATE conversation_service_cycles cycle
INNER JOIN tmp_rs_tenant_utc_offsets offset_map ON offset_map.tenant_id = cycle.tenant_id
SET
    cycle.first_incoming_at = CASE WHEN cycle.first_incoming_at IS NULL THEN NULL ELSE CONVERT_TZ(cycle.first_incoming_at, offset_map.utc_offset, '+00:00') END,
    cycle.last_incoming_at = CASE WHEN cycle.last_incoming_at IS NULL THEN NULL ELSE CONVERT_TZ(cycle.last_incoming_at, offset_map.utc_offset, '+00:00') END,
    cycle.first_response_at = CASE WHEN cycle.first_response_at IS NULL THEN NULL ELSE CONVERT_TZ(cycle.first_response_at, offset_map.utc_offset, '+00:00') END
WHERE @rs_normalize_history = 1;
SET @rs_cycles_normalized = ROW_COUNT();

UPDATE conversation_assignment_history history
INNER JOIN tmp_rs_tenant_utc_offsets offset_map ON offset_map.tenant_id = history.tenant_id
SET history.occurred_at = CONVERT_TZ(history.occurred_at, offset_map.utc_offset, '+00:00')
WHERE @rs_normalize_history = 1
  AND history.source = 'migration_snapshot';
SET @rs_assignment_snapshots_normalized = ROW_COUNT();

UPDATE rs_datetime_contract
SET historical_normalized_at_utc = COALESCE(historical_normalized_at_utc, UTC_TIMESTAMP()),
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1;

DROP TEMPORARY TABLE IF EXISTS tmp_rs_tenant_utc_offsets;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_rs_conversations_before_insert_metrics$$
CREATE TRIGGER trg_rs_conversations_before_insert_metrics
BEFORE INSERT ON conversations
FOR EACH ROW
BEGIN
    IF NEW.opened_at IS NULL THEN
        SET NEW.opened_at = UTC_TIMESTAMP();
    END IF;
    IF NEW.status_changed_at IS NULL THEN
        SET NEW.status_changed_at = UTC_TIMESTAMP();
    END IF;
    IF NEW.status = 'closed' AND NEW.closed_at IS NULL THEN
        SET NEW.closed_at = UTC_TIMESTAMP();
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_conversations_before_update_metrics$$
CREATE TRIGGER trg_rs_conversations_before_update_metrics
BEFORE UPDATE ON conversations
FOR EACH ROW
BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        IF NEW.status_changed_by_user_id <=> OLD.status_changed_by_user_id THEN
            SET NEW.status_changed_by_user_id = NULL;
        END IF;
        SET NEW.status_changed_at = UTC_TIMESTAMP();
        IF NEW.status = 'closed' THEN
            SET NEW.closed_at = UTC_TIMESTAMP();
        ELSEIF OLD.status = 'closed' THEN
            SET NEW.opened_at = UTC_TIMESTAMP();
            SET NEW.closed_at = NULL;
            SET NEW.first_incoming_at = NULL;
            SET NEW.last_incoming_at = NULL;
            SET NEW.first_response_at = NULL;
            SET NEW.first_response_user_id = NULL;
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_conversations_after_insert_history$$
CREATE TRIGGER trg_rs_conversations_after_insert_history
AFTER INSERT ON conversations
FOR EACH ROW
BEGIN
    INSERT INTO conversation_status_history
        (tenant_id, conversation_id, previous_status, status, responsible_user_id, actor_user_id, occurred_at)
    VALUES
        (NEW.tenant_id, NEW.id, NULL, NEW.status, NEW.assigned_user_id, NEW.status_changed_by_user_id, COALESCE(NEW.status_changed_at, UTC_TIMESTAMP()));

    IF NEW.assigned_user_id IS NOT NULL THEN
        INSERT INTO conversation_assignment_history
            (tenant_id, conversation_id, previous_user_id, assigned_user_id, action, source, actor_user_id, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, NULL, NEW.assigned_user_id, 'assign', COALESCE(NEW.assignment_source, 'initial'), NEW.assignment_updated_by_user_id, COALESCE(NEW.assigned_at, UTC_TIMESTAMP()));
    END IF;

    INSERT INTO conversation_service_cycles
        (tenant_id, conversation_id, cycle_number, opened_at,
         first_incoming_at, last_incoming_at, first_response_at,
         first_response_user_id, closed_at, closed_by_user_id,
         cycle_status, source)
    VALUES
        (NEW.tenant_id, NEW.id, 1, COALESCE(NEW.opened_at, NEW.created_at),
         NEW.first_incoming_at, NEW.last_incoming_at, NEW.first_response_at,
         NEW.first_response_user_id,
         CASE WHEN NEW.status = 'closed' THEN COALESCE(NEW.closed_at, UTC_TIMESTAMP()) ELSE NULL END,
         CASE WHEN NEW.status = 'closed' THEN NEW.assigned_user_id ELSE NULL END,
         CASE WHEN NEW.status = 'closed' THEN 'closed' ELSE 'active' END,
         'conversation_created');
END$$

DROP TRIGGER IF EXISTS trg_rs_conversations_after_update_history$$
CREATE TRIGGER trg_rs_conversations_after_update_history
AFTER UPDATE ON conversations
FOR EACH ROW
BEGIN
    DECLARE next_cycle_number INT UNSIGNED DEFAULT 1;
    DECLARE active_cycle_count INT UNSIGNED DEFAULT 0;

    IF NOT (NEW.assigned_user_id <=> OLD.assigned_user_id) THEN
        INSERT INTO conversation_assignment_history
            (tenant_id, conversation_id, previous_user_id, assigned_user_id,
             action, source, actor_user_id, occurred_at)
        VALUES
            (
                NEW.tenant_id,
                NEW.id,
                OLD.assigned_user_id,
                NEW.assigned_user_id,
                CASE
                    WHEN OLD.assigned_user_id IS NULL AND NEW.assigned_user_id IS NOT NULL THEN 'assign'
                    WHEN OLD.assigned_user_id IS NOT NULL AND NEW.assigned_user_id IS NULL THEN 'release'
                    ELSE 'transfer'
                END,
                COALESCE(NEW.assignment_source, 'system'),
                NEW.assignment_updated_by_user_id,
                UTC_TIMESTAMP()
            );
    END IF;

    IF NOT (NEW.status <=> OLD.status) THEN
        INSERT INTO conversation_status_history
            (tenant_id, conversation_id, previous_status, status,
             responsible_user_id, actor_user_id, occurred_at)
        VALUES
            (
                NEW.tenant_id,
                NEW.id,
                OLD.status,
                NEW.status,
                COALESCE(NEW.assigned_user_id, OLD.assigned_user_id),
                NEW.status_changed_by_user_id,
                COALESCE(NEW.status_changed_at, UTC_TIMESTAMP())
            );
    END IF;

    SELECT COUNT(*)
      INTO active_cycle_count
    FROM conversation_service_cycles active_cycle
    WHERE active_cycle.conversation_id = NEW.id
      AND active_cycle.tenant_id = NEW.tenant_id
      AND active_cycle.cycle_status = 'active';

    IF NEW.status = 'closed' THEN
        IF active_cycle_count > 0 THEN
            UPDATE conversation_service_cycles active_cycle
            SET active_cycle.first_incoming_at = COALESCE(active_cycle.first_incoming_at, NEW.first_incoming_at),
                active_cycle.last_incoming_at = COALESCE(NEW.last_incoming_at, active_cycle.last_incoming_at),
                active_cycle.first_response_at = COALESCE(active_cycle.first_response_at, NEW.first_response_at),
                active_cycle.first_response_user_id = COALESCE(active_cycle.first_response_user_id, NEW.first_response_user_id),
                active_cycle.closed_at = COALESCE(NEW.closed_at, UTC_TIMESTAMP()),
                active_cycle.closed_by_user_id = COALESCE(
                    NEW.status_changed_by_user_id,
                    NEW.assignment_updated_by_user_id,
                    OLD.assigned_user_id,
                    NEW.assigned_user_id
                ),
                active_cycle.cycle_status = 'closed'
            WHERE active_cycle.conversation_id = NEW.id
              AND active_cycle.tenant_id = NEW.tenant_id
              AND active_cycle.cycle_status = 'active';
        ELSEIF NOT (NEW.status <=> OLD.status) THEN
            SELECT COALESCE(MAX(existing_cycle.cycle_number), 0) + 1
              INTO next_cycle_number
            FROM conversation_service_cycles existing_cycle
            WHERE existing_cycle.conversation_id = NEW.id;

            INSERT INTO conversation_service_cycles
                (tenant_id, conversation_id, cycle_number, opened_at,
                 first_incoming_at, last_incoming_at, first_response_at,
                 first_response_user_id, closed_at, closed_by_user_id,
                 cycle_status, source)
            VALUES
                (
                    NEW.tenant_id,
                    NEW.id,
                    next_cycle_number,
                    COALESCE(NEW.opened_at, NEW.created_at, UTC_TIMESTAMP()),
                    NEW.first_incoming_at,
                    NEW.last_incoming_at,
                    NEW.first_response_at,
                    NEW.first_response_user_id,
                    COALESCE(NEW.closed_at, UTC_TIMESTAMP()),
                    COALESCE(
                        NEW.status_changed_by_user_id,
                        NEW.assignment_updated_by_user_id,
                        OLD.assigned_user_id,
                        NEW.assigned_user_id
                    ),
                    'closed',
                    'status_close_recovery'
                );
        END IF;
    ELSEIF active_cycle_count = 0 THEN
        SELECT COALESCE(MAX(existing_cycle.cycle_number), 0) + 1
          INTO next_cycle_number
        FROM conversation_service_cycles existing_cycle
        WHERE existing_cycle.conversation_id = NEW.id;

        INSERT INTO conversation_service_cycles
            (tenant_id, conversation_id, cycle_number, opened_at,
             first_incoming_at, last_incoming_at, first_response_at,
             first_response_user_id, cycle_status, source)
        VALUES
            (
                NEW.tenant_id,
                NEW.id,
                next_cycle_number,
                COALESCE(NEW.opened_at, UTC_TIMESTAMP()),
                NEW.first_incoming_at,
                NEW.last_incoming_at,
                NEW.first_response_at,
                NEW.first_response_user_id,
                'active',
                CASE
                    WHEN OLD.status = 'closed' THEN 'conversation_reopened'
                    ELSE 'status_cycle_recovery'
                END
            );
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_messages_after_insert_metrics$$
CREATE TRIGGER trg_rs_messages_after_insert_metrics
AFTER INSERT ON conversation_messages
FOR EACH ROW
BEGIN
    DECLARE next_cycle_number INT UNSIGNED DEFAULT 1;

    -- Uma conversa pode ter sido criada durante uma janela sem triggers ou por
    -- um fluxo legado. Nesse caso, a primeira mensagem posterior repara o ciclo.
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
             cycle_status, source)
        SELECT
            c.tenant_id,
            c.id,
            next_cycle_number,
            COALESCE(c.opened_at, c.created_at, NEW.sent_at, UTC_TIMESTAMP()),
            CASE WHEN NEW.direction = 'incoming' THEN NEW.sent_at ELSE c.first_incoming_at END,
            CASE WHEN NEW.direction = 'incoming' THEN NEW.sent_at ELSE c.last_incoming_at END,
            'active',
            'message_cycle_recovery'
        FROM conversations c
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id;
    END IF;

    IF NEW.direction = 'incoming' THEN
        UPDATE conversations c
        SET c.first_incoming_at = COALESCE(c.first_incoming_at, NEW.sent_at),
            c.last_incoming_at = NEW.sent_at
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id;

        UPDATE conversation_service_cycles active_cycle
        SET active_cycle.first_incoming_at = COALESCE(active_cycle.first_incoming_at, NEW.sent_at),
            active_cycle.last_incoming_at = NEW.sent_at
        WHERE active_cycle.conversation_id = NEW.conversation_id
          AND active_cycle.tenant_id = NEW.tenant_id
          AND active_cycle.cycle_status = 'active'
        ORDER BY active_cycle.cycle_number DESC
        LIMIT 1;
    ELSEIF NEW.direction = 'outgoing' AND NEW.sender_type = 'user' THEN
        UPDATE conversations c
        SET c.first_response_user_id = COALESCE(c.first_response_user_id, NEW.sender_user_id),
            c.first_response_at = COALESCE(c.first_response_at, NEW.sent_at)
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id
          AND c.first_response_at IS NULL
          AND c.first_incoming_at IS NOT NULL
          AND c.first_incoming_at <= NEW.sent_at;

        UPDATE conversation_service_cycles active_cycle
        SET active_cycle.first_response_user_id = NEW.sender_user_id,
            active_cycle.first_response_at = NEW.sent_at
        WHERE active_cycle.conversation_id = NEW.conversation_id
          AND active_cycle.tenant_id = NEW.tenant_id
          AND active_cycle.cycle_status = 'active'
          AND active_cycle.first_response_at IS NULL
          AND active_cycle.first_incoming_at IS NOT NULL
          AND active_cycle.first_incoming_at <= NEW.sent_at
        ORDER BY active_cycle.cycle_number DESC
        LIMIT 1;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_before_insert_metrics$$
CREATE TRIGGER trg_rs_appointments_before_insert_metrics
BEFORE INSERT ON calendar_appointments
FOR EACH ROW
BEGIN
    SET NEW.status_changed_at = COALESCE(NEW.status_changed_at, UTC_TIMESTAMP());
    IF NEW.owner_user_id IS NOT NULL THEN
        SET NEW.owner_changed_at = COALESCE(NEW.owner_changed_at, UTC_TIMESTAMP());
    END IF;
    IF NEW.status = 'confirmed' THEN SET NEW.confirmed_at = COALESCE(NEW.confirmed_at, UTC_TIMESTAMP()); END IF;
    IF NEW.status = 'completed' THEN SET NEW.completed_at = COALESCE(NEW.completed_at, UTC_TIMESTAMP()); END IF;
    IF NEW.status IN ('cancelled', 'rejected') THEN SET NEW.cancelled_at = COALESCE(NEW.cancelled_at, UTC_TIMESTAMP()); END IF;
    IF NEW.status = 'no_show' THEN SET NEW.no_show_at = COALESCE(NEW.no_show_at, UTC_TIMESTAMP()); END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_before_update_metrics$$
CREATE TRIGGER trg_rs_appointments_before_update_metrics
BEFORE UPDATE ON calendar_appointments
FOR EACH ROW
BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        IF NEW.status_changed_by_user_id <=> OLD.status_changed_by_user_id THEN
            SET NEW.status_changed_by_user_id = NULL;
        END IF;
        SET NEW.status_changed_at = UTC_TIMESTAMP();
        IF NEW.status = 'confirmed' THEN SET NEW.confirmed_at = UTC_TIMESTAMP(); END IF;
        IF NEW.status = 'completed' THEN SET NEW.completed_at = UTC_TIMESTAMP(); END IF;
        IF NEW.status IN ('cancelled', 'rejected') THEN SET NEW.cancelled_at = UTC_TIMESTAMP(); END IF;
        IF NEW.status = 'no_show' THEN SET NEW.no_show_at = UTC_TIMESTAMP(); END IF;
    END IF;

    IF NOT (NEW.owner_user_id <=> OLD.owner_user_id) THEN
        IF NEW.owner_changed_by_user_id <=> OLD.owner_changed_by_user_id THEN
            SET NEW.owner_changed_by_user_id = NULL;
        END IF;
        SET NEW.owner_changed_at = UTC_TIMESTAMP();
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_after_insert_history$$
CREATE TRIGGER trg_rs_appointments_after_insert_history
AFTER INSERT ON calendar_appointments
FOR EACH ROW
BEGIN
    INSERT INTO calendar_appointment_history
        (tenant_id, appointment_id, event_type, previous_status, status,
         previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
         title_snapshot, occurred_at)
    VALUES
        (NEW.tenant_id, NEW.id, 'created', NULL, NEW.status,
         NULL, NEW.owner_user_id, NEW.created_by_user_id, NEW.starts_at, NEW.ends_at,
         NEW.title, UTC_TIMESTAMP());
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_after_update_history$$
CREATE TRIGGER trg_rs_appointments_after_update_history
AFTER UPDATE ON calendar_appointments
FOR EACH ROW
BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        INSERT INTO calendar_appointment_history
            (tenant_id, appointment_id, event_type, previous_status, status,
             previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
             title_snapshot, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, 'status_changed', OLD.status, NEW.status,
             OLD.owner_user_id, NEW.owner_user_id, NEW.status_changed_by_user_id,
             NEW.starts_at, NEW.ends_at, NEW.title, COALESCE(NEW.status_changed_at, UTC_TIMESTAMP()));
    END IF;

    IF NOT (NEW.owner_user_id <=> OLD.owner_user_id) THEN
        INSERT INTO calendar_appointment_history
            (tenant_id, appointment_id, event_type, previous_status, status,
             previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
             title_snapshot, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, 'owner_changed', OLD.status, NEW.status,
             OLD.owner_user_id, NEW.owner_user_id, NEW.owner_changed_by_user_id,
             NEW.starts_at, NEW.ends_at, NEW.title, COALESCE(NEW.owner_changed_at, UTC_TIMESTAMP()));
    END IF;

    IF NOT (NEW.starts_at <=> OLD.starts_at) OR NOT (NEW.ends_at <=> OLD.ends_at) THEN
        INSERT INTO calendar_appointment_history
            (tenant_id, appointment_id, event_type, previous_status, status,
             previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
             title_snapshot, metadata_json, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, 'rescheduled', OLD.status, NEW.status,
             OLD.owner_user_id, NEW.owner_user_id,
             COALESCE(NEW.status_changed_by_user_id, NEW.owner_changed_by_user_id),
             NEW.starts_at, NEW.ends_at, NEW.title,
             JSON_OBJECT('previous_starts_at', OLD.starts_at, 'previous_ends_at', OLD.ends_at),
             UTC_TIMESTAMP());
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_before_delete_history$$
CREATE TRIGGER trg_rs_appointments_before_delete_history
BEFORE DELETE ON calendar_appointments
FOR EACH ROW
BEGIN
    INSERT INTO calendar_appointment_history
        (tenant_id, appointment_id, event_type, previous_status, status,
         previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
         title_snapshot, occurred_at)
    VALUES
        (OLD.tenant_id, OLD.id, 'deleted', OLD.status, NULL,
         OLD.owner_user_id, NULL, NULL, OLD.starts_at, OLD.ends_at, OLD.title, UTC_TIMESTAMP());
END$$

DELIMITER ;

SELECT
    CASE
        WHEN (SELECT COUNT(*) FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND TRIGGER_NAME LIKE 'trg_rs_%') >= 10
         AND (SELECT storage_timezone FROM rs_datetime_contract WHERE id = 1) = 'UTC'
        THEN CONCAT(
            'Migration 071 aplicada: contrato UTC ativo; mensagens normalizadas=',
            COALESCE(@rs_messages_normalized, 0),
            '; conversas normalizadas=',
            COALESCE(@rs_conversations_normalized, 0),
            '; ciclos normalizados=',
            COALESCE(@rs_cycles_normalized, 0),
            '; snapshots de atribuição normalizados=',
            COALESCE(@rs_assignment_snapshots_normalized, 0),
            '.'
        )
        ELSE 'ATENÇÃO: o contrato UTC não foi concluído. Verifique os triggers e a tabela rs_datetime_contract.'
    END AS resultado;
