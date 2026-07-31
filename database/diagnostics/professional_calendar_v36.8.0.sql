-- Diagnóstico da agenda por profissional — RS Connect v36.8.0
-- Execute no Adminer e substitua o valor de @tenant_id quando necessário.

SET @tenant_id = 1;

SELECT
    id,
    name,
    professional_calendar_enabled,
    professional_calendar_require_owner,
    professional_calendar_auto_from_conversation
FROM tenants
WHERE id = @tenant_id;

SELECT
    u.id AS user_id,
    u.name,
    u.email,
    u.status,
    p.accepting_appointments,
    p.timezone,
    p.google_calendar_id,
    p.default_duration_minutes,
    p.slot_interval_minutes,
    p.buffer_minutes,
    p.min_notice_hours,
    p.search_days_ahead,
    p.max_suggestions,
    p.workdays_json,
    p.working_hours_json,
    p.updated_at
FROM users u
LEFT JOIN user_calendar_profiles p
       ON p.user_id = u.id
      AND p.tenant_id = u.tenant_id
WHERE u.tenant_id = @tenant_id
ORDER BY u.name;

SELECT
    a.id,
    a.title,
    a.status,
    a.starts_at,
    a.ends_at,
    a.owner_user_id,
    u.name AS professional_name,
    a.availability_source,
    a.availability_status,
    a.google_calendar_id,
    a.google_event_id,
    a.google_event_state,
    a.sync_status,
    a.sync_error
FROM calendar_appointments a
LEFT JOIN users u ON u.id = a.owner_user_id
WHERE a.tenant_id = @tenant_id
ORDER BY a.starts_at DESC
LIMIT 100;

SELECT
    owner_user_id,
    COALESCE(u.name, 'Sem profissional') AS professional_name,
    COUNT(*) AS total,
    SUM(a.status IN ('scheduled', 'confirmed')) AS ativos,
    MIN(CASE WHEN a.status IN ('scheduled', 'confirmed') AND a.starts_at >= NOW() THEN a.starts_at END) AS proximo
FROM calendar_appointments a
LEFT JOIN users u ON u.id = a.owner_user_id
WHERE a.tenant_id = @tenant_id
GROUP BY owner_user_id, u.name
ORDER BY professional_name;
