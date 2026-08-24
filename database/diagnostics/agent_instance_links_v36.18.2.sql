-- RS Connect 36.18.2 — diagnóstico de vínculos entre assistentes e canais WhatsApp

SELECT
    CASE
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'ai_agent_instance_bindings'
        ) THEN 'OK'
        ELSE 'PENDENTE: execute a migration 055_multi_whatsapp_agent_routing.sql'
    END AS estrutura_vinculos;

SELECT
    t.name AS empresa,
    a.id AS assistente_id,
    a.name AS assistente,
    a.status,
    a.is_default,
    COUNT(b.id) AS canais_vinculados,
    GROUP_CONCAT(i.name ORDER BY b.is_primary DESC, i.name SEPARATOR ', ') AS canais
FROM ai_agents a
INNER JOIN tenants t ON t.id = a.tenant_id
LEFT JOIN ai_agent_instance_bindings b
    ON b.agent_id = a.id
   AND b.tenant_id = a.tenant_id
   AND b.status = 'active'
LEFT JOIN evolution_instances i ON i.id = b.instance_id
GROUP BY t.name, a.id, a.name, a.status, a.is_default
ORDER BY t.name, a.is_default DESC, a.name;

SELECT
    t.name AS empresa,
    a.id AS assistente_id,
    a.name AS assistente_sem_canal
FROM ai_agents a
INNER JOIN tenants t ON t.id = a.tenant_id
WHERE a.status = 'active'
  AND NOT EXISTS (
      SELECT 1
      FROM ai_agent_instance_bindings b
      WHERE b.tenant_id = a.tenant_id
        AND b.agent_id = a.id
        AND b.status = 'active'
  )
ORDER BY t.name, a.name;
