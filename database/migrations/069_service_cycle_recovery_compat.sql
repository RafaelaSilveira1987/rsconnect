-- RS Connect 36.10.1 — Recuperação resiliente de ciclos de atendimento
-- Compatível com MySQL 8/9 e MariaDB modernos.
-- Pode ser executada mais de uma vez.
--
-- Corrige conversas que ficaram sem conversation_service_cycles durante a
-- janela entre o snapshot da migration 068 e a criação efetiva dos triggers.
-- Também torna o trigger de mensagens autocorretivo: se uma conversa aberta
-- receber uma mensagem sem possuir ciclo ativo, o ciclo é criado antes de
-- registrar primeira entrada e primeira resposta humana.

SET NAMES utf8mb4;

-- 1) Recupera conversas abertas que não possuem ciclo ativo.
-- O número do ciclo respeita ciclos antigos já encerrados.
INSERT INTO conversation_service_cycles
    (tenant_id, conversation_id, cycle_number, opened_at,
     cycle_status, source)
SELECT
    c.tenant_id,
    c.id,
    COALESCE((
        SELECT MAX(existing_cycle.cycle_number) + 1
        FROM conversation_service_cycles existing_cycle
        WHERE existing_cycle.conversation_id = c.id
    ), 1),
    COALESCE(c.opened_at, c.created_at, CURRENT_TIMESTAMP),
    'active',
    'migration_069_recovery'
FROM conversations c
WHERE c.status <> 'closed'
  AND NOT EXISTS (
      SELECT 1
      FROM conversation_service_cycles active_cycle
      WHERE active_cycle.conversation_id = c.id
        AND active_cycle.cycle_status = 'active'
  );

-- 2) Recupera conversas encerradas que nunca receberam ciclo algum.
INSERT INTO conversation_service_cycles
    (tenant_id, conversation_id, cycle_number, opened_at,
     first_incoming_at, last_incoming_at, first_response_at,
     first_response_user_id, closed_at, closed_by_user_id,
     cycle_status, source)
SELECT
    c.tenant_id,
    c.id,
    1,
    COALESCE(c.opened_at, c.created_at, CURRENT_TIMESTAMP),
    c.first_incoming_at,
    c.last_incoming_at,
    c.first_response_at,
    c.first_response_user_id,
    COALESCE(c.closed_at, c.updated_at, CURRENT_TIMESTAMP),
    c.assigned_user_id,
    'closed',
    'migration_069_closed_snapshot'
FROM conversations c
WHERE c.status = 'closed'
  AND NOT EXISTS (
      SELECT 1
      FROM conversation_service_cycles any_cycle
      WHERE any_cycle.conversation_id = c.id
  );

-- 3) Calcula os marcos dos ciclos recuperados usando as mensagens reais.
-- Uma tabela temporária evita ler conversation_service_cycles por subconsulta
-- enquanto a mesma tabela é atualizada, mantendo compatibilidade com MySQL.
DROP TEMPORARY TABLE IF EXISTS tmp_rs_cycle_recovery_metrics;
CREATE TEMPORARY TABLE tmp_rs_cycle_recovery_metrics (
    cycle_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    first_incoming_at DATETIME NULL,
    last_incoming_at DATETIME NULL,
    first_response_at DATETIME NULL,
    first_response_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (cycle_id)
) ENGINE=InnoDB;

INSERT INTO tmp_rs_cycle_recovery_metrics
    (cycle_id, conversation_id, tenant_id, first_incoming_at, last_incoming_at)
SELECT
    recovered_cycle.id,
    recovered_cycle.conversation_id,
    recovered_cycle.tenant_id,
    MIN(CASE WHEN message.direction = 'incoming' THEN message.sent_at END),
    MAX(CASE WHEN message.direction = 'incoming' THEN message.sent_at END)
FROM conversation_service_cycles recovered_cycle
LEFT JOIN (
    SELECT
        current_cycle.id AS cycle_id,
        MAX(previous_cycle.closed_at) AS previous_closed_at
    FROM conversation_service_cycles current_cycle
    LEFT JOIN conversation_service_cycles previous_cycle
           ON previous_cycle.conversation_id = current_cycle.conversation_id
          AND previous_cycle.cycle_number < current_cycle.cycle_number
          AND previous_cycle.closed_at IS NOT NULL
    WHERE current_cycle.source IN ('migration_069_recovery', 'message_cycle_recovery')
    GROUP BY current_cycle.id
) boundary ON boundary.cycle_id = recovered_cycle.id
LEFT JOIN conversation_messages message
       ON message.conversation_id = recovered_cycle.conversation_id
      AND message.tenant_id = recovered_cycle.tenant_id
      AND (boundary.previous_closed_at IS NULL OR message.sent_at >= boundary.previous_closed_at)
