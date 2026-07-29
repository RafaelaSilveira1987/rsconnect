-- RS Connect 36.8.1 — Bloqueio opcional de conflito do cliente
-- Compatível com MySQL/MariaDB sem ADD COLUMN IF NOT EXISTS.
-- Pode ser executada mais de uma vez.

SET @db = DATABASE();

SET @has_prevent_contact_overlap = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db
      AND table_name = 'tenants'
      AND column_name = 'professional_calendar_prevent_contact_overlap'
);
SET @sql = IF(@has_prevent_contact_overlap = 0,
    'ALTER TABLE tenants ADD COLUMN professional_calendar_prevent_contact_overlap TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration 066 aplicada: conflito de horário do mesmo cliente bloqueado por padrão e configurável por empresa.' AS resultado;
