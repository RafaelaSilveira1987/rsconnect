USE rs_connect;

SELECT COUNT(*) AS estruturas_encontradas
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'scheduled_reports',
      'scheduled_report_recipients',
      'generated_reports',
      'scheduled_report_deliveries'
  );

SELECT permission_key, name
FROM permissions
WHERE permission_key = 'reports.schedule.manage';

SELECT
    sr.id,
    sr.name,
    sr.report_scope,
    sr.frequency,
    sr.status,
    sr.next_run_at,
    COUNT(r.id) AS destinatarios
FROM scheduled_reports sr
LEFT JOIN scheduled_report_recipients r
    ON r.scheduled_report_id = sr.id AND r.enabled = 1
GROUP BY sr.id, sr.name, sr.report_scope, sr.frequency, sr.status, sr.next_run_at
ORDER BY sr.id DESC;

SELECT
    gr.id,
    gr.report_name,
    gr.period_start,
    gr.period_end,
    gr.status,
    gr.size_bytes,
    COUNT(d.id) AS entregas,
    SUM(d.status = 'sent') AS enviadas,
    SUM(d.status = 'failed') AS falhas
FROM generated_reports gr
LEFT JOIN scheduled_report_deliveries d ON d.generated_report_id = gr.id
GROUP BY gr.id, gr.report_name, gr.period_start, gr.period_end, gr.status, gr.size_bytes
ORDER BY gr.id DESC
LIMIT 30;

SELECT generated_report_id, channel, destination, COUNT(*) AS quantidade
FROM scheduled_report_deliveries
GROUP BY generated_report_id, channel, destination
HAVING COUNT(*) > 1;
