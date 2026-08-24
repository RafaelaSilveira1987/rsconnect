-- RS Connect ZIP 19 — Onboarding do Cliente e Construtor de Prompt
-- Execute uma única vez após a migration 017_evolution_qrcode_status.sql.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN prompt_builder_json JSON DEFAULT NULL AFTER system_prompt',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'prompt_builder_json'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN onboarding_assistant_prompt_completed_at DATETIME NULL AFTER onboarding_completed_at',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'onboarding_assistant_prompt_completed_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE tenants t
SET onboarding_assistant_prompt_completed_at = COALESCE(onboarding_assistant_prompt_completed_at, onboarding_completed_at)
WHERE onboarding_completed_at IS NOT NULL;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('onboarding.manage', 'Executar onboarding', 'Configurar empresa, WhatsApp e assistente guiado do cliente.', 'Implantação');
