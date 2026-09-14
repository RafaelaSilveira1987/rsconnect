-- RS Connect 36.34.0 — Production Readiness Fase C / SLA operacional
-- Política persistente de primeira resposta humana, alerta preventivo e relógio
-- compatível com expediente. Migration aditiva e idempotente.

SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS tenant_sla_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    target_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    warning_percent TINYINT UNSIGNED NOT NULL DEFAULT 80,
    count_outside_business_hours TINYINT(1) NOT NULL DEFAULT 0,
    timezone VARCHAR(80) NOT NULL DEFAULT 'America/Sao_Paulo',
    business_hours_json JSON NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_tenant_sla_updated (updated_at),
    CONSTRAINT fk_tenant_sla_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_sla_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_sla_settings
    (tenant_id, enabled, target_minutes, warning_percent, count_outside_business_hours,
     timezone, business_hours_json, updated_at)
SELECT t.id, 1, 30, 80, 0,
       COALESCE(NULLIF(os.business_timezone, ''), 'America/Sao_Paulo'),
       COALESCE(os.business_hours_json, JSON_OBJECT('days', JSON_ARRAY('mon','tue','wed','thu','fri'), 'start', '08:00', 'end', '18:00')),
       UTC_TIMESTAMP()
FROM tenants t
LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = t.id
LEFT JOIN tenant_sla_settings ss ON ss.tenant_id = t.id
WHERE ss.tenant_id IS NULL;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='conversation_service_cycles' AND COLUMN_NAME='sla_target_minutes');
SET @sql := IF(@exists=0, 'ALTER TABLE conversation_service_cycles ADD COLUMN sla_target_minutes SMALLINT UNSIGNED NULL AFTER source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='conversation_service_cycles' AND COLUMN_NAME='sla_warning_percent');
SET @sql := IF(@exists=0, 'ALTER TABLE conversation_service_cycles ADD COLUMN sla_warning_percent TINYINT UNSIGNED NULL AFTER sla_target_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='conversation_service_cycles' AND COLUMN_NAME='sla_count_outside_business_hours');
SET @sql := IF(@exists=0, 'ALTER TABLE conversation_service_cycles ADD COLUMN sla_count_outside_business_hours TINYINT(1) NULL AFTER sla_warning_percent', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='conversation_service_cycles' AND COLUMN_NAME='sla_timezone');
SET @sql := IF(@exists=0, 'ALTER TABLE conversation_service_cycles ADD COLUMN sla_timezone VARCHAR(80) NULL AFTER sla_count_outside_business_hours', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='conversation_service_cycles' AND COLUMN_NAME='sla_business_hours_json');
SET @sql := IF(@exists=0, 'ALTER TABLE conversation_service_cycles ADD COLUMN sla_business_hours_json JSON NULL AFTER sla_timezone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='conversation_service_cycles' AND INDEX_NAME='idx_service_cycles_sla_pending');
SET @sql := IF(@exists=0, 'ALTER TABLE conversation_service_cycles ADD INDEX idx_service_cycles_sla_pending (tenant_id, cycle_status, first_response_at, first_incoming_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Snapshot para ciclos existentes. O valor fica congelado no ciclo e alterações
-- futuras da configuração afetam somente ciclos ainda sem snapshot/novos ciclos.
UPDATE conversation_service_cycles sc
LEFT JOIN tenant_sla_settings ss ON ss.tenant_id = sc.tenant_id
LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = sc.tenant_id
SET sc.sla_target_minutes = COALESCE(sc.sla_target_minutes, ss.target_minutes, 30),
    sc.sla_warning_percent = COALESCE(sc.sla_warning_percent, ss.warning_percent, 80),
    sc.sla_count_outside_business_hours = COALESCE(sc.sla_count_outside_business_hours, ss.count_outside_business_hours, 0),
    sc.sla_timezone = COALESCE(NULLIF(sc.sla_timezone, ''), NULLIF(ss.timezone, ''), NULLIF(os.business_timezone, ''), 'America/Sao_Paulo'),
    sc.sla_business_hours_json = COALESCE(sc.sla_business_hours_json, ss.business_hours_json, os.business_hours_json,
        JSON_OBJECT('days', JSON_ARRAY('mon','tue','wed','thu','fri'), 'start', '08:00', 'end', '18:00'))
WHERE sc.sla_target_minutes IS NULL
   OR sc.sla_warning_percent IS NULL
   OR sc.sla_count_outside_business_hours IS NULL
   OR sc.sla_timezone IS NULL
   OR sc.sla_business_hours_json IS NULL;

DELIMITER $$

-- Recria o trigger canônico da migration 071 e acrescenta somente o snapshot
-- do SLA na primeira entrada do cliente.
DROP TRIGGER IF EXISTS trg_rs_messages_after_insert_metrics$$
CREATE TRIGGER trg_rs_messages_after_insert_metrics
AFTER INSERT ON conversation_messages
FOR EACH ROW
BEGIN
    DECLARE next_cycle_number INT UNSIGNED DEFAULT 1;

    IF NOT EXISTS (
        SELECT 1
        FROM conversation_service_cycles active_cycle
        WHERE active_cycle.conversation_id = NEW.conversation_id
          AND active_cycle.tenant_id = NEW.tenant_id
          AND active_cycle.cycle_status = 'active'
    ) THEN
        SELECT COALESCE(MAX(existing_cycle.cycle_number), 0) + 1
          INTO next_cycle_number
        FROM conversation_service_cycles existing_cycle
        WHERE existing_cycle.conversation_id = NEW.conversation_id;

        INSERT IGNORE INTO conversation_service_cycles
            (tenant_id, conversation_id, cycle_number, opened_at,
             first_incoming_at, last_incoming_at,
             cycle_status, source,
             sla_target_minutes, sla_warning_percent, sla_count_outside_business_hours,
             sla_timezone, sla_business_hours_json)
        SELECT
            c.tenant_id,
            c.id,
            next_cycle_number,
            COALESCE(c.opened_at, c.created_at, NEW.sent_at, UTC_TIMESTAMP()),
            CASE WHEN NEW.direction = 'incoming' THEN NEW.sent_at ELSE c.first_incoming_at END,
            CASE WHEN NEW.direction = 'incoming' THEN NEW.sent_at ELSE c.last_incoming_at END,
            'active',
            'message_cycle_recovery',
            COALESCE(ss.target_minutes, 30),
            COALESCE(ss.warning_percent, 80),
            COALESCE(ss.count_outside_business_hours, 0),
            COALESCE(NULLIF(ss.timezone, ''), NULLIF(os.business_timezone, ''), 'America/Sao_Paulo'),
            COALESCE(ss.business_hours_json, os.business_hours_json,
                JSON_OBJECT('days', JSON_ARRAY('mon','tue','wed','thu','fri'), 'start', '08:00', 'end', '18:00'))
        FROM conversations c
        LEFT JOIN tenant_sla_settings ss ON ss.tenant_id = c.tenant_id
        LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = c.tenant_id
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id;
    END IF;

    IF NEW.direction = 'incoming' THEN
        UPDATE conversations c
        SET c.first_incoming_at = COALESCE(c.first_incoming_at, NEW.sent_at),
            c.last_incoming_at = NEW.sent_at
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id;

        UPDATE conversation_service_cycles active_cycle
        LEFT JOIN tenant_sla_settings ss ON ss.tenant_id = active_cycle.tenant_id
        LEFT JOIN tenant_onboarding_settings os ON os.tenant_id = active_cycle.tenant_id
        SET active_cycle.first_incoming_at = COALESCE(active_cycle.first_incoming_at, NEW.sent_at),
            active_cycle.last_incoming_at = NEW.sent_at,
            active_cycle.sla_target_minutes = COALESCE(active_cycle.sla_target_minutes, ss.target_minutes, 30),
            active_cycle.sla_warning_percent = COALESCE(active_cycle.sla_warning_percent, ss.warning_percent, 80),
            active_cycle.sla_count_outside_business_hours = COALESCE(active_cycle.sla_count_outside_business_hours, ss.count_outside_business_hours, 0),
            active_cycle.sla_timezone = COALESCE(NULLIF(active_cycle.sla_timezone, ''), NULLIF(ss.timezone, ''), NULLIF(os.business_timezone, ''), 'America/Sao_Paulo'),
            active_cycle.sla_business_hours_json = COALESCE(active_cycle.sla_business_hours_json, ss.business_hours_json, os.business_hours_json,
                JSON_OBJECT('days', JSON_ARRAY('mon','tue','wed','thu','fri'), 'start', '08:00', 'end', '18:00'))
        WHERE active_cycle.conversation_id = NEW.conversation_id
          AND active_cycle.tenant_id = NEW.tenant_id
          AND active_cycle.cycle_status = 'active'
        ORDER BY active_cycle.cycle_number DESC
        LIMIT 1;
    ELSEIF NEW.direction = 'outgoing' AND NEW.sender_type = 'user' THEN
        UPDATE conversations c
        SET c.first_response_user_id = COALESCE(c.first_response_user_id, NEW.sender_user_id),
            c.first_response_at = COALESCE(c.first_response_at, NEW.sent_at)
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id
          AND c.first_response_at IS NULL
          AND c.first_incoming_at IS NOT NULL
          AND c.first_incoming_at <= NEW.sent_at;

        UPDATE conversation_service_cycles active_cycle
        SET active_cycle.first_response_user_id = NEW.sender_user_id,
            active_cycle.first_response_at = NEW.sent_at
        WHERE active_cycle.conversation_id = NEW.conversation_id
          AND active_cycle.tenant_id = NEW.tenant_id
          AND active_cycle.cycle_status = 'active'
          AND active_cycle.first_response_at IS NULL
          AND active_cycle.first_incoming_at IS NOT NULL
          AND active_cycle.first_incoming_at <= NEW.sent_at
        ORDER BY active_cycle.cycle_number DESC
        LIMIT 1;
    END IF;
END$$

DELIMITER ;

SELECT 'Migration 115 aplicada: política de SLA operacional, snapshot por ciclo e alerta preventivo preparados.' AS resultado;
