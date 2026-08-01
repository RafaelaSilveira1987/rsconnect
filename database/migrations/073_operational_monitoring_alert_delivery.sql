USE rs_connect;

-- v36.12.0 — Monitoramento e alertas operacionais
-- Idempotente. Executar após a migration 072.

SET @db_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE system_incidents ADD COLUMN acknowledged_at DATETIME NULL AFTER last_seen_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_incidents' AND COLUMN_NAME = 'acknowledged_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE system_incidents ADD COLUMN acknowledged_by BIGINT UNSIGNED NULL AFTER acknowledged_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_incidents' AND COLUMN_NAME = 'acknowledged_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE system_incidents ADD COLUMN acknowledgement_note VARCHAR(500) NULL AFTER acknowledged_by',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_incidents' AND COLUMN_NAME = 'acknowledgement_note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_system_incidents_open_ack ON system_incidents (resolved_at, acknowledged_at, severity, last_seen_at)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_incidents' AND INDEX_NAME = 'idx_system_incidents_open_ack'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operational_alert_preferences ADD COLUMN webhooks_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER n8n_enabled',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_preferences' AND COLUMN_NAME = 'webhooks_enabled'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operational_alert_preferences ADD COLUMN disk_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER backup_enabled',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_preferences' AND COLUMN_NAME = 'disk_enabled'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operational_alert_preferences ADD COLUMN queue_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER disk_enabled',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_preferences' AND COLUMN_NAME = 'queue_enabled'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operational_alert_deliveries ADD COLUMN delivery_key VARCHAR(80) NOT NULL DEFAULT ''legacy'' AFTER channel',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_deliveries' AND COLUMN_NAME = 'delivery_key'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operational_alert_deliveries ADD COLUMN attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER status',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_deliveries' AND COLUMN_NAME = 'attempt_count'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operational_alert_deliveries ADD COLUMN provider_message_id VARCHAR(190) NULL AFTER destination',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_deliveries' AND COLUMN_NAME = 'provider_message_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operational_alert_deliveries ADD COLUMN last_attempt_at DATETIME NULL AFTER error_message',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_deliveries' AND COLUMN_NAME = 'last_attempt_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operational_alert_deliveries ADD COLUMN sent_at DATETIME NULL AFTER last_attempt_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_deliveries' AND COLUMN_NAME = 'sent_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE operational_alert_deliveries
SET delivery_key = CASE
    WHEN notification_kind = 'reminder' THEN CONCAT('legacy-reminder-', id)
    ELSE notification_kind
END
WHERE delivery_key IS NULL OR delivery_key = '' OR delivery_key = 'legacy';

-- O índice legado também pode estar sustentando a FK de incident_id.
-- Criamos primeiro um índice dedicado e o novo índice único; só então removemos o legado.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_operational_alert_delivery_incident ON operational_alert_deliveries (incident_id)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_deliveries' AND INDEX_NAME = 'idx_operational_alert_delivery_incident'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE UNIQUE INDEX uq_operational_alert_delivery_v2 ON operational_alert_deliveries (incident_id, user_id, notification_kind, channel, delivery_key)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_deliveries' AND INDEX_NAME = 'uq_operational_alert_delivery_v2'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE operational_alert_deliveries DROP INDEX uq_operational_alert_delivery',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operational_alert_deliveries' AND INDEX_NAME = 'uq_operational_alert_delivery'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Auditoria da entrega externa dos comunicados aos clientes.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN whatsapp_provider_message_id VARCHAR(190) NULL AFTER whatsapp_status',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'whatsapp_provider_message_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN whatsapp_error VARCHAR(500) NULL AFTER whatsapp_provider_message_id',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'whatsapp_error'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN whatsapp_sent_at DATETIME NULL AFTER whatsapp_error',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'whatsapp_sent_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN email_provider_message_id VARCHAR(190) NULL AFTER email_status',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'email_provider_message_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN email_error VARCHAR(500) NULL AFTER email_provider_message_id',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'email_error'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN email_sent_at DATETIME NULL AFTER email_error',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'email_sent_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS operational_monitor_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trigger_source VARCHAR(60) NOT NULL DEFAULT 'manual',
    status ENUM('running','success','partial','error') NOT NULL DEFAULT 'running',
    checks_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    healthy_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    warning_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    down_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    incidents_opened SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    incidents_recovered SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NULL,
    summary_json JSON NULL,
    error_message VARCHAR(1000) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_operational_monitor_runs_started (started_at),
    KEY idx_operational_monitor_runs_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO operational_alert_preferences (user_id)
SELECT id FROM users WHERE role = 'super_admin' AND status = 'active'
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

INSERT INTO system_incidents (event, severity, message, context_json, last_seen_at)
SELECT
    'operations.monitoring_v36_12_0_enabled',
    'info',
    'Monitoramento operacional v36.12.0 habilitado: disco, filas, ciclo de incidentes e canais externos.',
    JSON_OBJECT('migration', '073_operational_monitoring_alert_delivery.sql'),
    UTC_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM system_incidents WHERE event = 'operations.monitoring_v36_12_0_enabled' LIMIT 1
);
