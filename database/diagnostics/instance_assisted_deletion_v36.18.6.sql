-- RS Connect v36.18.6 — diagnóstico da exclusão assistida
-- Somente leitura. Pode ser executado no Adminer.

SELECT
    i.id,
    i.tenant_id,
    i.name,
    i.instance_name,
    i.status,
    i.is_default,
    (SELECT COUNT(*) FROM ai_agents a WHERE a.instance_id = i.id) AS agentes_legado,
    (SELECT COUNT(*) FROM ai_agent_instance_bindings b WHERE b.instance_id = i.id) AS vinculos_assistentes,
    (SELECT COUNT(*) FROM contacts ct WHERE ct.evolution_instance_id = i.id) AS contatos,
    (SELECT COUNT(*) FROM conversations cv WHERE cv.evolution_instance_id = i.id) AS conversas,
    (SELECT COUNT(*) FROM message_campaigns mc WHERE mc.evolution_instance_id = i.id) AS campanhas,
    (SELECT COUNT(*) FROM scheduled_reports sr WHERE sr.evolution_instance_id = i.id) AS relatorios_agendados,
    (SELECT COUNT(*) FROM evolution_connection_events ev WHERE ev.evolution_instance_id = i.id) AS eventos_tecnicos
FROM evolution_instances i
ORDER BY i.tenant_id, i.is_default DESC, i.name;

SELECT
    source.tenant_id,
    source.evolution_instance_id AS instancia_origem,
    target.evolution_instance_id AS instancia_destino,
    COUNT(*) AS conversas_que_exigiriam_consolidacao
FROM conversations source
INNER JOIN conversations target
    ON target.tenant_id = source.tenant_id
   AND target.remote_jid = source.remote_jid
   AND target.evolution_instance_id <> source.evolution_instance_id
WHERE source.id < target.id
GROUP BY source.tenant_id, source.evolution_instance_id, target.evolution_instance_id
ORDER BY conversas_que_exigiriam_consolidacao DESC;

SELECT
    tenant_id,
    SUM(is_default = 1) AS conexoes_padrao,
    COUNT(*) AS total_conexoes,
    CASE WHEN SUM(is_default = 1) <= 1 THEN 'OK' ELSE 'REVISAR' END AS diagnostico
FROM evolution_instances
GROUP BY tenant_id
ORDER BY tenant_id;
