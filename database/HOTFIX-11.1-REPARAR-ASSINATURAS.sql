-- HOTFIX 11.1 — Corrige erro 1267 Illegal mix of collations no ZIP 11
-- Execute no Adminer no banco rs_connect se a migration 012 parou no INSERT de tenant_subscriptions.

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

-- Conferência
SELECT t.id AS tenant_id, t.name AS empresa, t.plan AS plano_empresa, sp.plan_key, ts.billing_status, ts.amount
FROM tenants t
LEFT JOIN tenant_subscriptions ts ON ts.tenant_id = t.id
LEFT JOIN saas_plans sp ON sp.id = ts.plan_id
ORDER BY t.id;
