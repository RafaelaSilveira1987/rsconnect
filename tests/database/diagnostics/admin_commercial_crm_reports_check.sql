USE rs_connect;

SELECT 'etapas_crm_rs' AS indicador, COUNT(*) AS total FROM admin_crm_stages
UNION ALL SELECT 'oportunidades_crm_rs', COUNT(*) FROM admin_crm_opportunities
UNION ALL SELECT 'atividades_pendentes', COUNT(*) FROM admin_crm_activities WHERE status = 'pending'
UNION ALL SELECT 'empresas_ativas', COUNT(*) FROM tenants WHERE status = 'active'
UNION ALL SELECT 'assinaturas_ativas', COUNT(*) FROM tenant_subscriptions WHERE billing_status IN ('active','trialing')
UNION ALL SELECT 'mensagens_30_dias', COUNT(*) FROM conversation_messages WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY);

SELECT s.name AS etapa, COUNT(o.id) AS oportunidades, COALESCE(SUM(o.value),0) AS valor
FROM admin_crm_stages s
LEFT JOIN admin_crm_opportunities o ON o.stage_id = s.id
GROUP BY s.id, s.name, s.position
ORDER BY s.position;
