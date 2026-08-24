-- RS Connect v36.4.0 — Fundação de métricas para Relatórios Executivos
-- Aplicar após a migration 047.
-- A tabela é derivada: pode ser recalculada a partir das tabelas operacionais.

SET @db_name = DATABASE();

CREATE TABLE IF NOT EXISTS report_daily_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    metrics_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    contacts_new INT UNSIGNED NOT NULL DEFAULT 0,
    conversations_started INT UNSIGNED NOT NULL DEFAULT 0,
    conversations_closed INT UNSIGNED NOT NULL DEFAULT 0,

    messages_incoming INT UNSIGNED NOT NULL DEFAULT 0,
    messages_outgoing INT UNSIGNED NOT NULL DEFAULT 0,
    messages_ai INT UNSIGNED NOT NULL DEFAULT 0,
    messages_human INT UNSIGNED NOT NULL DEFAULT 0,
    messages_failed INT UNSIGNED NOT NULL DEFAULT 0,

    ai_success INT UNSIGNED NOT NULL DEFAULT 0,
    ai_errors INT UNSIGNED NOT NULL DEFAULT 0,
    n8n_success INT UNSIGNED NOT NULL DEFAULT 0,
    n8n_errors INT UNSIGNED NOT NULL DEFAULT 0,

    availability_requests INT UNSIGNED NOT NULL DEFAULT 0,
    availability_slots INT UNSIGNED NOT NULL DEFAULT 0,
    availability_selected_slots INT UNSIGNED NOT NULL DEFAULT 0,

    appointments_total INT UNSIGNED NOT NULL DEFAULT 0,
    appointments_scheduled INT UNSIGNED NOT NULL DEFAULT 0,
    appointments_confirmed INT UNSIGNED NOT NULL DEFAULT 0,
    appointments_completed INT UNSIGNED NOT NULL DEFAULT 0,
    appointments_cancelled INT UNSIGNED NOT NULL DEFAULT 0,
    appointments_no_show INT UNSIGNED NOT NULL DEFAULT 0,

    google_sync_success INT UNSIGNED NOT NULL DEFAULT 0,
    google_sync_errors INT UNSIGNED NOT NULL DEFAULT 0,

    crm_leads_created INT UNSIGNED NOT NULL DEFAULT 0,
    crm_won INT UNSIGNED NOT NULL DEFAULT 0,
    crm_lost INT UNSIGNED NOT NULL DEFAULT 0,
    crm_value_won DECIMAL(14,2) NOT NULL DEFAULT 0.00,

    refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_report_daily_metrics_tenant_date (tenant_id, metric_date),
    KEY idx_report_daily_metrics_date (metric_date, tenant_id),
    CONSTRAINT fk_report_daily_metrics_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índice auxiliar para relatórios por período sem alterar os índices compactos usados em Conversas.
SET @index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'conversation_messages'
      AND INDEX_NAME = 'idx_messages_tenant_sent_at'
);
SET @sql = IF(
    @index_exists = 0,
    'ALTER TABLE conversation_messages ADD INDEX idx_messages_tenant_sent_at (tenant_id, sent_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Registra a fundação sem duplicar o incidente em reaplicações.
INSERT INTO system_incidents (event, severity, message, context_json, created_by)
SELECT
    'reports.metrics_foundation_enabled',
    'info',
    'RS Connect v36.4.0: fundação de métricas diárias para relatórios executivos habilitada.',
    JSON_OBJECT('migration', '048_reporting_metrics_foundation.sql', 'table', 'report_daily_metrics'),
    NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM system_incidents
    WHERE event = 'reports.metrics_foundation_enabled'
    LIMIT 1
);
