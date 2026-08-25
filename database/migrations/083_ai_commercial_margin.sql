-- RS Connect 36.19.3 — gestão comercial da franquia de IA por empresa
-- Margem conhecida = receita de referência - custo projetado IA RS - outros custos informados.

CREATE TABLE IF NOT EXISTS tenant_ai_commercial_policies (
    tenant_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    revenue_source ENUM('subscription','manual') NOT NULL DEFAULT 'subscription',
    monthly_revenue_brl DECIMAL(12,2) NULL,
    other_monthly_cost_brl DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    target_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 60.00,
    warning_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 40.00,
    usd_brl_rate DECIMAL(12,6) NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_ai_commercial_updated_by (updated_by_user_id),
    CONSTRAINT fk_ai_commercial_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_commercial_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
