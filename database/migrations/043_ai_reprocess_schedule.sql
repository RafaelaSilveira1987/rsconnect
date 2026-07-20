-- RS Connect ZIP 36.1 — Reprocessamento seguro e agendado da fila da IA
-- Pode ser executada novamente com segurança.

CREATE TABLE IF NOT EXISTS ai_reprocess_settings (
    id TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    run_time TIME NOT NULL DEFAULT '03:00:00',
    timezone VARCHAR(80) NOT NULL DEFAULT 'America/Sao_Paulo',
    max_messages_per_run SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    last_scheduled_run_on DATE NULL,
    last_scheduled_claimed_at DATETIME NULL,
    last_run_at DATETIME NULL,
    last_run_source VARCHAR(30) NULL,
    last_run_status VARCHAR(30) NULL,
    last_summary_json JSON NULL,
    last_error VARCHAR(1000) NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_ai_reprocess_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_reprocess_settings (id, enabled, run_time, timezone, max_messages_per_run)
VALUES (1, 0, '03:00:00', 'America/Sao_Paulo', 100)
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS ai_reprocess_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source ENUM('manual','scheduled','webhook','cli') NOT NULL DEFAULT 'manual',
    status ENUM('running','success','partial','error','skipped') NOT NULL DEFAULT 'running',
    attempted_count INT UNSIGNED NOT NULL DEFAULT 0,
    replied_count INT UNSIGNED NOT NULL DEFAULT 0,
    evaluated_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    pending_before INT UNSIGNED NOT NULL DEFAULT 0,
    pending_after INT UNSIGNED NOT NULL DEFAULT 0,
    summary_json JSON NULL,
    error_message VARCHAR(1000) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_reprocess_runs_date (started_at, status),
    CONSTRAINT fk_ai_reprocess_runs_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
