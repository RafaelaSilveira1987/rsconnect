-- RS Connect 36.10.4 — Diagnóstico do contrato UTC

SELECT
    @@GLOBAL.time_zone AS mysql_global_timezone,
    @@SESSION.time_zone AS mysql_session_timezone,
    UTC_TIMESTAMP() AS database_utc,
    NOW() AS database_session_now;

SELECT
    id,
    storage_timezone,
    display_timezone,
    cutover_at_utc,
    historical_normalized_at_utc,
    updated_at
FROM rs_datetime_contract
WHERE id = 1;

SELECT
    TRIGGER_NAME,
    EVENT_MANIPULATION,
    EVENT_OBJECT_TABLE,
    ACTION_TIMING
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME LIKE 'trg_rs_%'
ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME;

-- As datas técnicas da mesma mensagem/ciclo devem estar no mesmo eixo UTC.
SELECT
    m.id AS message_id,
    m.conversation_id,
    m.direction,
    m.sender_type,
    m.sent_at AS sent_at_utc,
    c.opened_at AS conversation_opened_at_utc,
    sc.first_incoming_at AS cycle_first_incoming_at_utc,
    sc.first_response_at AS cycle_first_response_at_utc,
    TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at) AS first_response_seconds
FROM conversation_messages m
INNER JOIN conversations c ON c.id = m.conversation_id
LEFT JOIN conversation_service_cycles sc
       ON sc.conversation_id = m.conversation_id
      AND sc.cycle_status = 'active'
WHERE m.sent_at IS NOT NULL
ORDER BY m.id DESC
LIMIT 20;

-- Divergências improváveis: resposta antes da entrada ou último evento antes do primeiro.
SELECT
    id,
    tenant_id,
    conversation_id,
    cycle_number,
    first_incoming_at,
    last_incoming_at,
    first_response_at,
    closed_at,
    cycle_status
FROM conversation_service_cycles
WHERE (first_response_at IS NOT NULL AND first_incoming_at IS NOT NULL AND first_response_at < first_incoming_at)
   OR (last_incoming_at IS NOT NULL AND first_incoming_at IS NOT NULL AND last_incoming_at < first_incoming_at)
ORDER BY id DESC;
