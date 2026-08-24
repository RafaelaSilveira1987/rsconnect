-- RS Connect HOTFIX 36.1.2
-- Garante compatibilidade da fila e evita falha ao registrar status "failed".
-- Pode ser executada novamente com segurança.

SET @database_name = DATABASE();

-- Vínculo entre tentativa da IA e mensagem recebida.
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

-- Índice usado pelo modo de compatibilidade quando a mensagem ainda não possui vínculo direto.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE ai_automation_logs ADD INDEX idx_ai_logs_pending_lookup (tenant_id, conversation_id, agent_id, created_at)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'ai_automation_logs'
      AND INDEX_NAME = 'idx_ai_logs_pending_lookup'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Instalações antigas podem não aceitar os estados failed/received.
SET @status_type = (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'conversation_messages'
      AND COLUMN_NAME = 'status'
    LIMIT 1
);
SET @sql = IF(
    @status_type IS NOT NULL
    AND (@status_type NOT LIKE '%failed%' OR @status_type NOT LIKE '%received%'),
    'ALTER TABLE conversation_messages MODIFY COLUMN status ENUM(''pending'',''sent'',''delivered'',''read'',''failed'',''received'') NOT NULL DEFAULT ''received''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Relaciona logs antigos à mensagem recebida mais recente anterior à tentativa.
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
