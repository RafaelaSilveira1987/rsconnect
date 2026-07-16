-- ZIP 32.1 — Acompanhamento administrativo das empresas
-- Permite marcar atenção, acompanhamento e correção sem perder a classificação automática.

CREATE TABLE IF NOT EXISTS tenant_admin_tracking (
    tenant_id BIGINT UNSIGNED NOT NULL,
    tracking_status ENUM('automatic','attention','reviewed','resolved') NOT NULL DEFAULT 'automatic',
    priority ENUM('attention','critical','implantation') NOT NULL DEFAULT 'attention',
    note VARCHAR(500) NULL,
    acknowledged_at DATETIME NULL,
    resolved_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_tenant_admin_tracking_status (tracking_status, priority),
    CONSTRAINT fk_tenant_admin_tracking_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_admin_tracking_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
