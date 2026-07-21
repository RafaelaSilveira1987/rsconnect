-- HOTFIX 36.2.1 — validação da seleção de agenda isolada da IA
-- Substitua @conversation_id pela conversa testada.
SET @conversation_id := 586;

SELECT
    cm.id, cm.direction, cm.sender_type, cm.content, cm.status, cm.sent_at
FROM conversation_messages cm
WHERE cm.conversation_id = @conversation_id
ORDER BY cm.id DESC
LIMIT 30;

SELECT
    al.id, al.incoming_message_id, al.event, al.status,
    al.response_preview, al.error_message, al.raw_json, al.created_at
FROM ai_automation_logs al
WHERE al.conversation_id = @conversation_id
ORDER BY al.id DESC
LIMIT 30;

SELECT
    ce.id, ce.event_type, ce.description, ce.metadata_json, ce.created_at
FROM conversation_events ce
WHERE ce.conversation_id = @conversation_id
  AND ce.event_type LIKE 'calendar.%'
ORDER BY ce.id DESC
LIMIT 30;
