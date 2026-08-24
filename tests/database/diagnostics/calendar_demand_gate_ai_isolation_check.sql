-- HOTFIX 36.2.5 — validação da demanda isolada da IA e da agenda antiga

-- 1) Estado atual da conversa. Substitua 586 quando necessário.
SELECT
    c.id AS conversation_id,
    c.tenant_id,
    c.contact_id,
    fs.stage,
    fs.demand_status,
    fs.demand_summary,
    fs.last_intent,
    fs.updated_at
FROM conversations c
LEFT JOIN conversation_flow_states fs
       ON fs.tenant_id = c.tenant_id
      AND fs.conversation_id = c.id
WHERE c.id = 586;

-- 2) Últimas mensagens de agenda bloqueadas pela etapa anterior.
SELECT
    ce.id,
    ce.conversation_id,
    ce.event_type,
    ce.description,
    ce.metadata_json,
    ce.created_at
FROM conversation_events ce
WHERE ce.conversation_id = 586
  AND ce.event_type IN (
      'calendar.pre_schedule_blocked',
      'calendar.pre_scheduled',
      'calendar.pre_schedule_updated',
      'calendar.availability_options_sent'
  )
ORDER BY ce.id DESC
LIMIT 30;

-- 3) A mensagem bloqueada deve possuir ai.skipped e não ai.replied/ai.failed.
SELECT
    al.id,
    al.incoming_message_id,
    al.event,
    al.status,
    al.response_preview,
    al.error_message,
    al.raw_json,
    al.created_at
FROM ai_automation_logs al
WHERE al.conversation_id = 586
ORDER BY al.id DESC
LIMIT 30;

-- 4) Após a demanda ser coletada e a preferência ser reenviada,
-- deve existir appointment e request novos, ambos vinculados.
SELECT
    a.id AS appointment_id,
    a.conversation_id,
    a.contact_id,
    a.status AS appointment_status,
    a.preferred_day_text,
    a.preferred_time_text,
    a.availability_status,
    a.availability_request_id,
    r.id AS request_id,
    r.origin,
    r.status AS request_status,
    r.error_message,
    r.requested_at,
    r.responded_at
FROM calendar_appointments a
LEFT JOIN calendar_availability_requests r
       ON r.tenant_id = a.tenant_id
      AND r.appointment_id = a.id
WHERE a.tenant_id = 2
  AND a.conversation_id = 586
ORDER BY a.id DESC, r.id DESC;
