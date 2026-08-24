-- HOTFIX 29.2
-- Evita erro MySQL 1038 (Out of sort memory) ao abrir conversas com muito histórico.
-- Índice alinhado ao filtro tenant + conversa e à leitura das mensagens mais recentes por id.

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_messages'
      AND index_name = 'idx_messages_tenant_conversation_id'
);

SET @sql := IF(
    @index_exists = 0,
    'ALTER TABLE conversation_messages ADD INDEX idx_messages_tenant_conversation_id (tenant_id, conversation_id, id)',
    'SELECT ''Índice idx_messages_tenant_conversation_id já existe'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
