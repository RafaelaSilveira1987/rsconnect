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
    commercial_whatsapp VARCHAR(30) NULL,
    website VARCHAR(255) NULL,
    instagram VARCHAR(190) NULL,
    segment VARCHAR(120) NULL,
    postal_code VARCHAR(20) NULL,
    address_line VARCHAR(255) NULL,
    address_number VARCHAR(30) NULL,
    address_complement VARCHAR(120) NULL,
    district VARCHAR(120) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(60) NULL,
    company_about TEXT NULL,
    company_services TEXT NULL,
    company_differentials TEXT NULL,
    company_business_hours VARCHAR(255) NULL,
    company_notes TEXT NULL,
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
