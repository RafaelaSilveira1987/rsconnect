-- ZIP 08 - Agenda, compromissos e integração opcional com Google Calendar/n8n
-- Execute uma única vez após aplicar o ZIP 08.

CREATE TABLE IF NOT EXISTS calendar_appointments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED DEFAULT NULL,
    crm_lead_id BIGINT UNSIGNED DEFAULT NULL,
    conversation_id BIGINT UNSIGNED DEFAULT NULL,
    owner_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    title VARCHAR(180) COLLATE utf8mb4_unicode_ci NOT NULL,
    description TEXT COLLATE utf8mb4_unicode_ci NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    timezone VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/Sao_Paulo',
    status ENUM('scheduled','confirmed','completed','cancelled','no_show') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
    location_type ENUM('online','presencial','telefone') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'online',
    location VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    meeting_url VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    reminder_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    google_event_id VARCHAR(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    sync_status ENUM('pending','synced','failed','not_configured') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
    sync_error VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_calendar_tenant_start (tenant_id, starts_at),
    KEY idx_calendar_status_start (tenant_id, status, starts_at),
    KEY idx_calendar_contact (contact_id, starts_at),
    KEY idx_calendar_lead (crm_lead_id, starts_at),
    KEY idx_calendar_conversation (conversation_id),
    KEY idx_calendar_owner (owner_user_id, starts_at),
    KEY idx_calendar_sync (sync_status, updated_at),
    CONSTRAINT fk_calendar_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
    CONSTRAINT fk_calendar_lead FOREIGN KEY (crm_lead_id) REFERENCES crm_leads (id) ON DELETE SET NULL,
    CONSTRAINT fk_calendar_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE SET NULL,
    CONSTRAINT fk_calendar_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_calendar_creator FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('calendar.view', 'Visualizar agenda', 'Acessar compromissos, reuniões e retornos agendados.', 'Agenda'),
    ('calendar.manage', 'Gerenciar agenda', 'Criar, confirmar, concluir e cancelar agendamentos.', 'Agenda');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('calendar.view', 'calendar.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('calendar.view', 'calendar.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
  );
