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
    whatsapp_human_signature_enabled TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_human_signature_format ENUM('name','name_role','name_company') NOT NULL DEFAULT 'name_role',
    message_retention_mode ENUM('complete','reduced','ephemeral') NOT NULL DEFAULT 'reduced',
    message_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    message_raw_payload_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    message_ephemeral_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    message_retention_last_run_at DATETIME NULL,
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
    whatsapp_display_name VARCHAR(100) NULL,
    whatsapp_role_label VARCHAR(100) NULL,
    whatsapp_signature_enabled TINYINT(1) NOT NULL DEFAULT 1,
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
    management_mode ENUM('managed', 'external') NOT NULL DEFAULT 'external',
    integration VARCHAR(50) NOT NULL DEFAULT 'WHATSAPP-BAILEYS',
    remote_instance_id VARCHAR(120) NULL,
    remote_hash_encrypted TEXT NULL,
    webhook_enabled TINYINT(1) NOT NULL DEFAULT 1,
    webhook_events JSON NULL,
    receive_messages TINYINT(1) NOT NULL DEFAULT 1,
    ignore_groups TINYINT(1) NOT NULL DEFAULT 1,
    ignore_status TINYINT(1) NOT NULL DEFAULT 1,
    ignore_broadcast TINYINT(1) NOT NULL DEFAULT 1,
    ignore_newsletters TINYINT(1) NOT NULL DEFAULT 1,
    ignore_from_me TINYINT(1) NOT NULL DEFAULT 0,
    reject_calls TINYINT(1) NOT NULL DEFAULT 0,
    reject_call_message VARCHAR(255) NULL,
    always_online TINYINT(1) NOT NULL DEFAULT 0,
    read_messages TINYINT(1) NOT NULL DEFAULT 0,
    read_status TINYINT(1) NOT NULL DEFAULT 0,
    sync_full_history TINYINT(1) NOT NULL DEFAULT 0,
    remote_created_at DATETIME NULL,
    last_settings_sync_at DATETIME NULL,
    status ENUM('connected', 'disconnected', 'pending') NOT NULL DEFAULT 'disconnected',
    connection_state VARCHAR(60) NULL,
    connection_reason VARCHAR(255) NULL,
    connection_updated_at DATETIME NULL,
    last_webhook_at DATETIME NULL,
    profile_name VARCHAR(150) NULL,
    profile_phone VARCHAR(40) NULL,
    profile_picture_url VARCHAR(500) NULL,
    qrcode_base64 MEDIUMTEXT NULL,
    qrcode_updated_at DATETIME NULL,
    qrcode_expires_at DATETIME NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_instances_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_instance_tenant_name (tenant_id, instance_name),
    INDEX idx_instances_tenant_status (tenant_id, status),
    INDEX idx_instances_management (tenant_id, management_mode, webhook_enabled)
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
    name_source VARCHAR(24) NOT NULL DEFAULT 'legacy',
    whatsapp_name_candidate VARCHAR(150) NULL,
    whatsapp_name_seen_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    email VARCHAR(190) NULL,
    company VARCHAR(150) NULL,
    notes TEXT NULL,
    tags_json JSON NULL,
    avatar_url VARCHAR(500) NULL,
    avatar_checked_at TIMESTAMP NULL,
    status ENUM('lead', 'customer', 'inactive') NOT NULL DEFAULT 'lead',
    contact_group VARCHAR(40) NOT NULL DEFAULT 'unclassified',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contacts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_contacts_instance FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE SET NULL,
    UNIQUE KEY uq_contacts_tenant_phone (tenant_id, phone),
    INDEX idx_contacts_tenant_name (tenant_id, name),
    INDEX idx_contacts_whatsapp_candidate (tenant_id, whatsapp_name_candidate),
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
    sender_display_name VARCHAR(100) NULL,
    sender_role_label VARCHAR(100) NULL,
    message_type VARCHAR(40) NOT NULL DEFAULT 'text',
    content TEXT NULL,
    delivered_content TEXT NULL,
    status ENUM('pending', 'sent', 'delivered', 'read', 'failed', 'received') NOT NULL DEFAULT 'received',
    error_message VARCHAR(500) NULL,
    raw_payload_json JSON NULL,
    content_purged_at DATETIME NULL,
    raw_payload_purged_at DATETIME NULL,
    sent_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_user FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_messages_tenant_external (tenant_id, evolution_message_id),
    INDEX idx_messages_conversation_date (conversation_id, sent_at),
    INDEX idx_messages_tenant_direction (tenant_id, direction, sent_at),
    INDEX idx_messages_retention (tenant_id, sent_at, content_purged_at),
    INDEX idx_messages_raw_retention (tenant_id, sent_at, raw_payload_purged_at)
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

