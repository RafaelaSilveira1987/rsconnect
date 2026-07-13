-- ZIP 21 — Backup, Monitoramento e Recuperação
-- Painel operacional para Super Admin RS.

CREATE TABLE IF NOT EXISTS system_health_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_key VARCHAR(80) NOT NULL,
    label VARCHAR(140) NOT NULL,
    status ENUM('ok','warning','down') NOT NULL DEFAULT 'warning',
    message TEXT NULL,
    latency_ms INT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_health_key_id (check_key, id),
    KEY idx_health_status_checked (status, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_backups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backup_type VARCHAR(40) NOT NULL DEFAULT 'manual',
    status ENUM('success','error','running') NOT NULL DEFAULT 'success',
    file_name VARCHAR(255) NULL,
    location VARCHAR(600) NULL,
    size_bytes BIGINT UNSIGNED NULL,
    checksum VARCHAR(180) NULL,
    notes TEXT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_backups_status_created (status, created_at),
    KEY idx_backups_finished (finished_at),
    CONSTRAINT fk_system_backups_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_incidents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(140) NOT NULL,
    severity ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
    message TEXT NULL,
    context_json JSON NULL,
    resolved_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_incidents_severity_created (severity, created_at),
    KEY idx_incidents_event_created (event, created_at),
    CONSTRAINT fk_system_incidents_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_health_checks (check_key, label, status, message, checked_at)
SELECT 'database', 'Banco de dados', 'warning', 'Clique em Verificar agora para iniciar o monitoramento.', NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_health_checks WHERE check_key = 'database' LIMIT 1);

INSERT INTO system_health_checks (check_key, label, status, message, checked_at)
SELECT 'backup', 'Backup', 'warning', 'Nenhum backup registrado ainda.', NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_health_checks WHERE check_key = 'backup' LIMIT 1);

INSERT INTO system_health_checks (check_key, label, status, message, checked_at)
SELECT 'evolution', 'Evolution API', 'warning', 'Clique em Verificar agora para consultar o endpoint configurado.', NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_health_checks WHERE check_key = 'evolution' LIMIT 1);

INSERT INTO system_health_checks (check_key, label, status, message, checked_at)
SELECT 'n8n', 'n8n', 'warning', 'Clique em Verificar agora para consultar o endpoint configurado.', NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_health_checks WHERE check_key = 'n8n' LIMIT 1);

INSERT INTO system_incidents (event, severity, message)
SELECT 'operations.monitoring_enabled', 'info', 'ZIP 21 aplicado: monitoramento operacional habilitado.'
WHERE NOT EXISTS (SELECT 1 FROM system_incidents WHERE event = 'operations.monitoring_enabled' LIMIT 1);
