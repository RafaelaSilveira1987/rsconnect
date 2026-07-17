-- ZIP 34.5.2 — conferir as ocorrências usadas pelas marcações da tela Empresas
-- Troque @tenant_id pelo ID da empresa desejada.
SET @tenant_id = 2;

SELECT tenant_id, tracking_status, priority, acknowledged_at, resolved_at, note
FROM tenant_admin_tracking
WHERE tenant_id = @tenant_id;

SELECT 'IA' AS origem, id, event, status, error_message, conversation_id, agent_id, created_at
FROM ai_automation_logs
WHERE tenant_id = @tenant_id
  AND status = 'error'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY id DESC;

SELECT 'INTEGRACAO' AS origem, id, event, status, http_status, error_message, flow_id, created_at
FROM n8n_flow_logs
WHERE tenant_id = @tenant_id
  AND status = 'error'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY id DESC;
