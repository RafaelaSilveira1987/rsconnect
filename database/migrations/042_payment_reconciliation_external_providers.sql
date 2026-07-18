-- ZIP 36.0 — Pagamentos reais, conciliação e cobranças externas
-- Execute uma vez após a migration 041. Compatível com MySQL/MariaDB.

ALTER TABLE payment_gateways
    MODIFY provider ENUM('asaas','mercadopago','stripe','pagbank','infinitepay','external','manual') NOT NULL DEFAULT 'manual';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'external_imported_at') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN external_imported_at DATETIME NULL AFTER payment_link_created_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'payment_status_checked_at') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN payment_status_checked_at DATETIME NULL AFTER external_imported_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'access_released_at') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN access_released_at DATETIME NULL AFTER payment_status_checked_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE tenant_invoices SET external_payment_id = NULL WHERE external_payment_id = '';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND INDEX_NAME = 'idx_invoice_external_payment') = 0,
    'ALTER TABLE tenant_invoices ADD KEY idx_invoice_external_payment (gateway_provider, external_payment_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
