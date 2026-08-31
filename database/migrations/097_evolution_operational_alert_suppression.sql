USE rs_connect;

SET @db := DATABASE();

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'evolution_instances'
      AND COLUMN_NAME = 'operational_alerts_enabled'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE evolution_instances ADD COLUMN operational_alerts_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER receive_messages',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'evolution_instances'
      AND COLUMN_NAME = 'operational_alerts_paused_at'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE evolution_instances ADD COLUMN operational_alerts_paused_at DATETIME NULL AFTER operational_alerts_enabled',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'evolution_instances'
      AND COLUMN_NAME = 'operational_alerts_pause_reason'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE evolution_instances ADD COLUMN operational_alerts_pause_reason VARCHAR(80) NULL AFTER operational_alerts_paused_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE evolution_instances
SET operational_alerts_enabled = 0,
    operational_alerts_paused_at = COALESCE(operational_alerts_paused_at, disconnected_at, NOW()),
    operational_alerts_pause_reason = COALESCE(NULLIF(operational_alerts_pause_reason, ''), 'client_logout')
WHERE LOWER(COALESCE(connection_state, '')) IN ('logged_out', 'logout', 'loggedout', 'user_logout', 'manual_logout');