WHERE recovered_cycle.source IN ('migration_069_recovery', 'message_cycle_recovery')
GROUP BY recovered_cycle.id;

UPDATE tmp_rs_cycle_recovery_metrics recovery_metrics
SET
    recovery_metrics.first_response_at = (
        SELECT response_message.sent_at
        FROM conversation_messages response_message
        WHERE response_message.conversation_id = recovery_metrics.conversation_id
          AND response_message.tenant_id = recovery_metrics.tenant_id
          AND response_message.direction = 'outgoing'
          AND response_message.sender_type = 'user'
          AND recovery_metrics.first_incoming_at IS NOT NULL
          AND response_message.sent_at >= recovery_metrics.first_incoming_at
        ORDER BY response_message.sent_at, response_message.id
        LIMIT 1
    ),
    recovery_metrics.first_response_user_id = (
        SELECT response_message.sender_user_id
        FROM conversation_messages response_message
        WHERE response_message.conversation_id = recovery_metrics.conversation_id
          AND response_message.tenant_id = recovery_metrics.tenant_id
          AND response_message.direction = 'outgoing'
          AND response_message.sender_type = 'user'
          AND response_message.sender_user_id IS NOT NULL
          AND recovery_metrics.first_incoming_at IS NOT NULL
          AND response_message.sent_at >= recovery_metrics.first_incoming_at
        ORDER BY response_message.sent_at, response_message.id
        LIMIT 1
    );

UPDATE conversation_service_cycles recovered_cycle
JOIN tmp_rs_cycle_recovery_metrics recovery_metrics
  ON recovery_metrics.cycle_id = recovered_cycle.id
SET
    recovered_cycle.first_incoming_at = COALESCE(recovered_cycle.first_incoming_at, recovery_metrics.first_incoming_at),
    recovered_cycle.last_incoming_at = COALESCE(recovery_metrics.last_incoming_at, recovered_cycle.last_incoming_at),
    recovered_cycle.first_response_at = COALESCE(recovered_cycle.first_response_at, recovery_metrics.first_response_at),
    recovered_cycle.first_response_user_id = COALESCE(recovered_cycle.first_response_user_id, recovery_metrics.first_response_user_id);

DROP TEMPORARY TABLE IF EXISTS tmp_rs_cycle_recovery_metrics;

-- 4) Mantém os campos atuais da conversa coerentes com o ciclo ativo reparado.
UPDATE conversations c
JOIN conversation_service_cycles active_cycle
  ON active_cycle.conversation_id = c.id
 AND active_cycle.tenant_id = c.tenant_id
 AND active_cycle.cycle_status = 'active'
SET
    c.first_incoming_at = COALESCE(c.first_incoming_at, active_cycle.first_incoming_at),
    c.last_incoming_at = COALESCE(active_cycle.last_incoming_at, c.last_incoming_at),
    c.first_response_at = COALESCE(c.first_response_at, active_cycle.first_response_at),
    c.first_response_user_id = COALESCE(c.first_response_user_id, active_cycle.first_response_user_id)
WHERE active_cycle.source IN ('migration_069_recovery', 'message_cycle_recovery');

DELIMITER $$

-- 5) Recria somente o trigger de mensagens com autorrecuperação.
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
            COALESCE(c.opened_at, c.created_at, NEW.sent_at, CURRENT_TIMESTAMP),
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

DELIMITER ;

-- Resultado dinâmico: evita declarar sucesso se o trigger não foi recriado.
SELECT
    CASE
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND TRIGGER_NAME = 'trg_rs_messages_after_insert_metrics'
        )
        THEN 'Migration 069 aplicada: ciclos ausentes recuperados e trigger de mensagens com autorrecuperação ativo.'
        ELSE 'ATENÇÃO: dados recuperados, mas o trigger de mensagens não foi criado. Verifique privilégios e log_bin_trust_function_creators.'
    END AS resultado;
