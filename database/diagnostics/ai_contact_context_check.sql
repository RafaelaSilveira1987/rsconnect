-- HOTFIX 36.0.4 — confira o contexto cadastral que será usado pela IA.
-- Ajuste os filtros conforme necessário.

SELECT
    t.name AS empresa,
    ct.id AS contact_id,
    ct.name AS contato,
    ct.phone,
    ct.status AS classificacao_codigo,
    CASE ct.status
        WHEN 'lead' THEN 'Lead / novo contato'
        WHEN 'customer' THEN 'Cliente atual'
        WHEN 'inactive' THEN 'Contato inativo'
        ELSE ct.status
    END AS classificacao_exibida,
    ct.contact_group AS grupo_codigo,
    CASE ct.contact_group
        WHEN 'unclassified' THEN 'Não identificado'
        WHEN 'interested' THEN 'Novo interessado'
        WHEN 'patient' THEN 'Paciente atual'
        WHEN 'family' THEN 'Familiar'
        WHEN 'couple' THEN 'Casal'
        WHEN 'other' THEN 'Outro grupo'
        ELSE ct.contact_group
    END AS grupo_exibido,
    ct.tags_json AS tags,
    ct.updated_at AS contato_atualizado_em
FROM contacts ct
INNER JOIN tenants t ON t.id = ct.tenant_id
ORDER BY ct.updated_at DESC
LIMIT 50;

SELECT
    c.id AS conversation_id,
    ct.name AS contato,
    ct.status AS classificacao,
    ct.contact_group AS grupo,
    ct.tags_json AS tags,
    fs.stage,
    fs.demand_status,
    fs.demand_summary,
    fs.metadata_json,
    fs.updated_at AS contexto_atualizado_em
FROM conversations c
INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
LEFT JOIN conversation_flow_states fs ON fs.conversation_id = c.id AND fs.tenant_id = c.tenant_id
ORDER BY c.last_message_at DESC
LIMIT 50;

SELECT
    l.id,
    l.conversation_id,
    l.event,
    l.status,
    JSON_EXTRACT(l.raw_json, '$.contact_context') AS contexto_enviado_no_ultimo_log,
    l.created_at
FROM ai_automation_logs l
WHERE l.event = 'ai.replied'
ORDER BY l.id DESC
LIMIT 30;
