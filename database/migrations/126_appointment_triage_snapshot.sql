-- RS Connect QA Fase 2: contexto de triagem por agendamento.
-- Nenhuma linha histórica é preenchida a partir da triagem atual, para não fabricar histórico.
CREATE TABLE IF NOT EXISTS calendar_appointment_triage_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NULL,
    collected_json JSON NOT NULL,
    field_labels_json JSON NOT NULL,
    invalid_keys_json JSON NOT NULL,
    demand_status VARCHAR(40) NULL,
    demand_summary TEXT NULL,
    contact_group VARCHAR(80) NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendar_triage_snapshot_tenant_appointment (tenant_id, appointment_id),
    KEY idx_calendar_triage_snapshot_tenant_lock (tenant_id, locked_at),
    CONSTRAINT fk_calendar_triage_snapshot_appointment
        FOREIGN KEY (appointment_id) REFERENCES calendar_appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
