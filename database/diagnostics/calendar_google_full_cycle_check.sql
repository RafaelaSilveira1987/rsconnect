-- Diagnóstico do ZIP 35.0 — ciclo completo do Google Agenda

SELECT
    t.id AS tenant_id,
    t.name AS empresa,
    s.enabled,
    s.availability_mode,
    s.create_google_event_on_confirm,
    s.require_google_sync_on_confirm,
    s.update_google_event_on_reschedule,
    s.delete_google_event_on_cancel,
    s.maintenance_enabled,
    s.maintenance_interval_minutes,
    s.max_sync_attempts,
    CASE WHEN s.calendar_event_webhook_url_encrypted IS NULL OR s.calendar_event_webhook_url_encrypted = '' THEN 'não configurada' ELSE 'configurada' END AS url_ciclo_google
FROM tenants t
LEFT JOIN tenant_calendar_availability_settings s ON s.tenant_id = t.id
ORDER BY t.name;

SELECT
    tenant_id,
    id AS appointment_id,
    title,
    status,
    availability_source,
    starts_at,
    google_event_id,
    google_event_state,
    google_sync_key,
    google_last_operation,
    google_sync_attempts,
    google_last_sync_at,
    google_last_sync_error,
    google_hold_expires_at
FROM calendar_appointments
WHERE google_event_id IS NOT NULL
   OR availability_source IN ('google_free_slots','google_marked_slots','internal_fallback')
ORDER BY updated_at DESC
LIMIT 100;

SELECT *
FROM calendar_maintenance_runs
ORDER BY id DESC
LIMIT 20;

SELECT
    tenant_id,
    appointment_id,
    operation,
    status,
    google_event_id,
    error_message,
    created_at
FROM calendar_google_sync_logs
WHERE operation IN ('create','update','delete','maintenance_release','lifecycle_callback')
ORDER BY id DESC
LIMIT 100;
