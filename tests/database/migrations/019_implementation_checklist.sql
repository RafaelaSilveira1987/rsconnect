-- ZIP 19.1 — Checklist de implantação para Super Admin RS
-- Execute uma vez após aplicar o ZIP 19.

CREATE TABLE IF NOT EXISTS tenant_implementation_checklists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    status ENUM('not_started','in_progress','waiting_client','waiting_rs','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
    evolution_webhook_configured TINYINT(1) NOT NULL DEFAULT 0,
    n8n_agenda_configured TINYINT(1) NOT NULL DEFAULT 0,
    n8n_billing_configured TINYINT(1) NOT NULL DEFAULT 0,
    n8n_callback_tested TINYINT(1) NOT NULL DEFAULT 0,
    payment_link_tested TINYINT(1) NOT NULL DEFAULT 0,
    client_trained TINYINT(1) NOT NULL DEFAULT 0,
    environment_validated TINYINT(1) NOT NULL DEFAULT 0,
    implementation_completed TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT COLLATE utf8mb4_unicode_ci NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_impl_checklist_tenant (tenant_id),
    KEY idx_impl_checklist_status (status),
    KEY idx_impl_checklist_updated_by (updated_by_user_id),
    CONSTRAINT fk_impl_checklist_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_impl_checklist_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenant_implementation_checklists (tenant_id, status)
SELECT id, CASE WHEN onboarding_completed_at IS NOT NULL THEN 'in_progress' ELSE 'not_started' END
FROM tenants;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('implementations.view', 'Visualizar implantações', 'Acompanhar checklist de implantação dos clientes.', 'Implantação'),
    ('implementations.manage', 'Gerenciar implantações', 'Atualizar checklist técnico de implantação dos clientes.', 'Implantação');
