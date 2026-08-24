USE rs_connect;

-- ZIP 31.1 — Preferências de notificações e alertas operacionais para o cliente.
-- Pode ser executada novamente com segurança.

CREATE TABLE IF NOT EXISTS tenant_notification_preferences (
    tenant_id BIGINT UNSIGNED NOT NULL,
    messages_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ai_errors_enabled TINYINT(1) NOT NULL DEFAULT 1,
    automation_errors_enabled TINYINT(1) NOT NULL DEFAULT 1,
    calendar_enabled TINYINT(1) NOT NULL DEFAULT 1,
    billing_enabled TINYINT(1) NOT NULL DEFAULT 1,
    system_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_tenant_notification_preferences_user (updated_by_user_id),
    CONSTRAINT fk_tenant_notification_preferences_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_notification_preferences (tenant_id)
SELECT id FROM tenants
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);
