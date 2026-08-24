-- ZIP 09 — n8n por empresa/tenant
-- Execute uma vez no banco rs_connect.

CREATE TABLE IF NOT EXISTS n8n_tenant_flows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    flow_key VARCHAR(80) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    webhook_url_encrypted TEXT NOT NULL,
    secret_token_encrypted TEXT NULL,
    events_json JSON NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by_user_id BIGINT UNSIGNED NULL,
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n8n_flow_tenant_key (tenant_id, flow_key),
    KEY idx_n8n_flow_tenant_status (tenant_id, status),
    KEY idx_n8n_flow_creator (created_by_user_id),
    CONSTRAINT fk_n8n_flow_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_n8n_flow_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS n8n_flow_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    flow_id BIGINT UNSIGNED NULL,
    event VARCHAR(120) NOT NULL,
    status ENUM('success','error','skipped') NOT NULL DEFAULT 'success',
    http_status SMALLINT UNSIGNED NULL,
    request_url_masked VARCHAR(500) NULL,
    response_preview VARCHAR(1000) NULL,
    error_message VARCHAR(700) NULL,
    payload_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_n8n_logs_tenant_date (tenant_id, created_at),
    KEY idx_n8n_logs_event_status (event, status),
    KEY idx_n8n_logs_flow (flow_id),
    CONSTRAINT fk_n8n_logs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_n8n_logs_flow FOREIGN KEY (flow_id) REFERENCES n8n_tenant_flows(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('n8n.view', 'Visualizar fluxos n8n', 'Consultar integrações n8n configuradas por empresa.', 'Integrações'),
    ('n8n.manage', 'Gerenciar fluxos n8n', 'Cadastrar, testar e inativar webhooks n8n por empresa.', 'Integrações');
