-- v36.15.1-r1
-- Lista relatórios e entregas que podem ter ficado pendentes após o erro HY093.
-- Esta consulta não altera dados.

SELECT
    gr.id AS generated_report_id,
    gr.uuid AS report_uuid,
    gr.report_name,
    gr.period_start,
    gr.period_end,
    gr.status AS report_status,
    gr.created_at AS report_created_at,
    d.id AS delivery_id,
    d.destination,
    d.status AS delivery_status,
    d.attempt_count,
    d.provider_message_id,
    d.error_message,
    d.last_attempt_at,
    d.sent_at,
    d.updated_at
FROM generated_reports gr
INNER JOIN scheduled_report_deliveries d
    ON d.generated_report_id = gr.id
WHERE d.status IN ('pending', 'failed')
ORDER BY gr.id DESC, d.id DESC
LIMIT 100;
