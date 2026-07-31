SELECT
    t.id AS tenant_id,
    t.name AS empresa,
    t.professional_assignment_enabled AS recurso_ativo,
    t.professional_lock_enabled AS bloqueio_ativo,
    t.professional_auto_assign_enabled AS atribuicao_automatica,
    (
        SELECT COUNT(*)
        FROM contacts ct
        WHERE ct.tenant_id = t.id AND ct.preferred_user_id IS NOT NULL
    ) AS contatos_com_profissional_preferido,
    (
        SELECT COUNT(*)
        FROM conversations c
        WHERE c.tenant_id = t.id
          AND c.status IN ('open', 'pending')
          AND c.assigned_user_id IS NOT NULL
    ) AS conversas_ativas_atribuidas,
    (
        SELECT COUNT(*)
        FROM conversations c
        WHERE c.tenant_id = t.id
          AND c.status IN ('open', 'pending')
          AND c.assigned_user_id IS NULL
    ) AS conversas_ativas_disponiveis
FROM tenants t
ORDER BY t.name;

SELECT
    c.id AS conversation_id,
    t.name AS empresa,
    COALESCE(NULLIF(ct.name, ''), ct.phone) AS contato,
    pref.name AS profissional_preferido,
    resp.name AS responsavel_atual,
    c.status,
    c.attendance_mode,
    c.assignment_source,
    c.assigned_at,
    c.assignment_released_at
FROM conversations c
INNER JOIN tenants t ON t.id = c.tenant_id
INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = c.tenant_id
LEFT JOIN users pref ON pref.id = ct.preferred_user_id AND pref.tenant_id = ct.tenant_id
LEFT JOIN users resp ON resp.id = c.assigned_user_id AND resp.tenant_id = c.tenant_id
ORDER BY c.updated_at DESC
LIMIT 100;
