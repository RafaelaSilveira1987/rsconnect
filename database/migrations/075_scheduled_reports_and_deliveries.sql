USE rs_connect;

-- v36.15.1 — Relatórios automáticos, PDF e envio pelo WhatsApp
-- Idempotente. Executar após a migration 074.

CREATE TABLE IF NOT EXISTS scheduled_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    report_scope ENUM('admin','tenant') NOT NULL DEFAULT 'tenant',
    name VARCHAR(150) NOT NULL,
    status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
    frequency ENUM('manual','daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
    time_of_day TIME NOT NULL DEFAULT '08:00:00',
    weekday TINYINT UNSIGNED NULL,
    month_day TINYINT UNSIGNED NULL,
    timezone VARCHAR(80) NOT NULL DEFAULT 'America/Sao_Paulo',
    period_mode ENUM('previous_day','previous_week','previous_month','last_7_days','last_30_days','current_month') NOT NULL DEFAULT 'previous_week',
    sections_json JSON NULL,
    whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 1,
    evolution_instance_id BIGINT UNSIGNED NULL,
    next_run_at DATETIME NULL,
    last_run_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_scheduled_reports_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_scheduled_reports_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_scheduled_reports_instance
        FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE SET NULL,
    UNIQUE KEY uq_scheduled_reports_uuid (uuid),
    INDEX idx_scheduled_reports_due (status, next_run_at),
    INDEX idx_scheduled_reports_tenant (tenant_id, status, updated_at),
    INDEX idx_scheduled_reports_creator (created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_report_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scheduled_report_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NULL,
    phone VARCHAR(30) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_scheduled_report_recipients_schedule
        FOREIGN KEY (scheduled_report_id) REFERENCES scheduled_reports(id) ON DELETE CASCADE,
    UNIQUE KEY uq_scheduled_report_recipient (scheduled_report_id, phone),
    INDEX idx_scheduled_report_recipients_enabled (scheduled_report_id, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS generated_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    run_key VARCHAR(190) NOT NULL,
    scheduled_report_id BIGINT UNSIGNED NULL,
    tenant_id BIGINT UNSIGNED NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    report_scope ENUM('admin','tenant') NOT NULL DEFAULT 'tenant',
    report_name VARCHAR(150) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status ENUM('generating','ready','sending','sent','partial','failed','expired') NOT NULL DEFAULT 'generating',
    original_filename VARCHAR(190) NOT NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    storage_path VARCHAR(500) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NULL,
    summary_json JSON NULL,
    error_message VARCHAR(1000) NULL,
    generated_at DATETIME NULL,
    sent_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_generated_reports_schedule
        FOREIGN KEY (scheduled_report_id) REFERENCES scheduled_reports(id) ON DELETE SET NULL,
    CONSTRAINT fk_generated_reports_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_generated_reports_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_generated_reports_uuid (uuid),
    UNIQUE KEY uq_generated_reports_run_key (run_key),
    INDEX idx_generated_reports_scope (tenant_id, report_scope, created_at),
    INDEX idx_generated_reports_status (status, created_at),
    INDEX idx_generated_reports_expiry (expires_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_report_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    generated_report_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NULL,
    channel ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp',
    destination VARCHAR(60) NOT NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    provider_message_id VARCHAR(190) NULL,
    error_message VARCHAR(1000) NULL,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_scheduled_report_deliveries_report
        FOREIGN KEY (generated_report_id) REFERENCES generated_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_scheduled_report_deliveries_recipient
        FOREIGN KEY (recipient_id) REFERENCES scheduled_report_recipients(id) ON DELETE SET NULL,
    UNIQUE KEY uq_scheduled_report_delivery (generated_report_id, channel, destination),
    INDEX idx_scheduled_report_deliveries_status (status, last_attempt_at),
    INDEX idx_scheduled_report_deliveries_report (generated_report_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('reports.schedule.manage', 'Gerenciar relatórios automáticos', 'Criar, pausar, gerar e enviar relatórios automáticos da própria empresa.', 'Relatórios');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key = 'reports.schedule.manage'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO system_incidents (event, severity, message, context_json)
SELECT
    'reports.scheduled_v36_15_1_enabled',
    'info',
    'Relatórios automáticos com PDF e envio pelo WhatsApp habilitados.',
    JSON_OBJECT('migration', '075_scheduled_reports_and_deliveries.sql')
WHERE NOT EXISTS (
    SELECT 1 FROM system_incidents WHERE event = 'reports.scheduled_v36_15_1_enabled' LIMIT 1
);
