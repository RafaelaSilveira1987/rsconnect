-- Diagnóstico ZIP 33.0 — segurança e bloqueio de acesso

SELECT DATABASE() AS banco, NOW() AS data_hora_banco, @@session.time_zone AS timezone_banco;

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'users'
  AND column_name IN ('failed_login_count', 'last_failed_login_at', 'locked_until', 'lock_reason')
ORDER BY ORDINAL_POSITION;

SELECT
    u.id,
    u.name,
    u.email,
    u.failed_login_count,
    u.last_failed_login_at,
    u.locked_until,
    u.lock_reason
FROM users u
WHERE u.locked_until IS NOT NULL
ORDER BY u.locked_until DESC;

SELECT
    t.id,
    t.name,
    t.status AS empresa_status,
    ts.billing_status,
    ts.trial_ends_at,
    ts.current_period_ends_at,
    CASE
        WHEN ts.billing_status = 'trialing' THEN ts.trial_ends_at
        ELSE ts.current_period_ends_at
    END AS fim_efetivo,
    CASE
        WHEN t.status <> 'active' THEN 'BLOQUEADA: empresa inativa/suspensa'
        WHEN ts.billing_status IN ('suspended', 'canceled') THEN 'BLOQUEADA: assinatura suspensa/cancelada'
        WHEN COALESCE(CASE WHEN ts.billing_status = 'trialing' THEN ts.trial_ends_at ELSE ts.current_period_ends_at END, '9999-12-31') < CURDATE() THEN 'BLOQUEADA: vigência encerrada'
        ELSE 'VIGÊNCIA OK'
    END AS validacao_vigencia
FROM tenants t
LEFT JOIN tenant_subscriptions ts ON ts.id = (
    SELECT ts2.id
    FROM tenant_subscriptions ts2
    WHERE ts2.tenant_id = t.id
    ORDER BY ts2.id DESC
    LIMIT 1
)
ORDER BY t.name;

SELECT
    i.id,
    t.name AS empresa,
    i.invoice_number,
    i.status,
    i.due_date,
    DATEDIFF(CURDATE(), i.due_date) AS dias_vencida,
    CASE
        WHEN i.status IN ('open', 'overdue') AND DATEDIFF(CURDATE(), i.due_date) > 5 THEN 'BLOQUEIA'
        ELSE 'NÃO BLOQUEIA'
    END AS regra_acesso
FROM tenant_invoices i
INNER JOIN tenants t ON t.id = i.tenant_id
WHERE i.status IN ('open', 'overdue')
ORDER BY i.due_date;

SELECT
    us.id,
    u.name,
    u.email,
    us.ip_address,
    us.created_at,
    us.last_seen_at,
    us.expires_at,
    us.revoked_at,
    CASE
        WHEN us.revoked_at IS NOT NULL THEN 'REVOGADA'
        WHEN us.expires_at IS NOT NULL AND us.expires_at <= NOW() THEN 'EXPIRADA'
        ELSE 'ATIVA/VERIFICAR INATIVIDADE'
    END AS situacao
FROM user_sessions us
INNER JOIN users u ON u.id = us.user_id
ORDER BY us.last_seen_at DESC
LIMIT 50;

SELECT
    event,
    severity,
    tenant_id,
    user_id,
    created_at
FROM security_events
WHERE event LIKE 'access.blocked.%'
   OR event LIKE 'auth.user_%'
ORDER BY id DESC
LIMIT 50;
