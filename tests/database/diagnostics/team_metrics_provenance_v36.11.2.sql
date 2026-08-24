-- RS Connect 36.11.2 — Diagnóstico da proveniência das métricas de ciclo
-- Não altera dados. Ajuste @tenant_id para uma empresa específica ou mantenha 0 para todas.

SET @tenant_id = 0;
SET @start_utc = '2026-07-01 00:00:00';
SET @end_utc = '2026-07-31 23:59:59';

-- 1. Marco global a partir do qual o contrato técnico passou a gravar datas em UTC.
SELECT
    storage_timezone,
    display_timezone,
    cutover_at_utc,
    historical_normalized_at_utc
FROM rs_datetime_contract
WHERE id = 1;

-- 2. Quantidade de ciclos medidos por empresa e qualidade do dado.
SELECT
    sc.tenant_id,
    t.name AS empresa,
    CASE
        WHEN COALESCE(sc.source, '') IN ('migration_snapshot', 'migration_069_recovery')
          OR (dc.cutover_at_utc IS NOT NULL AND sc.first_response_at < dc.cutover_at_utc)
            THEN 'historical_recovered'
        WHEN COALESCE(sc.source, '') = 'message_cycle_recovery'
            THEN 'operational_recovered'
        ELSE 'operational'
    END AS data_quality,
    COUNT(*) AS respostas_medidas,
    ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at)))) AS media_segundos,
    MIN(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at))) AS menor_segundos,
    MAX(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at))) AS maior_segundos
FROM conversation_service_cycles sc
INNER JOIN tenants t ON t.id = sc.tenant_id
LEFT JOIN rs_datetime_contract dc ON dc.id = 1
WHERE (@tenant_id = 0 OR sc.tenant_id = @tenant_id)
  AND sc.first_incoming_at IS NOT NULL
  AND sc.first_response_at IS NOT NULL
  AND sc.first_response_user_id IS NOT NULL
  AND sc.first_response_at BETWEEN @start_utc AND @end_utc
GROUP BY sc.tenant_id, t.name, data_quality
ORDER BY sc.tenant_id, data_quality;

-- 3. Detalhamento auditável dos ciclos e da origem utilizada pelo relatório.
SELECT
    sc.tenant_id,
    sc.conversation_id,
    sc.cycle_number,
    COALESCE(NULLIF(ct.name, ''), ct.phone, 'Cliente') AS cliente,
    COALESCE(NULLIF(u.whatsapp_display_name, ''), u.name, 'Usuário') AS profissional,
    sc.first_incoming_at AS entrada_utc,
    sc.first_response_at AS resposta_utc,
    GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at)) AS resposta_segundos,
    sc.cycle_status,
    sc.source,
    dc.cutover_at_utc,
    CASE
        WHEN COALESCE(sc.source, '') IN ('migration_snapshot', 'migration_069_recovery')
          OR (dc.cutover_at_utc IS NOT NULL AND sc.first_response_at < dc.cutover_at_utc)
            THEN 'historical_recovered'
        WHEN COALESCE(sc.source, '') = 'message_cycle_recovery'
            THEN 'operational_recovered'
        ELSE 'operational'
    END AS data_quality,
    CASE
        WHEN COALESCE(sc.source, '') NOT IN ('migration_snapshot', 'migration_069_recovery')
         AND (dc.cutover_at_utc IS NULL OR sc.first_response_at >= dc.cutover_at_utc)
            THEN 1 ELSE 0
    END AS incluido_no_filtro_operacional
FROM conversation_service_cycles sc
INNER JOIN conversations c ON c.id = sc.conversation_id AND c.tenant_id = sc.tenant_id
INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = sc.tenant_id
LEFT JOIN users u ON u.id = sc.first_response_user_id AND u.tenant_id = sc.tenant_id
LEFT JOIN rs_datetime_contract dc ON dc.id = 1
WHERE (@tenant_id = 0 OR sc.tenant_id = @tenant_id)
  AND sc.first_incoming_at IS NOT NULL
  AND sc.first_response_at IS NOT NULL
  AND sc.first_response_user_id IS NOT NULL
  AND sc.first_response_at BETWEEN @start_utc AND @end_utc
ORDER BY sc.first_response_at DESC, sc.id DESC;

-- 4. Deve retornar zero: ciclos marcados como operacionais apesar de fonte histórica explícita.
SELECT COUNT(*) AS inconsistencias_classificacao
FROM conversation_service_cycles sc
LEFT JOIN rs_datetime_contract dc ON dc.id = 1
WHERE (@tenant_id = 0 OR sc.tenant_id = @tenant_id)
  AND COALESCE(sc.source, '') IN ('migration_snapshot', 'migration_069_recovery')
  AND CASE
        WHEN COALESCE(sc.source, '') NOT IN ('migration_snapshot', 'migration_069_recovery')
         AND (dc.cutover_at_utc IS NULL OR sc.first_response_at >= dc.cutover_at_utc)
        THEN 1 ELSE 0
      END = 1;
