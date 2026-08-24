-- RS Connect 36.17.2 — Atualização resiliente da foto do contato WhatsApp
-- Adiciona controle de última consulta para renovar URLs temporárias sem sobrecarregar a Evolution.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE contacts ADD COLUMN avatar_checked_at TIMESTAMP NULL AFTER avatar_url',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'contacts'
      AND COLUMN_NAME = 'avatar_checked_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Valores vazios antigos impediam uma nova consulta. Voltam a NULL uma única vez
-- para que a primeira abertura da conversa tente recuperar a foto novamente.
UPDATE contacts
SET avatar_url = NULL
WHERE avatar_url = ''
  AND avatar_checked_at IS NULL;
