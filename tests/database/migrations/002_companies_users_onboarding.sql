USE rs_connect;

-- Execute este arquivo UMA ÚNICA VEZ sobre o banco criado pelo ZIP 01.

ALTER TABLE tenants
    ADD COLUMN legal_name VARCHAR(190) NULL AFTER name,
    ADD COLUMN email VARCHAR(190) NULL AFTER document,
    ADD COLUMN phone VARCHAR(30) NULL AFTER email,
    ADD COLUMN website VARCHAR(255) NULL AFTER phone,
    ADD COLUMN segment VARCHAR(120) NULL AFTER website,
    ADD COLUMN onboarding_step TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN onboarding_completed_at DATETIME NULL AFTER onboarding_step;

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL,
    category VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_permissions_category (category)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,
    role VARCHAR(30) NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_role_permissions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    INDEX idx_role_permissions_lookup (tenant_id, role, permission_id),
    INDEX idx_role_permissions_role (role)
) ENGINE=InnoDB;

CREATE TABLE ai_agents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    instance_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    segment VARCHAR(120) NOT NULL,
    model_provider ENUM('google', 'openai', 'anthropic', 'custom') NOT NULL DEFAULT 'google',
    model_name VARCHAR(120) NOT NULL DEFAULT 'gemini-2.0-flash',
    temperature DECIMAL(3,2) NOT NULL DEFAULT 0.20,
    system_prompt TEXT NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_agents_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_agents_instance FOREIGN KEY (instance_id) REFERENCES evolution_instances(id) ON DELETE SET NULL,
    INDEX idx_agents_tenant_status (tenant_id, status),
    INDEX idx_agents_instance (instance_id)
) ENGINE=InnoDB;

INSERT INTO permissions (permission_key, name, description, category) VALUES
('dashboard.view', 'Visualizar dashboard', 'Acessar os indicadores principais da empresa.', 'Painel'),
('company.view', 'Visualizar empresa', 'Consultar os dados cadastrais da própria empresa.', 'Empresa'),
('company.manage', 'Editar empresa', 'Alterar os dados cadastrais da própria empresa.', 'Empresa'),
('users.view', 'Visualizar usuários', 'Consultar os usuários da própria empresa.', 'Usuários'),
('users.manage', 'Gerenciar usuários', 'Cadastrar, editar, inativar e redefinir senha de usuários.', 'Usuários'),
('permissions.view', 'Visualizar permissões', 'Consultar a matriz de permissões dos perfis.', 'Usuários'),
('onboarding.manage', 'Executar onboarding', 'Configurar empresa, instância e primeiro agente.', 'Implantação'),
('instances.view', 'Visualizar instâncias', 'Consultar conexões da Evolution da própria empresa.', 'WhatsApp'),
('instances.manage', 'Gerenciar instâncias', 'Cadastrar instâncias e enviar mensagens de teste.', 'WhatsApp'),
('agents.view', 'Visualizar agentes', 'Consultar configurações dos agentes de IA.', 'Inteligência artificial'),
('agents.manage', 'Gerenciar agentes', 'Cadastrar, ativar e definir o agente padrão.', 'Inteligência artificial');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', id, 1 FROM permissions;

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', id, 1
FROM permissions
WHERE permission_key IN (
    'dashboard.view', 'company.view', 'permissions.view',
    'instances.view', 'agents.view'
);

UPDATE tenants
SET onboarding_step = CASE
    WHEN EXISTS (SELECT 1 FROM evolution_instances i WHERE i.tenant_id = tenants.id) THEN 3
    ELSE 1
END;
