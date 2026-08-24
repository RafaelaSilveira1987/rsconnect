-- Diagnóstico ZIP 34.5 — Fluxo, grupos e pré-agendamento
SELECT id, tenant_id, name, phone, status, contact_group, tags_json
FROM contacts
ORDER BY updated_at DESC
LIMIT 30;

SELECT cfs.*, c.remote_jid, ct.name AS contact_name, ct.contact_group
FROM conversation_flow_states cfs
INNER JOIN conversations c ON c.id = cfs.conversation_id
INNER JOIN contacts ct ON ct.id = cfs.contact_id
ORDER BY cfs.updated_at DESC
LIMIT 30;

SELECT agr.*, a.name AS agent_name, t.name AS tenant_name
FROM ai_agent_group_rules agr
INNER JOIN ai_agents a ON a.id = agr.agent_id
INNER JOIN tenants t ON t.id = agr.tenant_id
ORDER BY t.name, a.name, agr.contact_group;

SELECT ca.id, ca.tenant_id, ca.conversation_id, ca.contact_id, ca.title, ca.status,
       ca.preferred_day_text, ca.preferred_time_text, ca.created_at
FROM calendar_appointments ca
WHERE ca.is_pre_schedule = 1
ORDER BY ca.id DESC
LIMIT 30;
