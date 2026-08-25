-- RS Connect 36.18.5 — diagnóstico de takeover humano e bloqueio operacional

SELECT
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'PENDENTE' END AS estrutura_fila_pos_horario,
    COUNT(*) AS tabela_encontrada
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ai_after_hours_pending';

SELECT
    status,
    COUNT(*) AS quantidade
FROM ai_after_hours_pending
GROUP BY status
ORDER BY status;

SELECT
    c.id AS conversation_id,
    c.attendance_mode,
    c.status,
    c.assigned_user_id,
    u.name AS responsavel,
    p.status AS fila_status,
    p.recovery_source,
    p.next_attempt_at,
    p.updated_at
FROM conversations c
LEFT JOIN users u ON u.id = c.assigned_user_id AND u.tenant_id = c.tenant_id
LEFT JOIN ai_after_hours_pending p ON p.conversation_id = c.id
WHERE p.status IN ('pending','processing','blocked_plan','blocked_human','error')
   OR (c.attendance_mode = 'human' AND c.status <> 'closed')
ORDER BY COALESCE(p.updated_at, c.updated_at) DESC
LIMIT 100;

SELECT
    t.id AS tenant_id,
    t.name AS empresa,
    t.professional_assignment_enabled,
    t.professional_lock_enabled,
    t.professional_auto_assign_enabled
FROM tenants t
ORDER BY t.name;
