-- HOTFIX 36.2.2 — diagnóstico da exclusão sincronizada com Google Agenda

-- 1) Últimas ações delete enviadas e callbacks recebidos.
SELECT
    id,
    tenant_id,
    appointment_id,
    operation,
    status,
    google_calendar_id,
    google_event_id,
    error_message,
    created_at
FROM calendar_google_sync_logs
WHERE operation IN ('delete', 'callback')
ORDER BY id DESC
LIMIT 40;

-- 2) Agendamentos que ainda possuem evento Google mesmo após estado de exclusão.
-- O resultado esperado é zero linhas.
SELECT
    id,
    tenant_id,
    title,
    status,
    availability_source,
    google_event_id,
    google_event_state,
    updated_at
FROM calendar_appointments
WHERE google_event_state = 'deleted'
  AND COALESCE(google_event_id, '') <> ''
ORDER BY id DESC;
