-- Diagnóstico RS Connect 36.19.0
SELECT IF(COUNT(*) = 3, 'OK', 'ERRO') AS ai_memory_agent_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ai_agents'
  AND COLUMN_NAME IN ('ai_progressive_memory_enabled','ai_memory_refresh_messages','ai_memory_max_chars');

SELECT IF(COUNT(*) = 2, 'OK', 'ERRO') AS ai_memory_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('conversation_ai_memory','contact_ai_memory');

SELECT COUNT(*) AS conversation_memory_rows,
       SUM(status = 'active') AS active_rows,
       SUM(status = 'error') AS error_rows,
       SUM(refresh_count) AS refreshes
FROM conversation_ai_memory;

SELECT COUNT(*) AS contact_memory_rows,
       SUM(status = 'active') AS active_rows,
       SUM(status = 'error') AS error_rows,
       SUM(refresh_count) AS refreshes
FROM contact_ai_memory;

SELECT execution_strategy,
       COUNT(*) AS events,
       SUM(COALESCE(provider_calls,0)) AS provider_calls,
       SUM(COALESCE(provider_calls_avoided,0)) AS provider_calls_avoided,
       SUM(COALESCE(total_tokens,0)) AS total_tokens,
       SUM(COALESCE(estimated_input_tokens_avoided,0)) AS input_tokens_avoided
FROM ai_usage_events
WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
GROUP BY execution_strategy;
