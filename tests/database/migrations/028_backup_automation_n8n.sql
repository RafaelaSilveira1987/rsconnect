-- ZIP 24 — Backup automático via n8n
-- Execute uma vez no banco rs_connect após aplicar o ZIP 24.

CREATE TABLE IF NOT EXISTS operations_backup_routines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    n8n_webhook_url_encrypted TEXT NULL,
    secret_token_encrypted TEXT NULL,
    frequency ENUM('daily','weekly','monthly','manual','custom') NOT NULL DEFAULT 'daily',
    schedule_label VARCHAR(120) NULL,
    preferred_time VARCHAR(20) NULL,
    timezone VARCHAR(80) NOT NULL DEFAULT 'America/Sao_Paulo',
    storage_type ENUM('server','easypanel','google_drive','s3_minio','dropbox','other') NOT NULL DEFAULT 'server',
    storage_path VARCHAR(700) NULL,
    retention_days INT UNSIGNED NOT NULL DEFAULT 14,
    max_age_hours INT UNSIGNED NOT NULL DEFAULT 24,
    last_requested_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    last_error VARCHAR(700) NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_backup_routines_status (status),
    KEY idx_backup_routines_last_success (last_success_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operations_backup_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    routine_id BIGINT UNSIGNED NULL,
    status ENUM('requested','running','success','error','skipped') NOT NULL DEFAULT 'requested',
    trigger_type ENUM('manual','scheduled','test','webhook') NOT NULL DEFAULT 'manual',
    request_payload_json LONGTEXT NULL,
    response_preview VARCHAR(1000) NULL,
    error_message VARCHAR(900) NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    backup_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_backup_jobs_routine_date (routine_id, created_at),
    KEY idx_backup_jobs_status_date (status, created_at),
    KEY idx_backup_jobs_backup (backup_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('operations.backup_automation', 'Gerenciar backup automático', 'Configurar e testar rotina de backup automático via n8n.', 'Operação');
