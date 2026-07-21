-- HOTFIX 36.2.3 — Diagnóstico de falso timeout da Agenda

SELECT
    r.id AS request_id,
    r.tenant_id,
    r.appointment_id,
    r.origin,
    r.status AS request_status,
    r.error_message,
    r.requested_at,
    r.sent_at,
    r.responded_at,
    TIMESTAMPDIFF(SECOND, r.requested_at, COALESCE(r.responded_at, NOW())) AS elapsed_seconds,
    a.availability_status,
    a.availability_slot_count,
    a.availability_error,
    a.google_event_state,
    a.updated_at AS appointment_updated_at
FROM calendar_availability_requests r
LEFT JOIN calendar_appointments a
       ON a.id = r.appointment_id
      AND a.tenant_id = r.tenant_id
ORDER BY r.id DESC
LIMIT 50;

-- Não deve retornar linhas novas após o hotfix.
SELECT
    id, tenant_id, appointment_id, status, error_message, responded_at, updated_at
FROM calendar_availability_requests
WHERE responded_at IS NOT NULL
  AND status = 'failed'
  AND error_message LIKE '%Tempo de resposta do fluxo excedido%'
ORDER BY id DESC
LIMIT 50;
