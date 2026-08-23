-- Diagnóstico RS Connect 36.18.0 — Economia de IA fase 2
SET @database_name = DATABASE();

SELECT
    CASE WHEN COUNT(*) = 7 THEN 'OK' ELSE 'PENDENTE' END AS configuracoes_assistente,
    COUNT(*) AS colunas_encontradas
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @database_name
  AND TABLE_NAME = 'ai_agents'
  AND COLUMN_NAME IN (
      'ai_local_replies_enabled','ai_greeting_reply','ai_gratitude_reply','ai_farewell_reply',
      'ai_menu_reply','ai_exact_cache_enabled','ai_exact_cache_ttl_hours'
  );

SELECT
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'PENDENTE' END AS tabela_cache
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'ai_response_cache';

SELECT
    CASE WHEN COUNT(*) = 2 THEN 'OK' ELSE 'PENDENTE' END AS telemetria_economia,
    COUNT(*) AS colunas_encontradas
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @database_name
  AND TABLE_NAME = 'ai_usage_events'
  AND COLUMN_NAME IN ('execution_strategy','provider_calls_avoided');

SELECT execution_strategy, COUNT(*) AS respostas, SUM(provider_calls_avoided) AS chamadas_evitadas
FROM ai_usage_events
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY execution_strategy
ORDER BY respostas DESC;
