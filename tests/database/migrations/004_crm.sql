USE rs_connect;

-- ZIP 04 — CRM, funil, notas, tarefas e follow-ups.
-- Execute UMA ÚNICA VEZ após a migration 003_conversations.sql.

CREATE TABLE IF NOT EXISTS crm_pipelines (
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

CREATE TABLE IF NOT EXISTS crm_stages (
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

CREATE TABLE IF NOT EXISTS crm_leads (
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

CREATE TABLE IF NOT EXISTS crm_notes (
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

CREATE TABLE IF NOT EXISTS crm_tasks (
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

INSERT INTO permissions (permission_key, name, description, category) VALUES
('contacts.view', 'Visualizar contatos', 'Consultar contatos, clientes e histórico básico do relacionamento.', 'CRM'),
('contacts.manage', 'Gerenciar contatos', 'Cadastrar e editar contatos da empresa.', 'CRM'),
('crm.view', 'Visualizar CRM', 'Acessar o funil de vendas, negócios, notas e indicadores.', 'CRM'),
('crm.manage', 'Gerenciar CRM', 'Cadastrar negócios, movimentar o funil e registrar notas.', 'CRM'),
('tasks.view', 'Visualizar tarefas', 'Consultar tarefas, ligações, reuniões e follow-ups.', 'CRM'),
('tasks.manage', 'Gerenciar tarefas', 'Cadastrar, concluir e cancelar tarefas e follow-ups.', 'CRM')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    category = VALUES(category);

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('contacts.view', 'contacts.manage', 'crm.view', 'crm.manage', 'tasks.view', 'tasks.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL
        AND rp.role = 'client_admin'
        AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('contacts.view', 'contacts.manage', 'crm.view', 'crm.manage', 'tasks.view', 'tasks.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL
        AND rp.role = 'client_user'
        AND rp.permission_id = p.id
  );

INSERT INTO crm_pipelines (tenant_id, name, is_default)
SELECT t.id, 'Funil comercial', 1
FROM tenants t
WHERE NOT EXISTS (
    SELECT 1 FROM crm_pipelines p WHERE p.tenant_id = t.id
);

INSERT INTO crm_stages (tenant_id, pipeline_id, name, stage_type, color_key, position, probability)
SELECT p.tenant_id, p.id, stage.name, stage.stage_type, stage.color_key, stage.position, stage.probability
FROM crm_pipelines p
INNER JOIN (
    SELECT 'Novo' AS name, 'open' AS stage_type, 'blue' AS color_key, 1 AS position, 10 AS probability
    UNION ALL SELECT 'Qualificação', 'open', 'cyan', 2, 25
    UNION ALL SELECT 'Proposta', 'open', 'violet', 3, 50
    UNION ALL SELECT 'Negociação', 'open', 'amber', 4, 75
    UNION ALL SELECT 'Ganho', 'won', 'green', 5, 100
    UNION ALL SELECT 'Perdido', 'lost', 'slate', 6, 0
) stage
WHERE p.is_default = 1
  AND NOT EXISTS (
      SELECT 1 FROM crm_stages s
      WHERE s.pipeline_id = p.id AND s.name = stage.name
  );
