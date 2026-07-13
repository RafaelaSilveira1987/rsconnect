USE rs_connect;

-- ZIP 20 — LGPD e Privacidade
-- Execute após a migration 021_security_system.sql.

CREATE TABLE IF NOT EXISTS tenant_privacy_settings (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    require_company_acceptance TINYINT(1) NOT NULL DEFAULT 1,
    policy_version VARCHAR(40) NOT NULL DEFAULT 'v1',
    privacy_policy_title VARCHAR(190) NOT NULL DEFAULT 'Política de Privacidade',
    privacy_policy_text MEDIUMTEXT NULL,
    terms_title VARCHAR(190) NOT NULL DEFAULT 'Termos de Uso e Tratamento de Dados',
    terms_text MEDIUMTEXT NULL,
    dpo_name VARCHAR(150) NULL,
    dpo_email VARCHAR(190) NULL,
    retention_days INT UNSIGNED NOT NULL DEFAULT 365,
    allow_export_requests TINYINT(1) NOT NULL DEFAULT 1,
    allow_delete_requests TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_privacy_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_privacy_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_terms_acceptances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    policy_version VARCHAR(40) NOT NULL,
    terms_hash CHAR(64) NOT NULL,
    accepted_at DATETIME NOT NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_terms_acceptance_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_terms_acceptance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_user_policy_acceptance (tenant_id, user_id, policy_version),
    INDEX idx_terms_acceptance_tenant (tenant_id, accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS privacy_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    requester_name VARCHAR(150) NULL,
    requester_email VARCHAR(190) NULL,
    requester_phone VARCHAR(40) NULL,
    request_type ENUM('export','delete','anonymize','consent_review','other') NOT NULL DEFAULT 'export',
    status ENUM('open','processing','completed','rejected') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    response_summary TEXT NULL,
    requested_by BIGINT UNSIGNED NULL,
    processed_by BIGINT UNSIGNED NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_privacy_requests_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_privacy_requests_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_privacy_requests_requested_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_privacy_requests_processed_user FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_privacy_requests_tenant_status (tenant_id, status, requested_at),
    INDEX idx_privacy_requests_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS privacy_consents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    conversation_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    source VARCHAR(80) NOT NULL DEFAULT 'manual',
    consent_type VARCHAR(80) NOT NULL DEFAULT 'privacy_policy',
    policy_version VARCHAR(40) NOT NULL DEFAULT 'v1',
    consent_text TEXT NULL,
    accepted TINYINT(1) NOT NULL DEFAULT 1,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_privacy_consents_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_privacy_consents_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_privacy_consents_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_privacy_consents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_privacy_consents_tenant_contact (tenant_id, contact_id),
    INDEX idx_privacy_consents_type (tenant_id, consent_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_privacy_settings
    (tenant_id, require_company_acceptance, policy_version, privacy_policy_text, terms_text, retention_days)
SELECT
    t.id,
    1,
    'v1',
    'Esta empresa utiliza o RS Connect para atendimento, CRM, agenda, automações e gestão de relacionamento. Os dados são tratados para executar o atendimento solicitado, manter histórico operacional, cumprir obrigações contratuais e melhorar a qualidade do serviço. Ajuste este texto conforme a operação da empresa.',
    'Ao acessar o painel, a empresa e seus usuários declaram ciência sobre o uso do RS Connect para tratamento de dados pessoais necessários ao atendimento, gestão de contatos, conversas, agenda, cobrança, automações e registros de auditoria. Ajuste este termo antes de operar comercialmente.',
    365
FROM tenants t
LEFT JOIN tenant_privacy_settings ps ON ps.tenant_id = t.id
WHERE ps.tenant_id IS NULL;

INSERT INTO permissions (permission_key, name, description, category) VALUES
('privacy.view', 'Visualizar privacidade/LGPD', 'Acessar central de privacidade, políticas, solicitações e aceites.', 'Privacidade'),
('privacy.manage', 'Gerenciar privacidade/LGPD', 'Editar políticas, registrar solicitações, concluir pedidos e configurar retenção.', 'Privacidade')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), category = VALUES(category);

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('privacy.view', 'privacy.manage')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('privacy.view')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
  );

INSERT INTO tenant_module_settings (tenant_id, module_key, is_visible, is_enabled)
SELECT t.id, 'privacy', 1, 1
FROM tenants t
WHERE EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_module_settings'
)
ON DUPLICATE KEY UPDATE is_visible = VALUES(is_visible), is_enabled = VALUES(is_enabled), updated_at = CURRENT_TIMESTAMP;
