-- Diagnóstico opcional. Não altera dados.
-- Execute apenas se a tela de Conversas continuar apresentando erro.

SELECT id, name, email, tenant_id, role, status
FROM users
ORDER BY tenant_id, id;

SELECT
    c.id AS conversation_id,
    c.tenant_id AS conversation_tenant,
    ct.tenant_id AS contact_tenant,
    i.tenant_id AS instance_tenant,
    c.contact_id,
    c.evolution_instance_id
FROM conversations c
LEFT JOIN contacts ct ON ct.id = c.contact_id
LEFT JOIN evolution_instances i ON i.id = c.evolution_instance_id
WHERE ct.id IS NULL
   OR i.id IS NULL
   OR ct.tenant_id <> c.tenant_id
   OR i.tenant_id <> c.tenant_id
ORDER BY c.id DESC;

SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      (TABLE_NAME = 'conversations' AND COLUMN_NAME IN ('crm_lead_id', 'agenda_intent_detected'))
      OR (TABLE_NAME = 'crm_leads' AND COLUMN_NAME IN ('tenant_id', 'title', 'status', 'value', 'priority', 'pipeline_id', 'stage_id'))
      OR (TABLE_NAME = 'crm_stages' AND COLUMN_NAME IN ('tenant_id', 'name'))
  )
ORDER BY TABLE_NAME, COLUMN_NAME;
