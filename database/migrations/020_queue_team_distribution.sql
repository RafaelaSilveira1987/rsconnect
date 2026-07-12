-- ZIP 20 — Equipe, fila e distribuição de atendimento
-- Execute uma vez após aplicar o ZIP 19.1.

CREATE TABLE IF NOT EXISTS service_departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL,
    description VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    color VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#146498',
    status ENUM('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_department_tenant_name (tenant_id, name),
    KEY idx_service_departments_tenant_status (tenant_id, status),
    CONSTRAINT fk_service_departments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_internal_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    note TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_internal_notes_conversation (conversation_id, created_at),
    KEY idx_internal_notes_tenant (tenant_id, created_at),
    CONSTRAINT fk_internal_notes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_internal_notes_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_internal_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'department_id') = 0,
    'ALTER TABLE conversations ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER assigned_user_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'operational_status') = 0,
    'ALTER TABLE conversations ADD COLUMN operational_status ENUM(''new'',''waiting_agent'',''in_service'',''waiting_customer'',''resolved'',''archived'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''new'' AFTER department_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'priority') = 0,
    'ALTER TABLE conversations ADD COLUMN priority ENUM(''low'',''normal'',''high'',''urgent'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''normal'' AFTER operational_status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'assigned_at') = 0,
    'ALTER TABLE conversations ADD COLUMN assigned_at DATETIME NULL AFTER priority',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'first_response_at') = 0,
    'ALTER TABLE conversations ADD COLUMN first_response_at DATETIME NULL AFTER assigned_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'closed_at') = 0,
    'ALTER TABLE conversations ADD COLUMN closed_at DATETIME NULL AFTER first_response_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND INDEX_NAME = 'idx_conversations_queue_status') = 0,
    'CREATE INDEX idx_conversations_queue_status ON conversations (tenant_id, operational_status, priority, last_message_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND INDEX_NAME = 'idx_conversations_department') = 0,
    'CREATE INDEX idx_conversations_department ON conversations (department_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE conversations
SET operational_status = CASE
        WHEN status = 'closed' THEN 'resolved'
        WHEN assigned_user_id IS NOT NULL THEN 'in_service'
        WHEN unread_count > 0 THEN 'waiting_agent'
        ELSE 'new'
    END,
    priority = COALESCE(priority, 'normal'),
    assigned_at = IF(assigned_user_id IS NOT NULL AND assigned_at IS NULL, updated_at, assigned_at)
WHERE operational_status IS NULL OR operational_status = 'new';

INSERT IGNORE INTO service_departments (tenant_id, name, description, color)
SELECT id, 'Comercial', 'Atendimentos de vendas e oportunidades.', '#146498'
FROM tenants
WHERE status <> 'inactive';

INSERT IGNORE INTO service_departments (tenant_id, name, description, color)
SELECT id, 'Suporte', 'Dúvidas, ajuda técnica e pós-venda.', '#631b7c'
FROM tenants
WHERE status <> 'inactive';

INSERT IGNORE INTO service_departments (tenant_id, name, description, color)
SELECT id, 'Financeiro', 'Cobranças, pagamentos e contratos.', '#0f766e'
FROM tenants
WHERE status <> 'inactive';

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('queue.view', 'Visualizar fila de atendimento', 'Acessar a visão operacional de conversas por status, setor e responsável.', 'Atendimento'),
    ('queue.manage', 'Gerenciar fila de atendimento', 'Criar setores, distribuir conversas, alterar prioridade e status operacional.', 'Atendimento');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('queue.view', 'queue.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('queue.view', 'queue.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
  );
