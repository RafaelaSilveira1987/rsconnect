-- RS Connect 36.26.7 — resolução persistente de alertas e liberação segura da fila.
-- Adiciona o status cancelled às mensagens para permitir retirar respostas pendentes
-- sem apagar o histórico e sem reenviá-las após a reconexão do WhatsApp.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(
        COUNT(*) = 1
        AND LOCATE("'cancelled'", LOWER(MAX(COLUMN_TYPE))) = 0,
        'ALTER TABLE conversation_messages MODIFY COLUMN status ENUM(''pending'',''sent'',''delivered'',''read'',''failed'',''received'',''cancelled'') NOT NULL DEFAULT ''received''',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'conversation_messages'
      AND COLUMN_NAME = 'status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
