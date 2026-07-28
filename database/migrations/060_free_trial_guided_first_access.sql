USE rs_connect;

-- RS Connect 36.6.34 — teste gratuito efetivo e primeiro acesso guiado.

SET @db := DATABASE();

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_subscriptions' AND COLUMN_NAME='trial_days'),
    'SELECT 1',
    'ALTER TABLE tenant_subscriptions ADD COLUMN trial_days SMALLINT UNSIGNED NULL AFTER trial_ends_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_subscriptions' AND COLUMN_NAME='trial_end_behavior'),
    'SELECT 1',
    "ALTER TABLE tenant_subscriptions ADD COLUMN trial_end_behavior ENUM('await_payment','activate','suspend') NOT NULL DEFAULT 'await_payment' AFTER trial_days"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_subscriptions' AND COLUMN_NAME='trial_grace_days'),
    'SELECT 1',
    'ALTER TABLE tenant_subscriptions ADD COLUMN trial_grace_days SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER trial_end_behavior'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_subscriptions' AND COLUMN_NAME='trial_converted_at'),
    'SELECT 1',
    'ALTER TABLE tenant_subscriptions ADD COLUMN trial_converted_at DATETIME NULL AFTER trial_grace_days'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_subscriptions' AND COLUMN_NAME='trial_expired_at'),
    'SELECT 1',
    'ALTER TABLE tenant_subscriptions ADD COLUMN trial_expired_at DATETIME NULL AFTER trial_converted_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tenant_onboarding_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    business_timezone VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/Sao_Paulo',
    business_hours_json JSON NULL,
    after_hours_message VARCHAR(500) COLLATE utf8mb4_unicode_ci NULL,
    human_handoff_message VARCHAR(500) COLLATE utf8mb4_unicode_ci NULL,
    cooldown_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_onboarding_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserva configurações atuais do agente principal para empresas já existentes.
INSERT INTO tenant_onboarding_settings
    (tenant_id, business_timezone, business_hours_json, after_hours_message, human_handoff_message, cooldown_seconds)
SELECT
    a.tenant_id,
    COALESCE(NULLIF(a.business_timezone, ''), 'America/Sao_Paulo'),
    a.business_hours_json,
    a.after_hours_message,
    a.human_handoff_message,
    COALESCE(a.cooldown_seconds, 60)
FROM ai_agents a
INNER JOIN (
    SELECT tenant_id, MAX(CASE WHEN is_default = 1 THEN id ELSE 0 END) AS default_id, MAX(id) AS latest_id
    FROM ai_agents
    GROUP BY tenant_id
) pick ON pick.tenant_id = a.tenant_id AND a.id = IF(pick.default_id > 0, pick.default_id, pick.latest_id)
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

UPDATE tenant_subscriptions
SET trial_days = CASE
        WHEN billing_status = 'trialing' AND trial_ends_at IS NOT NULL
        THEN GREATEST(1, DATEDIFF(trial_ends_at, starts_at) + 1)
        ELSE trial_days
    END,
    next_billing_at = CASE
        WHEN billing_status = 'trialing' AND trial_ends_at IS NOT NULL
             AND (next_billing_at IS NULL OR next_billing_at <= trial_ends_at)
        THEN DATE_ADD(trial_ends_at, INTERVAL 1 DAY)
        ELSE next_billing_at
    END
WHERE billing_status = 'trialing';

-- Empresas que já operavam antes desta atualização não devem ser bloqueadas
-- pelo novo primeiro acesso. A implantação guiada obrigatória fica para novas
-- empresas ou cadastros ainda sem qualquer operação real.
UPDATE tenants t
SET t.onboarding_step = 7,
    t.onboarding_completed_at = COALESCE(t.onboarding_completed_at, NOW())
WHERE t.onboarding_completed_at IS NULL
  AND (
      EXISTS (SELECT 1 FROM ai_agents a WHERE a.tenant_id = t.id)
      OR EXISTS (SELECT 1 FROM evolution_instances ei WHERE ei.tenant_id = t.id)
      OR EXISTS (SELECT 1 FROM conversations c WHERE c.tenant_id = t.id)
  );
