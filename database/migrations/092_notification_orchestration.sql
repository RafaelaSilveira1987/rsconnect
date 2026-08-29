-- RS Connect v36.23.0 — central de notificações de agenda e comercial

CREATE TABLE IF NOT EXISTS tenant_notification_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(120) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
    whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    recipient_phone VARCHAR(30) NULL,
    reminder_minutes SMALLINT UNSIGNED NULL,
    escalation_minutes SMALLINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_notification_rule (tenant_id, event_key),
    KEY idx_notification_rule_user (updated_by_user_id),
    CONSTRAINT fk_notification_rule_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_rule_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('in_app','whatsapp') NOT NULL,
    recipient VARCHAR(190) NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(500) NULL,
    severity ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    payload_json JSON NULL,
    status ENUM('pending','processing','retry','sent','skipped','failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    scheduled_at DATETIME NOT NULL,
    next_attempt_at DATETIME NOT NULL,
    locked_at DATETIME NULL,
    sent_at DATETIME NULL,
    failed_at DATETIME NULL,
    last_error TEXT NULL,
    deduplication_key VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_job_dedupe (deduplication_key),
    KEY idx_notification_jobs_due (status, next_attempt_at, scheduled_at),
    KEY idx_notification_jobs_tenant_event (tenant_id, event_key, created_at),
    CONSTRAINT fk_notification_job_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_notification_rules (tenant_id, event_key, enabled, in_app_enabled, whatsapp_enabled, reminder_minutes, escalation_minutes)
SELECT t.id, e.event_key, 1, 1, 0, e.reminder_minutes, e.escalation_minutes
FROM tenants t
CROSS JOIN (
    SELECT 'calendar.appointment.created' AS event_key, NULL AS reminder_minutes, NULL AS escalation_minutes
    UNION ALL SELECT 'calendar.appointment.confirmed', NULL, NULL
    UNION ALL SELECT 'calendar.appointment.cancelled', NULL, NULL
    UNION ALL SELECT 'calendar.appointment.rescheduled', NULL, NULL
    UNION ALL SELECT 'calendar.appointment.reminder', 120, NULL
    UNION ALL SELECT 'commercial.quote.requested', NULL, NULL
    UNION ALL SELECT 'commercial.quote.overdue', NULL, 30
) e
ON DUPLICATE KEY UPDATE event_key = VALUES(event_key);
