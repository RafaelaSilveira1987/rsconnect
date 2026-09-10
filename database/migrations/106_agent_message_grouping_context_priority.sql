-- RS Connect 36.29.4 — agrupamento obrigatório de mensagens e prioridade ao turno atual.
-- Mantém cooldown_seconds como o tempo de silêncio; as novas flags controlam o comportamento por assistente.

SET @db := DATABASE();

SET @has_message_grouping_enabled := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'ai_agents' AND column_name = 'message_grouping_enabled'
);
SET @sql_message_grouping_enabled := IF(
    @has_message_grouping_enabled = 0,
    'ALTER TABLE ai_agents ADD COLUMN message_grouping_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER cooldown_seconds',
    'SELECT 1'
);
PREPARE stmt FROM @sql_message_grouping_enabled;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_prioritize_current_turn := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'ai_agents' AND column_name = 'prioritize_current_turn'
);
SET @sql_prioritize_current_turn := IF(
    @has_prioritize_current_turn = 0,
    'ALTER TABLE ai_agents ADD COLUMN prioritize_current_turn TINYINT(1) NOT NULL DEFAULT 1 AFTER message_grouping_enabled',
    'SELECT 1'
);
PREPARE stmt FROM @sql_prioritize_current_turn;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Preserva a intenção das instalações anteriores: cooldown 0 significava resposta imediata.
UPDATE ai_agents
SET message_grouping_enabled = CASE WHEN COALESCE(cooldown_seconds, 0) > 0 THEN 1 ELSE 0 END
WHERE @has_message_grouping_enabled = 0;
