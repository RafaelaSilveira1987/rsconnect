-- RS Connect v36.22.0 — Monitor pós-horário e solicitações comerciais pendentes
-- Torna a recuperação pós-horário visível/auditável e cria alertas de orçamento com tarefa no CRM.

CREATE TABLE IF NOT EXISTS ai_after_hours_monitor_settings (
    id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    max_items_per_run SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    last_run_at DATETIME NULL,
    last_run_status ENUM('success','partial','error','busy','disabled') NULL,
    last_summary_json JSON NULL,
    last_error TEXT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_after_hours_monitor_updated_by (updated_by_user_id),
    CONSTRAINT fk_after_hours_monitor_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_after_hours_monitor_settings (id, enabled, interval_minutes, max_items_per_run)
VALUES (1, 1, 15, 50)
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS tenant_commercial_request_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    detect_quote_requests TINYINT(1) NOT NULL DEFAULT 1,
    create_task TINYINT(1) NOT NULL DEFAULT 1,
    show_conversation_alert TINYINT(1) NOT NULL DEFAULT 1,
    notify_team TINYINT(1) NOT NULL DEFAULT 1,
    move_stage_mode ENUM('none','follow_crm','suggest','automatic') NOT NULL DEFAULT 'follow_crm',
    target_stage_id BIGINT UNSIGNED NULL,
    default_assignee_user_id BIGINT UNSIGNED NULL,
    response_sla_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_commercial_request_target_stage (target_stage_id),
    KEY idx_commercial_request_assignee (default_assignee_user_id),
    KEY idx_commercial_request_updated_by (updated_by_user_id),
    CONSTRAINT fk_commercial_request_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_commercial_request_stage FOREIGN KEY (target_stage_id) REFERENCES crm_stages(id) ON DELETE SET NULL,
    CONSTRAINT fk_commercial_request_assignee FOREIGN KEY (default_assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_commercial_request_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_commercial_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    lead_id BIGINT UNSIGNED NULL,
    incoming_message_id BIGINT UNSIGNED NULL,
    task_id BIGINT UNSIGNED NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    request_type ENUM('quote') NOT NULL DEFAULT 'quote',
    status ENUM('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
    confidence DECIMAL(4,3) NOT NULL DEFAULT 0.900,
    reason VARCHAR(500) NOT NULL,
    excerpt VARCHAR(700) NULL,
    detected_by ENUM('direct_rule','context_rule','ai_context','manual') NOT NULL DEFAULT 'direct_rule',
    due_at DATETIME NULL,
    detected_at DATETIME NOT NULL,
    last_detected_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    resolved_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_commercial_requests_tenant_status_due (tenant_id, status, due_at),
    KEY idx_commercial_requests_conversation_status (conversation_id, status, id),
    KEY idx_commercial_requests_lead_status (lead_id, status, id),
    KEY idx_commercial_requests_task (task_id),
    CONSTRAINT fk_commercial_requests_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_commercial_requests_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_commercial_requests_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_commercial_requests_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL,
    CONSTRAINT fk_commercial_requests_message FOREIGN KEY (incoming_message_id) REFERENCES conversation_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_commercial_requests_task FOREIGN KEY (task_id) REFERENCES crm_tasks(id) ON DELETE SET NULL,
    CONSTRAINT fk_commercial_requests_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_commercial_requests_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
