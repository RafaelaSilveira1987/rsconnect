-- RS Connect 36.6.26 — identidade confiável do contato WhatsApp
-- O nome recebido em um único webhook deixa de ser promovido imediatamente.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contacts' AND COLUMN_NAME='name_source') = 0,
    'ALTER TABLE contacts ADD COLUMN name_source VARCHAR(24) NOT NULL DEFAULT ''legacy'' AFTER name',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contacts' AND COLUMN_NAME='whatsapp_name_candidate') = 0,
    'ALTER TABLE contacts ADD COLUMN whatsapp_name_candidate VARCHAR(150) NULL AFTER name_source',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contacts' AND COLUMN_NAME='whatsapp_name_seen_count') = 0,
    'ALTER TABLE contacts ADD COLUMN whatsapp_name_seen_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER whatsapp_name_candidate',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='contacts' AND INDEX_NAME='idx_contacts_whatsapp_candidate') = 0,
    'ALTER TABLE contacts ADD INDEX idx_contacts_whatsapp_candidate (tenant_id, whatsapp_name_candidate)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE contacts
SET name_source = CASE
    WHEN NULLIF(TRIM(name), '') IS NULL THEN 'unknown'
    ELSE COALESCE(NULLIF(name_source, ''), 'legacy')
END;
