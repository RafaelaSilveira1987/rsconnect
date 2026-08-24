-- RS Connect ZIP 36.1.1 — fila da IA vinculada à mensagem recebida
-- Corrige pendências invisíveis causadas por ai.failed, falha de entrega da Evolution ou execução interrompida.
-- Pode ser executada novamente com segurança.

SET @database_name = DATABASE();

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_automation_logs ADD COLUMN incoming_message_id BIGINT UNSIGNED NULL AFTER agent_id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_automation_logs'
      AND COLUMN_NAME = 'incoming_message_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_automation_logs ADD INDEX idx_ai_logs_incoming_message (incoming_message_id, id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_automation_logs'
      AND INDEX_NAME = 'idx_ai_logs_incoming_message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Relaciona logs antigos de tentativa à mensagem recebida mais recente da conversa.
-- Isso torna visíveis pendências criadas antes da instalação deste hotfix.
UPDATE ai_automation_logs al
SET al.incoming_message_id = (
    SELECT cm.id
    FROM conversation_messages cm
    WHERE cm.conversation_id = al.conversation_id
      AND cm.tenant_id = al.tenant_id
      AND cm.direction = 'incoming'
      AND cm.sent_at <= al.created_at
    ORDER BY cm.sent_at DESC, cm.id DESC
    LIMIT 1
)
WHERE al.incoming_message_id IS NULL
  AND al.conversation_id IS NOT NULL
  AND al.event IN ('ai.cooldown', 'ai.failed', 'ai.replied');
