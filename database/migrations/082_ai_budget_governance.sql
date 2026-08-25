-- RS Connect 36.19.2 — governança de orçamento de IA por empresa
-- 1) Define orçamento mensal/ciclo por empresa para IA custeada pela RS Connect.
-- 2) Permite alertar, forçar modo Econômico ou bloquear somente novas chamadas com credencial RS.
-- 3) Mantém atendimento humano, regras locais, cache e credenciais próprias funcionando.

CREATE TABLE IF NOT EXISTS tenant_ai_budget_policies (
    tenant_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    monthly_budget_usd DECIMAL(12,4) NULL,
    warning_percent TINYINT UNSIGNED NOT NULL DEFAULT 80,
    critical_percent TINYINT UNSIGNED NOT NULL DEFAULT 95,
    hard_limit_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
    warning_action ENUM('none','economy') NOT NULL DEFAULT 'none',
    hard_limit_action ENUM('notify_only','economy','block_rs_ai') NOT NULL DEFAULT 'notify_only',
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tenant_ai_budget_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_ai_budget_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_budget_threshold_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    threshold_percent TINYINT UNSIGNED NOT NULL,
    budget_usd DECIMAL(12,4) NOT NULL,
    used_usd DECIMAL(14,6) NOT NULL,
    action_taken VARCHAR(40) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_budget_threshold (tenant_id, period_start, threshold_percent, budget_usd),
    KEY idx_ai_budget_period (period_start, period_end),
    CONSTRAINT fk_ai_budget_threshold_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
