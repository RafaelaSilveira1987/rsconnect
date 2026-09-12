-- RS Connect 36.32.1 — consistência da primeira resposta humana e corrida Evolution/painel.
--
-- Objetivos:
-- 1) reparar ciclos em que uma resposta humana existe em conversation_messages,
--    mas first_response_at / first_response_user_id ficaram incompletos;
-- 2) cobrir o caso em que o eco da Evolution insere a mensagem como "system"
--    antes do painel executar o ON DUPLICATE KEY UPDATE para "user";
-- 3) manter conversations e conversation_service_cycles sincronizados.

DROP TEMPORARY TABLE IF EXISTS tmp_rs_first_human_cycle_response;
CREATE TEMPORARY TABLE tmp_rs_first_human_cycle_response (
    cycle_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    KEY idx_tmp_rs_first_human_message (message_id)
) ENGINE=InnoDB;

INSERT INTO tmp_rs_first_human_cycle_response (cycle_id, message_id)
SELECT sc.id,
       (
           SELECT m.id
           FROM conversation_messages m
           WHERE m.tenant_id = sc.tenant_id
             AND m.conversation_id = sc.conversation_id
             AND m.direction = 'outgoing'
             AND m.sender_type = 'user'
             AND m.sender_user_id IS NOT NULL
             AND m.sent_at >= sc.first_incoming_at
             AND (sc.closed_at IS NULL OR m.sent_at <= sc.closed_at)
           ORDER BY m.sent_at ASC, m.id ASC
           LIMIT 1
       ) AS message_id
FROM conversation_service_cycles sc
WHERE sc.first_incoming_at IS NOT NULL
  AND (sc.first_response_at IS NULL OR sc.first_response_user_id IS NULL)
HAVING message_id IS NOT NULL;

UPDATE conversation_service_cycles sc
INNER JOIN tmp_rs_first_human_cycle_response repair ON repair.cycle_id = sc.id
INNER JOIN conversation_messages m ON m.id = repair.message_id
SET sc.first_response_at = m.sent_at,
    sc.first_response_user_id = m.sender_user_id
WHERE m.sender_user_id IS NOT NULL;

-- A linha de conversations representa o ciclo mais recente. Reaplica os marcos
-- reparados apenas a partir desse ciclo para não misturar reaberturas antigas.
UPDATE conversations c
INNER JOIN conversation_service_cycles sc
        ON sc.conversation_id = c.id
       AND sc.tenant_id = c.tenant_id
LEFT JOIN conversation_service_cycles newer
       ON newer.conversation_id = sc.conversation_id
      AND newer.tenant_id = sc.tenant_id
      AND newer.cycle_number > sc.cycle_number
SET c.first_response_at = sc.first_response_at,
    c.first_response_user_id = sc.first_response_user_id
WHERE newer.id IS NULL
  AND sc.first_response_at IS NOT NULL
  AND sc.first_response_user_id IS NOT NULL
  AND (c.first_response_at IS NULL OR c.first_response_user_id IS NULL);

DROP TEMPORARY TABLE IF EXISTS tmp_rs_first_human_cycle_response;

DELIMITER $$

-- Quando o webhook da Evolution vence a corrida, a mensagem de saída pode ser
-- criada primeiro como "system". O envio humano do painel então atualiza a mesma
-- linha via ON DUPLICATE KEY UPDATE. AFTER INSERT não roda nesse caminho, por
-- isso este trigger completa os marcos na transição para sender_type = user.
DROP TRIGGER IF EXISTS trg_rs_messages_after_update_human_metrics$$
CREATE TRIGGER trg_rs_messages_after_update_human_metrics
AFTER UPDATE ON conversation_messages
FOR EACH ROW
BEGIN
    IF NEW.direction = 'outgoing'
       AND NEW.sender_type = 'user'
       AND NEW.sender_user_id IS NOT NULL
       AND (
            NOT (OLD.direction <=> NEW.direction)
            OR NOT (OLD.sender_type <=> NEW.sender_type)
            OR NOT (OLD.sender_user_id <=> NEW.sender_user_id)
            OR NOT (OLD.sent_at <=> NEW.sent_at)
       ) THEN

        UPDATE conversations c
        SET c.first_response_at = NEW.sent_at,
            c.first_response_user_id = NEW.sender_user_id
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id
          AND c.first_incoming_at IS NOT NULL
          AND c.first_incoming_at <= NEW.sent_at
          AND (
               c.first_response_at IS NULL
               OR (c.first_response_user_id IS NULL AND c.first_response_at >= NEW.sent_at)
          );

        UPDATE conversation_service_cycles sc
        SET sc.first_response_at = NEW.sent_at,
            sc.first_response_user_id = NEW.sender_user_id
        WHERE sc.conversation_id = NEW.conversation_id
          AND sc.tenant_id = NEW.tenant_id
          AND sc.cycle_status = 'active'
          AND sc.first_incoming_at IS NOT NULL
          AND sc.first_incoming_at <= NEW.sent_at
          AND (
               sc.first_response_at IS NULL
               OR (sc.first_response_user_id IS NULL AND sc.first_response_at >= NEW.sent_at)
          )
        ORDER BY sc.cycle_number DESC
        LIMIT 1;
    END IF;
END$$

DELIMITER ;

SELECT 'Migration 113 aplicada: primeira resposta humana reparada e protegida contra corrida Evolution/painel.' AS resultado;
