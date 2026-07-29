-- RS Connect 36.7.0 — Atendimento opcional por profissional
-- Compatível com versões de MySQL/MariaDB sem ADD COLUMN IF NOT EXISTS.
-- Pode ser executada mais de uma vez.

SET @db = DATABASE();

-- Configurações por empresa.
SET @has_tenant_professional_assignment = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'professional_assignment_enabled'
);
SET @sql = IF(@has_tenant_professional_assignment = 0,
    'ALTER TABLE tenants ADD COLUMN professional_assignment_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_tenant_professional_lock = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'professional_lock_enabled'
);
SET @sql = IF(@has_tenant_professional_lock = 0,
    'ALTER TABLE tenants ADD COLUMN professional_lock_enabled TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_tenant_professional_auto = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'tenants' AND column_name = 'professional_auto_assign_enabled'
);
SET @sql = IF(@has_tenant_professional_auto = 0,
    'ALTER TABLE tenants ADD COLUMN professional_auto_assign_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Profissional preferido do contato. O vínculo não bloqueia sozinho; apenas informa preferência.
SET @has_contact_preferred_user = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'contacts' AND column_name = 'preferred_user_id'
);
SET @sql = IF(@has_contact_preferred_user = 0,
    'ALTER TABLE contacts ADD COLUMN preferred_user_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_contact_preferred_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'contacts' AND column_name = 'preferred_user_assigned_at'
);
SET @sql = IF(@has_contact_preferred_at = 0,
    'ALTER TABLE contacts ADD COLUMN preferred_user_assigned_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_contact_preferred_by = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'contacts' AND column_name = 'preferred_user_assigned_by_user_id'
);
SET @sql = IF(@has_contact_preferred_by = 0,
    'ALTER TABLE contacts ADD COLUMN preferred_user_assigned_by_user_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Auditoria de atribuição da conversa.
SET @has_conversation_assignment_source = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'assignment_source'
);
SET @sql = IF(@has_conversation_assignment_source = 0,
    'ALTER TABLE conversations ADD COLUMN assignment_source VARCHAR(30) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conversation_assignment_updated_by = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'assignment_updated_by_user_id'
);
SET @sql = IF(@has_conversation_assignment_updated_by = 0,
    'ALTER TABLE conversations ADD COLUMN assignment_updated_by_user_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conversation_assignment_released_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'assignment_released_at'
);
SET @sql = IF(@has_conversation_assignment_released_at = 0,
    'ALTER TABLE conversations ADD COLUMN assignment_released_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índices.
SET @has_idx_contacts_preferred_user = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = @db AND table_name = 'contacts' AND index_name = 'idx_contacts_preferred_user'
);
SET @sql = IF(@has_idx_contacts_preferred_user = 0,
    'ALTER TABLE contacts ADD INDEX idx_contacts_preferred_user (tenant_id, preferred_user_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_conversations_assignment = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = @db AND table_name = 'conversations' AND index_name = 'idx_conversations_assignment_lock'
);
SET @sql = IF(@has_idx_conversations_assignment = 0,
    'ALTER TABLE conversations ADD INDEX idx_conversations_assignment_lock (tenant_id, status, assigned_user_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Chaves estrangeiras, adicionadas apenas quando ainda não existem.
SET @has_fk_contact_preferred_user = (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE constraint_schema = @db AND constraint_name = 'fk_contacts_preferred_user'
);
SET @sql = IF(@has_fk_contact_preferred_user = 0,
    'ALTER TABLE contacts ADD CONSTRAINT fk_contacts_preferred_user FOREIGN KEY (preferred_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk_contact_preferred_by = (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE constraint_schema = @db AND constraint_name = 'fk_contacts_preferred_by_user'
);
SET @sql = IF(@has_fk_contact_preferred_by = 0,
    'ALTER TABLE contacts ADD CONSTRAINT fk_contacts_preferred_by_user FOREIGN KEY (preferred_user_assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk_conversation_assignment_updated_by = (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE constraint_schema = @db AND constraint_name = 'fk_conversations_assignment_updated_by'
);
SET @sql = IF(@has_fk_conversation_assignment_updated_by = 0,
    'ALTER TABLE conversations ADD CONSTRAINT fk_conversations_assignment_updated_by FOREIGN KEY (assignment_updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration 064 aplicada: atendimento por profissional opcional; atribuição automática permanece desativada por padrão.' AS resultado;
