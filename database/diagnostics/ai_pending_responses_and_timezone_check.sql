-- ZIP 34.5.1 — Diagnóstico de horário e conversas aguardando resposta
-- Ajuste os IDs antes de executar.
SET @tenant_id := 2;
SET @agent_id := 1;

SELECT
    NOW() AS mysql_now,
    UTC_TIMESTAMP() AS mysql_utc,
    TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS mysql_offset_minutes,
    @@session.time_zone AS session_time_zone,
    @@global.time_zone AS global_time_zone;

SELECT
    a.id AS agent_id,
    a.name AS agent_name,
    a.instance_id,
    a.cooldown_seconds,
    MAX(CASE WHEN l.status = 'success' THEN l.created_at END) AS last_success_db,
    MAX(CASE WHEN l.status = 'error' THEN l.created_at END) AS last_error_db
FROM ai_agents a
LEFT JOIN ai_automation_logs l ON l.agent_id = a.id
WHERE a.tenant_id = @tenant_id AND a.id = @agent_id
GROUP BY a.id, a.name, a.instance_id, a.cooldown_seconds;

-- Conversas realmente sem resposta posterior e cujo último evento da IA é ai.cooldown.
SELECT
    c.id AS conversation_id,
    ct.name AS contact_name,
    COUNT(cm.id) AS incoming_messages_without_reply,
    MIN(cm.sent_at) AS waiting_since,
    MAX(cm.sent_at) AS latest_incoming,
    (
        SELECT al.event
        FROM ai_automation_logs al
        WHERE al.tenant_id = c.tenant_id
          AND al.conversation_id = c.id
          AND al.agent_id = @agent_id
        ORDER BY al.id DESC
        LIMIT 1
    ) AS latest_ai_event
FROM conversations c
INNER JOIN contacts ct ON ct.id = c.contact_id
INNER JOIN conversation_messages cm
    ON cm.conversation_id = c.id
   AND cm.tenant_id = c.tenant_id
WHERE c.tenant_id = @tenant_id
  AND c.attendance_mode = 'ai'
  AND c.status <> 'closed'
  AND cm.direction = 'incoming'
  AND cm.message_type <> 'reaction'
  AND (
        SELECT al.event
        FROM ai_automation_logs al
        WHERE al.tenant_id = c.tenant_id
          AND al.conversation_id = c.id
          AND al.agent_id = @agent_id
        ORDER BY al.id DESC
        LIMIT 1
  ) = 'ai.cooldown'
  AND NOT EXISTS (
        SELECT 1
        FROM conversation_messages outgoing
        WHERE outgoing.conversation_id = c.id
          AND outgoing.direction = 'outgoing'
          AND (
                outgoing.sent_at > cm.sent_at
                OR (outgoing.sent_at = cm.sent_at AND outgoing.id > cm.id)
          )
  )
GROUP BY c.id, ct.name
ORDER BY waiting_since;
