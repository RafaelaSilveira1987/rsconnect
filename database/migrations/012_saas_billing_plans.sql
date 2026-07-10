-- ZIP 11 — Planos, assinaturas, cobrança e controle comercial do SaaS
-- Execute uma vez no banco rs_connect após aplicar o ZIP 11.

CREATE TABLE IF NOT EXISTS saas_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_key VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    limits_json JSON NULL,
    features_json JSON NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_saas_plans_key (plan_key),
    KEY idx_saas_plans_status_order (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    billing_cycle ENUM('monthly','quarterly','semiannual','annual') NOT NULL DEFAULT 'monthly',
    billing_status ENUM('trialing','active','overdue','suspended','canceled') NOT NULL DEFAULT 'active',
    starts_at DATE NOT NULL,
    trial_ends_at DATE NULL,
    current_period_starts_at DATE NOT NULL,
    current_period_ends_at DATE NOT NULL,
    next_billing_at DATE NULL,
    cancel_at DATETIME NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subscription_tenant_status (tenant_id, billing_status),
    KEY idx_subscription_period (current_period_ends_at, next_billing_at),
    KEY idx_subscription_plan (plan_id),
    KEY idx_subscription_creator (created_by_user_id),
    CONSTRAINT fk_subscription_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_plan FOREIGN KEY (plan_id) REFERENCES saas_plans(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    invoice_number VARCHAR(60) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    due_date DATE NOT NULL,
    paid_at DATETIME NULL,
    status ENUM('open','paid','overdue','cancelled') NOT NULL DEFAULT 'open',
    payment_method VARCHAR(80) NULL,
    external_reference VARCHAR(190) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_number (invoice_number),
    KEY idx_invoice_tenant_status_due (tenant_id, status, due_date),
    KEY idx_invoice_subscription (subscription_id),
    CONSTRAINT fk_invoice_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_subscription FOREIGN KEY (subscription_id) REFERENCES tenant_subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO saas_plans (plan_key, name, description, monthly_price, limits_json, features_json, status, is_default, sort_order)
VALUES
('starter', 'Starter', 'Plano inicial para validar atendimento com WhatsApp, CRM e IA básica.', 197.00,
 JSON_OBJECT('users', 2, 'instances', 1, 'agents', 1, 'n8n_flows', 1, 'contacts_month', 300, 'conversations_month', 300, 'messages_month', 1500, 'ai_replies_month', 600, 'appointments_month', 100, 'crm_leads_month', 300),
 JSON_ARRAY('1 instância WhatsApp', '1 agente IA', 'CRM básico', 'Agenda interna', '1 fluxo n8n'), 'active', 1, 10),
('pro', 'Profissional', 'Plano recomendado para operação comercial com IA, CRM, agenda e integrações.', 397.00,
 JSON_OBJECT('users', 5, 'instances', 2, 'agents', 3, 'n8n_flows', 5, 'contacts_month', 1500, 'conversations_month', 1200, 'messages_month', 8000, 'ai_replies_month', 3500, 'appointments_month', 500, 'crm_leads_month', 1200),
 JSON_ARRAY('2 instâncias WhatsApp', '3 agentes IA', 'CRM completo', 'Agenda + Google Calendar via n8n', '5 fluxos n8n'), 'active', 0, 20),
('business', 'Business', 'Plano avançado para clientes com maior volume e integrações por processo.', 797.00,
 JSON_OBJECT('users', 15, 'instances', 5, 'agents', 10, 'n8n_flows', 15, 'contacts_month', 8000, 'conversations_month', 5000, 'messages_month', 30000, 'ai_replies_month', 12000, 'appointments_month', 2000, 'crm_leads_month', 5000),
 JSON_ARRAY('5 instâncias WhatsApp', '10 agentes IA', 'Automações avançadas', 'Relatórios operacionais', '15 fluxos n8n'), 'active', 0, 30),
('custom', 'Custom', 'Plano personalizado para negociação especial.', 0.00,
 JSON_OBJECT('users', NULL, 'instances', NULL, 'agents', NULL, 'n8n_flows', NULL, 'contacts_month', NULL, 'conversations_month', NULL, 'messages_month', NULL, 'ai_replies_month', NULL, 'appointments_month', NULL, 'crm_leads_month', NULL),
 JSON_ARRAY('Limites personalizados', 'Condições comerciais sob medida'), 'active', 0, 99)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    monthly_price = VALUES(monthly_price),
    limits_json = VALUES(limits_json),
    features_json = VALUES(features_json),
    status = VALUES(status),
    sort_order = VALUES(sort_order);

INSERT INTO tenant_subscriptions
    (tenant_id, plan_id, billing_cycle, billing_status, starts_at, current_period_starts_at, current_period_ends_at, next_billing_at, amount, notes)
SELECT
    t.id,
    COALESCE(sp.id, sp_default.id),
    'monthly',
    CASE WHEN t.status = 'suspended' THEN 'suspended' ELSE 'active' END,
    CURDATE(),
    DATE_FORMAT(CURDATE(), '%Y-%m-01'),
    LAST_DAY(CURDATE()),
    DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY),
    COALESCE(sp.monthly_price, sp_default.monthly_price),
    'Assinatura criada automaticamente ao aplicar ZIP 11, usando o plano atual da empresa.'
FROM tenants t
LEFT JOIN saas_plans sp
    ON sp.plan_key = (CONVERT(t.plan USING utf8mb4) COLLATE utf8mb4_unicode_ci)
INNER JOIN saas_plans sp_default
    ON sp_default.plan_key = 'starter'
WHERE NOT EXISTS (
    SELECT 1 FROM tenant_subscriptions ts WHERE ts.tenant_id = t.id
);

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('billing.view', 'Visualizar assinatura', 'Consultar plano, uso e cobranças da empresa.', 'Financeiro SaaS'),
    ('billing.manage', 'Gerenciar cobrança', 'Gerenciar planos, assinaturas e cobranças do SaaS.', 'Financeiro SaaS');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key = 'billing.view'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key = 'billing.view'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
  );
