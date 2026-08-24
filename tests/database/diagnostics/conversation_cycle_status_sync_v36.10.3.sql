-- Diagnóstico RS Connect 36.10.3 — status da conversa x ciclo operacional

SELECT
    conversation.id AS conversation_id,
    conversation.tenant_id,
    conversation.status AS conversation_status,
    cycle.id AS cycle_id,
    cycle.cycle_number,
    cycle.cycle_status,
    cycle.opened_at,
    cycle.closed_at,
    cycle.closed_by_user_id,
    cycle.source,
    cycle.updated_at
FROM conversations conversation
LEFT JOIN conversation_service_cycles cycle
       ON cycle.conversation_id = conversation.id
      AND cycle.tenant_id = conversation.tenant_id
      AND cycle.cycle_status = 'active'
WHERE (conversation.status = 'closed' AND cycle.id IS NOT NULL)
   OR (conversation.status <> 'closed' AND cycle.id IS NULL)
ORDER BY conversation.updated_at DESC;

SELECT
    conversation.id AS conversation_id,
    conversation.status,
    conversation.status_changed_by_user_id,
    conversation.closed_at AS conversation_closed_at,
    cycle.cycle_number,
    cycle.cycle_status,
    cycle.closed_at AS cycle_closed_at,
    cycle.closed_by_user_id,
    cycle.source
FROM conversations conversation
INNER JOIN conversation_service_cycles cycle
        ON cycle.conversation_id = conversation.id
       AND cycle.tenant_id = conversation.tenant_id
WHERE conversation.id = 1104
ORDER BY cycle.cycle_number;

SELECT
    TRIGGER_NAME,
    EVENT_MANIPULATION,
    EVENT_OBJECT_TABLE,
    ACTION_TIMING
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME IN (
      'trg_rs_conversations_before_update_metrics',
      'trg_rs_conversations_after_update_history',
      'trg_rs_messages_after_insert_metrics'
  )
ORDER BY TRIGGER_NAME;
