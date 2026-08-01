-- Diagnóstico RS Connect 36.11.1
-- Esperado: tabela presente, sem buckets bloqueados antigos e eventos recentes visíveis.

SELECT
    table_name,
    engine,
    table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('security_events', 'login_attempts', 'user_sessions', 'security_rate_limits')
ORDER BY table_name;

SELECT
    COUNT(*) AS buckets_ativos,
    SUM(CASE WHEN blocked_until > UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS buckets_bloqueados_agora,
    MAX(updated_at) AS ultima_atualizacao
FROM security_rate_limits;

SELECT
    event,
    severity,
    COUNT(*) AS quantidade,
    MAX(created_at) AS ultimo_evento
FROM security_events
WHERE event IN (
    'auth.session_rotated',
    'auth.session_idle_expired',
    'auth.session_absolute_expired',
    'auth.session_user_agent_mismatch',
    'auth.session_principal_disabled',
    'auth.login_blocked_ip_rate_limit',
    'webhook.payload_too_large',
    'webhook.rate_limited',
    'webhook.token_invalid',
    'webhook.token_missing_config'
)
GROUP BY event, severity
ORDER BY ultimo_evento DESC;

SELECT
    id,
    email,
    ip_address,
    success,
    reason,
    created_at
FROM login_attempts
ORDER BY id DESC
LIMIT 30;
