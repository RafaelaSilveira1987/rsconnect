-- Diagnóstico RS Connect 36.9.1 — Base histórica e métricas por profissional

SELECT 'Estrutura' AS secao,
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversation_assignment_history') AS assignment_history,
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversation_status_history') AS status_history,
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_appointment_history') AS appointment_history;

SELECT
    t.id AS tenant_id,
    t.name AS empresa,
    COUNT(DISTINCT c.id) AS conversas,
    SUM(c.first_incoming_at IS NOT NULL) AS conversas_com_entrada,
    SUM(c.first_response_at IS NOT NULL) AS conversas_com_primeira_resposta,
    SUM(c.status = 'closed') AS conversas_encerradas,
    ROUND(AVG(CASE
        WHEN c.first_incoming_at IS NOT NULL AND c.first_response_at IS NOT NULL
        THEN TIMESTAMPDIFF(SECOND, c.first_incoming_at, c.first_response_at)
        ELSE NULL
    END) / 60, 2) AS resposta_media_minutos
FROM tenants t
LEFT JOIN conversations c ON c.tenant_id = t.id
GROUP BY t.id, t.name
ORDER BY t.name;

SELECT
    t.name AS empresa,
    COALESCE(u.name, 'Sem profissional') AS profissional,
    COUNT(DISTINCT ah.conversation_id) AS conversas_atribuidas,
    SUM(ah.action = 'transfer') AS transferencias_recebidas,
    MIN(ah.occurred_at) AS primeira_atribuicao,
    MAX(ah.occurred_at) AS ultima_atribuicao
FROM conversation_assignment_history ah
INNER JOIN tenants t ON t.id = ah.tenant_id
LEFT JOIN users u ON u.id = ah.assigned_user_id
GROUP BY t.id, t.name, u.id, u.name
ORDER BY t.name, profissional;

SELECT
    t.name AS empresa,
    COALESCE(u.name, 'Sem profissional') AS profissional,
    COUNT(*) AS agendamentos,
    SUM(a.status = 'confirmed') AS confirmados,
    SUM(a.status = 'completed') AS concluidos,
    SUM(a.status = 'cancelled') AS cancelados,
    SUM(a.status = 'no_show') AS nao_compareceu
FROM calendar_appointments a
INNER JOIN tenants t ON t.id = a.tenant_id
LEFT JOIN users u ON u.id = a.owner_user_id
GROUP BY t.id, t.name, u.id, u.name
ORDER BY t.name, profissional;

SELECT
    p.permission_key,
    rp.role,
    rp.allowed
FROM permissions p
LEFT JOIN role_permissions rp ON rp.permission_id = p.id AND rp.tenant_id IS NULL
WHERE p.permission_key IN ('reports.team.view_own', 'reports.team.view_all')
ORDER BY p.permission_key, rp.role;
