-- Diagnóstico da fundação de Relatórios Executivos v36.4.0
SELECT DATABASE() AS database_name, NOW() AS checked_at;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'report_daily_metrics';

SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_order
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'report_daily_metrics'
GROUP BY INDEX_NAME
ORDER BY INDEX_NAME;

SELECT
    COUNT(*) AS cached_rows,
    COUNT(DISTINCT tenant_id) AS tenants_cached,
    MIN(metric_date) AS first_metric_date,
    MAX(metric_date) AS last_metric_date,
    SUM(messages_incoming + messages_outgoing) AS messages_cached,
    SUM(conversations_started) AS conversations_cached,
    SUM(contacts_new) AS contacts_cached
FROM report_daily_metrics;

SELECT
    tenant_id,
    metric_date,
    messages_incoming,
    messages_outgoing,
    messages_ai,
    messages_human,
    appointments_total,
    crm_leads_created,
    refreshed_at
FROM report_daily_metrics
ORDER BY metric_date DESC, tenant_id
LIMIT 30;
