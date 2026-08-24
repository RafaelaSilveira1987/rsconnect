USE rs_connect;

-- v36.12.0 — Diagnóstico do monitoramento e alertas operacionais

-- 1. Confirma a estrutura da migration 073.
SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operational_monitor_runs') AS tabela_execucoes,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_incidents' AND COLUMN_NAME = 'acknowledged_at') AS coluna_reconhecimento,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operational_alert_deliveries' AND COLUMN_NAME = 'delivery_key') AS coluna_chave_entrega,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operational_alert_preferences' AND COLUMN_NAME = 'disk_enabled') AS preferencia_disco,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operational_alert_preferences' AND COLUMN_NAME = 'queue_enabled') AS preferencia_fila,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'whatsapp_provider_message_id') AS auditoria_whatsapp_cliente,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'email_provider_message_id') AS auditoria_email_cliente;

-- 2. Última evidência de cada verificação operacional.
SELECT hc.check_key, hc.label, hc.status, hc.message, hc.latency_ms, hc.checked_at
FROM system_health_checks hc
INNER JOIN (
    SELECT check_key, MAX(id) AS max_id
    FROM system_health_checks
    WHERE check_key <> 'billing_cron_heartbeat'
    GROUP BY check_key
) latest ON latest.max_id = hc.id
ORDER BY FIELD(hc.status, 'down', 'warning', 'unknown', 'ok'), hc.check_key;

-- 3. Incidentes ativos, responsável e empresa afetada.
SELECT
    i.id,
    i.event,
    i.tenant_id,
    t.name AS empresa,
    i.severity,
    i.message,
    i.created_at,
    i.last_seen_at,
    i.acknowledged_at,
    u.name AS reconhecido_por,
    i.acknowledgement_note
FROM system_incidents i
LEFT JOIN tenants t ON t.id = i.tenant_id
LEFT JOIN users u ON u.id = i.acknowledged_by
WHERE i.resolved_at IS NULL
  AND i.severity IN ('warning','error','critical')
ORDER BY FIELD(i.severity, 'critical', 'error', 'warning'), i.last_seen_at DESC;

-- 4. Últimas execuções do monitor.
SELECT
    id, trigger_source, status, checks_total, healthy_total,
    warning_total, down_total, duration_ms, error_message,
    started_at, finished_at
FROM operational_monitor_runs
ORDER BY id DESC
LIMIT 30;

-- 5. Entregas recentes por canal.
SELECT
    d.id,
    d.incident_id,
    d.user_id,
    d.notification_kind,
    d.channel,
    d.delivery_key,
    d.status,
    d.attempt_count,
    d.destination,
    d.provider_message_id,
    d.error_message,
    d.last_attempt_at,
    d.sent_at,
    d.created_at
FROM operational_alert_deliveries d
ORDER BY d.id DESC
LIMIT 100;

-- 6. Entregas externas dos comunicados aos clientes.
SELECT
    r.communication_id,
    r.tenant_id,
    t.name AS empresa,
    r.whatsapp_status,
    r.whatsapp_provider_message_id,
    r.whatsapp_error,
    r.whatsapp_sent_at,
    r.email_status,
    r.email_provider_message_id,
    r.email_error,
    r.email_sent_at,
    r.created_at
FROM client_communication_recipients r
INNER JOIN tenants t ON t.id = r.tenant_id
WHERE r.whatsapp_status <> 'not_requested' OR r.email_status <> 'not_requested'
ORDER BY r.id DESC
LIMIT 100;

-- 7. Não deve retornar linhas: duplicidades para a mesma chave de entrega.
SELECT
    incident_id, user_id, notification_kind, channel, delivery_key, COUNT(*) AS quantidade
FROM operational_alert_deliveries
GROUP BY incident_id, user_id, notification_kind, channel, delivery_key
HAVING COUNT(*) > 1;

-- 8. Resumo final. O esperado é estruturas = 7 e duplicidades = 0.
SELECT
    (
        (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operational_monitor_runs')
      + (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_incidents' AND COLUMN_NAME = 'acknowledged_at')
      + (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operational_alert_deliveries' AND COLUMN_NAME = 'delivery_key')
      + (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operational_alert_preferences' AND COLUMN_NAME = 'disk_enabled')
      + (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operational_alert_preferences' AND COLUMN_NAME = 'queue_enabled')
      + (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'whatsapp_provider_message_id')
      + (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_communication_recipients' AND COLUMN_NAME = 'email_provider_message_id')
    ) AS estruturas_encontradas,
    (
        SELECT COUNT(*) FROM (
            SELECT incident_id, user_id, notification_kind, channel, delivery_key
            FROM operational_alert_deliveries
            GROUP BY incident_id, user_id, notification_kind, channel, delivery_key
            HAVING COUNT(*) > 1
        ) duplicados
    ) AS duplicidades_entrega;
