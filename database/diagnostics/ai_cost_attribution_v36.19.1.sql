-- Diagnóstico RS Connect 36.19.1 — custo atribuído por empresa e assistente.

SELECT CASE
    WHEN COUNT(*) = 0 THEN 'OK'
    ELSE 'ATENCAO'
END AS status,
COUNT(*) AS eventos_openai_sem_custo_conhecido
FROM ai_usage_events
WHERE provider = 'openai'
  AND COALESCE(provider_calls,0) > 0
  AND (COALESCE(input_tokens,0) > 0 OR COALESCE(output_tokens,0) > 0)
  AND estimated_cost IS NULL
  AND (
        LOWER(model) LIKE 'gpt-4o-mini%'
     OR LOWER(model) LIKE 'gpt-4o%'
     OR LOWER(model) LIKE 'gpt-4.1%'
     OR LOWER(model) LIKE 'gpt-5.6%'
     OR LOWER(model) LIKE 'gpt-5-mini%'
     OR LOWER(model) = 'gpt-5'
     OR LOWER(model) LIKE 'gpt-5-20%'
  )
  AND LOWER(model) NOT LIKE '%tts%'
  AND LOWER(model) NOT LIKE '%transcribe%'
  AND LOWER(model) NOT LIKE '%realtime%';

SELECT
    t.name AS empresa,
    COUNT(DISTINCT e.conversation_id) AS conversas,
    SUM(COALESCE(e.input_tokens,0)) AS tokens_entrada,
    SUM(COALESCE(e.output_tokens,0)) AS tokens_saida,
    SUM(COALESCE(e.total_tokens,0)) AS tokens_total,
    ROUND(SUM(COALESCE(e.estimated_cost,0)), 6) AS custo_estimado_usd
FROM ai_usage_events e
LEFT JOIN tenants t ON t.id = e.tenant_id
WHERE e.provider = 'openai'
  AND e.created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
GROUP BY e.tenant_id, t.name
ORDER BY custo_estimado_usd DESC, tokens_total DESC;

SELECT
    COALESCE(a.name, 'Sem assistente') AS assistente,
    COALESCE(t.name, 'Empresa') AS empresa,
    SUM(COALESCE(e.total_tokens,0)) AS tokens_total,
    ROUND(SUM(COALESCE(e.estimated_cost,0)), 6) AS custo_estimado_usd
FROM ai_usage_events e
LEFT JOIN ai_agents a ON a.id = e.agent_id
LEFT JOIN tenants t ON t.id = e.tenant_id
WHERE e.provider = 'openai'
  AND e.created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
GROUP BY e.agent_id, a.name, e.tenant_id, t.name
ORDER BY custo_estimado_usd DESC, tokens_total DESC;
