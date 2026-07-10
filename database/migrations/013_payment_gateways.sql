-- ZIP 12 — Gateways de pagamento: Asaas, Mercado Pago e Stripe
-- Execute uma vez no banco rs_connect após aplicar o ZIP 12.
-- Compatível com MySQL/MariaDB sem ADD COLUMN IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS payment_gateways (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(140) NOT NULL,
    provider ENUM('asaas','mercadopago','stripe','manual') NOT NULL DEFAULT 'manual',
    environment ENUM('sandbox','production') NOT NULL DEFAULT 'production',
    api_base_url VARCHAR(255) NULL,
    api_key_encrypted TEXT NULL,
    public_key VARCHAR(255) NULL,
    webhook_secret_encrypted TEXT NULL,
    default_payment_method VARCHAR(40) NOT NULL DEFAULT 'UNDEFINED',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payment_gateway_provider_status (provider, status),
    KEY idx_payment_gateway_default (is_default, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_gateway_customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    gateway_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    external_customer_id VARCHAR(190) NOT NULL,
    customer_payload_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_customer_tenant_gateway (tenant_id, gateway_id),
    KEY idx_payment_customer_provider_external (provider, external_customer_id),
    CONSTRAINT fk_payment_customer_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_customer_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_gateway_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NULL,
    gateway_id BIGINT UNSIGNED NULL,
    event VARCHAR(140) NOT NULL,
    status ENUM('success','error','ignored') NOT NULL DEFAULT 'success',
    external_id VARCHAR(190) NULL,
    payload_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payment_events_tenant_created (tenant_id, created_at),
    KEY idx_payment_events_gateway_created (gateway_id, created_at),
    KEY idx_payment_events_external (external_id),
    CONSTRAINT fk_payment_event_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_event_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'payment_gateway_id') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN payment_gateway_id BIGINT UNSIGNED NULL AFTER payment_method',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'gateway_provider') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN gateway_provider VARCHAR(40) NULL AFTER payment_gateway_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'external_customer_id') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN external_customer_id VARCHAR(190) NULL AFTER external_reference',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'external_payment_id') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN external_payment_id VARCHAR(190) NULL AFTER external_customer_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'external_checkout_url') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN external_checkout_url TEXT NULL AFTER external_payment_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'external_invoice_url') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN external_invoice_url TEXT NULL AFTER external_checkout_url',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'external_status') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN external_status VARCHAR(100) NULL AFTER external_invoice_url',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'payment_payload_json') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN payment_payload_json JSON NULL AFTER external_status',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_invoices' AND COLUMN_NAME = 'payment_link_created_at') = 0,
    'ALTER TABLE tenant_invoices ADD COLUMN payment_link_created_at DATETIME NULL AFTER payment_payload_json',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('payments.view', 'Visualizar gateways de pagamento', 'Consultar gateways, links e webhooks de pagamento.', 'Financeiro SaaS'),
    ('payments.manage', 'Gerenciar gateways de pagamento', 'Cadastrar gateways e gerar links de cobrança.', 'Financeiro SaaS');

-- Super Admin já tem acesso total pelo middleware. Permissão abaixo fica disponível para futuras regras por perfil.
