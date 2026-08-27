-- RS Connect v36.20.13.3 — compatibilidade do schema de conciliação de pagamentos
-- Reaplica de forma idempotente as colunas operacionais previstas na migration 042.
-- Compatível com MySQL/MariaDB e seguro para bancos que já possuem parte ou todo o schema.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tenant_invoices'
       AND COLUMN_NAME = 'external_imported_at') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN external_imported_at DATETIME NULL AFTER payment_link_created_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tenant_invoices'
       AND COLUMN_NAME = 'payment_status_checked_at') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN payment_status_checked_at DATETIME NULL AFTER external_imported_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tenant_invoices'
       AND COLUMN_NAME = 'access_released_at') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN access_released_at DATETIME NULL AFTER payment_status_checked_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE tenant_invoices
SET external_payment_id = NULL
WHERE external_payment_id = '';

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tenant_invoices'
       AND INDEX_NAME = 'idx_invoice_external_payment') = 0,
    'ALTER TABLE tenant_invoices ADD KEY idx_invoice_external_payment (gateway_provider, external_payment_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
