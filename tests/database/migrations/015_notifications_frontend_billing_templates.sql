-- ZIP 14 — Templates de cobrança, notificações do cliente e polimento visual
-- Execute uma vez no banco rs_connect após aplicar o ZIP 14.

CREATE TABLE IF NOT EXISTS client_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(80) NOT NULL DEFAULT 'system',
    severity ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    title VARCHAR(160) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(500) NULL,
    source_event VARCHAR(120) NULL,
    reference_type VARCHAR(80) NULL,
    reference_id BIGINT UNSIGNED NULL,
    status ENUM('unread','read','archived') NOT NULL DEFAULT 'unread',
    metadata_json JSON NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_client_notifications_tenant_status (tenant_id, status, created_at),
    KEY idx_client_notifications_reference (reference_type, reference_id),
    KEY idx_client_notifications_event (source_event),
    CONSTRAINT fk_client_notifications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('notifications.view', 'Visualizar notificações', 'Consultar alertas e avisos da conta cliente.', 'Atendimento'),
    ('notifications.manage', 'Gerenciar notificações', 'Marcar notificações como lidas e administrar avisos da conta.', 'Atendimento');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('notifications.view', 'notifications.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('notifications.view')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
  );
