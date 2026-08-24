USE rs_connect;

-- ZIP 23 — Onboarding guiado do cliente
-- Execute após 026_fix_implementation_manual_checklist_table.sql.

CREATE TABLE IF NOT EXISTS tenant_onboarding_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    step_key VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
    status ENUM('auto','pending','complete','skipped','attention') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
    notes TEXT COLLATE utf8mb4_unicode_ci NULL,
    completed_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_onboarding_step (tenant_id, step_key),
    KEY idx_tenant_onboarding_status (tenant_id, status),
    KEY idx_tenant_onboarding_updated (tenant_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_onboarding_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    event VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL,
    message VARCHAR(500) COLLATE utf8mb4_unicode_ci NOT NULL,
    context_json LONGTEXT COLLATE utf8mb4_unicode_ci NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant_onboarding_events (tenant_id, created_at),
    KEY idx_tenant_onboarding_event (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('onboarding.manage', 'Executar onboarding', 'Acessar e concluir o onboarding guiado da empresa.', 'Implantação');

INSERT IGNORE INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key = 'onboarding.manage';

-- Evento inicial para empresas existentes que ainda não têm histórico.
INSERT INTO tenant_onboarding_events (tenant_id, user_id, event, message, context_json)
SELECT t.id, NULL, 'onboarding.guided_enabled', 'ZIP 23 aplicado: onboarding guiado habilitado para a empresa.', NULL
FROM tenants t
LEFT JOIN tenant_onboarding_events e
  ON e.tenant_id = t.id AND e.event = 'onboarding.guided_enabled'
WHERE e.id IS NULL;
