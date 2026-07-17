-- RS Connect ZIP 33.0 — Bloqueio por vigência, inadimplência e tentativas de login
-- Pode ser executada novamente com segurança.

SET @db_name = DATABASE();

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db_name AND table_name = 'users' AND column_name = 'failed_login_count') = 0,
    'ALTER TABLE users ADD COLUMN failed_login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db_name AND table_name = 'users' AND column_name = 'last_failed_login_at') = 0,
    'ALTER TABLE users ADD COLUMN last_failed_login_at DATETIME NULL AFTER failed_login_count',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db_name AND table_name = 'users' AND column_name = 'locked_until') = 0,
    'ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER last_failed_login_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db_name AND table_name = 'users' AND column_name = 'lock_reason') = 0,
    'ALTER TABLE users ADD COLUMN lock_reason VARCHAR(120) NULL AFTER locked_until',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @db_name AND table_name = 'users' AND index_name = 'idx_users_locked_until') = 0,
    'ALTER TABLE users ADD INDEX idx_users_locked_until (locked_until)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO security_events (event, severity, context_json, ip_address)
SELECT 'security.zip33_access_enforcement_applied', 'info', JSON_OBJECT('migration', '036_security_access_enforcement'), NULL
WHERE NOT EXISTS (
    SELECT 1 FROM security_events WHERE event = 'security.zip33_access_enforcement_applied'
);
