-- RS Connect v36.14.0 — diagnóstico do painel executivo da RS Admin
-- Somente leitura. Não altera dados.

SELECT
    COUNT(*) AS estruturas_encontradas,
    8 AS estruturas_esperadas
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'tenants',
      'conversations',
      'conversation_messages',
      'conversation_service_cycles',
      'calendar_appointments',
      'system_incidents',
      'tenant_health_incidents',
      'users'
  );

SELECT
    (SELECT COUNT(*) FROM tenants WHERE status = 'active') AS empresas_ativas,
    (SELECT COUNT(*) FROM conversations WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS conversas_30_dias,
    (SELECT COUNT(*) FROM conversation_messages WHERE sent_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS interacoes_30_dias,
    (SELECT COUNT(*) FROM conversation_messages WHERE direction = 'outgoing' AND sender_type = 'user' AND sent_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS respostas_humanas_30_dias,
    (SELECT COUNT(*) FROM conversation_messages WHERE direction = 'outgoing' AND sender_type = 'ai' AND sent_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS respostas_ia_30_dias,
    (SELECT COUNT(*) FROM calendar_appointments WHERE starts_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS agendamentos_30_dias;

SELECT
    COUNT(*) AS primeiras_respostas_medidas,
    COALESCE(ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at)))), 0) AS media_primeira_resposta_segundos,
    COALESCE(MIN(GREATEST(0, TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at))), 0) AS menor_primeira_resposta_segundos,
    COALESCE(MAX(GREATEST(0, TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at))), 0) AS maior_primeira_resposta_segundos
FROM conversation_service_cycles
WHERE first_incoming_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
  AND first_response_at IS NOT NULL;

SELECT
    SUM(resolved_at IS NULL) AS situacoes_sistema_abertas,
    COUNT(*) AS total_situacoes_sistema
FROM system_incidents;
