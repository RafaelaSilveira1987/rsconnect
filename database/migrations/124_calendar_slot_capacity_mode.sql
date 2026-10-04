-- RS Connect 36.42.0
-- Modelo de ocupação da Agenda interna: individual (1 vaga por horário) ou
-- capacidade/turma (várias pessoas consumindo vagas do mesmo horário publicado).
-- A implementação preserva os campos legados de hold do slot para compatibilidade,
-- mas novas reservas usam uma tabela de alocações, permitindo N compromissos no
-- mesmo horário sem duplicar visualmente o slot.

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
    'booking_capacity_mode',
    "VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single' AFTER internal_availability_strategy"
);

CALL rs_add_column_if_missing(
    'tenant_calendar_availability_settings',
    'default_slot_capacity',
    'SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER booking_capacity_mode'
);

CALL rs_add_column_if_missing(
    'calendar_internal_slots',
    'capacity_total',
    'SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER ends_at'
);

CREATE TABLE IF NOT EXISTS calendar_internal_slot_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'held',
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_internal_slot_appointment (slot_id, appointment_id),
    KEY idx_internal_alloc_tenant_slot_state (tenant_id, slot_id, state),
    KEY idx_internal_alloc_appointment (tenant_id, appointment_id, state),
    KEY idx_internal_alloc_expiry (tenant_id, state, expires_at),
    CONSTRAINT fk_internal_alloc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_internal_alloc_slot FOREIGN KEY (slot_id) REFERENCES calendar_internal_slots(id) ON DELETE CASCADE,
    CONSTRAINT fk_internal_alloc_appointment FOREIGN KEY (appointment_id) REFERENCES calendar_appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Converte holds/bookings legados em alocações. IGNORE torna a migration idempotente.
INSERT IGNORE INTO calendar_internal_slot_allocations
    (tenant_id, slot_id, appointment_id, state, expires_at)
SELECT tenant_id,
       id,
       hold_appointment_id,
       CASE WHEN status = 'booked' THEN 'booked' ELSE 'held' END,
       CASE WHEN status = 'held' THEN hold_expires_at ELSE NULL END
FROM calendar_internal_slots
WHERE hold_appointment_id IS NOT NULL
  AND status IN ('held', 'booked');

-- Empresas existentes permanecem estritamente individuais.
UPDATE tenant_calendar_availability_settings
SET booking_capacity_mode = 'single',
    default_slot_capacity = 1
WHERE booking_capacity_mode IS NULL OR booking_capacity_mode = '';

UPDATE calendar_internal_slots
SET capacity_total = 1
WHERE capacity_total IS NULL OR capacity_total < 1;

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
