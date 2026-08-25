-- Diagnóstico RS Connect 36.20.0 — rentabilidade histórica e simulação de planos.
SELECT
    CASE
        WHEN EXISTS (
            SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_ai_profitability_snapshots'
        )
        AND EXISTS (
            SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_ai_commercial_policy_history'
        )
        THEN 'OK' ELSE 'PENDENTE'
    END AS estrutura_rentabilidade_historica;

SELECT
    COUNT(*) AS politicas_historicas,
    COUNT(DISTINCT tenant_id) AS empresas_com_historico
FROM tenant_ai_commercial_policy_history;

SELECT
    t.name AS empresa,
    s.period_month,
    s.revenue_brl,
    s.ai_cost_usd,
    s.ai_cost_brl,
    s.other_cost_brl,
    s.contribution_brl,
    s.margin_percent,
    s.provider_calls,
    s.avoided_calls,
    s.total_tokens,
    s.revenue_source,
    s.revenue_quality
FROM tenant_ai_profitability_snapshots s
INNER JOIN tenants t ON t.id = s.tenant_id
ORDER BY s.period_month DESC, t.name
LIMIT 100;
