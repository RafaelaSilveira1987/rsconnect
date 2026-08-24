-- RS Connect ZIP 17 — Relatórios e Conversas quase em tempo real
-- Execute uma vez após aplicar o ZIP 17.

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('reports.view', 'Visualizar relatórios', 'Acessar dashboards, métricas e exportações operacionais.', 'Relatórios');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key = 'reports.view'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key = 'reports.view'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
  );
