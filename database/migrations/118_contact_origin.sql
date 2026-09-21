USE rs_connect;

-- RS Connect 36.36.6
-- Registra a origem operacional do contato e classifica a base existente.

SET @db := DATABASE();
SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'origin'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE contacts ADD COLUMN origin VARCHAR(32) NOT NULL DEFAULT ''legacy'' AFTER name_source, ADD INDEX idx_contacts_tenant_origin (tenant_id, origin)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cadastro feito explicitamente pela tela de contatos.
UPDATE contacts c
SET c.origin = 'manual'
WHERE c.origin = 'legacy'
  AND EXISTS (
      SELECT 1
      FROM audit_logs al
      WHERE al.tenant_id = c.tenant_id
        AND al.action = 'contact.created'
        AND CAST(JSON_UNQUOTE(JSON_EXTRACT(al.context_json, '$.contact_id')) AS UNSIGNED) = c.id
  );

-- Contato que possui conversa real. Mantém 'manual' quando foi cadastrado antes da conversa.
UPDATE contacts c
SET c.origin = 'conversation'
WHERE c.origin = 'legacy'
  AND EXISTS (
      SELECT 1
      FROM conversations cv
      WHERE cv.tenant_id = c.tenant_id
        AND cv.contact_id = c.id
  );

-- Restante associado à Evolution sem conversa nem cadastro manual: criado pela
-- sincronização histórica da agenda CONTACTS_UPSERT/CONTACTS_UPDATE.
UPDATE contacts c
SET c.origin = 'whatsapp_sync'
WHERE c.origin = 'legacy'
  AND c.evolution_instance_id IS NOT NULL
  AND c.remote_jid IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM conversations cv
      WHERE cv.tenant_id = c.tenant_id AND cv.contact_id = c.id
  )
  AND NOT EXISTS (
      SELECT 1 FROM audit_logs al
      WHERE al.tenant_id = c.tenant_id
        AND al.action = 'contact.created'
        AND CAST(JSON_UNQUOTE(JSON_EXTRACT(al.context_json, '$.contact_id')) AS UNSIGNED) = c.id
  );
