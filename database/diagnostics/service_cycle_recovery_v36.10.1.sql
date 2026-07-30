-- RS Connect 36.10.1 — Diagnóstico de recuperação dos ciclos de atendimento

-- 1. Conversas abertas ainda sem ciclo ativo (esperado: 0).
SELECT
    c.id AS conversation_id,
    c.tenant_id,
    c.status,
    c.created_at,
    c.opened_at,
    COUNT(sc.id) AS total_ciclos
FROM conversations c
LEFT JOIN conversation_service_cycles sc
       ON sc.conversation_id = c.id
      AND sc.cycle_status = 'active'
WHERE c.status <> 'closed'
GROUP BY c.id, c.tenant_id, c.status, c.created_at, c.opened_at
HAVING COUNT(sc.id) = 0
ORDER BY c.id DESC;

-- 2. Mensagens humanas recentes sem ciclo ativo (esperado: 0).
SELECT
    m.id AS message_id,
    m.conversation_id,
    m.tenant_id,
    m.direction,
    m.sender_type,
    m.sender_user_id,
    m.sent_at,
    LEFT(m.content, 100) AS mensagem
FROM conversation_messages m
WHERE m.sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  AND NOT EXISTS (
      SELECT 1
      FROM conversation_service_cycles sc
      WHERE sc.conversation_id = m.conversation_id
        AND sc.tenant_id = m.tenant_id
        AND sc.cycle_status = 'active'
  )
ORDER BY m.id DESC;

-- 3. Ciclos recuperados pela migration ou pelo trigger autocorretivo.
SELECT
    sc.id,
    sc.tenant_id,
    sc.conversation_id,
    sc.cycle_number,
    sc.opened_at,
    sc.first_incoming_at,
    sc.last_incoming_at,
    sc.first_response_at,
    sc.first_response_user_id,
    sc.cycle_status,
    sc.source,
    sc.created_at,
    sc.updated_at
FROM conversation_service_cycles sc
WHERE sc.source IN ('migration_069_recovery', 'migration_069_closed_snapshot', 'message_cycle_recovery')
ORDER BY sc.id DESC;

-- 4. Confirma os dez triggers operacionais esperados.
SELECT
    TRIGGER_NAME,
    EVENT_MANIPULATION,
    EVENT_OBJECT_TABLE,
    ACTION_TIMING
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME LIKE 'trg_rs_%'
ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME;

-- 5. Validação direta da conversa observada durante a homologação.
SELECT
    sc.conversation_id,
    sc.cycle_number,
    sc.opened_at,
    sc.first_incoming_at,
    sc.last_incoming_at,
    sc.first_response_at,
    sc.first_response_user_id,
    sc.closed_at,
    sc.cycle_status,
    sc.source
FROM conversation_service_cycles sc
WHERE sc.conversation_id = 1104
ORDER BY sc.cycle_number;
