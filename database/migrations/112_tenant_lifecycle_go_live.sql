-- RS Connect 36.32.0 — ciclo operacional da empresa e Go-Live explícito.
-- IMPORTANTE: empresas existentes entram em ONBOARDING nesta migration para
-- que a entrada em produção seja confirmada conscientemente pelo Superadmin.
-- O status de acesso (tenants.status) e a assinatura comercial continuam separados.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN lifecycle_status ENUM(''onboarding'',''ready'',''live'',''suspended'') NOT NULL DEFAULT ''onboarding'' AFTER status',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'lifecycle_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN lifecycle_changed_at DATETIME NULL AFTER lifecycle_status',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'lifecycle_changed_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN lifecycle_changed_by BIGINT UNSIGNED NULL AFTER lifecycle_changed_at',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'lifecycle_changed_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN ready_at DATETIME NULL AFTER lifecycle_changed_by',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'ready_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN went_live_at DATETIME NULL AFTER ready_at',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'went_live_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN suspended_at DATETIME NULL AFTER went_live_at',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'suspended_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tenant_lifecycle_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    note VARCHAR(500) NULL,
    changed_by_user_id BIGINT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tenant_lifecycle_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_lifecycle_events_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant_lifecycle_events_lookup (tenant_id, changed_at, id),
    INDEX idx_tenant_lifecycle_events_status (tenant_id, to_status, changed_at)
) ENGINE=InnoDB;

-- Registra o marco inicial somente quando a empresa ainda não possui histórico.
INSERT INTO tenant_lifecycle_events (tenant_id, from_status, to_status, note, changed_by_user_id, changed_at)
SELECT t.id, NULL, COALESCE(NULLIF(t.lifecycle_status, ''), 'onboarding'),
       'Marco inicial criado pela migration 112.', NULL, UTC_TIMESTAMP()
FROM tenants t
LEFT JOIN tenant_lifecycle_events e ON e.tenant_id = t.id
WHERE e.id IS NULL;

UPDATE tenants
SET lifecycle_changed_at = COALESCE(lifecycle_changed_at, UTC_TIMESTAMP())
WHERE lifecycle_changed_at IS NULL;
