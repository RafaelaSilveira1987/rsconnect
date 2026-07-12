-- ZIP 21 — Campanhas e disparos controlados
-- Execute uma vez após aplicar o ZIP 20.

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'marketing_opt_in') = 0,
    'ALTER TABLE contacts ADD COLUMN marketing_opt_in TINYINT(1) NOT NULL DEFAULT 1 AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'opt_out_at') = 0,
    'ALTER TABLE contacts ADD COLUMN opt_out_at DATETIME NULL AFTER marketing_opt_in',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'opt_out_reason') = 0,
    'ALTER TABLE contacts ADD COLUMN opt_out_reason VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL AFTER opt_out_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS message_campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    evolution_instance_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) COLLATE utf8mb4_unicode_ci NOT NULL,
    description VARCHAR(500) COLLATE utf8mb4_unicode_ci NULL,
    channel ENUM('whatsapp') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'whatsapp',
    audience_filter ENUM('all_leads','customers','tag','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all_leads',
    tag_filter VARCHAR(120) COLLATE utf8mb4_unicode_ci NULL,
    manual_numbers_text MEDIUMTEXT COLLATE utf8mb4_unicode_ci NULL,
    message_template TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
    status ENUM('draft','queued','sending','paused','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
    approval_status ENUM('draft','pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
    queued_count INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_dispatched_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_campaigns_tenant_status (tenant_id, status, approval_status),
    KEY idx_campaigns_instance (evolution_instance_id),
    KEY idx_campaigns_scheduled (scheduled_at, status),
    CONSTRAINT fk_campaigns_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaigns_instance FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaigns_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_campaign_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    phone VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL,
    name VARCHAR(160) COLLATE utf8mb4_unicode_ci NULL,
    personalized_message TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
    status ENUM('queued','sent','failed','skipped','opted_out') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
    error_message VARCHAR(500) COLLATE utf8mb4_unicode_ci NULL,
    external_response_json JSON NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaign_contact (campaign_id, contact_id),
    UNIQUE KEY uq_campaign_phone (campaign_id, phone),
    KEY idx_campaign_recipients_status (campaign_id, status, id),
    KEY idx_campaign_recipients_tenant (tenant_id, status),
    CONSTRAINT fk_campaign_recipient_campaign FOREIGN KEY (campaign_id) REFERENCES message_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_recipient_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_recipient_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_campaign_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
    status VARCHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
    message VARCHAR(500) COLLATE utf8mb4_unicode_ci NULL,
    context_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_campaign_logs_campaign (campaign_id, created_at),
    KEY idx_campaign_logs_tenant (tenant_id, created_at),
    CONSTRAINT fk_campaign_logs_campaign FOREIGN KEY (campaign_id) REFERENCES message_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_logs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('campaigns.view', 'Visualizar campanhas', 'Acessar campanhas, destinatários, status de disparo e resultados.', 'Marketing'),
    ('campaigns.manage', 'Gerenciar campanhas', 'Criar campanhas, gerar audiência, aprovar e disparar lotes controlados.', 'Marketing');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('campaigns.view', 'campaigns.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, IF(p.permission_key = 'campaigns.view', 1, 0)
FROM permissions p
WHERE p.permission_key IN ('campaigns.view', 'campaigns.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
  );
