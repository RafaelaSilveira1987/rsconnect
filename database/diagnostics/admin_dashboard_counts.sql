-- Diagnóstico do Dashboard Admin RS — ZIP 32.1
-- Execute no Adminer para comparar os números do banco com os cards do dashboard.

SELECT
    COUNT(*) AS empresas_total,
    SUM(status = 'active') AS empresas_ativas,
    SUM(status = 'inactive') AS empresas_inativas,
    SUM(status = 'suspended') AS empresas_suspensas
FROM tenants;

SELECT COUNT(*) AS mensagens_ultimas_24h
FROM conversation_messages
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);

SELECT
    COUNT(*) AS conexoes_total,
    SUM(status IN ('connected','open','active','online')) AS conexoes_operacionais
FROM evolution_instances;

SELECT COALESCE(SUM(unread_count), 0) AS conversas_nao_lidas
FROM conversations;

SELECT
    COUNT(*) AS assinaturas_consideradas_ativas,
    COALESCE(SUM(
        CASE ts.billing_cycle
            WHEN 'quarterly' THEN ts.amount / 3
            WHEN 'semiannual' THEN ts.amount / 6
            WHEN 'annual' THEN ts.amount / 12
            ELSE ts.amount
        END
    ), 0) AS receita_mensal_estimada
FROM tenant_subscriptions ts
INNER JOIN (
    SELECT tenant_id, MAX(id) AS max_id
    FROM tenant_subscriptions
    GROUP BY tenant_id
) latest ON latest.max_id = ts.id
WHERE ts.billing_status IN ('active','trialing','overdue');

SELECT
    t.id,
    t.name,
    t.status,
    t.plan,
    t.created_at,
    tat.tracking_status,
    tat.priority,
    tat.note,
    tat.acknowledged_at,
    tat.resolved_at
FROM tenants t
LEFT JOIN tenant_admin_tracking tat ON tat.tenant_id = t.id
ORDER BY t.id DESC;
