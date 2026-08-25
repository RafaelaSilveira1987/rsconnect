-- Diagnóstico RS Connect 36.19.3 — margem comercial de IA
SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_ai_commercial_policies'),
    'OK', 'PENDENTE'
) AS estrutura_margem_comercial_ia;

SELECT
    t.id AS tenant_id,
    t.name AS empresa,
    sp.name AS plano,
    ts.billing_cycle AS ciclo,
    ts.amount AS valor_contratado,
    p.revenue_source AS origem_receita,
    p.monthly_revenue_brl AS receita_manual_brl,
    p.other_monthly_cost_brl AS outros_custos_brl,
    p.target_margin_percent AS margem_alvo_percent,
    p.warning_margin_percent AS margem_atencao_percent,
    p.usd_brl_rate AS cambio_override
FROM tenants t
LEFT JOIN tenant_ai_commercial_policies p ON p.tenant_id = t.id
LEFT JOIN tenant_subscriptions ts ON ts.id = (
    SELECT ts2.id FROM tenant_subscriptions ts2
    WHERE ts2.tenant_id = t.id
      AND ts2.billing_status IN ('trialing','active','overdue','suspended')
    ORDER BY FIELD(ts2.billing_status,'active','trialing','overdue','suspended'), ts2.id DESC
    LIMIT 1
)
LEFT JOIN saas_plans sp ON sp.id = ts.plan_id
WHERE t.status = 'active'
ORDER BY t.name;

SELECT
    e.tenant_id,
    t.name AS empresa,
    ROUND(SUM(CASE WHEN e.credential_owner = 'rs_connect' AND e.estimated_cost_currency = 'USD' THEN COALESCE(e.estimated_cost,0) ELSE 0 END), 6) AS custo_ia_rs_mes_usd,
    SUM(CASE WHEN e.credential_owner = 'rs_connect' THEN COALESCE(e.provider_calls,0) ELSE 0 END) AS chamadas_rs,
    SUM(CASE WHEN e.credential_owner = 'rs_connect' THEN COALESCE(e.total_tokens,0) ELSE 0 END) AS tokens_rs
FROM ai_usage_events e
JOIN tenants t ON t.id = e.tenant_id
WHERE e.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
  AND e.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
GROUP BY e.tenant_id, t.name
ORDER BY custo_ia_rs_mes_usd DESC;
