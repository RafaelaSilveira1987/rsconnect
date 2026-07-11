-- ZIP 18 - QR Code e status da Evolution por instância
-- Compatível com MySQL/MariaDB sem ADD COLUMN IF NOT EXISTS.

SET @db := DATABASE();

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_instances' AND COLUMN_NAME = 'connection_state'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE evolution_instances ADD COLUMN connection_state VARCHAR(60) NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_instances' AND COLUMN_NAME = 'last_status_check_at'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE evolution_instances ADD COLUMN last_status_check_at DATETIME NULL AFTER connection_state',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_instances' AND COLUMN_NAME = 'qrcode_requested_at'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE evolution_instances ADD COLUMN qrcode_requested_at DATETIME NULL AFTER last_status_check_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_instances' AND COLUMN_NAME = 'connected_at'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE evolution_instances ADD COLUMN connected_at DATETIME NULL AFTER qrcode_requested_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_instances' AND COLUMN_NAME = 'disconnected_at'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE evolution_instances ADD COLUMN disconnected_at DATETIME NULL AFTER connected_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'evolution_instances' AND INDEX_NAME = 'idx_instances_connection_state'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE evolution_instances ADD INDEX idx_instances_connection_state (tenant_id, connection_state)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Garante permissões para clientes administrarem a própria instância, quando o perfil já usa permissões.
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'instances.manage', 'Gerenciar instâncias', 'Permite cadastrar, testar, gerar QR Code e atualizar status das instâncias WhatsApp.', 'Automação'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'instances.manage');
