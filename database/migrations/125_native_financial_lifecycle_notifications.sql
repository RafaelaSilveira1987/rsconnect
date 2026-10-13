-- RS Connect v36.43.0 — notificações nativas de cobrança, vigência e trial
-- Mantém n8n opcional e adiciona envio interno/WhatsApp pela fila nativa.

ALTER TABLE billing_reminder_rules
    MODIFY channel ENUM(
        'native_in_app',
        'native_whatsapp',
        'native_both',
        'n8n',
        'whatsapp',
        'email',
        'manual'
    ) NOT NULL DEFAULT 'native_in_app';

ALTER TABLE billing_reminder_logs
    MODIFY status ENUM('pending','queued','sent','error','logged','skipped') NOT NULL DEFAULT 'pending';

ALTER TABLE notification_jobs
    ADD COLUMN sender_instance_id BIGINT UNSIGNED NULL AFTER recipient,
    ADD COLUMN source_type VARCHAR(80) NULL AFTER deduplication_key,
    ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER source_type,
    ADD KEY idx_notification_jobs_sender_instance (sender_instance_id),
    ADD KEY idx_notification_jobs_source (source_type, source_id),
    ADD CONSTRAINT fk_notification_job_sender_instance
        FOREIGN KEY (sender_instance_id) REFERENCES evolution_instances(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS platform_notification_settings (
    id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    whatsapp_instance_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_platform_notification_instance (whatsapp_instance_id),
    KEY idx_platform_notification_user (updated_by_user_id),
    CONSTRAINT fk_platform_notification_instance
        FOREIGN KEY (whatsapp_instance_id) REFERENCES evolution_instances(id) ON DELETE SET NULL,
    CONSTRAINT fk_platform_notification_user
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_notification_settings (id, whatsapp_instance_id, updated_by_user_id)
VALUES (1, NULL, NULL)
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS subscription_notification_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(160) NOT NULL,
    target_date ENUM('current_period_end','trial_end','trial_grace_end') NOT NULL DEFAULT 'current_period_end',
    days_from_target INT NOT NULL DEFAULT -7 COMMENT 'Negativo = antes; 0 = no dia; positivo = após a data-alvo',
    channel ENUM('native_in_app','native_whatsapp','native_both','n8n','manual') NOT NULL DEFAULT 'native_in_app',
    message_template TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subscription_notice_target_status (target_date, days_from_target, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_notification_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id BIGINT UNSIGNED NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','queued','sent','error','logged','skipped') NOT NULL DEFAULT 'pending',
    payload_json JSON NULL,
    result_json JSON NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subscription_notice_log_tenant_created (tenant_id, created_at),
    KEY idx_subscription_notice_log_subscription_created (subscription_id, created_at),
    KEY idx_subscription_notice_log_rule_created (rule_id, created_at),
    CONSTRAINT fk_subscription_notice_log_rule
        FOREIGN KEY (rule_id) REFERENCES subscription_notification_rules(id) ON DELETE SET NULL,
    CONSTRAINT fk_subscription_notice_log_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_notice_log_subscription
        FOREIGN KEY (subscription_id) REFERENCES tenant_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO subscription_notification_rules
    (id, label, target_date, days_from_target, channel, message_template, status)
VALUES
    (1, '7 dias antes do fim da vigência', 'current_period_end', -7, 'native_in_app',
     'Olá, {{empresa}}. A vigência atual do plano {{plano}} termina em {{fim_vigencia}}. Faltam {{dias}} dia(s).', 'inactive'),
    (2, '3 dias antes do fim do teste', 'trial_end', -3, 'native_in_app',
     'Olá, {{empresa}}. Seu período de teste termina em {{fim_teste}}. Faltam {{dias}} dia(s).', 'inactive'),
    (3, 'Aviso no fim do teste', 'trial_end', 0, 'native_in_app',
     'Olá, {{empresa}}. Seu período de teste termina hoje ({{fim_teste}}). Confira sua assinatura para manter o acesso.', 'inactive'),
    (4, 'Aviso no fim da tolerância', 'trial_grace_end', 0, 'native_in_app',
     'Olá, {{empresa}}. O prazo de tolerância do período de teste termina hoje ({{fim_tolerancia}}). Regularize sua assinatura para evitar interrupção.', 'inactive');
