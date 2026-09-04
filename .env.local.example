-- Diagnóstico do ZIP 36.2 — agenda conversacional
-- Alternativas reais -> escolha do contato -> pré-reserva -> aprovação profissional.

SELECT DATABASE() AS banco_atual;

SELECT
    SUM(COLUMN_NAME = 'availability_options_request_id') AS availability_options_request_id,
    SUM(COLUMN_NAME = 'availability_options_sent_at') AS availability_options_sent_at,
    SUM(COLUMN_NAME = 'availability_selection_expires_at') AS availability_selection_expires_at,
    SUM(COLUMN_NAME = 'availability_selected_at') AS availability_selected_at,
    SUM(COLUMN_NAME = 'availability_selected_by') AS availability_selected_by
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'calendar_appointments'
  AND COLUMN_NAME IN (
      'availability_options_request_id',
      'availability_options_sent_at',
      'availability_selection_expires_at',
      'availability_selected_at',
      'availability_selected_by'
  );

SELECT
    SUM(COLUMN_NAME = 'suggestion_position') AS suggestion_position,
    SUM(COLUMN_NAME = 'suggested_at') AS suggested_at
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'calendar_availability_slots'
  AND COLUMN_NAME IN ('suggestion_position', 'suggested_at');

SELECT
    SUM(COLUMN_NAME = 'availability_options_message') AS availability_options_message,
    SUM(COLUMN_NAME = 'slot_selected_message') AS slot_selected_message,
    SUM(COLUMN_NAME = 'no_availability_message') AS no_availability_message,
    SUM(COLUMN_NAME = 'invalid_slot_message') AS invalid_slot_message
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'tenant_pre_schedule_settings'
  AND COLUMN_NAME IN (
      'availability_options_message',
      'slot_selected_message',
      'no_availability_message',
      'invalid_slot_message'
  );

SELECT
    a.tenant_id,
    a.id AS appointment_id,
    a.contact_id,
    a.conversation_id,
    a.title,
    a.status,
    a.approval_status,
    a.preferred_day_text,
    a.preferred_time_text,
    a.availability_status,
    a.availability_request_id,
    a.availability_options_request_id,
    a.availability_options_sent_at,
    a.availability_selection_expires_at,
    a.chosen_availability_slot_id,
    a.availability_selected_at,
    a.availability_selected_by,
    a.google_event_id,
    a.google_event_state,
    a.availability_error,
    a.updated_at
FROM calendar_appointments a
WHERE a.is_pre_schedule = 1
ORDER BY a.updated_at DESC, a.id DESC
LIMIT 50;

SELECT
    s.tenant_id,
    s.appointment_id,
    s.request_id,
    s.id AS slot_id,
    s.suggestion_position,
    s.starts_at,
    s.ends_at,
    s.modality,
    s.source,
    s.event_state,
    s.google_event_id,
    s.selected_at,
    s.suggested_at
FROM calendar_availability_slots s
WHERE s.suggestion_position IS NOT NULL
   OR s.selected_at IS NOT NULL
ORDER BY s.request_id DESC, s.suggestion_position ASC, s.starts_at ASC
LIMIT 100;

SELECT
    e.tenant_id,
    e.conversation_id,
    e.event_type,
    e.description,
    e.metadata_json,
    e.created_at
FROM conversation_events e
WHERE e.event_type IN (
    'calendar.availability_options_sent',
    'calendar.no_availability_sent',
    'calendar.no_availability_after_hold_failure',
    'calendar.invalid_slot_selection',
    'calendar.slot_hold_failed',
    'calendar.slot_auto_selected',
    'calendar.slot_selected_by_contact'
)
ORDER BY e.id DESC
LIMIT 100;
