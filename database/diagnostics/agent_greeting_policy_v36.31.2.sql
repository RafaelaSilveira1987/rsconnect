-- Diagnóstico RS Connect 36.31.2 — política de saudação dos assistentes.
SELECT
    id,
    tenant_id,
    name,
    ai_greeting_mode,
    ai_greeting_use_contact_name,
    CASE WHEN NULLIF(TRIM(ai_greeting_reply), '') IS NULL THEN 'sem_texto_pronto' ELSE 'texto_configurado' END AS greeting_reply_status
FROM ai_agents
ORDER BY tenant_id, id;
