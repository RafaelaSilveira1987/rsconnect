-- RS Connect v36.15.0-r1
-- Consistência dos indicadores executivos entre RS Admin e empresa cliente.
-- Ajuste @tenant_id, @start_utc e @end_utc conforme a empresa e o período.

SET @tenant_id := 2;
SET @start_utc := '2026-07-06 03:00:00';
SET @end_utc := '2026-08-05 03:00:00';

SELECT
    COUNT(*) AS conversas_iniciadas
FROM conversations
WHERE tenant_id = @tenant_id
  AND created_at >= @start_utc
  AND created_at < @end_utc;

SELECT
    COUNT(*) AS respostas_medidas,
    ROUND(AVG(TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at))) AS media_segundos,
    MIN(TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at)) AS menor_segundos,
    MAX(TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at)) AS maior_segundos
FROM conversation_service_cycles
WHERE tenant_id = @tenant_id
  AND first_incoming_at >= @start_utc
  AND first_incoming_at < @end_utc
  AND first_response_at IS NOT NULL
  AND source NOT IN ('migration_snapshot', 'migration_069_recovery');

SELECT
    SUM(direction = 'outgoing' AND sender_type = 'ai') AS respostas_ia,
    SUM(direction = 'outgoing' AND sender_type = 'user') AS respostas_humanas,
    ROUND(
        100 * SUM(direction = 'outgoing' AND sender_type = 'ai')
        / NULLIF(
            SUM(direction = 'outgoing' AND sender_type IN ('ai','user')),
            0
        ),
        1
    ) AS participacao_ia_percentual
FROM conversation_messages
WHERE tenant_id = @tenant_id
  AND sent_at >= @start_utc
  AND sent_at < @end_utc;

SELECT
    COUNT(*) AS ciclos_historicos_fora_dos_cards
FROM conversation_service_cycles
WHERE tenant_id = @tenant_id
  AND first_incoming_at >= @start_utc
  AND first_incoming_at < @end_utc
  AND first_response_at IS NOT NULL
  AND source IN ('migration_snapshot', 'migration_069_recovery');
