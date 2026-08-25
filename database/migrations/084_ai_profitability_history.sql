-- RS Connect 36.20.0 — rentabilidade histórica, MRR e simulação comercial da IA.
-- Mantém histórico das políticas comerciais e snapshots mensais para preservar a leitura mês a mês.

CREATE TABLE IF NOT EXISTS tenant_ai_commercial_policy_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    effective_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    revenue_source ENUM('subscription','manual') NOT NULL DEFAULT 'subscription',
    monthly_revenue_brl DECIMAL(12,2) NULL,
    other_monthly_cost_brl DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    target_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 60.00,
    warning_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 40.00,
    usd_brl_rate DECIMAL(12,6) NULL,
    changed_by_user_id BIGINT UNSIGNED NULL,
    source ENUM('migration','user','system') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_commercial_history_tenant_effective (tenant_id, effective_at),
    KEY idx_ai_commercial_history_user (changed_by_user_id),
    CONSTRAINT fk_ai_commercial_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_commercial_history_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_ai_commercial_policy_history
    (tenant_id, effective_at, enabled, revenue_source, monthly_revenue_brl, other_monthly_cost_brl,
     target_margin_percent, warning_margin_percent, usd_brl_rate, changed_by_user_id, source)
SELECT p.tenant_id,
       COALESCE(p.created_at, NOW()),
       p.enabled,
       p.revenue_source,
       p.monthly_revenue_brl,
       p.other_monthly_cost_brl,
       p.target_margin_percent,
       p.warning_margin_percent,
       p.usd_brl_rate,
       p.updated_by_user_id,
       'migration'
FROM tenant_ai_commercial_policies p
WHERE NOT EXISTS (
    SELECT 1
    FROM tenant_ai_commercial_policy_history h
    WHERE h.tenant_id = p.tenant_id
);

CREATE TABLE IF NOT EXISTS tenant_ai_profitability_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    period_month DATE NOT NULL,
    revenue_brl DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    revenue_source VARCHAR(40) NOT NULL DEFAULT 'unknown',
    revenue_quality ENUM('actual','contracted','estimated','missing') NOT NULL DEFAULT 'missing',
    ai_cost_usd DECIMAL(16,8) NOT NULL DEFAULT 0.00000000,
    usd_brl_rate DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    ai_cost_brl DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    other_cost_brl DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    known_cost_brl DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    contribution_brl DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    margin_percent DECIMAL(8,3) NULL,
    target_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 60.00,
    provider_calls BIGINT UNSIGNED NOT NULL DEFAULT 0,
    avoided_calls BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ai_conversations BIGINT UNSIGNED NOT NULL DEFAULT 0,
    plan_id BIGINT UNSIGNED NULL,
    plan_key VARCHAR(80) NULL,
    plan_name VARCHAR(120) NULL,
    subscription_id BIGINT UNSIGNED NULL,
    source_json JSON NULL,
    calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_profitability_tenant_month (tenant_id, period_month),
    KEY idx_ai_profitability_month (period_month),
    KEY idx_ai_profitability_margin (margin_percent),
    CONSTRAINT fk_ai_profitability_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
