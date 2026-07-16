USE rs_connect;

-- ZIP 31.0 — Perfil empresarial amigável para o cliente.
-- Acrescenta informações comerciais, endereço e contexto usado pelos assistentes.
-- Pode ser executada novamente sem duplicar colunas.

SET @db_name := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='commercial_whatsapp')=0,
  'ALTER TABLE tenants ADD COLUMN commercial_whatsapp VARCHAR(30) NULL AFTER phone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='instagram')=0,
  'ALTER TABLE tenants ADD COLUMN instagram VARCHAR(190) NULL AFTER website', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='postal_code')=0,
  'ALTER TABLE tenants ADD COLUMN postal_code VARCHAR(20) NULL AFTER segment', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='address_line')=0,
  'ALTER TABLE tenants ADD COLUMN address_line VARCHAR(255) NULL AFTER postal_code', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='address_number')=0,
  'ALTER TABLE tenants ADD COLUMN address_number VARCHAR(30) NULL AFTER address_line', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='address_complement')=0,
  'ALTER TABLE tenants ADD COLUMN address_complement VARCHAR(120) NULL AFTER address_number', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='district')=0,
  'ALTER TABLE tenants ADD COLUMN district VARCHAR(120) NULL AFTER address_complement', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='city')=0,
  'ALTER TABLE tenants ADD COLUMN city VARCHAR(120) NULL AFTER district', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='state')=0,
  'ALTER TABLE tenants ADD COLUMN state VARCHAR(60) NULL AFTER city', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='company_about')=0,
  'ALTER TABLE tenants ADD COLUMN company_about TEXT NULL AFTER state', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='company_services')=0,
  'ALTER TABLE tenants ADD COLUMN company_services TEXT NULL AFTER company_about', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='company_differentials')=0,
  'ALTER TABLE tenants ADD COLUMN company_differentials TEXT NULL AFTER company_services', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='company_business_hours')=0,
  'ALTER TABLE tenants ADD COLUMN company_business_hours VARCHAR(255) NULL AFTER company_differentials', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='tenants' AND COLUMN_NAME='company_notes')=0,
  'ALTER TABLE tenants ADD COLUMN company_notes TEXT NULL AFTER company_business_hours', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
