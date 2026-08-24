-- RS Connect ZIP 18 — Pré-agendamento opcional e menus por empresa
-- Execute uma vez após aplicar o ZIP 18.

DELIMITER $$

DROP PROCEDURE IF EXISTS rs_add_column_if_missing$$
CREATE PROCEDURE rs_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS rs_add_index_if_missing$$
CREATE PROCEDURE rs_add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

SET @status_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'calendar_appointments'
      AND COLUMN_NAME = 'status'
    LIMIT 1
);

SET @sql := IF(@status_type IS NOT NULL AND @status_type NOT LIKE '%pre_scheduled%',
    "ALTER TABLE calendar_appointments MODIFY status ENUM('pre_scheduled','awaiting_approval','scheduled','confirmed','completed','cancelled','rejected','rescheduled','no_show') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CALL rs_add_column_if_missing('calendar_appointments', 'is_pre_schedule', 'tinyint(1) NOT NULL DEFAULT 0 AFTER sync_status');
CALL rs_add_column_if_missing('calendar_appointments', 'pre_schedule_source', "varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER is_pre_schedule");
CALL rs_add_column_if_missing('calendar_appointments', 'preferred_day_text', "varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER pre_schedule_source");
CALL rs_add_column_if_missing('calendar_appointments', 'preferred_time_text', "varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER preferred_day_text");
CALL rs_add_column_if_missing('calendar_appointments', 'approval_status', "enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER preferred_time_text");
CALL rs_add_column_if_missing('calendar_appointments', 'approval_notes', "varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER approval_status");
CALL rs_add_column_if_missing('calendar_appointments', 'approved_by_user_id', 'bigint unsigned DEFAULT NULL AFTER approval_notes');
CALL rs_add_column_if_missing('calendar_appointments', 'approved_at', 'datetime DEFAULT NULL AFTER approved_by_user_id');

CALL rs_add_index_if_missing('calendar_appointments', 'idx_calendar_pre_schedule', '(tenant_id, is_pre_schedule, status, starts_at)');
CALL rs_add_index_if_missing('calendar_appointments', 'idx_calendar_approval', '(tenant_id, approval_status, starts_at)');

CALL rs_add_column_if_missing('conversations', 'agenda_intent_detected', 'tinyint(1) NOT NULL DEFAULT 0 AFTER last_ai_suggestion_at');
CALL rs_add_column_if_missing('conversations', 'agenda_intent_at', 'datetime DEFAULT NULL AFTER agenda_intent_detected');
CALL rs_add_column_if_missing('conversations', 'agenda_intent_note', "varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER agenda_intent_at");
CALL rs_add_index_if_missing('conversations', 'idx_conversations_agenda_intent', '(tenant_id, agenda_intent_detected, agenda_intent_at)');

CREATE TABLE IF NOT EXISTS tenant_pre_schedule_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    require_human_approval TINYINT(1) NOT NULL DEFAULT 1,
    ai_can_suggest_slots TINYINT(1) NOT NULL DEFAULT 1,
    ai_can_confirm TINYINT(1) NOT NULL DEFAULT 0,
    default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    default_message VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT 'Vou registrar sua preferência e encaminhar para confirmação da profissional.',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_pre_schedule_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenant_pre_schedule_settings (tenant_id, enabled, require_human_approval, ai_can_suggest_slots, ai_can_confirm)
SELECT id, 0, 1, 1, 0 FROM tenants;

CREATE TABLE IF NOT EXISTS tenant_module_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    module_key VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_module_settings (tenant_id, module_key),
    KEY idx_tenant_module_enabled (tenant_id, is_enabled, is_visible),
    CONSTRAINT fk_tenant_module_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenant_module_settings (tenant_id, module_key, is_visible, is_enabled)
SELECT t.id, m.module_key, m.is_visible, m.is_enabled
FROM tenants t
JOIN (
    SELECT 'dashboard' AS module_key, 1 AS is_visible, 1 AS is_enabled UNION ALL
    SELECT 'conversations', 1, 1 UNION ALL
    SELECT 'contacts', 1, 1 UNION ALL
    SELECT 'crm', 1, 1 UNION ALL
    SELECT 'tasks', 1, 1 UNION ALL
    SELECT 'calendar', 1, 1 UNION ALL
    SELECT 'reports', 1, 1 UNION ALL
    SELECT 'instances', 1, 1 UNION ALL
    SELECT 'agents', 1, 1 UNION ALL
    SELECT 'automations', 1, 1 UNION ALL
    SELECT 'notifications', 1, 1 UNION ALL
    SELECT 'subscription', 1, 1 UNION ALL
    SELECT 'users', 1, 1 UNION ALL
    SELECT 'permissions', 1, 1 UNION ALL
    SELECT 'campaigns', 0, 0 UNION ALL
    SELECT 'attendance_filters', 0, 0 UNION ALL
    SELECT 'company_settings', 1, 1
) m;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('pre_schedule.view', 'Visualizar pré-agendamentos', 'Acessar solicitações de pré-agendamento na agenda.', 'Agenda'),
    ('pre_schedule.manage', 'Gerenciar pré-agendamentos', 'Aprovar, recusar e remarcar pré-agendamentos.', 'Agenda');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('pre_schedule.view', 'pre_schedule.manage')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key = 'pre_schedule.view'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
  );

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
DROP PROCEDURE IF EXISTS rs_add_index_if_missing;
