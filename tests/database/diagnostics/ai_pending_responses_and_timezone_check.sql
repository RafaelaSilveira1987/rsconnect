-- HOTFIX 36.1.1 — Diagnóstico de horário e mensagens realmente sem resposta
-- Ajuste os IDs antes de executar.
SET @tenant_id := 2;
SET @agent_id := 1;

SELECT
    NOW() AS mysql_now,
    UTC_TIMESTAMP() AS mysql_utc,
    TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS mysql_offset_minutes,
    @@session.time_zone AS session_time_zone,
    @@global.time_zone AS global_time_zone;

SELECT
    a.id AS agent_id,
    a.name AS agent_name,
    a.instance_id,
    a.cooldown_seconds,
    MAX(CASE WHEN l.status = 'success' THEN l.created_at END) AS last_success_db,
    MAX(CASE WHEN l.status = 'error' THEN l.created_at END) AS last_error_db
FROM ai_agents a
LEFT JOIN ai_automation_logs l ON l.agent_id = a.id
WHERE a.tenant_id = @tenant_id AND a.id = @agent_id
GROUP BY a.id, a.name, a.instance_id, a.cooldown_seconds;

-- Cada linha abaixo é uma mensagem recebida sem saída válida posterior.
-- Motivos elegíveis: cooldown, falha da IA, falha de entrega da Evolution
-- ou execução interrompida antes de registrar o log.
SELECT
    c.id AS conversation_id,
    cm.id AS incoming_message_id,
    ct.name AS contact_name,
    cm.content AS incoming_content,
    cm.sent_at AS waiting_since,
    (
        SELECT al.event
        FROM ai_automation_logs al
        WHERE al.incoming_message_id = cm.id
        ORDER BY al.id DESC
        LIMIT 1
    ) AS latest_message_event,
    (
        SELECT al.error_message
        FROM ai_automation_logs al
        WHERE al.incoming_message_id = cm.id
        ORDER BY al.id DESC
        LIMIT 1
    ) AS latest_error,
    EXISTS (
        SELECT 1
        FROM conversation_messages failed_outgoing
        WHERE failed_outgoing.conversation_id = cm.conversation_id
          AND failed_outgoing.direction = 'outgoing'
          AND failed_outgoing.sender_type = 'ai'
          AND failed_outgoing.status = 'failed'
          AND (
                failed_outgoing.sent_at > cm.sent_at
                OR (failed_outgoing.sent_at = cm.sent_at AND failed_outgoing.id > cm.id)
          )
    ) AS has_failed_ai_delivery
FROM conversations c
INNER JOIN contacts ct ON ct.id = c.contact_id
INNER JOIN conversation_messages cm
    ON cm.conversation_id = c.id
   AND cm.tenant_id = c.tenant_id
WHERE c.tenant_id = @tenant_id
  AND c.attendance_mode = 'ai'
  AND c.status <> 'closed'
  AND cm.direction = 'incoming'
  AND cm.message_type <> 'reaction'
  AND NOT EXISTS (
        SELECT 1
        FROM conversation_messages outgoing
        WHERE outgoing.conversation_id = c.id
          AND outgoing.direction = 'outgoing'
          AND outgoing.status <> 'failed'
          AND (
                outgoing.sent_at > cm.sent_at
                OR (outgoing.sent_at = cm.sent_at AND outgoing.id > cm.id)
          )
  )
  AND (
        COALESCE((
            SELECT al.event
            FROM ai_automation_logs al
            WHERE al.incoming_message_id = cm.id
            ORDER BY al.id DESC
            LIMIT 1
        ), '') IN ('ai.cooldown', 'ai.failed')
        OR EXISTS (
            SELECT 1
            FROM conversation_messages failed_outgoing
            WHERE failed_outgoing.conversation_id = cm.conversation_id
              AND failed_outgoing.direction = 'outgoing'
              AND failed_outgoing.sender_type = 'ai'
              AND failed_outgoing.status = 'failed'
              AND COALESCE((
                    SELECT al_failed.event
                    FROM ai_automation_logs al_failed
                    WHERE al_failed.incoming_message_id = cm.id
                    ORDER BY al_failed.id DESC
                    LIMIT 1
              ), '') IN ('', 'ai.replied', 'ai.failed')
              AND (
                    failed_outgoing.sent_at > cm.sent_at
                    OR (failed_outgoing.sent_at = cm.sent_at AND failed_outgoing.id > cm.id)
              )
        )
        OR (
            NOT EXISTS (
                SELECT 1
                FROM ai_automation_logs al_msg
                WHERE al_msg.incoming_message_id = cm.id
            )
            AND cm.sent_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            AND (
                COALESCE((
                    SELECT al_legacy.event
                    FROM ai_automation_logs al_legacy
                    WHERE al_legacy.tenant_id = c.tenant_id
                      AND al_legacy.conversation_id = c.id
                      AND al_legacy.agent_id = @agent_id
                      AND al_legacy.created_at >= cm.sent_at
                    ORDER BY al_legacy.id DESC
                    LIMIT 1
                ), '') IN ('ai.cooldown', 'ai.failed')
                OR NOT EXISTS (
                    SELECT 1
                    FROM ai_automation_logs al_missing
                    WHERE al_missing.tenant_id = c.tenant_id
                      AND al_missing.conversation_id = c.id
                      AND al_missing.agent_id = @agent_id
                      AND al_missing.created_at >= cm.sent_at
                )
            )
        )
  )
ORDER BY cm.sent_at;
