-- RS Connect 36.11.0 — Auditoria de integridade e isolamento entre empresas
-- Somente leitura. O resultado esperado para todas as linhas é quantidade = 0.

SELECT 'contato x instancia' AS verificacao, COUNT(*) AS quantidade
FROM contacts c
INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id
WHERE c.tenant_id <> i.tenant_id
UNION ALL
SELECT 'conversa x contato', COUNT(*)
FROM conversations c
INNER JOIN contacts ct ON ct.id = c.contact_id
WHERE c.tenant_id <> ct.tenant_id
UNION ALL
SELECT 'conversa x instancia', COUNT(*)
FROM conversations c
INNER JOIN evolution_instances i ON i.id = c.evolution_instance_id
WHERE c.tenant_id <> i.tenant_id
UNION ALL
SELECT 'conversa x responsavel', COUNT(*)
FROM conversations c
INNER JOIN users u ON u.id = c.assigned_user_id
WHERE c.tenant_id <> u.tenant_id
UNION ALL
SELECT 'mensagem x conversa', COUNT(*)
FROM conversation_messages m
INNER JOIN conversations c ON c.id = m.conversation_id
WHERE m.tenant_id <> c.tenant_id
UNION ALL
SELECT 'mensagem x usuario', COUNT(*)
FROM conversation_messages m
INNER JOIN users u ON u.id = m.sender_user_id
WHERE m.tenant_id <> u.tenant_id
UNION ALL
SELECT 'agenda x contato', COUNT(*)
FROM calendar_appointments a
INNER JOIN contacts c ON c.id = a.contact_id
WHERE a.tenant_id <> c.tenant_id
UNION ALL
SELECT 'agenda x conversa', COUNT(*)
FROM calendar_appointments a
INNER JOIN conversations c ON c.id = a.conversation_id
WHERE a.tenant_id <> c.tenant_id
UNION ALL
SELECT 'agenda x profissional', COUNT(*)
FROM calendar_appointments a
INNER JOIN users u ON u.id = a.owner_user_id
WHERE a.tenant_id <> u.tenant_id
UNION ALL
SELECT 'crm lead x contato', COUNT(*)
FROM crm_leads l
INNER JOIN contacts c ON c.id = l.contact_id
WHERE l.tenant_id <> c.tenant_id
UNION ALL
SELECT 'crm lead x pipeline', COUNT(*)
FROM crm_leads l
INNER JOIN crm_pipelines p ON p.id = l.pipeline_id
WHERE l.tenant_id <> p.tenant_id
UNION ALL
SELECT 'crm lead x etapa', COUNT(*)
FROM crm_leads l
INNER JOIN crm_stages s ON s.id = l.stage_id
WHERE l.tenant_id <> s.tenant_id
UNION ALL
SELECT 'crm tarefa x contato', COUNT(*)
FROM crm_tasks t
INNER JOIN contacts c ON c.id = t.contact_id
WHERE t.tenant_id <> c.tenant_id
UNION ALL
SELECT 'crm tarefa x lead', COUNT(*)
FROM crm_tasks t
INNER JOIN crm_leads l ON l.id = t.lead_id
WHERE t.tenant_id <> l.tenant_id
UNION ALL
SELECT 'crm tarefa x responsavel', COUNT(*)
FROM crm_tasks t
INNER JOIN users u ON u.id = t.assigned_user_id
WHERE t.tenant_id <> u.tenant_id
UNION ALL
SELECT 'perfil de agenda x usuario', COUNT(*)
FROM user_calendar_profiles p
INNER JOIN users u ON u.id = p.user_id
WHERE p.tenant_id <> u.tenant_id;

-- Tentativas bloqueadas pelo hardening. Esta consulta pode retornar registros
-- quando alguém alterar manualmente um UUID/ID para um registro de outra empresa.
SELECT
    id,
    tenant_id,
    user_id,
    event,
    severity,
    context_json,
    ip_address,
    created_at
FROM security_events
WHERE event = 'tenant.cross_scope_access_blocked'
ORDER BY id DESC
LIMIT 50;
