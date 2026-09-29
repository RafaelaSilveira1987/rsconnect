-- RS Connect 36.38.0
-- Disponibilidade publicada da Agenda interna.
-- Mantém o comportamento legado (horários calculados) como padrão e adiciona um
-- modo opt-in no qual o agente oferece somente horários liberados explicitamente.

DELIMITER $$

DROP PROCEDURE IF EXISTS rs_add_column_if_missing$$
CREATE PROCEDURE rs_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'internal_availability_strategy',
    "varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'calculated' AFTER availability_mode"
);

CREATE TABLE IF NOT EXISTS calendar_internal_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
    modality VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'indefinida',
    service_key VARCHAR(120) COLLATE utf8mb4_unicode_ci NULL,
    source VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
    hold_appointment_id BIGINT UNSIGNED NULL,
    hold_expires_at DATETIME NULL,
    notes VARCHAR(500) COLLATE utf8mb4_unicode_ci NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_internal_slots_tenant_start (tenant_id, starts_at, status),
    KEY idx_internal_slots_owner_start (tenant_id, owner_user_id, starts_at, status),
    KEY idx_internal_slots_hold (tenant_id, hold_appointment_id, status),
    CONSTRAINT fk_internal_slots_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_internal_slots_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_internal_slots_hold_appointment FOREIGN KEY (hold_appointment_id) REFERENCES calendar_appointments(id) ON DELETE SET NULL,
    CONSTRAINT fk_internal_slots_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
