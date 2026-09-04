-- RS Connect 36.27.15 — corrige vínculos existentes de agentes cuja área é agendamento.
-- O papel multiagente é inferido por routing_keywords: quando o agente não é principal,
-- está ativo e sua área contém "agend", o vínculo sem direcionamento passa a atuar como
-- especialista de agenda. Regras já configuradas pelo usuário não são alteradas.

UPDATE ai_agent_instance_bindings b
INNER JOIN ai_agents a
        ON a.id = b.agent_id
       AND a.tenant_id = b.tenant_id
SET b.routing_keywords = 'agendar, agendamento, marcar, remarcar, reagendar, reservar'
WHERE b.status = 'active'
  AND b.is_primary = 0
  AND (b.routing_keywords IS NULL OR TRIM(b.routing_keywords) = '')
  AND a.status = 'active'
  AND LOWER(TRIM(a.segment)) LIKE '%agend%';
