-- ZIP 07 - Conversas Pro + CRM Automático
-- Execute uma única vez após aplicar o ZIP 07.

DELIMITER $$

DROP PROCEDURE IF EXISTS rs_add_column_if_missing$$
CREATE PROCEDURE rs_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS rs_add_index_if_missing$$
CREATE PROCEDURE rs_add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL rs_add_column_if_missing('conversations', 'crm_lead_id', 'bigint unsigned DEFAULT NULL AFTER contact_id');
CALL rs_add_column_if_missing('conversations', 'ai_summary', 'text COLLATE utf8mb4_unicode_ci NULL AFTER last_message_preview');
CALL rs_add_column_if_missing('conversations', 'ai_interest_level', "enum('frio','morno','quente') COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER ai_summary");
CALL rs_add_column_if_missing('conversations', 'ai_next_action', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER ai_interest_level');
CALL rs_add_column_if_missing('conversations', 'last_ai_suggestion', 'text COLLATE utf8mb4_unicode_ci NULL AFTER ai_next_action');
CALL rs_add_column_if_missing('conversations', 'last_ai_suggestion_at', 'datetime DEFAULT NULL AFTER last_ai_suggestion');

CALL rs_add_column_if_missing('crm_leads', 'source', "varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' AFTER status");
CALL rs_add_column_if_missing('crm_leads', 'source_conversation_id', 'bigint unsigned DEFAULT NULL AFTER source');

CALL rs_add_index_if_missing('conversations', 'idx_conversations_crm_lead', '(crm_lead_id)');
CALL rs_add_index_if_missing('crm_leads', 'idx_crm_leads_source_conversation', '(source_conversation_id)');
CALL rs_add_index_if_missing('crm_leads', 'idx_crm_leads_source', '(tenant_id, source, status)');

UPDATE crm_leads SET source = 'manual' WHERE source IS NULL OR source = '';

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;
DROP PROCEDURE IF EXISTS rs_add_index_if_missing;
