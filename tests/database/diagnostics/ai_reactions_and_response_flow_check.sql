-- Diagnóstico ZIP 34.3.1 — Intervalos, pendências e reações
SELECT
    id, tenant_id, instance_id, name, status, auto_reply_enabled,
    cooldown_seconds, reply_to_reactions, updated_at
FROM ai_agents
ORDER BY tenant_id, is_default DESC, id DESC;

SELECT
    event,
    status,
    COUNT(*) AS total,
    MAX(created_at) AS ultimo_registro
FROM ai_automation_logs
WHERE event IN ('ai.cooldown', 'ai.replied', 'ai.failed')
GROUP BY event, status
ORDER BY event, status;

SELECT
    c.id AS conversation_id,
    c.tenant_id,
    c.evolution_instance_id,
    c.attendance_mode,
    c.status,
    incoming.id AS ultima_mensagem_recebida_id,
    incoming.message_type,
    incoming.sent_at AS ultima_mensagem_recebida_em,
    (
        SELECT MAX(outgoing.sent_at)
        FROM conversation_messages outgoing
        WHERE outgoing.conversation_id = c.id
          AND outgoing.direction = 'outgoing'
    ) AS ultima_resposta_em
FROM conversations c
INNER JOIN conversation_messages incoming ON incoming.id = (
    SELECT cm.id
    FROM conversation_messages cm
    WHERE cm.conversation_id = c.id
      AND cm.direction = 'incoming'
    ORDER BY cm.sent_at DESC, cm.id DESC
    LIMIT 1
)
WHERE EXISTS (
    SELECT 1
    FROM ai_automation_logs al
    WHERE al.conversation_id = c.id
      AND al.event = 'ai.cooldown'
)
ORDER BY incoming.sent_at DESC
LIMIT 50;
