USE rs_connect;

-- Hotfix 22.2 — White Label Pro
-- Campos extras para logo horizontal, ícone reduzido, favicon, textos do login e cores da tela.

SET @db_name := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_icon_url') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_icon_url VARCHAR(500) NULL AFTER brand_logo_url',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_logo_variant') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_logo_variant VARCHAR(20) NOT NULL DEFAULT ''horizontal'' AFTER brand_icon_text',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_logo_background') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_logo_background VARCHAR(20) NOT NULL DEFAULT ''light'' AFTER brand_logo_variant',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_background_color') = 0,
  'ALTER TABLE tenants ADD COLUMN login_background_color CHAR(7) NOT NULL DEFAULT ''#07111f'' AFTER brand_accent_color',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_text_color') = 0,
  'ALTER TABLE tenants ADD COLUMN login_text_color CHAR(7) NOT NULL DEFAULT ''#ffffff'' AFTER login_background_color',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_eyebrow') = 0,
  'ALTER TABLE tenants ADD COLUMN login_eyebrow VARCHAR(160) NULL AFTER login_text_color',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_button_text') = 0,
  'ALTER TABLE tenants ADD COLUMN login_button_text VARCHAR(80) NULL AFTER login_subtitle',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_benefit_1') = 0,
  'ALTER TABLE tenants ADD COLUMN login_benefit_1 VARCHAR(120) NULL AFTER login_button_text',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_benefit_2') = 0,
  'ALTER TABLE tenants ADD COLUMN login_benefit_2 VARCHAR(120) NULL AFTER login_benefit_1',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_benefit_3') = 0,
  'ALTER TABLE tenants ADD COLUMN login_benefit_3 VARCHAR(120) NULL AFTER login_benefit_2',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_security_text') = 0,
  'ALTER TABLE tenants ADD COLUMN login_security_text VARCHAR(190) NULL AFTER login_benefit_3',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE tenants
SET brand_logo_variant = 'horizontal'
WHERE brand_logo_variant IS NULL OR brand_logo_variant = '';

UPDATE tenants
SET brand_logo_background = 'light'
WHERE brand_logo_background IS NULL OR brand_logo_background = '';
