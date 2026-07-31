-- RS Connect 36.10.0 — Diagnóstico dos relatórios de equipe e profissionais
-- Ajuste @tenant_id e o período antes de executar no Adminer.

SET @tenant_id = 0;
SET @start_at = DATE_SUB(CURDATE(), INTERVAL 29 DAY);
SET @end_at = DATE_ADD(CURDATE(), INTERVAL 1 DAY);

SELECT
    t.id AS tenant_id,
    t.name AS empresa,
    t.professional_assignment_enabled,
    t.professional_calendar_enabled
FROM tenants t
WHERE (@tenant_id = 0 OR t.id = @tenant_id)
ORDER BY t.name;

SELECT
    u.id AS user_id,
    u.name AS profissional,
    u.whatsapp_display_name,
    u.whatsapp_role_label,
    u.role,
    u.status,
    COUNT(DISTINCT CASE
        WHEN m.direction = 'outgoing'
         AND m.sender_type = 'user'
         AND m.sent_at >= @start_at
         AND m.sent_at < @end_at
        THEN m.conversation_id END) AS conversas_respondidas,
    COUNT(CASE
        WHEN m.direction = 'outgoing'
         AND m.sender_type = 'user'
         AND m.sent_at >= @start_at
         AND m.sent_at < @end_at
        THEN 1 END) AS mensagens_humanas
FROM users u
LEFT JOIN conversation_messages m
       ON m.tenant_id = u.tenant_id
      AND m.sender_user_id = u.id
WHERE (@tenant_id = 0 OR u.tenant_id = @tenant_id)
  AND u.tenant_id IS NOT NULL
GROUP BY u.id, u.name, u.whatsapp_display_name, u.whatsapp_role_label, u.role, u.status
ORDER BY mensagens_humanas DESC, profissional;

SELECT
    u.id AS user_id,
    u.name AS profissional,
    COUNT(c.id) AS primeiras_respostas,
    ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, c.first_incoming_at, c.first_response_at)))) AS tempo_medio_primeira_resposta_segundos
FROM users u
LEFT JOIN conversation_service_cycles c
       ON c.tenant_id = u.tenant_id
      AND c.first_response_user_id = u.id
      AND c.first_response_at >= @start_at
      AND c.first_response_at < @end_at
WHERE (@tenant_id = 0 OR u.tenant_id = @tenant_id)
  AND u.tenant_id IS NOT NULL
GROUP BY u.id, u.name
ORDER BY primeiras_respostas DESC, profissional;

SELECT
    u.id AS user_id,
    u.name AS profissional,
    COUNT(a.id) AS agendamentos,
    SUM(a.status = 'confirmed') AS confirmados,
    SUM(a.status = 'completed') AS concluidos,
    SUM(a.status IN ('cancelled', 'rejected')) AS cancelados,
    SUM(a.status = 'no_show') AS nao_compareceram
FROM users u
LEFT JOIN calendar_appointments a
       ON a.tenant_id = u.tenant_id
      AND a.owner_user_id = u.id
      AND a.starts_at >= @start_at
      AND a.starts_at < @end_at
WHERE (@tenant_id = 0 OR u.tenant_id = @tenant_id)
  AND u.tenant_id IS NOT NULL
GROUP BY u.id, u.name
ORDER BY agendamentos DESC, profissional;

SELECT
    'conversation_service_cycles' AS estrutura,
    COUNT(*) AS registros
FROM conversation_service_cycles
WHERE (@tenant_id = 0 OR tenant_id = @tenant_id)
  AND opened_at < @end_at
  AND COALESCE(closed_at, @end_at) >= @start_at
UNION ALL
SELECT
    'conversation_assignment_history' AS estrutura,
    COUNT(*) AS registros
FROM conversation_assignment_history
WHERE (@tenant_id = 0 OR tenant_id = @tenant_id)
  AND occurred_at >= @start_at
  AND occurred_at < @end_at
UNION ALL
SELECT
    'conversation_status_history', COUNT(*)
FROM conversation_status_history
WHERE (@tenant_id = 0 OR tenant_id = @tenant_id)
  AND occurred_at >= @start_at
  AND occurred_at < @end_at
UNION ALL
SELECT
    'calendar_appointment_history', COUNT(*)
FROM calendar_appointment_history
WHERE (@tenant_id = 0 OR tenant_id = @tenant_id)
  AND occurred_at >= @start_at
  AND occurred_at < @end_at;
