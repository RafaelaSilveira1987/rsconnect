USE rs_connect;

-- ZIP 22 — White label básico
-- Compatível com MySQL/MariaDB sem depender de ADD COLUMN IF NOT EXISTS.

SET @db_name := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'white_label_enabled') = 0,
  'ALTER TABLE tenants ADD COLUMN white_label_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER onboarding_completed_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_name') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_name VARCHAR(160) NULL AFTER white_label_enabled',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_subtitle') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_subtitle VARCHAR(190) NULL AFTER brand_name',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_logo_url') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_logo_url VARCHAR(500) NULL AFTER brand_subtitle',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_favicon_url') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_favicon_url VARCHAR(500) NULL AFTER brand_logo_url',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_icon_text') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_icon_text VARCHAR(8) NULL AFTER brand_favicon_url',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_primary_color') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_primary_color CHAR(7) NOT NULL DEFAULT ''#146498'' AFTER brand_icon_text',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_secondary_color') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_secondary_color CHAR(7) NOT NULL DEFAULT ''#631b7c'' AFTER brand_primary_color',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_accent_color') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_accent_color CHAR(7) NOT NULL DEFAULT ''#01c5b6'' AFTER brand_secondary_color',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_title') = 0,
  'ALTER TABLE tenants ADD COLUMN login_title VARCHAR(255) NULL AFTER brand_accent_color',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'login_subtitle') = 0,
  'ALTER TABLE tenants ADD COLUMN login_subtitle TEXT NULL AFTER login_title',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'brand_footer_text') = 0,
  'ALTER TABLE tenants ADD COLUMN brand_footer_text VARCHAR(190) NULL AFTER login_subtitle',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'support_email') = 0,
  'ALTER TABLE tenants ADD COLUMN support_email VARCHAR(190) NULL AFTER brand_footer_text',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'custom_domain') = 0,
  'ALTER TABLE tenants ADD COLUMN custom_domain VARCHAR(190) NULL AFTER support_email',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'show_powered_by') = 0,
  'ALTER TABLE tenants ADD COLUMN show_powered_by TINYINT(1) NOT NULL DEFAULT 1 AFTER custom_domain',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND INDEX_NAME = 'uq_tenants_custom_domain') = 0,
  'ALTER TABLE tenants ADD UNIQUE KEY uq_tenants_custom_domain (custom_domain)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'white_label.manage', 'Gerenciar white label', 'Configurar marca, cores e login personalizado dos clientes.', 'Administração RS'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'white_label.manage');
