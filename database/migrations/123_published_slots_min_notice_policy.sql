-- RS Connect 36.40.3
-- Política de antecedência mínima para horários explicitamente publicados.
-- Preserva o comportamento atual como padrão: horários publicados respeitam a
-- antecedência mínima, salvo quando a empresa desativar essa regra.

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
    'published_slots_respect_min_notice',
    'TINYINT(1) NOT NULL DEFAULT 1 AFTER internal_availability_strategy'
);

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
