-- RS Connect v36.13.0
-- Diagnóstico dos anexos das conversas.

SELECT
    COUNT(*) AS estruturas_encontradas
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'conversation_message_attachments';

SELECT
    COUNT(*) AS colunas_encontradas
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'conversation_message_attachments';

SELECT
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas,
    NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'conversation_message_attachments'
GROUP BY INDEX_NAME, NON_UNIQUE
ORDER BY INDEX_NAME;

SELECT
    status,
    attachment_kind,
    direction,
    COUNT(*) AS quantidade,
    COALESCE(SUM(size_bytes), 0) AS bytes_armazenados
FROM conversation_message_attachments
GROUP BY status, attachment_kind, direction
ORDER BY status, attachment_kind, direction;

-- Deve retornar zero linhas: anexo e conversa precisam pertencer à mesma empresa.
SELECT
    a.id AS attachment_id,
    a.uuid AS attachment_uuid,
    a.tenant_id AS attachment_tenant_id,
    c.tenant_id AS conversation_tenant_id,
    a.conversation_id
FROM conversation_message_attachments a
INNER JOIN conversations c ON c.id = a.conversation_id
WHERE a.tenant_id <> c.tenant_id;

-- Deve retornar zero linhas: anexo e mensagem precisam pertencer à mesma conversa/empresa.
SELECT
    a.id AS attachment_id,
    a.message_id,
    a.conversation_id AS attachment_conversation_id,
    m.conversation_id AS message_conversation_id,
    a.tenant_id AS attachment_tenant_id,
    m.tenant_id AS message_tenant_id
FROM conversation_message_attachments a
INNER JOIN conversation_messages m ON m.id = a.message_id
WHERE a.message_id IS NOT NULL
  AND (
      a.conversation_id <> m.conversation_id
      OR a.tenant_id <> m.tenant_id
  );

-- Deve retornar zero linhas: UUID público duplicado.
SELECT uuid, COUNT(*) AS quantidade
FROM conversation_message_attachments
GROUP BY uuid
HAVING COUNT(*) > 1;

-- Arquivos com falha para análise operacional.
SELECT
    id,
    tenant_id,
    conversation_id,
    attachment_kind,
    original_name,
    mime_type,
    error_message,
    created_at
FROM conversation_message_attachments
WHERE status = 'failed'
ORDER BY id DESC
LIMIT 50;
