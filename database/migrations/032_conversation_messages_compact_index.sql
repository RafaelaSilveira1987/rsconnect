-- ZIP 30.2 / HOTFIX CONVERSAS
-- Garante um índice compacto e com nome novo para leitura das mensagens por empresa/conversa/id.
-- O controller também possui fallback seguro, mas este índice evita varreduras desnecessárias.

SET @index_columns := (
    SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_messages'
      AND index_name = 'idx_messages_tenant_conversation_id_v2'
);

SET @drop_sql := IF(
    @index_columns IS NOT NULL
    AND @index_columns <> 'tenant_id,conversation_id,id',
    'ALTER TABLE conversation_messages DROP INDEX idx_messages_tenant_conversation_id_v2',
    'SELECT ''Índice v2 ausente ou já está correto'' AS info'
);

PREPARE drop_stmt FROM @drop_sql;
EXECUTE drop_stmt;
DEALLOCATE PREPARE drop_stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_messages'
      AND index_name = 'idx_messages_tenant_conversation_id_v2'
);

SET @create_sql := IF(
    @index_exists = 0,
    'ALTER TABLE conversation_messages ADD INDEX idx_messages_tenant_conversation_id_v2 (tenant_id, conversation_id, id)',
    'SELECT ''Índice idx_messages_tenant_conversation_id_v2 já existe'' AS info'
);

PREPARE create_stmt FROM @create_sql;
EXECUTE create_stmt;
DEALLOCATE PREPARE create_stmt;

SELECT
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_order
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'conversation_messages'
  AND index_name IN (
      'idx_messages_tenant_conversation_id',
      'idx_messages_tenant_conversation_id_v2'
  )
GROUP BY INDEX_NAME
ORDER BY INDEX_NAME;
