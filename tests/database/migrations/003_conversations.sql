USE rs_connect;

-- Execute este arquivo UMA ÚNICA VEZ após a migration 002.

CREATE TABLE contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    evolution_instance_id BIGINT UNSIGNED NULL,
    remote_jid VARCHAR(190) NULL,
    phone VARCHAR(30) NOT NULL,
    name VARCHAR(150) NULL,
    email VARCHAR(190) NULL,
    company VARCHAR(150) NULL,
    notes TEXT NULL,
    tags_json JSON NULL,
    avatar_url VARCHAR(500) NULL,
    status ENUM('lead', 'customer', 'inactive') NOT NULL DEFAULT 'lead',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contacts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_contacts_instance FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE SET NULL,
    UNIQUE KEY uq_contacts_tenant_phone (tenant_id, phone),
    INDEX idx_contacts_tenant_name (tenant_id, name),
    INDEX idx_contacts_instance (evolution_instance_id)
) ENGINE=InnoDB;

CREATE TABLE conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    evolution_instance_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    remote_jid VARCHAR(190) NOT NULL,
    status ENUM('open', 'pending', 'closed') NOT NULL DEFAULT 'open',
    attendance_mode ENUM('ai', 'human', 'paused') NOT NULL DEFAULT 'ai',
    assigned_user_id BIGINT UNSIGNED NULL,
    unread_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_message_at DATETIME NULL,
    last_message_preview VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_conversations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversations_instance FOREIGN KEY (evolution_instance_id) REFERENCES evolution_instances(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversations_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversations_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_conversation_instance_jid (evolution_instance_id, remote_jid),
    INDEX idx_conversations_tenant_last (tenant_id, last_message_at),
    INDEX idx_conversations_status_mode (tenant_id, status, attendance_mode),
    INDEX idx_conversations_assignee (assigned_user_id)
) ENGINE=InnoDB;

CREATE TABLE conversation_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    evolution_message_id VARCHAR(190) NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    sender_type ENUM('contact', 'user', 'ai', 'system') NOT NULL,
    sender_user_id BIGINT UNSIGNED NULL,
    message_type VARCHAR(40) NOT NULL DEFAULT 'text',
    content TEXT NULL,
    status ENUM('pending', 'sent', 'delivered', 'read', 'failed', 'received') NOT NULL DEFAULT 'received',
    error_message VARCHAR(500) NULL,
    raw_payload_json JSON NULL,
    sent_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_user FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_messages_tenant_external (tenant_id, evolution_message_id),
    INDEX idx_messages_conversation_date (conversation_id, sent_at),
    INDEX idx_messages_tenant_direction (tenant_id, direction, sent_at)
) ENGINE=InnoDB;

CREATE TABLE conversation_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_conversation_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_events_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_conversation_events_date (conversation_id, created_at)
) ENGINE=InnoDB;

INSERT INTO permissions (permission_key, name, description, category) VALUES
('conversations.view', 'Visualizar conversas', 'Acessar a caixa de entrada, histórico e dados básicos dos contatos.', 'Atendimento'),
('conversations.manage', 'Gerenciar conversas', 'Enviar mensagens, assumir atendimentos, pausar IA e atualizar contatos.', 'Atendimento')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    category = VALUES(category);

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('conversations.view', 'conversations.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL
        AND rp.role = 'client_admin'
        AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('conversations.view', 'conversations.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL
        AND rp.role = 'client_user'
        AND rp.permission_id = p.id
  );
