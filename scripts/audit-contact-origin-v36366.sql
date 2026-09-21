-- RS Connect 36.36.6 — auditoria de origem de contatos
-- Ajuste @tenant conforme necessário.
SET @tenant := 2;

-- 1) Resumo por origem registrada após a migration 118.
SELECT origin, COUNT(*) AS quantidade
FROM contacts
WHERE tenant_id = @tenant
GROUP BY origin
ORDER BY quantidade DESC;

-- 2) Por instância Evolution: quantos contatos e quantos nunca tiveram conversa.
SELECT
    c.evolution_instance_id,
    i.name AS instancia,
    i.instance_name,
    i.profile_phone AS whatsapp_conectado,
    COUNT(*) AS contatos,
    SUM(CASE WHEN cv.id IS NULL THEN 1 ELSE 0 END) AS sem_conversa,
    MIN(c.created_at) AS primeiro_contato,
    MAX(c.created_at) AS ultimo_contato
FROM contacts c
LEFT JOIN evolution_instances i
       ON i.id = c.evolution_instance_id
      AND i.tenant_id = c.tenant_id
LEFT JOIN conversations cv
       ON cv.tenant_id = c.tenant_id
      AND cv.contact_id = c.id
WHERE c.tenant_id = @tenant
GROUP BY c.evolution_instance_id, i.name, i.instance_name, i.profile_phone
ORDER BY contatos DESC;

-- 3) Registros classificados como sincronização de agenda e sem conversa.
SELECT
    c.id,
    c.name,
    c.phone,
    c.remote_jid,
    c.evolution_instance_id,
    i.instance_name,
    i.profile_phone AS whatsapp_conectado,
    c.created_at,
    c.updated_at
FROM contacts c
LEFT JOIN evolution_instances i
       ON i.id = c.evolution_instance_id
      AND i.tenant_id = c.tenant_id
WHERE c.tenant_id = @tenant
  AND c.origin = 'whatsapp_sync'
  AND NOT EXISTS (
      SELECT 1 FROM conversations cv
      WHERE cv.tenant_id = c.tenant_id AND cv.contact_id = c.id
  )
ORDER BY c.created_at DESC;

-- 4) Somente contagem de candidatos à limpeza. Nenhum DELETE é executado aqui.
SELECT COUNT(*) AS candidatos_limpeza
FROM contacts c
WHERE c.tenant_id = @tenant
  AND c.origin = 'whatsapp_sync'
  AND NOT EXISTS (SELECT 1 FROM conversations cv WHERE cv.tenant_id=c.tenant_id AND cv.contact_id=c.id)
  AND NOT EXISTS (SELECT 1 FROM crm_leads l WHERE l.tenant_id=c.tenant_id AND l.contact_id=c.id)
  AND NOT EXISTS (SELECT 1 FROM crm_notes n WHERE n.tenant_id=c.tenant_id AND n.contact_id=c.id)
  AND NOT EXISTS (SELECT 1 FROM crm_tasks t WHERE t.tenant_id=c.tenant_id AND t.contact_id=c.id)
  AND NOT EXISTS (
      SELECT 1 FROM audit_logs al
      WHERE al.tenant_id=c.tenant_id
        AND al.action='contact.created'
        AND CAST(JSON_UNQUOTE(JSON_EXTRACT(al.context_json, '$.contact_id')) AS UNSIGNED)=c.id
  );
