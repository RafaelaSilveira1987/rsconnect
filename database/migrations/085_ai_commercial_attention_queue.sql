-- RS Connect 36.20.2 — lista de clientes que precisam de atenção.
-- Guarda somente o acompanhamento humano. Os motivos e prioridades são recalculados com os dados atuais.

CREATE TABLE IF NOT EXISTS tenant_ai_commercial_attention_tracking (
    tenant_id BIGINT UNSIGNED NOT NULL,
    status ENUM('open','reviewing','waiting','resolved') NOT NULL DEFAULT 'open',
    signal_hash CHAR(64) NULL,
    note TEXT NULL,
    due_at DATE NULL,
    reviewed_at DATETIME NULL,
    resolved_at DATETIME NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_ai_commercial_attention_status_due (status, due_at),
    KEY idx_ai_commercial_attention_updated_by (updated_by_user_id),
    CONSTRAINT fk_ai_commercial_attention_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_commercial_attention_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
