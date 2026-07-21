-- HOTFIX 36.2.4 — Diagnóstico de nova preferência e consulta atual

SELECT
    a.id AS appointment_id,
    a.tenant_id,
    a.conversation_id,
    a.contact_id,
    a.status,
    a.preferred_day_text,
    a.preferred_time_text,
    a.starts_at,
    a.availability_status,
    a.availability_request_id,
    a.availability_options_request_id,
    a.availability_slot_count,
    a.chosen_availability_slot_id,
    a.google_event_id,
    a.google_event_state,
    a.availability_error,
    a.updated_at
FROM calendar_appointments a
WHERE a.is_pre_schedule = 1
ORDER BY a.id DESC
LIMIT 20;

SELECT
    r.id AS request_id,
    r.appointment_id,
    r.origin,
    r.status,
    r.preferred_day_text,
    r.preferred_time_text,
    r.search_start_at,
    r.search_end_at,
    r.error_message,
    r.requested_at,
    r.sent_at,
    r.responded_at
FROM calendar_availability_requests r
ORDER BY r.id DESC
LIMIT 30;

SELECT
    s.id AS slot_id,
    s.appointment_id,
    s.request_id,
    s.starts_at,
    s.modality,
    s.google_event_id,
    s.event_state,
    s.suggestion_position,
    s.selected_at,
    s.suggested_at
FROM calendar_availability_slots s
ORDER BY s.id DESC
LIMIT 50;

SELECT
    ce.id,
    ce.conversation_id,
    ce.event_type,
    ce.description,
    ce.metadata_json,
    ce.created_at
FROM conversation_events ce
WHERE ce.event_type IN (
    'calendar.preference_changed',
    'calendar.availability_options_sent',
    'calendar.slot_selected_by_contact',
    'calendar.pre_schedule_updated'
)
ORDER BY ce.id DESC
LIMIT 40;
