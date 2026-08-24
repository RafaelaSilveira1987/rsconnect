-- RS Connect ZIP 34.4 — Saúde do cliente e diagnóstico por empresa
-- Pode ser executada novamente com segurança.

CREATE TABLE IF NOT EXISTS tenant_health_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    overall_status ENUM('healthy','attention','critical','idle','blocked') NOT NULL DEFAULT 'attention',
    score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ok_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    info_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    warning_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    critical_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    summary_json JSON NULL,
    source ENUM('manual','cron','automatic') NOT NULL DEFAULT 'manual',
    checked_by BIGINT UNSIGNED NULL,
    checked_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant_health_snapshots_tenant_date (tenant_id, checked_at),
    KEY idx_tenant_health_snapshots_status (overall_status, checked_at),
    CONSTRAINT fk_health_snapshot_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_health_snapshot_user FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_health_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(60) NOT NULL,
    component_key VARCHAR(150) NOT NULL,
    component_label VARCHAR(190) NOT NULL,
    status ENUM('ok','info','warning','critical') NOT NULL DEFAULT 'info',
    summary VARCHAR(500) NOT NULL,
    details_json JSON NULL,
    action_url VARCHAR(500) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    checked_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant_health_checks_snapshot (snapshot_id, sort_order, id),
    KEY idx_tenant_health_checks_tenant_status (tenant_id, status, checked_at),
    KEY idx_tenant_health_checks_component (tenant_id, category, component_key),
    CONSTRAINT fk_health_check_snapshot FOREIGN KEY (snapshot_id) REFERENCES tenant_health_snapshots(id) ON DELETE CASCADE,
    CONSTRAINT fk_health_check_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_health_incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    fingerprint VARCHAR(190) NOT NULL,
    category VARCHAR(60) NOT NULL,
    component_key VARCHAR(150) NOT NULL,
    severity ENUM('warning','critical') NOT NULL DEFAULT 'warning',
    status ENUM('open','acknowledged','monitoring','resolved') NOT NULL DEFAULT 'open',
    title VARCHAR(190) NOT NULL,
    summary VARCHAR(700) NOT NULL,
    technical_details_json JSON NULL,
    related_url VARCHAR(500) NULL,
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    acknowledged_at DATETIME NULL,
    resolved_at DATETIME NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    last_snapshot_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_health_incident_fingerprint (tenant_id, fingerprint),
    KEY idx_tenant_health_incidents_status (tenant_id, status, severity, last_seen_at),
    KEY idx_tenant_health_incidents_assignee (assigned_user_id, status),
    CONSTRAINT fk_health_incident_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_health_incident_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_health_incident_snapshot FOREIGN KEY (last_snapshot_id) REFERENCES tenant_health_snapshots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_health_incident_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('opened','reopened','acknowledged','monitoring','resolved','auto_resolved','note') NOT NULL,
    note VARCHAR(1000) NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_health_incident_events_incident (incident_id, created_at),
    KEY idx_health_incident_events_tenant (tenant_id, created_at),
    CONSTRAINT fk_health_incident_event_incident FOREIGN KEY (incident_id) REFERENCES tenant_health_incidents(id) ON DELETE CASCADE,
    CONSTRAINT fk_health_incident_event_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_health_incident_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
