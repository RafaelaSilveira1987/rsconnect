-- RS Connect 36.17.0 — diagnóstico da camada de eficiência de IA
-- Execute após database/migrations/077_ai_efficiency_foundation.sql.

SELECT
    COUNT(*) AS colunas_agente_encontradas,
    CASE WHEN COUNT(*) = 4 THEN 'OK' ELSE 'PENDENTE' END AS situacao
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ai_agents'
  AND COLUMN_NAME IN (
      'ai_efficiency_mode',
      'ai_max_output_tokens',
      'ai_knowledge_budget_chars',
      'ai_selective_knowledge'
  );

SELECT
    COUNT(*) AS colunas_telemetria_encontradas,
    CASE WHEN COUNT(*) = 6 THEN 'OK' ELSE 'PENDENTE' END AS situacao
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ai_usage_events'
  AND COLUMN_NAME IN (
      'efficiency_mode',
      'history_messages_total',
      'history_messages_sent',
      'knowledge_chars_total',
      'knowledge_chars_sent',
      'estimated_input_tokens_avoided'
  );

SELECT
    ai_efficiency_mode,
    COUNT(*) AS assistentes,
    SUM(ai_selective_knowledge = 1) AS selecao_base_ativa,
    AVG(max_context_messages) AS media_limite_historico,
    AVG(ai_max_output_tokens) AS media_saida_personalizada,
    AVG(ai_knowledge_budget_chars) AS media_orcamento_base_personalizado
FROM ai_agents
GROUP BY ai_efficiency_mode
ORDER BY ai_efficiency_mode;

SELECT
    efficiency_mode,
    COUNT(*) AS eventos,
    COALESCE(SUM(provider_calls), 0) AS chamadas_provedor,
    COALESCE(SUM(input_tokens), 0) AS tokens_entrada_reais,
    COALESCE(SUM(output_tokens), 0) AS tokens_saida_reais,
    COALESCE(SUM(estimated_input_tokens_avoided), 0) AS tokens_entrada_evitados_estimados,
    COALESCE(SUM(history_messages_sent), 0) AS mensagens_historico_enviadas,
    COALESCE(SUM(knowledge_chars_sent), 0) AS caracteres_base_enviados
FROM ai_usage_events
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY efficiency_mode
ORDER BY eventos DESC;

SELECT
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ai_usage_events'
  AND INDEX_NAME = 'idx_ai_usage_efficiency'
GROUP BY INDEX_NAME;
