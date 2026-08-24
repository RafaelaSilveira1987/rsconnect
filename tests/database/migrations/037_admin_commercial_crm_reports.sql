USE rs_connect;

-- ZIP 34.0 — CRM comercial da RS Connect e relatórios executivos administrativos.

CREATE TABLE IF NOT EXISTS admin_crm_stages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stage_key VARCHAR(60) NOT NULL,
    name VARCHAR(120) NOT NULL,
    stage_type ENUM('open','won','lost','active') NOT NULL DEFAULT 'open',
    color_key VARCHAR(30) NOT NULL DEFAULT 'slate',
    position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    probability TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_crm_stage_key (stage_key),
    UNIQUE KEY uq_admin_crm_stage_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_crm_opportunities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NULL,
    stage_id BIGINT UNSIGNED NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    company_name VARCHAR(180) NOT NULL,
    contact_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    segment VARCHAR(120) NULL,
    source VARCHAR(120) NULL,
    title VARCHAR(190) NOT NULL,
    value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    status ENUM('open','won','lost','active') NOT NULL DEFAULT 'open',
    expected_close_at DATE NULL,
    next_activity_at DATETIME NULL,
    lost_reason VARCHAR(500) NULL,
    converted_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_crm_stage_updated (stage_id, updated_at),
    KEY idx_admin_crm_owner_status (owner_user_id, status),
    KEY idx_admin_crm_next_activity (status, next_activity_at),
    KEY idx_admin_crm_tenant (tenant_id),
    CONSTRAINT fk_admin_crm_stage FOREIGN KEY (stage_id) REFERENCES admin_crm_stages(id) ON DELETE RESTRICT,
    CONSTRAINT fk_admin_crm_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_crm_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_crm_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_crm_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_crm_notes_opportunity (opportunity_id, created_at),
    CONSTRAINT fk_admin_crm_notes_opportunity FOREIGN KEY (opportunity_id) REFERENCES admin_crm_opportunities(id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_crm_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_crm_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    activity_type ENUM('task','follow_up','call','meeting','demo','proposal') NOT NULL DEFAULT 'follow_up',
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    due_at DATETIME NULL,
    status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_crm_activity_due (status, due_at),
    KEY idx_admin_crm_activity_opportunity (opportunity_id, status, due_at),
    CONSTRAINT fk_admin_crm_activity_opportunity FOREIGN KEY (opportunity_id) REFERENCES admin_crm_opportunities(id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_crm_activity_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_crm_activity_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_crm_stages (stage_key, name, stage_type, color_key, position, probability) VALUES
('new', 'Novo contato', 'open', 'blue', 1, 10),
('demo', 'Demonstração', 'open', 'cyan', 2, 25),
('proposal', 'Proposta enviada', 'open', 'violet', 3, 50),
('negotiation', 'Negociação', 'open', 'amber', 4, 75),
('implementation', 'Aguardando implantação', 'won', 'indigo', 5, 90),
('active', 'Cliente ativo', 'active', 'green', 6, 100),
('risk', 'Em risco', 'open', 'red', 7, 60),
('cancelled', 'Cancelado', 'lost', 'slate', 8, 0)
ON DUPLICATE KEY UPDATE
    name = VALUES(name), stage_type = VALUES(stage_type), color_key = VALUES(color_key),
    position = VALUES(position), probability = VALUES(probability);
