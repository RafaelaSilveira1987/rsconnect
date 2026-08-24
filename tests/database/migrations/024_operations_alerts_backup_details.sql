-- Hotfix 21.3 — Alertas, incidentes e backup detalhado
-- Execute após o ZIP 21 e hotfixes 21.1/21.2.

SET @db_name = DATABASE();

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE system_backups ADD COLUMN storage_type VARCHAR(40) NOT NULL DEFAULT ''manual_local'' AFTER backup_type',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_backups' AND COLUMN_NAME = 'storage_type'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE system_backups ADD COLUMN verified_at DATETIME NULL AFTER notes',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_backups' AND COLUMN_NAME = 'verified_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE system_backups ADD COLUMN verified_by BIGINT UNSIGNED NULL AFTER verified_at',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_backups' AND COLUMN_NAME = 'verified_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'CREATE INDEX idx_system_backups_storage_verified ON system_backups (storage_type, verified_at)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_backups' AND INDEX_NAME = 'idx_system_backups_storage_verified'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE system_backups
SET storage_type = 'manual_local'
WHERE storage_type IS NULL OR storage_type = '';

-- Transforma checks atuais em alertas operacionais persistentes quando estão em warning/down.
INSERT INTO system_incidents (event, severity, message, context_json, created_by, created_at)
SELECT
    CONCAT('operations.alert.', latest.check_key) AS event,
    CASE WHEN latest.status = 'down' THEN 'critical' ELSE 'warning' END AS severity,
    CONCAT(latest.label, ': ', COALESCE(latest.message, 'Verificação requer atenção.')) AS message,
    JSON_OBJECT('check_key', latest.check_key, 'status', latest.status, 'source', 'migration_024') AS context_json,
    NULL AS created_by,
    NOW() AS created_at
FROM (
    SELECT hc.*
    FROM system_health_checks hc
    INNER JOIN (
        SELECT check_key, MAX(id) AS max_id
        FROM system_health_checks
        GROUP BY check_key
    ) x ON x.max_id = hc.id
    WHERE hc.status IN ('warning', 'down')
) latest
WHERE NOT EXISTS (
    SELECT 1
    FROM system_incidents si
    WHERE si.event = CONCAT('operations.alert.', latest.check_key)
      AND si.resolved_at IS NULL
    LIMIT 1
);

INSERT INTO system_incidents (event, severity, message)
SELECT 'operations.hotfix_21_3_applied', 'info', 'Hotfix 21.3 aplicado: alertas persistentes e backup detalhado habilitados.'
WHERE NOT EXISTS (SELECT 1 FROM system_incidents WHERE event = 'operations.hotfix_21_3_applied' LIMIT 1);
