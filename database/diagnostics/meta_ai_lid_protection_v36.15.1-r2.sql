-- RS Connect v36.15.1-r2
-- Diagnóstico somente leitura para contatos LID, Meta AI e falhas exists:false.

SELECT
    c.id AS conversation_id,
    c.tenant_id,
    t.name AS empresa,
    c.remote_jid,
    ct.id AS contact_id,
    ct.name AS contato,
    ct.phone,
    c.status AS conversation_status,
    c.attendance_mode,
    c.last_message_at
FROM conversations c
INNER JOIN tenants t ON t.id = c.tenant_id
LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
WHERE LOWER(COALESCE(c.remote_jid, '')) LIKE '%@lid'
   OR LOWER(COALESCE(ct.name, '')) IN ('meta ai', 'meta ia', 'ia da meta')
ORDER BY c.last_message_at DESC, c.id DESC;

SELECT
    al.id,
    al.created_at,
    al.tenant_id,
    al.conversation_id,
    al.incoming_message_id,
    al.event,
    al.status,
    al.error_message,
    JSON_PRETTY(al.raw_json) AS detalhes
FROM ai_automation_logs al
WHERE al.event = 'ai.recipient.unavailable'
   OR LOWER(COALESCE(al.error_message, '')) LIKE '%"exists":false%'
ORDER BY al.id DESC
LIMIT 100;
