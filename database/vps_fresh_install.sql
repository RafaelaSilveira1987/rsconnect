-- RS Connect - instalação fresca completa
-- Execute em MySQL/MariaDB se não usar o docker-compose com init automático.
CREATE DATABASE IF NOT EXISTS rs_connect
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE rs_connect;

CREATE TABLE tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    legal_name VARCHAR(190) NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    document VARCHAR(30) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(30) NULL,
    website VARCHAR(255) NULL,
    segment VARCHAR(120) NULL,
    plan ENUM('starter', 'pro', 'business', 'custom') NOT NULL DEFAULT 'starter',
    status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    onboarding_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
    onboarding_completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenants_status (status)
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'client_admin', 'client_user') NOT NULL DEFAULT 'client_user',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_users_tenant (tenant_id),
    INDEX idx_users_role_status (role, status)
) ENGINE=InnoDB;

CREATE TABLE evolution_instances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    instance_name VARCHAR(120) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    api_key_encrypted TEXT NOT NULL,
    status ENUM('connected', 'disconnected', 'pending') NOT NULL DEFAULT 'disconnected',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_instances_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_instance_tenant_name (tenant_id, instance_name),
    INDEX idx_instances_tenant_status (tenant_id, status)
) ENGINE=InnoDB;

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

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    context_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_tenant_date (tenant_id, created_at),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB;

CREATE TABLE contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    evolution_instance_id BIGINT UNSIGNED NULL,
    remote_jid VARCHAR(190) NULL,
    phone VARCHAR(30) NOT NULL,
    name VARCHAR(150) NULL,
    email VARCHAR(190) NULL,
    company VARCHAR(150) NULL,
    notes TEXT NULL,
    tags_json JSON NULL,
    avatar_url VARCHAR(500) NULL,
    status ENUM('lead', 'customer', 'inactive') NOT NULL DEFAULT 'lead',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contacts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_contacts_instance FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE SET NULL,
    UNIQUE KEY uq_contacts_tenant_phone (tenant_id, phone),
    INDEX idx_contacts_tenant_name (tenant_id, name),
    INDEX idx_contacts_instance (evolution_instance_id)
) ENGINE=InnoDB;

CREATE TABLE conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    evolution_instance_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    remote_jid VARCHAR(190) NOT NULL,
    status ENUM('open', 'pending', 'closed') NOT NULL DEFAULT 'open',
    attendance_mode ENUM('ai', 'human', 'paused') NOT NULL DEFAULT 'ai',
    assigned_user_id BIGINT UNSIGNED NULL,
    unread_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_message_at DATETIME NULL,
    last_message_preview VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_conversations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversations_instance FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversations_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversations_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_conversation_instance_jid (evolution_instance_id, remote_jid),
    INDEX idx_conversations_tenant_last (tenant_id, last_message_at),
    INDEX idx_conversations_status_mode (tenant_id, status, attendance_mode),
    INDEX idx_conversations_assignee (assigned_user_id)
) ENGINE=InnoDB;

CREATE TABLE conversation_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    evolution_message_id VARCHAR(190) NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    sender_type ENUM('contact', 'user', 'ai', 'system') NOT NULL,
    sender_user_id BIGINT UNSIGNED NULL,
    message_type VARCHAR(40) NOT NULL DEFAULT 'text',
    content TEXT NULL,
    status ENUM('pending', 'sent', 'delivered', 'read', 'failed', 'received') NOT NULL DEFAULT 'received',
    error_message VARCHAR(500) NULL,
    raw_payload_json JSON NULL,
    sent_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_user FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_messages_tenant_external (tenant_id, evolution_message_id),
    INDEX idx_messages_conversation_date (conversation_id, sent_at),
    INDEX idx_messages_tenant_direction (tenant_id, direction, sent_at)
) ENGINE=InnoDB;

CREATE TABLE conversation_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_conversation_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_events_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_conversation_events_date (conversation_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE crm_pipelines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_crm_pipelines_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_crm_pipeline_tenant_name (tenant_id, name),
    INDEX idx_crm_pipelines_tenant_default (tenant_id, is_default)
) ENGINE=InnoDB;

CREATE TABLE crm_stages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    pipeline_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    stage_type ENUM('open', 'won', 'lost') NOT NULL DEFAULT 'open',
    color_key VARCHAR(30) NOT NULL DEFAULT 'slate',
    position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    probability TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_crm_stages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_stages_pipeline FOREIGN KEY (pipeline_id) REFERENCES crm_pipelines(id) ON DELETE CASCADE,
    UNIQUE KEY uq_crm_stage_pipeline_name (pipeline_id, name),
    UNIQUE KEY uq_crm_stage_pipeline_position (pipeline_id, position),
    INDEX idx_crm_stages_tenant_pipeline (tenant_id, pipeline_id)
) ENGINE=InnoDB;

CREATE TABLE crm_leads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    pipeline_id BIGINT UNSIGNED NOT NULL,
    stage_id BIGINT UNSIGNED NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    status ENUM('open', 'won', 'lost') NOT NULL DEFAULT 'open',
    expected_close_at DATE NULL,
    lost_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    CONSTRAINT fk_crm_leads_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_leads_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_leads_pipeline FOREIGN KEY (pipeline_id) REFERENCES crm_pipelines(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_leads_stage FOREIGN KEY (stage_id) REFERENCES crm_stages(id) ON DELETE RESTRICT,
    CONSTRAINT fk_crm_leads_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_crm_leads_tenant_status (tenant_id, status),
    INDEX idx_crm_leads_stage (pipeline_id, stage_id, updated_at),
    INDEX idx_crm_leads_contact (contact_id),
    INDEX idx_crm_leads_owner (owner_user_id)
) ENGINE=InnoDB;

