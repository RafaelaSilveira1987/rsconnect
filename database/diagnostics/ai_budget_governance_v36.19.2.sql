-- Diagnóstico RS Connect 36.19.2 — governança de orçamento por empresa
SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_ai_budget_policies')
    AND EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_budget_threshold_events'),
    'OK', 'PENDENTE'
) AS estrutura_orcamento_ia;

SELECT
    t.id AS tenant_id,
    t.name AS empresa,
    COALESCE(p.enabled, 0) AS politica_ativa,
    p.monthly_budget_usd AS orcamento_usd,
    p.warning_percent,
    p.critical_percent,
    p.hard_limit_percent,
    p.warning_action,
    p.hard_limit_action,
    COALESCE(SUM(CASE
        WHEN e.credential_owner = 'rs_connect'
         AND e.estimated_cost_currency = 'USD'
         AND COALESCE(e.provider_calls,0) > 0
         AND e.created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')
         AND e.created_at < DATE_ADD(LAST_DAY(CURRENT_DATE), INTERVAL 1 DAY)
        THEN COALESCE(e.estimated_cost,0) ELSE 0 END), 0) AS custo_rs_mes_usd
FROM tenants t
LEFT JOIN tenant_ai_budget_policies p ON p.tenant_id = t.id
LEFT JOIN ai_usage_events e ON e.tenant_id = t.id
GROUP BY t.id, t.name, p.enabled, p.monthly_budget_usd, p.warning_percent, p.critical_percent, p.hard_limit_percent, p.warning_action, p.hard_limit_action
ORDER BY custo_rs_mes_usd DESC, t.name;

SELECT *
FROM ai_budget_threshold_events
ORDER BY id DESC
LIMIT 30;
