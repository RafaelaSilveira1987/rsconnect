-- RS Connect v36.39.0 — comunicação automática com clientes e continuidade de agendamentos
-- Adiciona configurações por empresa, fila própria de mensagens ao cliente e estado de confirmação/pedidos do contato.

DELIMITER $$

DROP PROCEDURE IF EXISTS rs_add_column_if_missing$$
CREATE PROCEDURE rs_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS rs_add_index_if_missing$$
CREATE PROCEDURE rs_add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CREATE TABLE IF NOT EXISTS tenant_calendar_client_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    lookup_enabled TINYINT(1) NOT NULL DEFAULT 1,
    lookup_outside_hours TINYINT(1) NOT NULL DEFAULT 0,
    send_created_enabled TINYINT(1) NOT NULL DEFAULT 0,
    send_confirmed_enabled TINYINT(1) NOT NULL DEFAULT 1,
    send_cancelled_enabled TINYINT(1) NOT NULL DEFAULT 1,
    send_rescheduled_enabled TINYINT(1) NOT NULL DEFAULT 1,
    reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
    reminder_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    presence_request_enabled TINYINT(1) NOT NULL DEFAULT 0,
    presence_request_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1440,
    created_message TEXT COLLATE utf8mb4_unicode_ci NULL,
    confirmed_message TEXT COLLATE utf8mb4_unicode_ci NULL,
    cancelled_message TEXT COLLATE utf8mb4_unicode_ci NULL,
    rescheduled_message TEXT COLLATE utf8mb4_unicode_ci NULL,
    reminder_message TEXT COLLATE utf8mb4_unicode_ci NULL,
    presence_request_message TEXT COLLATE utf8mb4_unicode_ci NULL,
    lookup_no_appointment_message TEXT COLLATE utf8mb4_unicode_ci NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    KEY idx_calendar_client_settings_user (updated_by_user_id),
    CONSTRAINT fk_calendar_client_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_client_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_calendar_client_settings
    (tenant_id, lookup_enabled, lookup_outside_hours, send_created_enabled, send_confirmed_enabled,
     send_cancelled_enabled, send_rescheduled_enabled, reminder_enabled, reminder_minutes,
     presence_request_enabled, presence_request_minutes, created_message, confirmed_message,
     cancelled_message, rescheduled_message, reminder_message, presence_request_message,
     lookup_no_appointment_message)
SELECT
    t.id,
    1,
    0,
    0,
    COALESCE(ps.send_approval_message, 1),
    1,
    1,
    0,
    120,
    0,
    1440,
    'Seu agendamento foi registrado para {{data}} às {{hora}}. {{local}}',
    COALESCE(NULLIF(ps.approved_message, ''), 'Seu agendamento foi confirmado para {{data}} às {{hora}}. {{local}}'),
    'Seu agendamento de {{data}} às {{hora}} foi cancelado. Se precisar, podemos verificar uma nova data.',
    COALESCE(NULLIF(ps.reschedule_message, ''), 'Recebemos a solicitação de ajuste do seu agendamento. A equipe seguirá com você por aqui para definir uma nova data.'),
    'Lembrete: seu atendimento está marcado para {{data}} às {{hora}}. {{local}}',
    'Seu atendimento está marcado para {{data}} às {{hora}}. Você poderá comparecer? Responda sim para confirmar ou informe se precisa cancelar/remarcar.',
    'Não encontrei um agendamento futuro ativo para este contato. Se quiser marcar um novo horário, me diga sua preferência.'
FROM tenants t
LEFT JOIN tenant_pre_schedule_settings ps ON ps.tenant_id = t.id
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

CREATE TABLE IF NOT EXISTS calendar_client_message_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
    expected_starts_at DATETIME NULL,
    scheduled_at DATETIME NOT NULL,
    next_attempt_at DATETIME NOT NULL,
    status ENUM('pending','processing','retry','sent','skipped','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    locked_at DATETIME NULL,
    sent_at DATETIME NULL,
    failed_at DATETIME NULL,
    last_error VARCHAR(1000) COLLATE utf8mb4_unicode_ci NULL,
    deduplication_key VARCHAR(190) COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendar_client_job_dedupe (deduplication_key),
    KEY idx_calendar_client_job_due (status, next_attempt_at, scheduled_at),
    KEY idx_calendar_client_job_appointment (tenant_id, appointment_id, event_key, status),
    CONSTRAINT fk_calendar_client_job_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_client_job_appointment FOREIGN KEY (appointment_id) REFERENCES calendar_appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL rs_add_column_if_missing(
    'calendar_appointments',
    'client_confirmation_status',
    "varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_requested' AFTER approval_message_error"
);
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'client_confirmation_requested_at',
    'datetime NULL AFTER client_confirmation_status'
);
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'client_confirmation_responded_at',
    'datetime NULL AFTER client_confirmation_requested_at'
);
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'client_last_lookup_at',
    'datetime NULL AFTER client_confirmation_responded_at'
);
CALL rs_add_column_if_missing(
    'calendar_appointments',
    'client_last_lookup_intent',
    "varchar(40) COLLATE utf8mb4_unicode_ci NULL AFTER client_last_lookup_at"
);
CALL rs_add_index_if_missing(
    'calendar_appointments',
    'idx_calendar_client_confirmation',
    '(tenant_id, client_confirmation_status, starts_at)'
);

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
DROP PROCEDURE IF EXISTS rs_add_index_if_missing;
