-- HOTFIX 36.1.2 — diagnóstico do webhook 422 e da fila

SELECT 'incoming_message_id' AS check_name,
       COUNT(*) AS found
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ai_automation_logs'
  AND COLUMN_NAME = 'incoming_message_id';

SELECT 'conversation_status_enum' AS check_name,
       COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'conversation_messages'
  AND COLUMN_NAME = 'status';

SELECT cm.id,
       cm.tenant_id,
       cm.conversation_id,
       cm.direction,
       cm.sender_type,
       cm.status,
       cm.evolution_message_id,
       cm.sent_at,
       LEFT(cm.content, 180) AS content_preview
FROM conversation_messages cm
ORDER BY cm.id DESC
LIMIT 30;

SELECT al.id,
       al.tenant_id,
       al.conversation_id,
       al.agent_id,
       al.incoming_message_id,
       al.event,
       al.status,
       al.error_message,
       al.created_at
FROM ai_automation_logs al
ORDER BY al.id DESC
LIMIT 30;

SELECT cm.id AS incoming_message_id,
       cm.tenant_id,
       cm.conversation_id,
       cm.sent_at,
       LEFT(cm.content, 180) AS content_preview
FROM conversation_messages cm
INNER JOIN conversations c
        ON c.id = cm.conversation_id
       AND c.tenant_id = cm.tenant_id
WHERE cm.direction = 'incoming'
  AND c.attendance_mode = 'ai'
  AND c.status <> 'closed'
  AND cm.sent_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
  AND NOT EXISTS (
      SELECT 1
      FROM conversation_messages outgoing
      WHERE outgoing.conversation_id = cm.conversation_id
        AND outgoing.direction = 'outgoing'
        AND outgoing.status IN ('sent', 'delivered', 'read')
        AND (
            outgoing.sent_at > cm.sent_at
            OR (outgoing.sent_at = cm.sent_at AND outgoing.id > cm.id)
        )
  )
ORDER BY cm.sent_at DESC
LIMIT 100;
