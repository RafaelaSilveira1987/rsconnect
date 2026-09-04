USE rs_connect;

-- RS Connect v36.27.14 — ledger persistente do resumo operacional diário.
-- Idempotente e sem alteração de dados comerciais/operacionais existentes.

CREATE TABLE IF NOT EXISTS operational_health_digest_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('platform','whatsapp','email') NOT NULL,
    digest_date DATE NOT NULL,
    delivery_key VARCHAR(80) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'unknown',
    message_hash CHAR(64) NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_operations_health_digest_user_channel_date (user_id, channel, digest_date),
    UNIQUE KEY uq_operations_health_digest_delivery_key (user_id, delivery_key),
    KEY idx_operations_health_digest_date (digest_date, channel),
    CONSTRAINT fk_operations_health_digest_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
