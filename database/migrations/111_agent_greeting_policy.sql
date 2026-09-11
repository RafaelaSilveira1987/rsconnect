-- RS Connect 36.31.2 — política de saudação por assistente.
-- Permite saudar todos, somente novos contatos ou desativar a saudação automática.
-- Instalações existentes preservam o comportamento anterior com all_contacts.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_greeting_mode ENUM(''all_contacts'',''new_contacts'',''disabled'') NOT NULL DEFAULT ''all_contacts'' AFTER ai_greeting_reply',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_greeting_mode'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_agents ADD COLUMN ai_greeting_use_contact_name TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_greeting_mode',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_agents' AND COLUMN_NAME = 'ai_greeting_use_contact_name'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