CREATE TABLE crm_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    lead_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_crm_notes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_notes_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_notes_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_crm_notes_contact_date (contact_id, created_at),
    INDEX idx_crm_notes_lead_date (lead_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE crm_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    lead_id BIGINT UNSIGNED NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    task_type ENUM('task', 'follow_up', 'call', 'meeting') NOT NULL DEFAULT 'task',
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_crm_tasks_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_tasks_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_tasks_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_tasks_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_tasks_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_crm_tasks_tenant_status_due (tenant_id, status, due_at),
    INDEX idx_crm_tasks_assignee (assigned_user_id, status, due_at),
    INDEX idx_crm_tasks_lead (lead_id)
) ENGINE=InnoDB;
USE rs_connect;

INSERT INTO tenants
    (name, legal_name, slug, document, email, phone, website, segment, plan, status, onboarding_step)
VALUES
    ('Empresa Demonstração', 'Empresa Demonstração Ltda.', 'empresa-demonstracao', '00.000.000/0001-00',
     'contato@demo.local', '(11) 99999-9999', 'https://example.com', 'Serviços', 'pro', 'active', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active';

SET @demo_tenant_id = (SELECT id FROM tenants WHERE slug = 'empresa-demonstracao' LIMIT 1);

-- Senha dos usuários de demonstração: Admin@123
INSERT INTO users (tenant_id, name, email, password_hash, role, status)
VALUES
(NULL, 'Administrador RS', 'admin@rsconnect.local', '$2y$12$BTlf5MTlUXL4ZZProLBQweY9CPUdupF9LVoKnNfhqo7FUbvqxcb2S', 'super_admin', 'active'),
(@demo_tenant_id, 'Cliente Demonstração', 'cliente@demo.local', '$2y$12$BTlf5MTlUXL4ZZProLBQweY9CPUdupF9LVoKnNfhqo7FUbvqxcb2S', 'client_admin', 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active';

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
('agents.manage', 'Gerenciar agentes', 'Cadastrar, ativar e definir o agente padrão.', 'Inteligência artificial'),
('conversations.view', 'Visualizar conversas', 'Acessar a caixa de entrada, histórico e dados básicos dos contatos.', 'Atendimento'),
('conversations.manage', 'Gerenciar conversas', 'Enviar mensagens, assumir atendimentos, pausar IA e atualizar contatos.', 'Atendimento'),
('contacts.view', 'Visualizar contatos', 'Consultar contatos, clientes e histórico básico do relacionamento.', 'CRM'),
('contacts.manage', 'Gerenciar contatos', 'Cadastrar e editar contatos da empresa.', 'CRM'),
('crm.view', 'Visualizar CRM', 'Acessar o funil de vendas, negócios, notas e indicadores.', 'CRM'),
('crm.manage', 'Gerenciar CRM', 'Cadastrar negócios, movimentar o funil e registrar notas.', 'CRM'),
('tasks.view', 'Visualizar tarefas', 'Consultar tarefas, ligações, reuniões e follow-ups.', 'CRM'),
('tasks.manage', 'Gerenciar tarefas', 'Cadastrar, concluir e cancelar tarefas e follow-ups.', 'CRM')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), category = VALUES(category);

DELETE FROM role_permissions WHERE tenant_id IS NULL AND role IN ('client_admin', 'client_user');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', id, 1 FROM permissions;

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', id, 1
FROM permissions
WHERE permission_key IN (
    'dashboard.view', 'company.view', 'permissions.view',
    'instances.view', 'agents.view',
    'conversations.view', 'conversations.manage',
    'contacts.view', 'contacts.manage', 'crm.view', 'crm.manage',
    'tasks.view', 'tasks.manage'
);

INSERT INTO crm_pipelines (tenant_id, name, is_default)
VALUES (@demo_tenant_id, 'Funil comercial', 1)
ON DUPLICATE KEY UPDATE is_default = 1;

SET @demo_pipeline_id = (
    SELECT id FROM crm_pipelines
    WHERE tenant_id = @demo_tenant_id AND name = 'Funil comercial'
    LIMIT 1
);

INSERT INTO crm_stages (tenant_id, pipeline_id, name, stage_type, color_key, position, probability) VALUES
(@demo_tenant_id, @demo_pipeline_id, 'Novo', 'open', 'blue', 1, 10),
(@demo_tenant_id, @demo_pipeline_id, 'Qualificação', 'open', 'cyan', 2, 25),
(@demo_tenant_id, @demo_pipeline_id, 'Proposta', 'open', 'violet', 3, 50),
(@demo_tenant_id, @demo_pipeline_id, 'Negociação', 'open', 'amber', 4, 75),
(@demo_tenant_id, @demo_pipeline_id, 'Ganho', 'won', 'green', 5, 100),
(@demo_tenant_id, @demo_pipeline_id, 'Perdido', 'lost', 'slate', 6, 0)
ON DUPLICATE KEY UPDATE
    stage_type = VALUES(stage_type),
    color_key = VALUES(color_key),
    probability = VALUES(probability);