-- ZIP 32.1 — acompanhamento administrativo das empresas
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


-- ZIP 34.5 — estado do fluxo de atendimento e regras por grupo
CREATE TABLE IF NOT EXISTS conversation_flow_states (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    stage VARCHAR(60) NOT NULL DEFAULT 'identifying_contact',
    demand_status ENUM('pending','collected','refused','not_required') NOT NULL DEFAULT 'pending',
    demand_summary TEXT NULL,
    is_existing_patient TINYINT(1) NOT NULL DEFAULT 0,
    last_intent VARCHAR(80) NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'platform',
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conversation_flow_state (conversation_id),
    KEY idx_conversation_flow_tenant_stage (tenant_id, stage, demand_status),
    KEY idx_conversation_flow_contact (contact_id),
    CONSTRAINT fk_conversation_flow_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_flow_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_flow_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_group_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    contact_group VARCHAR(40) NOT NULL,
    allow_pre_schedule TINYINT(1) NOT NULL DEFAULT 1,
    require_demand_before_pre_schedule TINYINT(1) NOT NULL DEFAULT 1,
    allow_reschedule_without_demand TINYINT(1) NOT NULL DEFAULT 0,
    instructions TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agent_group_rule (agent_id, contact_group),
    KEY idx_agent_group_rules_tenant (tenant_id, contact_group),
    CONSTRAINT fk_agent_group_rule_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_agent_group_rule_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- RS Connect ZIP 36.1 — Reprocessamento seguro e agendado da fila da IA
-- Pode ser executada novamente com segurança.

CREATE TABLE IF NOT EXISTS ai_reprocess_settings (
    id TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    run_time TIME NOT NULL DEFAULT '03:00:00',
    timezone VARCHAR(80) NOT NULL DEFAULT 'America/Sao_Paulo',
    max_messages_per_run SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    last_scheduled_run_on DATE NULL,
    last_scheduled_claimed_at DATETIME NULL,
    last_run_at DATETIME NULL,
    last_run_source VARCHAR(30) NULL,
    last_run_status VARCHAR(30) NULL,
    last_summary_json JSON NULL,
    last_error VARCHAR(1000) NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_ai_reprocess_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_reprocess_settings (id, enabled, run_time, timezone, max_messages_per_run)
VALUES (1, 0, '03:00:00', 'America/Sao_Paulo', 100)
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS ai_reprocess_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source ENUM('manual','scheduled','webhook','cli') NOT NULL DEFAULT 'manual',
    status ENUM('running','success','partial','error','skipped') NOT NULL DEFAULT 'running',
    attempted_count INT UNSIGNED NOT NULL DEFAULT 0,
    replied_count INT UNSIGNED NOT NULL DEFAULT 0,
    evaluated_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    pending_before INT UNSIGNED NOT NULL DEFAULT 0,
    pending_after INT UNSIGNED NOT NULL DEFAULT 0,
    summary_json JSON NULL,
    error_message VARCHAR(1000) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_reprocess_runs_date (started_at, status),
    CONSTRAINT fk_ai_reprocess_runs_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Included from migration 062_prompt_studio_and_versions.sql

-- RS Connect 36.6.35 — Prompt Studio guiado e versionamento de prompts.

CREATE TABLE IF NOT EXISTS ai_prompt_studio_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    agent_id BIGINT UNSIGNED NULL,
    answers_json JSON NULL,
    generated_prompt MEDIUMTEXT NULL,
    validation_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prompt_studio_draft (tenant_id, user_id, agent_id),
    KEY idx_prompt_studio_tenant (tenant_id, updated_at),
    CONSTRAINT fk_prompt_studio_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_prompt_studio_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_prompt_studio_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_prompt_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    source ENUM('onboarding','prompt_studio','manual','restored','system') NOT NULL DEFAULT 'manual',
    title VARCHAR(160) NULL,
    prompt_text MEDIUMTEXT NOT NULL,
    answers_json JSON NULL,
    validation_json JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_prompt_version (agent_id, version_number),
    KEY idx_prompt_versions_tenant (tenant_id, created_at),
    KEY idx_prompt_versions_agent (agent_id, created_at),
    CONSTRAINT fk_prompt_version_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_prompt_version_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_prompt_version_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registra a configuração atual como versão inicial sem duplicar agentes já versionados.
INSERT INTO ai_agent_prompt_versions
    (tenant_id, agent_id, version_number, source, title, prompt_text, created_by, created_at)
SELECT a.tenant_id, a.id, 1, 'system', 'Prompt existente antes do Prompt Studio', a.system_prompt, NULL, NOW()
FROM ai_agents a
WHERE TRIM(COALESCE(a.system_prompt, '')) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM ai_agent_prompt_versions v WHERE v.agent_id = a.id
  );


CREATE TABLE IF NOT EXISTS evolution_connection_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    evolution_instance_id BIGINT UNSIGNED NOT NULL,
    event_name VARCHAR(80) NOT NULL,
    connection_state VARCHAR(60) NULL,
    connection_reason VARCHAR(255) NULL,
    profile_name VARCHAR(150) NULL,
    profile_phone VARCHAR(40) NULL,
    metadata_json JSON NULL,
    occurred_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_evolution_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_evolution_events_instance FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE CASCADE,
    INDEX idx_evolution_events_instance_date (evolution_instance_id, occurred_at),
    INDEX idx_evolution_events_tenant_date (tenant_id, occurred_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_retention_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,
    source ENUM('manual','cron','n8n','system') NOT NULL DEFAULT 'system',
    status ENUM('success','warning','error') NOT NULL DEFAULT 'success',
    messages_purged INT UNSIGNED NOT NULL DEFAULT 0,
    payloads_purged INT UNSIGNED NOT NULL DEFAULT 0,
    previews_purged INT UNSIGNED NOT NULL DEFAULT 0,
    details_json JSON NULL,
    error_message VARCHAR(500) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_retention_runs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    INDEX idx_retention_runs_tenant_date (tenant_id, started_at)
) ENGINE=InnoDB;

-- v36.13.0 — anexos privados das conversas
CREATE TABLE IF NOT EXISTS conversation_message_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    evolution_message_id VARCHAR(190) NULL,
    direction ENUM('incoming','outgoing') NOT NULL,
    attachment_kind ENUM('image','audio','document','video','other') NOT NULL DEFAULT 'other',
    original_name VARCHAR(190) NOT NULL,
    stored_name VARCHAR(190) NULL,
    mime_type VARCHAR(120) NOT NULL,
    extension VARCHAR(20) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    storage_disk VARCHAR(40) NOT NULL DEFAULT 'local_private',
    storage_path VARCHAR(500) NULL,
    sha256 CHAR(64) NULL,
    status ENUM('pending','ready','failed','purged') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    metadata_json JSON NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    purged_at DATETIME NULL,
    CONSTRAINT fk_conversation_attachments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_attachments_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_attachments_message FOREIGN KEY (message_id) REFERENCES conversation_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_attachments_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_conversation_attachment_uuid (uuid),
    INDEX idx_conversation_attachments_message (message_id, status),
    INDEX idx_conversation_attachments_conversation (tenant_id, conversation_id, id),
    INDEX idx_conversation_attachments_retention (tenant_id, created_at, purged_at),
    INDEX idx_conversation_attachments_external (tenant_id, evolution_message_id)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS tenant_ai_commercial_attention_tracking (
    tenant_id BIGINT UNSIGNED NOT NULL,
    status ENUM('open','reviewing','waiting','resolved') NOT NULL DEFAULT 'open',
    signal_hash CHAR(64) NULL,
    note TEXT NULL,
    due_at DATE NULL,
    reviewed_at DATETIME NULL,
    resolved_at DATETIME NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_ai_commercial_attention_status_due (status, due_at),
    CONSTRAINT fk_ai_commercial_attention_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_commercial_attention_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
