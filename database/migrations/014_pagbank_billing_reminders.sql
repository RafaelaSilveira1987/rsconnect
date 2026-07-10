-- ZIP 13 — PagBank + Régua de Cobrança
-- Execute uma vez no banco rs_connect após aplicar o ZIP 13.
-- Compatível com MySQL/MariaDB.

ALTER TABLE payment_gateways
    MODIFY provider ENUM('asaas','mercadopago','stripe','pagbank','manual') NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS billing_reminder_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(160) NOT NULL,
    days_from_due INT NOT NULL DEFAULT 0 COMMENT 'Negativo = antes do vencimento; 0 = no dia; positivo = após vencimento',
    event_key VARCHAR(120) NOT NULL DEFAULT 'billing.reminder.due_today',
    channel ENUM('n8n','whatsapp','email','manual') NOT NULL DEFAULT 'n8n',
    auto_mark_overdue TINYINT(1) NOT NULL DEFAULT 0,
    auto_suspend TINYINT(1) NOT NULL DEFAULT 0,
    message_template TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_billing_reminder_due_status (days_from_due, status),
    KEY idx_billing_reminder_event (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_reminder_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id BIGINT UNSIGNED NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','sent','error','logged') NOT NULL DEFAULT 'pending',
    payload_json JSON NULL,
    result_json JSON NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_billing_reminder_log_tenant_created (tenant_id, created_at),
    KEY idx_billing_reminder_log_invoice_created (invoice_id, created_at),
    KEY idx_billing_reminder_log_rule_created (rule_id, created_at),
    CONSTRAINT fk_billing_reminder_log_rule FOREIGN KEY (rule_id) REFERENCES billing_reminder_rules(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_reminder_log_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_reminder_log_invoice FOREIGN KEY (invoice_id) REFERENCES tenant_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO billing_reminder_rules
    (id, label, days_from_due, event_key, channel, auto_mark_overdue, auto_suspend, message_template, status)
VALUES
    (1, '3 dias antes do vencimento', -3, 'billing.reminder.before_due', 'n8n', 0, 0,
     'Olá, {{empresa}}. Passando para lembrar que a cobrança {{invoice_number}} no valor de {{valor}} vence em {{vencimento}}. Link: {{link_pagamento}}', 'active'),
    (2, 'Aviso no dia do vencimento', 0, 'billing.reminder.due_today', 'n8n', 0, 0,
     'Olá, {{empresa}}. Sua cobrança {{invoice_number}} vence hoje no valor de {{valor}}. Link: {{link_pagamento}}', 'active'),
    (3, '2 dias após vencimento', 2, 'billing.reminder.overdue', 'n8n', 1, 0,
     'Olá, {{empresa}}. Identificamos que a cobrança {{invoice_number}} está em aberto há {{dias}} dia(s). Link: {{link_pagamento}}', 'active'),
    (4, '7 dias após vencimento — suspender', 7, 'billing.subscription.suspended', 'n8n', 1, 1,
     'Olá, {{empresa}}. Sua assinatura foi sinalizada para suspensão por pendência da cobrança {{invoice_number}}. Para regularizar, use o link: {{link_pagamento}}', 'inactive');

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('billing.reminders.view', 'Visualizar régua de cobrança', 'Consultar regras e histórico de notificações financeiras.', 'Financeiro SaaS'),
    ('billing.reminders.manage', 'Gerenciar régua de cobrança', 'Criar regras e processar notificações financeiras.', 'Financeiro SaaS');
