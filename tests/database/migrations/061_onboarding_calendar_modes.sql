USE rs_connect;

-- RS Connect 36.6.34.2 — escolha explícita entre Agenda interna,
-- Agenda inteligente liberada pela RS e operação sem agenda.

SET @db := DATABASE();

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_onboarding_settings' AND COLUMN_NAME='calendar_mode'),
    'SELECT 1',
    "ALTER TABLE tenant_onboarding_settings ADD COLUMN calendar_mode ENUM('none','internal','smart') NOT NULL DEFAULT 'none' AFTER cooldown_seconds"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_onboarding_settings' AND COLUMN_NAME='smart_calendar_status'),
    'SELECT 1',
    "ALTER TABLE tenant_onboarding_settings ADD COLUMN smart_calendar_status ENUM('locked','configuring','ready') NOT NULL DEFAULT 'locked' AFTER calendar_mode"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_onboarding_settings' AND COLUMN_NAME='smart_calendar_released_by'),
    'SELECT 1',
    'ALTER TABLE tenant_onboarding_settings ADD COLUMN smart_calendar_released_by BIGINT UNSIGNED NULL AFTER smart_calendar_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_onboarding_settings' AND COLUMN_NAME='smart_calendar_released_at'),
    'SELECT 1',
    'ALTER TABLE tenant_onboarding_settings ADD COLUMN smart_calendar_released_at DATETIME NULL AFTER smart_calendar_released_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Reconhece integrações já operacionais como liberadas/homologadas pela RS.
UPDATE tenant_onboarding_settings os
SET os.smart_calendar_status = 'ready',
    os.smart_calendar_released_at = COALESCE(os.smart_calendar_released_at, NOW())
WHERE EXISTS (
    SELECT 1
    FROM tenant_calendar_availability_settings s
    WHERE s.tenant_id = os.tenant_id
      AND s.enabled = 1
      AND s.use_n8n = 1
);

-- Preserva o comportamento de empresas existentes.
UPDATE tenant_onboarding_settings os
LEFT JOIN tenant_pre_schedule_settings ps ON ps.tenant_id = os.tenant_id
SET os.calendar_mode = CASE
    WHEN COALESCE(ps.enabled, 0) = 0 THEN 'none'
    WHEN os.smart_calendar_status = 'ready' THEN 'smart'
    ELSE 'internal'
END
WHERE os.calendar_mode = 'none';
