-- RS Connect ZIP 34.3.1 — Intervalo preservado, reprocessamento seguro e preferência para reações
-- Pode ser executada novamente com segurança.
-- Esta migration NÃO altera os intervalos já configurados nos assistentes.

SET @db_name = DATABASE();

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = @db_name AND table_name = 'ai_agents' AND column_name = 'reply_to_reactions') = 0,
    'ALTER TABLE ai_agents ADD COLUMN reply_to_reactions TINYINT(1) NOT NULL DEFAULT 0 AFTER cooldown_seconds',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
