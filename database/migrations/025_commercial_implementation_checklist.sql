-- ZIP 22 — Checklist Comercial de Implantação
-- Execute uma vez após aplicar o ZIP 22.

CREATE TABLE IF NOT EXISTS tenant_implementation_status (
    tenant_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','configuring','testing','ready','attention') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
    percent_complete TINYINT UNSIGNED NOT NULL DEFAULT 0,
    attention_count INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT COLLATE utf8mb4_unicode_ci NULL,
    last_checked_at DATETIME NULL,
    ready_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_implementation_status (status, percent_complete),
    CONSTRAINT fk_impl_status_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_impl_status_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_implementation_checklist (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    item_key VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    label VARCHAR(160) COLLATE utf8mb4_unicode_ci NOT NULL,
    category VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
    manual_status ENUM('auto','pending','complete','skipped','attention') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
    notes TEXT COLLATE utf8mb4_unicode_ci NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_impl_checklist_tenant_item (tenant_id, item_key),
    KEY idx_impl_checklist_tenant_status (tenant_id, manual_status),
    CONSTRAINT fk_impl_checklist_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_impl_checklist_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenant_implementation_status (tenant_id, status, percent_complete, attention_count, last_checked_at)
SELECT id, 'pending', 0, 0, NOW()
FROM tenants;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('implementation.view', 'Visualizar implantação', 'Acessar checklist comercial de implantação dos clientes.', 'Implantação'),
    ('implementation.manage', 'Gerenciar implantação', 'Recalcular e marcar itens do checklist de implantação.', 'Implantação');

INSERT INTO system_incidents (event, severity, message)
SELECT 'implementation.checklist_enabled', 'info', 'ZIP 22 aplicado: checklist comercial de implantação habilitado.'
WHERE EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_incidents')
  AND NOT EXISTS (SELECT 1 FROM system_incidents WHERE event = 'implementation.checklist_enabled' LIMIT 1);
