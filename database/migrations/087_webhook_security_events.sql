-- RS Connect 36.20.13 — ENT-028 / PA-002
-- Registro idempotente e auditável dos webhooks críticos.
-- Migration aditiva: não remove nem altera dados existentes.

CREATE TABLE IF NOT EXISTS webhook_security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source VARCHAR(80) NOT NULL,
    event_key VARCHAR(190) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status ENUM('processing', 'processed', 'failed') NOT NULL DEFAULT 'processing',
    metadata_json LONGTEXT NULL,
    first_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    response_code SMALLINT UNSIGNED NULL,
    response_digest CHAR(64) NULL,
    last_error VARCHAR(700) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_security_source_event (source, event_key),
    KEY idx_webhook_security_status_attempt (status, last_attempt_at),
    KEY idx_webhook_security_received (last_received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
