USE rs_connect;

-- Hotfix da migration 073 para MySQL erro 1553.
-- Seguro para ambiente em que a migration foi executada parcialmente.
-- O índice legado uq_operational_alert_delivery pode sustentar a FK de incident_id.
-- Por isso, criamos os índices substitutos antes de removê-lo.

SET @db_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_operational_alert_delivery_incident ON operational_alert_deliveries (incident_id)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'operational_alert_deliveries'
      AND INDEX_NAME = 'idx_operational_alert_delivery_incident'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE UNIQUE INDEX uq_operational_alert_delivery_v2 ON operational_alert_deliveries (incident_id, user_id, notification_kind, channel, delivery_key)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'operational_alert_deliveries'
      AND INDEX_NAME = 'uq_operational_alert_delivery_v2'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE operational_alert_deliveries DROP INDEX uq_operational_alert_delivery',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'operational_alert_deliveries'
      AND INDEX_NAME = 'uq_operational_alert_delivery'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT
    INDEX_NAME,
    NON_UNIQUE,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ', ') AS colunas
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db_name
  AND TABLE_NAME = 'operational_alert_deliveries'
  AND INDEX_NAME IN (
      'idx_operational_alert_delivery_incident',
      'uq_operational_alert_delivery',
      'uq_operational_alert_delivery_v2'
  )
GROUP BY INDEX_NAME, NON_UNIQUE
ORDER BY INDEX_NAME;
