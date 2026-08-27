-- RS Connect 36.20.11 — preços por origem da IA e fidelidade comercial.
-- Compatível com MySQL/MariaDB sem ADD COLUMN IF NOT EXISTS.

SET @db := DATABASE();

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='saas_plans' AND COLUMN_NAME='own_ai_monthly_price'),
    'SELECT 1',
    'ALTER TABLE saas_plans ADD COLUMN own_ai_monthly_price DECIMAL(12,2) NULL AFTER monthly_price'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='saas_plans' AND COLUMN_NAME='rs_ai_monthly_price'),
    'SELECT 1',
    'ALTER TABLE saas_plans ADD COLUMN rs_ai_monthly_price DECIMAL(12,2) NULL AFTER own_ai_monthly_price'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='saas_plans' AND COLUMN_NAME='commitment_discounts_json'),
    'SELECT 1',
    'ALTER TABLE saas_plans ADD COLUMN commitment_discounts_json JSON NULL AFTER rs_ai_monthly_price'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_subscriptions' AND COLUMN_NAME='ai_billing_mode'),
    'SELECT 1',
    "ALTER TABLE tenant_subscriptions ADD COLUMN ai_billing_mode ENUM('tenant','rs_connect') NOT NULL DEFAULT 'rs_connect' AFTER billing_cycle"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_subscriptions' AND COLUMN_NAME='commitment_months'),
    'SELECT 1',
    'ALTER TABLE tenant_subscriptions ADD COLUMN commitment_months SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER ai_billing_mode'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='tenant_subscriptions' AND COLUMN_NAME='commitment_ends_at'),
    'SELECT 1',
    'ALTER TABLE tenant_subscriptions ADD COLUMN commitment_ends_at DATE NULL AFTER commitment_months'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Matriz comercial aprovada: preço padrão de 3 meses, 8% no semestre e 15% no anual.
UPDATE saas_plans
SET own_ai_monthly_price = CASE plan_key
        WHEN 'starter' THEN 69.00
        WHEN 'pro' THEN 129.00
        WHEN 'business' THEN 259.00
        ELSE COALESCE(own_ai_monthly_price, monthly_price)
    END,
    rs_ai_monthly_price = CASE plan_key
        WHEN 'starter' THEN 99.00
        WHEN 'pro' THEN 179.00
        WHEN 'business' THEN 349.00
        ELSE COALESCE(rs_ai_monthly_price, monthly_price)
    END,
    monthly_price = CASE plan_key
        WHEN 'starter' THEN 99.00
        WHEN 'pro' THEN 179.00
        WHEN 'business' THEN 349.00
        ELSE monthly_price
    END,
    commitment_discounts_json = COALESCE(commitment_discounts_json, JSON_OBJECT('3', 0, '6', 8, '12', 15)),
    limits_json = CASE plan_key
        WHEN 'starter' THEN JSON_SET(COALESCE(limits_json, JSON_OBJECT()), '$.users', 3, '$.agents', 1)
        WHEN 'pro' THEN JSON_SET(COALESCE(limits_json, JSON_OBJECT()), '$.users', 6, '$.agents', 2)
        WHEN 'business' THEN JSON_SET(COALESCE(limits_json, JSON_OBJECT()), '$.users', 15, '$.agents', 5)
        ELSE limits_json
    END;

UPDATE tenant_subscriptions ts
INNER JOIN saas_plans sp ON sp.id = ts.plan_id
SET ts.ai_billing_mode = COALESCE(NULLIF(ts.ai_billing_mode, ''), 'rs_connect'),
    ts.commitment_months = CASE WHEN ts.commitment_months IN (3,6,12) THEN ts.commitment_months ELSE 3 END,
    ts.commitment_ends_at = COALESCE(
        ts.commitment_ends_at,
        DATE_SUB(DATE_ADD(ts.starts_at, INTERVAL CASE WHEN ts.commitment_months IN (3,6,12) THEN ts.commitment_months ELSE 3 END MONTH), INTERVAL 1 DAY)
    )
WHERE ts.billing_status IN ('trialing','active','overdue','suspended');
