-- RS Connect 36.10.3 — Sincronização resiliente entre status e ciclos
-- Compatível com MySQL 8/9 e MariaDB modernos.
-- Pode ser executada mais de uma vez.
--
-- Corrige o cenário em que a interface altera conversations.status, mas o
-- ciclo permanece active. Além do reparo imediato, recria o trigger de UPDATE
-- com sincronização idempotente em toda alteração da conversa. O backend da
-- v36.10.3 também executa a mesma garantia nas ações manuais do painel.

SET NAMES utf8mb4;

-- 1) Fecha ciclos ativos cuja conversa já está encerrada.
UPDATE conversation_service_cycles cycle
INNER JOIN conversations conversation
        ON conversation.id = cycle.conversation_id
       AND conversation.tenant_id = cycle.tenant_id
SET
    cycle.first_incoming_at = COALESCE(cycle.first_incoming_at, conversation.first_incoming_at),
    cycle.last_incoming_at = COALESCE(conversation.last_incoming_at, cycle.last_incoming_at),
    cycle.first_response_at = COALESCE(cycle.first_response_at, conversation.first_response_at),
    cycle.first_response_user_id = COALESCE(cycle.first_response_user_id, conversation.first_response_user_id),
    cycle.closed_at = COALESCE(cycle.closed_at, conversation.closed_at, conversation.updated_at, CURRENT_TIMESTAMP),
    cycle.closed_by_user_id = COALESCE(
        cycle.closed_by_user_id,
        conversation.status_changed_by_user_id,
        conversation.assignment_updated_by_user_id,
        conversation.assigned_user_id
    ),
    cycle.cycle_status = 'closed'
WHERE conversation.status = 'closed'
  AND cycle.cycle_status = 'active';
SET @rs_cycles_closed_repaired = ROW_COUNT();

-- 2) Reabre a trilha histórica de conversas não encerradas sem ciclo ativo.
INSERT INTO conversation_service_cycles
    (tenant_id, conversation_id, cycle_number, opened_at,
     first_incoming_at, last_incoming_at, first_response_at,
     first_response_user_id, cycle_status, source)
SELECT
    conversation.tenant_id,
    conversation.id,
    COALESCE((
        SELECT MAX(existing_cycle.cycle_number) + 1
        FROM conversation_service_cycles existing_cycle
        WHERE existing_cycle.conversation_id = conversation.id
    ), 1),
    COALESCE(conversation.opened_at, conversation.created_at, CURRENT_TIMESTAMP),
    conversation.first_incoming_at,
    conversation.last_incoming_at,
    conversation.first_response_at,
    conversation.first_response_user_id,
    'active',
    'migration_070_status_sync'
FROM conversations conversation
WHERE conversation.status <> 'closed'
  AND NOT EXISTS (
      SELECT 1
      FROM conversation_service_cycles active_cycle
      WHERE active_cycle.conversation_id = conversation.id
        AND active_cycle.tenant_id = conversation.tenant_id
        AND active_cycle.cycle_status = 'active'
  );
SET @rs_cycles_opened_repaired = ROW_COUNT();

DELIMITER $$

-- 3) Substitui o trigger de UPDATE por uma versão resiliente.
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
                CURRENT_TIMESTAMP
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
                COALESCE(NEW.status_changed_at, CURRENT_TIMESTAMP)
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
                active_cycle.closed_at = COALESCE(NEW.closed_at, CURRENT_TIMESTAMP),
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
                    COALESCE(NEW.opened_at, NEW.created_at, CURRENT_TIMESTAMP),
                    NEW.first_incoming_at,
                    NEW.last_incoming_at,
                    NEW.first_response_at,
                    NEW.first_response_user_id,
                    COALESCE(NEW.closed_at, CURRENT_TIMESTAMP),
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
                COALESCE(NEW.opened_at, CURRENT_TIMESTAMP),
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

DELIMITER ;

-- 4) Resultado verificável. Não declara sucesso apenas por ter chegado ao fim.
SELECT
    CASE
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND TRIGGER_NAME = 'trg_rs_conversations_after_update_history'
        )
        AND NOT EXISTS (
            SELECT 1
            FROM conversations conversation
            INNER JOIN conversation_service_cycles cycle
                    ON cycle.conversation_id = conversation.id
                   AND cycle.tenant_id = conversation.tenant_id
            WHERE conversation.status = 'closed'
              AND cycle.cycle_status = 'active'
        )
        THEN CONCAT(
            'Migration 070 aplicada: sincronização de status ativa; ciclos encerrados reparados=',
            COALESCE(@rs_cycles_closed_repaired, 0),
            '; ciclos abertos reparados=',
            COALESCE(@rs_cycles_opened_repaired, 0),
            '.'
        )
        ELSE 'ATENÇÃO: a sincronização de status não foi concluída. Verifique o trigger e as conversas com ciclo divergente.'
    END AS resultado;
