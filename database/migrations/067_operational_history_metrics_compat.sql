-- RS Connect 36.9.1 — Base histórica operacional e métricas por profissional
-- Compatível com MySQL/MariaDB sem ADD COLUMN IF NOT EXISTS.
-- Pode ser executada mais de uma vez: colunas/tabelas são preservadas e triggers são recriados.

SET NAMES utf8mb4;
SET @db = DATABASE();

-- ---------------------------------------------------------------------------
-- Conversas: datas operacionais e autoria da primeira resposta/status.
-- ---------------------------------------------------------------------------
SET @has_conversation_opened_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'opened_at'
);
SET @sql = IF(@has_conversation_opened_at = 0,
    'ALTER TABLE conversations ADD COLUMN opened_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conversation_first_incoming_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'first_incoming_at'
);
SET @sql = IF(@has_conversation_first_incoming_at = 0,
    'ALTER TABLE conversations ADD COLUMN first_incoming_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conversation_last_incoming_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'last_incoming_at'
);
SET @sql = IF(@has_conversation_last_incoming_at = 0,
    'ALTER TABLE conversations ADD COLUMN last_incoming_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conversation_first_response_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'first_response_at'
);
SET @sql = IF(@has_conversation_first_response_at = 0,
    'ALTER TABLE conversations ADD COLUMN first_response_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conversation_first_response_user = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'first_response_user_id'
);
SET @sql = IF(@has_conversation_first_response_user = 0,
    'ALTER TABLE conversations ADD COLUMN first_response_user_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conversation_closed_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'closed_at'
);
SET @sql = IF(@has_conversation_closed_at = 0,
    'ALTER TABLE conversations ADD COLUMN closed_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conversation_status_changed_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'status_changed_at'
);
SET @sql = IF(@has_conversation_status_changed_at = 0,
    'ALTER TABLE conversations ADD COLUMN status_changed_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conversation_status_changed_by = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'conversations' AND column_name = 'status_changed_by_user_id'
);
SET @sql = IF(@has_conversation_status_changed_by = 0,
    'ALTER TABLE conversations ADD COLUMN status_changed_by_user_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Agenda: datas operacionais e autoria das mudanças.
-- ---------------------------------------------------------------------------
SET @has_appointment_confirmed_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'calendar_appointments' AND column_name = 'confirmed_at'
);
SET @sql = IF(@has_appointment_confirmed_at = 0,
    'ALTER TABLE calendar_appointments ADD COLUMN confirmed_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_appointment_completed_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'calendar_appointments' AND column_name = 'completed_at'
);
SET @sql = IF(@has_appointment_completed_at = 0,
    'ALTER TABLE calendar_appointments ADD COLUMN completed_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_appointment_cancelled_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'calendar_appointments' AND column_name = 'cancelled_at'
);
SET @sql = IF(@has_appointment_cancelled_at = 0,
    'ALTER TABLE calendar_appointments ADD COLUMN cancelled_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_appointment_no_show_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'calendar_appointments' AND column_name = 'no_show_at'
);
SET @sql = IF(@has_appointment_no_show_at = 0,
    'ALTER TABLE calendar_appointments ADD COLUMN no_show_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_appointment_status_changed_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'calendar_appointments' AND column_name = 'status_changed_at'
);
SET @sql = IF(@has_appointment_status_changed_at = 0,
    'ALTER TABLE calendar_appointments ADD COLUMN status_changed_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_appointment_status_changed_by = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'calendar_appointments' AND column_name = 'status_changed_by_user_id'
);
SET @sql = IF(@has_appointment_status_changed_by = 0,
    'ALTER TABLE calendar_appointments ADD COLUMN status_changed_by_user_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_appointment_owner_changed_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'calendar_appointments' AND column_name = 'owner_changed_at'
);
SET @sql = IF(@has_appointment_owner_changed_at = 0,
    'ALTER TABLE calendar_appointments ADD COLUMN owner_changed_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_appointment_owner_changed_by = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = @db AND table_name = 'calendar_appointments' AND column_name = 'owner_changed_by_user_id'
);
SET @sql = IF(@has_appointment_owner_changed_by = 0,
    'ALTER TABLE calendar_appointments ADD COLUMN owner_changed_by_user_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Históricos normalizados para relatórios por profissional.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conversation_assignment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    previous_user_id BIGINT UNSIGNED NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL,
    source VARCHAR(40) COLLATE utf8mb4_unicode_ci NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    metadata_json JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conversation_assignment_tenant_date (tenant_id, occurred_at),
    KEY idx_conversation_assignment_conversation (conversation_id, occurred_at),
    KEY idx_conversation_assignment_user (assigned_user_id, occurred_at),
    CONSTRAINT fk_conversation_assignment_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_assignment_history_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_assignment_history_previous_user FOREIGN KEY (previous_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_conversation_assignment_history_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_conversation_assignment_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    previous_status VARCHAR(24) COLLATE utf8mb4_unicode_ci NULL,
    status VARCHAR(24) COLLATE utf8mb4_unicode_ci NOT NULL,
    responsible_user_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    metadata_json JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conversation_status_tenant_date (tenant_id, occurred_at),
    KEY idx_conversation_status_conversation (conversation_id, occurred_at),
    KEY idx_conversation_status_responsible (responsible_user_id, occurred_at),
    CONSTRAINT fk_conversation_status_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_status_history_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_status_history_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_conversation_status_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_appointment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL,
    previous_status VARCHAR(32) COLLATE utf8mb4_unicode_ci NULL,
    status VARCHAR(32) COLLATE utf8mb4_unicode_ci NULL,
    previous_owner_user_id BIGINT UNSIGNED NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    title_snapshot VARCHAR(180) COLLATE utf8mb4_unicode_ci NULL,
    metadata_json JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_appointment_history_tenant_date (tenant_id, occurred_at),
    KEY idx_appointment_history_appointment (appointment_id, occurred_at),
    KEY idx_appointment_history_owner (owner_user_id, occurred_at),
    KEY idx_appointment_history_event (tenant_id, event_type, occurred_at),
    CONSTRAINT fk_appointment_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_appointment_history_previous_owner FOREIGN KEY (previous_owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_appointment_history_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_appointment_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices para consultas de desempenho.
SET @has_idx_conversation_first_response = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = @db AND table_name = 'conversations' AND index_name = 'idx_conversations_response_metrics'
);
SET @sql = IF(@has_idx_conversation_first_response = 0,
    'ALTER TABLE conversations ADD INDEX idx_conversations_response_metrics (tenant_id, first_response_user_id, first_response_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_appointment_operational = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = @db AND table_name = 'calendar_appointments' AND index_name = 'idx_calendar_owner_operational'
);
SET @sql = IF(@has_idx_appointment_operational = 0,
    'ALTER TABLE calendar_appointments ADD INDEX idx_calendar_owner_operational (tenant_id, owner_user_id, status, starts_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Permissões preparadas para a próxima tela de relatórios.
INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
    ('reports.team.view_own', 'Ver próprios indicadores', 'Visualizar indicadores de atendimento e agenda vinculados ao próprio usuário.', 'Relatórios'),
    ('reports.team.view_all', 'Ver indicadores da equipe', 'Visualizar indicadores consolidados de todos os profissionais da empresa.', 'Relatórios');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key = 'reports.team.view_own'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE p.permission_key IN ('reports.team.view_own', 'reports.team.view_all')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

-- Backfill seguro dos marcos atuais. Usa mensagens reais quando disponíveis e
-- cria somente snapshots do estado atual; mudanças antigas não são inventadas.
UPDATE conversations
SET opened_at = COALESCE(opened_at, created_at),
    closed_at = CASE WHEN status = 'closed' THEN COALESCE(closed_at, updated_at) ELSE closed_at END,
    status_changed_at = COALESCE(status_changed_at, updated_at)
WHERE opened_at IS NULL OR status_changed_at IS NULL OR (status = 'closed' AND closed_at IS NULL);

UPDATE conversations c
SET c.first_incoming_at = COALESCE(
        c.first_incoming_at,
        (SELECT MIN(mi.sent_at)
         FROM conversation_messages mi
         WHERE mi.tenant_id = c.tenant_id
           AND mi.conversation_id = c.id
           AND mi.direction = 'incoming')
    ),
    c.last_incoming_at = COALESCE(
        c.last_incoming_at,
        (SELECT MAX(ml.sent_at)
         FROM conversation_messages ml
         WHERE ml.tenant_id = c.tenant_id
           AND ml.conversation_id = c.id
           AND ml.direction = 'incoming')
    )
WHERE c.first_incoming_at IS NULL OR c.last_incoming_at IS NULL;

UPDATE conversations c
SET c.first_response_at = (
        SELECT mo.sent_at
        FROM conversation_messages mo
        WHERE mo.tenant_id = c.tenant_id
          AND mo.conversation_id = c.id
          AND mo.direction = 'outgoing'
          AND mo.sender_type = 'user'
          AND mo.sent_at >= c.first_incoming_at
        ORDER BY mo.sent_at, mo.id
        LIMIT 1
    ),
    c.first_response_user_id = (
        SELECT mu.sender_user_id
        FROM conversation_messages mu
        WHERE mu.tenant_id = c.tenant_id
          AND mu.conversation_id = c.id
          AND mu.direction = 'outgoing'
          AND mu.sender_type = 'user'
          AND mu.sent_at >= c.first_incoming_at
        ORDER BY mu.sent_at, mu.id
        LIMIT 1
    )
WHERE c.first_response_at IS NULL
  AND c.first_incoming_at IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM conversation_messages mx
      WHERE mx.tenant_id = c.tenant_id
        AND mx.conversation_id = c.id
        AND mx.direction = 'outgoing'
        AND mx.sender_type = 'user'
        AND mx.sent_at >= c.first_incoming_at
  );

UPDATE calendar_appointments
SET status_changed_at = COALESCE(status_changed_at, updated_at),
    confirmed_at = CASE WHEN status = 'confirmed' THEN COALESCE(confirmed_at, updated_at) ELSE confirmed_at END,
    completed_at = CASE WHEN status = 'completed' THEN COALESCE(completed_at, updated_at) ELSE completed_at END,
    cancelled_at = CASE WHEN status IN ('cancelled', 'rejected') THEN COALESCE(cancelled_at, updated_at) ELSE cancelled_at END,
    no_show_at = CASE WHEN status = 'no_show' THEN COALESCE(no_show_at, updated_at) ELSE no_show_at END,
    owner_changed_at = CASE WHEN owner_user_id IS NOT NULL THEN COALESCE(owner_changed_at, created_at) ELSE owner_changed_at END
WHERE status_changed_at IS NULL
   OR (status = 'confirmed' AND confirmed_at IS NULL)
   OR (status = 'completed' AND completed_at IS NULL)
   OR (status IN ('cancelled', 'rejected') AND cancelled_at IS NULL)
   OR (status = 'no_show' AND no_show_at IS NULL)
   OR (owner_user_id IS NOT NULL AND owner_changed_at IS NULL);

-- Snapshot idempotente do estado atual para iniciar os relatórios sem inventar mudanças.
INSERT INTO conversation_status_history
    (tenant_id, conversation_id, previous_status, status, responsible_user_id, actor_user_id, metadata_json, occurred_at)
SELECT c.tenant_id, c.id, NULL, c.status, c.assigned_user_id, NULL,
       JSON_OBJECT('source', 'migration_snapshot'), COALESCE(c.status_changed_at, c.updated_at)
FROM conversations c
WHERE NOT EXISTS (
    SELECT 1 FROM conversation_status_history h
    WHERE h.tenant_id = c.tenant_id AND h.conversation_id = c.id
);

INSERT INTO conversation_assignment_history
    (tenant_id, conversation_id, previous_user_id, assigned_user_id, action, source, actor_user_id, metadata_json, occurred_at)
SELECT c.tenant_id, c.id, NULL, c.assigned_user_id, 'snapshot', 'migration_snapshot', NULL,
       JSON_OBJECT('current_status', c.status), COALESCE(c.assigned_at, c.updated_at)
FROM conversations c
WHERE c.assigned_user_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM conversation_assignment_history h
      WHERE h.tenant_id = c.tenant_id AND h.conversation_id = c.id
  );

INSERT INTO calendar_appointment_history
    (tenant_id, appointment_id, event_type, previous_status, status,
     previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
     title_snapshot, metadata_json, occurred_at)
SELECT a.tenant_id, a.id, 'snapshot', NULL, a.status,
       NULL, a.owner_user_id, NULL, a.starts_at, a.ends_at,
       a.title, JSON_OBJECT('source', 'migration_snapshot'), a.updated_at
FROM calendar_appointments a
WHERE NOT EXISTS (
    SELECT 1 FROM calendar_appointment_history h
    WHERE h.tenant_id = a.tenant_id AND h.appointment_id = a.id
);

-- ---------------------------------------------------------------------------
-- Triggers: capturam todos os caminhos (painel, IA, n8n, webhook e manutenção).
-- ---------------------------------------------------------------------------
DELIMITER $$

DROP TRIGGER IF EXISTS trg_rs_conversations_before_insert_metrics$$
CREATE TRIGGER trg_rs_conversations_before_insert_metrics
BEFORE INSERT ON conversations
FOR EACH ROW
BEGIN
    IF NEW.opened_at IS NULL THEN
        SET NEW.opened_at = CURRENT_TIMESTAMP;
    END IF;
    IF NEW.status_changed_at IS NULL THEN
        SET NEW.status_changed_at = CURRENT_TIMESTAMP;
    END IF;
    IF NEW.status = 'closed' AND NEW.closed_at IS NULL THEN
        SET NEW.closed_at = CURRENT_TIMESTAMP;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_conversations_before_update_metrics$$
CREATE TRIGGER trg_rs_conversations_before_update_metrics
BEFORE UPDATE ON conversations
FOR EACH ROW
BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        IF NEW.status_changed_by_user_id <=> OLD.status_changed_by_user_id THEN
            SET NEW.status_changed_by_user_id = NULL;
        END IF;
        SET NEW.status_changed_at = CURRENT_TIMESTAMP;
        IF NEW.status = 'closed' THEN
            SET NEW.closed_at = CURRENT_TIMESTAMP;
        ELSEIF OLD.status = 'closed' THEN
            SET NEW.opened_at = CURRENT_TIMESTAMP;
            SET NEW.closed_at = NULL;
            SET NEW.first_incoming_at = NULL;
            SET NEW.last_incoming_at = NULL;
            SET NEW.first_response_at = NULL;
            SET NEW.first_response_user_id = NULL;
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_conversations_after_insert_history$$
CREATE TRIGGER trg_rs_conversations_after_insert_history
AFTER INSERT ON conversations
FOR EACH ROW
BEGIN
    INSERT INTO conversation_status_history
        (tenant_id, conversation_id, previous_status, status, responsible_user_id, actor_user_id, occurred_at)
    VALUES
        (NEW.tenant_id, NEW.id, NULL, NEW.status, NEW.assigned_user_id, NEW.status_changed_by_user_id, COALESCE(NEW.status_changed_at, CURRENT_TIMESTAMP));

    IF NEW.assigned_user_id IS NOT NULL THEN
        INSERT INTO conversation_assignment_history
            (tenant_id, conversation_id, previous_user_id, assigned_user_id, action, source, actor_user_id, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, NULL, NEW.assigned_user_id, 'assign', COALESCE(NEW.assignment_source, 'initial'), NEW.assignment_updated_by_user_id, COALESCE(NEW.assigned_at, CURRENT_TIMESTAMP));
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_conversations_after_update_history$$
CREATE TRIGGER trg_rs_conversations_after_update_history
AFTER UPDATE ON conversations
FOR EACH ROW
BEGIN
    IF NOT (NEW.assigned_user_id <=> OLD.assigned_user_id) THEN
        INSERT INTO conversation_assignment_history
            (tenant_id, conversation_id, previous_user_id, assigned_user_id, action, source, actor_user_id, occurred_at)
        VALUES
            (
                NEW.tenant_id,
                NEW.id,
                OLD.assigned_user_id,
                NEW.assigned_user_id,
                CASE
                    WHEN OLD.assigned_user_id IS NULL AND NEW.assigned_user_id IS NOT NULL THEN 'assign'
                    WHEN OLD.assigned_user_id IS NOT NULL AND NEW.assigned_user_id IS NULL THEN 'release'
                    ELSE 'transfer'
                END,
                COALESCE(NEW.assignment_source, 'system'),
                NEW.assignment_updated_by_user_id,
                CURRENT_TIMESTAMP
            );
    END IF;

    IF NOT (NEW.status <=> OLD.status) THEN
        INSERT INTO conversation_status_history
            (tenant_id, conversation_id, previous_status, status, responsible_user_id, actor_user_id, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, OLD.status, NEW.status, NEW.assigned_user_id, NEW.status_changed_by_user_id, COALESCE(NEW.status_changed_at, CURRENT_TIMESTAMP));
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_messages_after_insert_metrics$$
CREATE TRIGGER trg_rs_messages_after_insert_metrics
AFTER INSERT ON conversation_messages
FOR EACH ROW
BEGIN
    IF NEW.direction = 'incoming' THEN
        UPDATE conversations c
        SET c.first_incoming_at = COALESCE(c.first_incoming_at, NEW.sent_at),
            c.last_incoming_at = NEW.sent_at
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id;
    ELSEIF NEW.direction = 'outgoing' AND NEW.sender_type = 'user' THEN
        UPDATE conversations c
        SET c.first_response_user_id = COALESCE(c.first_response_user_id, NEW.sender_user_id),
            c.first_response_at = COALESCE(c.first_response_at, NEW.sent_at)
        WHERE c.id = NEW.conversation_id
          AND c.tenant_id = NEW.tenant_id
          AND c.first_response_at IS NULL
          AND c.first_incoming_at IS NOT NULL
          AND c.first_incoming_at <= NEW.sent_at;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_before_insert_metrics$$
CREATE TRIGGER trg_rs_appointments_before_insert_metrics
BEFORE INSERT ON calendar_appointments
FOR EACH ROW
BEGIN
    SET NEW.status_changed_at = COALESCE(NEW.status_changed_at, CURRENT_TIMESTAMP);
    IF NEW.owner_user_id IS NOT NULL THEN
        SET NEW.owner_changed_at = COALESCE(NEW.owner_changed_at, CURRENT_TIMESTAMP);
    END IF;
    IF NEW.status = 'confirmed' THEN SET NEW.confirmed_at = COALESCE(NEW.confirmed_at, CURRENT_TIMESTAMP); END IF;
    IF NEW.status = 'completed' THEN SET NEW.completed_at = COALESCE(NEW.completed_at, CURRENT_TIMESTAMP); END IF;
    IF NEW.status IN ('cancelled', 'rejected') THEN SET NEW.cancelled_at = COALESCE(NEW.cancelled_at, CURRENT_TIMESTAMP); END IF;
    IF NEW.status = 'no_show' THEN SET NEW.no_show_at = COALESCE(NEW.no_show_at, CURRENT_TIMESTAMP); END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_before_update_metrics$$
CREATE TRIGGER trg_rs_appointments_before_update_metrics
BEFORE UPDATE ON calendar_appointments
FOR EACH ROW
BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        IF NEW.status_changed_by_user_id <=> OLD.status_changed_by_user_id THEN
            SET NEW.status_changed_by_user_id = NULL;
        END IF;
        SET NEW.status_changed_at = CURRENT_TIMESTAMP;
        IF NEW.status = 'confirmed' THEN SET NEW.confirmed_at = CURRENT_TIMESTAMP; END IF;
        IF NEW.status = 'completed' THEN SET NEW.completed_at = CURRENT_TIMESTAMP; END IF;
        IF NEW.status IN ('cancelled', 'rejected') THEN SET NEW.cancelled_at = CURRENT_TIMESTAMP; END IF;
        IF NEW.status = 'no_show' THEN SET NEW.no_show_at = CURRENT_TIMESTAMP; END IF;
    END IF;

    IF NOT (NEW.owner_user_id <=> OLD.owner_user_id) THEN
        IF NEW.owner_changed_by_user_id <=> OLD.owner_changed_by_user_id THEN
            SET NEW.owner_changed_by_user_id = NULL;
        END IF;
        SET NEW.owner_changed_at = CURRENT_TIMESTAMP;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_after_insert_history$$
CREATE TRIGGER trg_rs_appointments_after_insert_history
AFTER INSERT ON calendar_appointments
FOR EACH ROW
BEGIN
    INSERT INTO calendar_appointment_history
        (tenant_id, appointment_id, event_type, previous_status, status,
         previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
         title_snapshot, occurred_at)
    VALUES
        (NEW.tenant_id, NEW.id, 'created', NULL, NEW.status,
         NULL, NEW.owner_user_id, NEW.created_by_user_id, NEW.starts_at, NEW.ends_at,
         NEW.title, CURRENT_TIMESTAMP);
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_after_update_history$$
CREATE TRIGGER trg_rs_appointments_after_update_history
AFTER UPDATE ON calendar_appointments
FOR EACH ROW
BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        INSERT INTO calendar_appointment_history
            (tenant_id, appointment_id, event_type, previous_status, status,
             previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
             title_snapshot, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, 'status_changed', OLD.status, NEW.status,
             OLD.owner_user_id, NEW.owner_user_id, NEW.status_changed_by_user_id,
             NEW.starts_at, NEW.ends_at, NEW.title, COALESCE(NEW.status_changed_at, CURRENT_TIMESTAMP));
    END IF;

    IF NOT (NEW.owner_user_id <=> OLD.owner_user_id) THEN
        INSERT INTO calendar_appointment_history
            (tenant_id, appointment_id, event_type, previous_status, status,
             previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
             title_snapshot, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, 'owner_changed', OLD.status, NEW.status,
             OLD.owner_user_id, NEW.owner_user_id, NEW.owner_changed_by_user_id,
             NEW.starts_at, NEW.ends_at, NEW.title, COALESCE(NEW.owner_changed_at, CURRENT_TIMESTAMP));
    END IF;

    IF NOT (NEW.starts_at <=> OLD.starts_at) OR NOT (NEW.ends_at <=> OLD.ends_at) THEN
        INSERT INTO calendar_appointment_history
            (tenant_id, appointment_id, event_type, previous_status, status,
             previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
             title_snapshot, metadata_json, occurred_at)
        VALUES
            (NEW.tenant_id, NEW.id, 'rescheduled', OLD.status, NEW.status,
             OLD.owner_user_id, NEW.owner_user_id,
             COALESCE(NEW.status_changed_by_user_id, NEW.owner_changed_by_user_id),
             NEW.starts_at, NEW.ends_at, NEW.title,
             JSON_OBJECT('previous_starts_at', OLD.starts_at, 'previous_ends_at', OLD.ends_at),
             CURRENT_TIMESTAMP);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_rs_appointments_before_delete_history$$
CREATE TRIGGER trg_rs_appointments_before_delete_history
BEFORE DELETE ON calendar_appointments
FOR EACH ROW
BEGIN
    INSERT INTO calendar_appointment_history
        (tenant_id, appointment_id, event_type, previous_status, status,
         previous_owner_user_id, owner_user_id, actor_user_id, starts_at, ends_at,
         title_snapshot, occurred_at)
    VALUES
        (OLD.tenant_id, OLD.id, 'deleted', OLD.status, NULL,
         OLD.owner_user_id, NULL, NULL, OLD.starts_at, OLD.ends_at, OLD.title, CURRENT_TIMESTAMP);
END$$

DELIMITER ;

SELECT 'Migration 067 aplicada: histórico de atribuições, ciclos de conversa, primeira resposta humana e mudanças da agenda disponíveis para relatórios por profissional.' AS resultado;
