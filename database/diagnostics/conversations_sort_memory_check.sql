-- Diagnóstico do HOTFIX 30.2
-- Substitua os valores abaixo por tenant_id e conversation_id reais se desejar usar EXPLAIN manualmente.

SELECT
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_order,
    CARDINALITY
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'conversation_messages'
GROUP BY INDEX_NAME, CARDINALITY
ORDER BY INDEX_NAME;

SELECT
    COUNT(*) AS total_messages,
    COUNT(DISTINCT conversation_id) AS conversations_with_messages,
    MIN(id) AS first_message_id,
    MAX(id) AS last_message_id
FROM conversation_messages;
