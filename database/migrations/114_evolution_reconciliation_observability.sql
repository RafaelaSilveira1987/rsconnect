-- RS Connect 36.33.0 — Production Readiness Fase B / Evolution Reliability
-- Reconciliação auditável entre o estado local e o estado observado na Evolution.
-- Migration aditiva e idempotente.

SET @db := DATABASE();

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='remote_connection_state');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN remote_connection_state VARCHAR(60) NULL AFTER connection_state', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='reconciliation_status');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN reconciliation_status VARCHAR(40) NOT NULL DEFAULT "unknown" AFTER remote_connection_state', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='reconciliation_reason');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN reconciliation_reason VARCHAR(255) NULL AFTER reconciliation_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='last_reconciled_at');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN last_reconciled_at DATETIME NULL AFTER reconciliation_reason', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND COLUMN_NAME='reconciliation_failures');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD COLUMN reconciliation_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_reconciled_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='evolution_instances' AND INDEX_NAME='idx_instances_reconciliation');
SET @sql := IF(@exists=0, 'ALTER TABLE evolution_instances ADD INDEX idx_instances_reconciliation (tenant_id, reconciliation_status, last_reconciled_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS evolution_reconciliation_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    evolution_instance_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'system',
    local_state_before VARCHAR(60) NULL,
    remote_state VARCHAR(60) NULL,
    result_status VARCHAR(40) NOT NULL,
    action_taken VARCHAR(80) NULL,
    error_message VARCHAR(500) NULL,
    metadata_json LONGTEXT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_evolution_reconcile_instance_date (evolution_instance_id, started_at),
    KEY idx_evolution_reconcile_tenant_date (tenant_id, started_at),
    KEY idx_evolution_reconcile_status_date (result_status, started_at),
    CONSTRAINT fk_evolution_reconcile_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_evolution_reconcile_instance FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O primeiro estado remoto só será preenchido por uma reconciliação real.
UPDATE evolution_instances
SET reconciliation_status = CASE
    WHEN LOWER(COALESCE(connection_state, '')) = 'identity_mismatch' THEN 'identity_mismatch'
    ELSE COALESCE(NULLIF(reconciliation_status, ''), 'unknown')
END
WHERE reconciliation_status IS NULL OR reconciliation_status = '' OR LOWER(COALESCE(connection_state, '')) = 'identity_mismatch';
