USE rs_connect;

-- 36.6.5 — Resolução e comunicação operacional

SET @db_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE system_incidents ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER event',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_incidents' AND COLUMN_NAME = 'tenant_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_system_incidents_tenant_open ON system_incidents (tenant_id, resolved_at)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_incidents' AND INDEX_NAME = 'idx_system_incidents_tenant_open'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE system_incidents ADD COLUMN last_seen_at DATETIME NULL AFTER context_json',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_incidents' AND COLUMN_NAME = 'last_seen_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE system_incidents ADD COLUMN resolved_notified_at DATETIME NULL AFTER resolved_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_incidents' AND COLUMN_NAME = 'resolved_notified_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE system_incidents SET last_seen_at = COALESCE(last_seen_at, created_at) WHERE last_seen_at IS NULL;

CREATE TABLE IF NOT EXISTS operational_alert_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    critical_enabled TINYINT(1) NOT NULL DEFAULT 1,
    warning_enabled TINYINT(1) NOT NULL DEFAULT 1,
    evolution_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ai_enabled TINYINT(1) NOT NULL DEFAULT 1,
    n8n_enabled TINYINT(1) NOT NULL DEFAULT 1,
    backup_enabled TINYINT(1) NOT NULL DEFAULT 1,
    routines_enabled TINYINT(1) NOT NULL DEFAULT 1,
    platform_enabled TINYINT(1) NOT NULL DEFAULT 1,
    whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    email_enabled TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_recipient VARCHAR(40) NULL,
    email_recipient VARCHAR(190) NULL,
    reminder_hours SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_operational_alert_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO operational_alert_preferences (user_id)
SELECT id FROM users WHERE role = 'super_admin' AND status = 'active'
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

CREATE TABLE IF NOT EXISTS admin_operational_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    incident_id BIGINT UNSIGNED NULL,
    notification_kind ENUM('opened','reminder','recovered','manual') NOT NULL DEFAULT 'opened',
    severity ENUM('info','warning','danger','success') NOT NULL DEFAULT 'info',
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(500) NULL,
    status ENUM('unread','read','archived') NOT NULL DEFAULT 'unread',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    KEY idx_admin_ops_notifications_user_status (user_id, status, created_at),
    UNIQUE KEY uq_admin_ops_notification (incident_id, user_id, notification_kind),
    KEY idx_admin_ops_notifications_incident (incident_id, notification_kind),
    CONSTRAINT fk_admin_ops_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_ops_notifications_incident FOREIGN KEY (incident_id) REFERENCES system_incidents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operational_alert_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_kind ENUM('opened','reminder','recovered') NOT NULL,
    channel ENUM('platform','whatsapp','email') NOT NULL,
    status ENUM('sent','pending_configuration','skipped','error') NOT NULL DEFAULT 'sent',
    destination VARCHAR(190) NULL,
    error_message VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_operational_alert_delivery (incident_id, user_id, notification_kind, channel),
    KEY idx_operational_alert_delivery_created (created_at),
    CONSTRAINT fk_operational_alert_delivery_incident FOREIGN KEY (incident_id) REFERENCES system_incidents(id) ON DELETE CASCADE,
    CONSTRAINT fk_operational_alert_delivery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_communications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    communication_type ENUM('information','maintenance','attention','incident','resolved') NOT NULL DEFAULT 'information',
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    audience_type ENUM('selected','all','incident') NOT NULL DEFAULT 'selected',
    incident_id BIGINT UNSIGNED NULL,
    channels_json JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_client_communications_created (created_at),
    CONSTRAINT fk_client_communications_incident FOREIGN KEY (incident_id) REFERENCES system_incidents(id) ON DELETE SET NULL,
    CONSTRAINT fk_client_communications_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_communication_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    communication_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    notification_id BIGINT UNSIGNED NULL,
    in_app_status ENUM('sent','skipped','error') NOT NULL DEFAULT 'sent',
    whatsapp_status ENUM('not_requested','pending_configuration','queued','sent','error') NOT NULL DEFAULT 'not_requested',
    email_status ENUM('not_requested','pending_configuration','queued','sent','error') NOT NULL DEFAULT 'not_requested',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_communication_recipient (communication_id, tenant_id),
    KEY idx_client_communication_recipient_tenant (tenant_id, created_at),
    CONSTRAINT fk_client_communication_recipient_communication FOREIGN KEY (communication_id) REFERENCES client_communications(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_communication_recipient_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_communication_recipient_notification FOREIGN KEY (notification_id) REFERENCES client_notifications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
