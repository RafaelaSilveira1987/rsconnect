USE rs_connect;

-- 36.6.25 — Central de comunicação in-app
SET @db_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communications ADD COLUMN priority ENUM(''normal'',''important'',''critical'') NOT NULL DEFAULT ''normal'' AFTER communication_type',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communications' AND COLUMN_NAME = 'priority'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communications ADD COLUMN response_mode ENUM(''none'',''acknowledge'',''reply'') NOT NULL DEFAULT ''none'' AFTER message',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communications' AND COLUMN_NAME = 'response_mode'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communications ADD COLUMN expires_at DATETIME NULL AFTER sent_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communications' AND COLUMN_NAME = 'expires_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN read_at DATETIME NULL AFTER in_app_status',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'read_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN read_by_user_id BIGINT UNSIGNED NULL AFTER read_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'read_by_user_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN tenant_last_seen_at DATETIME NULL AFTER read_by_user_id',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'tenant_last_seen_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN acknowledged_at DATETIME NULL AFTER tenant_last_seen_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'acknowledged_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN acknowledged_by_user_id BIGINT UNSIGNED NULL AFTER acknowledged_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'acknowledged_by_user_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE client_communication_recipients ADD COLUMN last_reply_at DATETIME NULL AFTER acknowledged_by_user_id',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'last_reply_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS client_communication_replies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    communication_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    direction ENUM('tenant_to_rs','rs_to_tenant') NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_client_communication_replies_thread (communication_id, tenant_id, id),
    KEY idx_client_communication_replies_created (created_at),
    CONSTRAINT fk_client_comm_reply_communication FOREIGN KEY (communication_id) REFERENCES client_communications(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_comm_reply_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_comm_reply_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recupera leituras já registradas no sininho para o novo inbox.
UPDATE client_communication_recipients r
INNER JOIN client_notifications n ON n.id = r.notification_id
SET r.read_at = COALESCE(r.read_at, n.read_at),
    r.tenant_last_seen_at = COALESCE(r.tenant_last_seen_at, n.read_at),
    r.read_by_user_id = COALESCE(r.read_by_user_id, NULL)
WHERE r.read_at IS NULL AND n.status = 'read';

UPDATE client_communication_recipients
SET tenant_last_seen_at = COALESCE(tenant_last_seen_at, read_at)
WHERE read_at IS NOT NULL AND tenant_last_seen_at IS NULL;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_client_comm_recipient_unread ON client_communication_recipients (tenant_id, read_at, created_at)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'client_communication_recipients' AND INDEX_NAME = 'idx_client_comm_recipient_unread'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
