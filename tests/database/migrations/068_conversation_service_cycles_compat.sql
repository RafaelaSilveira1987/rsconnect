-- RS Connect 36.10.0 — Ciclos persistentes de atendimento para relatórios
-- Compatível com MySQL/MariaDB. Pode ser executada mais de uma vez.
-- Preserva os triggers da migration 067 e acrescenta a persistência de cada
-- ciclo aberto/reaberto, evitando perder a primeira resposta ao reabrir uma conversa.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS conversation_service_cycles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    cycle_number INT UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL,
    first_incoming_at DATETIME NULL,
    last_incoming_at DATETIME NULL,
    first_response_at DATETIME NULL,
    first_response_user_id BIGINT UNSIGNED NULL,
    closed_at DATETIME NULL,
    closed_by_user_id BIGINT UNSIGNED NULL,
    cycle_status VARCHAR(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
    source VARCHAR(40) COLLATE utf8mb4_unicode_ci NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conversation_service_cycle (conversation_id, cycle_number),
    KEY idx_service_cycles_tenant_opened (tenant_id, opened_at),
    KEY idx_service_cycles_first_response (tenant_id, first_response_user_id, first_response_at),
    KEY idx_service_cycles_closed_by (tenant_id, closed_by_user_id, closed_at),
    CONSTRAINT fk_service_cycles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_cycles_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_cycles_first_response_user FOREIGN KEY (first_response_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_cycles_closed_by_user FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot idempotente. Conversas anteriores recebem um ciclo inicial com os
-- marcos atualmente disponíveis; ciclos antigos que nunca foram armazenados
-- não são inventados.
INSERT INTO conversation_service_cycles
    (tenant_id, conversation_id, cycle_number, opened_at,
     first_incoming_at, last_incoming_at, first_response_at,
     first_response_user_id, closed_at, closed_by_user_id,
     cycle_status, source)
SELECT
    c.tenant_id,
    c.id,
    1,
    COALESCE(c.opened_at, c.created_at),
    c.first_incoming_at,
    c.last_incoming_at,
    c.first_response_at,
    c.first_response_user_id,
    CASE WHEN c.status = 'closed' THEN COALESCE(c.closed_at, c.updated_at) ELSE NULL END,
    CASE WHEN c.status = 'closed' THEN c.assigned_user_id ELSE NULL END,
    CASE WHEN c.status = 'closed' THEN 'closed' ELSE 'active' END,
    'migration_snapshot'
FROM conversations c
WHERE NOT EXISTS (
    SELECT 1
    FROM conversation_service_cycles sc
    WHERE sc.conversation_id = c.id
);

DELIMITER $$

-- Recria o trigger da migration 067 preservando histórico de status e
-- atribuição e acrescentando o ciclo inicial/reaberto/encerrado.
DROP TRIGGER IF EXISTS trg_rs_conversations_after_insert_history$$
CREATE TRIGGER trg_rs_conversations_after_insert_history
AFTER INSERT ON conversations
FOR EACH ROW
BEGIN
    INSERT INTO conversation_status_history
        (tenant_id, conversation_id, previous_status, status, responsible_user_id, actor_user_id, occurred_at)
    VALUES
        (NEW.tenant_id, NEW.id, NULL, NEW.status, NEW.assigned_user_id, NEW.status_changed_by_user_id, COALESCE(NEW.status_changed_at, CURRENT_TIMESTAMP));

    IF NEW.assigned_user_id IS NOT NULL THEN
        INSERT INTO conversation_assignment_history
            (tenant_id, conversation_id, previous_user_id, assigned_user_id, action, source, actor_user_id, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, NULL, NEW.assigned_user_id, 'assign', COALESCE(NEW.assignment_source, 'initial'), NEW.assignment_updated_by_user_id, COALESCE(NEW.assigned_at, CURRENT_TIMESTAMP));
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
         CASE WHEN NEW.status = 'closed' THEN COALESCE(NEW.closed_at, CURRENT_TIMESTAMP) ELSE NULL END,
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

    IF NOT (NEW.assigned_user_id <=> OLD.assigned_user_id) THEN
        INSERT INTO conversation_assignment_history
            (tenant_id, conversation_id, previous_user_id, assigned_user_id, action, source, actor_user_id, occurred_at)
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
            (tenant_id, conversation_id, previous_status, status, responsible_user_id, actor_user_id, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, OLD.status, NEW.status, NEW.assigned_user_id, NEW.status_changed_by_user_id, COALESCE(NEW.status_changed_at, CURRENT_TIMESTAMP));

        IF OLD.status = 'closed' AND NEW.status <> 'closed' THEN
            SELECT COALESCE(MAX(sc.cycle_number), 0) + 1
              INTO next_cycle_number
            FROM conversation_service_cycles sc
            WHERE sc.conversation_id = NEW.id;

            INSERT INTO conversation_service_cycles
                (tenant_id, conversation_id, cycle_number, opened_at,
                 cycle_status, source)
            VALUES
                (NEW.tenant_id, NEW.id, next_cycle_number,
                 COALESCE(NEW.opened_at, CURRENT_TIMESTAMP), 'active', 'conversation_reopened');
        ELSEIF OLD.status <> 'closed' AND NEW.status = 'closed' THEN
            UPDATE conversation_service_cycles sc
            SET sc.first_incoming_at = COALESCE(sc.first_incoming_at, NEW.first_incoming_at),
                sc.last_incoming_at = COALESCE(NEW.last_incoming_at, sc.last_incoming_at),
                sc.first_response_at = COALESCE(sc.first_response_at, NEW.first_response_at),
                sc.first_response_user_id = COALESCE(sc.first_response_user_id, NEW.first_response_user_id),
                sc.closed_at = COALESCE(NEW.closed_at, CURRENT_TIMESTAMP),
                sc.closed_by_user_id = NEW.assigned_user_id,
                sc.cycle_status = 'closed'
            WHERE sc.conversation_id = NEW.id
              AND sc.cycle_status = 'active'
            ORDER BY sc.cycle_number DESC
            LIMIT 1;
        END IF;
    END IF;
END$$

-- Recria o trigger de mensagens da migration 067 e grava os mesmos marcos no
-- ciclo ativo. Assim a reabertura não apaga os dados do ciclo anterior.
DROP TRIGGER IF EXISTS trg_rs_messages_after_insert_metrics$$
CREATE TRIGGER trg_rs_messages_after_insert_metrics
AFTER INSERT ON conversation_messages
FOR EACH ROW
BEGIN
    IF NEW.direction = 'incoming' THEN
        UPDATE conversations c
        SET c.first_incoming_at = COALESCE(c.first_incoming_at, NEW.sent_at),
            c.last_incoming_at = NEW.sent_at
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id;

        UPDATE conversation_service_cycles sc
        SET sc.first_incoming_at = COALESCE(sc.first_incoming_at, NEW.sent_at),
            sc.last_incoming_at = NEW.sent_at
        WHERE sc.conversation_id = NEW.conversation_id
          AND sc.tenant_id = NEW.tenant_id
          AND sc.cycle_status = 'active'
        ORDER BY sc.cycle_number DESC
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

        UPDATE conversation_service_cycles sc
        SET sc.first_response_user_id = NEW.sender_user_id,
            sc.first_response_at = NEW.sent_at
        WHERE sc.conversation_id = NEW.conversation_id
          AND sc.tenant_id = NEW.tenant_id
          AND sc.cycle_status = 'active'
          AND sc.first_response_at IS NULL
          AND sc.first_incoming_at IS NOT NULL
          AND sc.first_incoming_at <= NEW.sent_at
        ORDER BY sc.cycle_number DESC
        LIMIT 1;
    END IF;
END$$

DELIMITER ;

SELECT 'Migration 068 aplicada: ciclos de atendimento e primeira resposta persistidos para relatórios por profissional.' AS resultado;
