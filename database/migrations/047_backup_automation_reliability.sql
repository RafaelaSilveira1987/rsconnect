-- RS Connect v36.3.0 — Backup operacional confiável
-- Aplicar após a migration 046.
-- Torna o ciclo solicitado -> executando -> callback -> concluído idempotente e auditável.

SET @db_name = DATABASE();

-- Rotinas: arquivamento visual de duplicadas antigas.
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE operations_backup_routines ADD COLUMN archived_at DATETIME NULL AFTER notes',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'operations_backup_routines'
      AND COLUMN_NAME = 'archived_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Jobs: novos estados e dados de rastreabilidade.
ALTER TABLE operations_backup_jobs
    MODIFY COLUMN status ENUM('requested','running','success','error','timeout','skipped') NOT NULL DEFAULT 'requested';

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operations_backup_jobs ADD COLUMN execution_uuid CHAR(36) NULL AFTER routine_id',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND COLUMN_NAME = 'execution_uuid'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operations_backup_jobs ADD COLUMN acknowledged_at DATETIME NULL AFTER started_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND COLUMN_NAME = 'acknowledged_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operations_backup_jobs ADD COLUMN callback_received_at DATETIME NULL AFTER finished_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND COLUMN_NAME = 'callback_received_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operations_backup_jobs ADD COLUMN duration_seconds INT UNSIGNED NULL AFTER callback_received_at',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND COLUMN_NAME = 'duration_seconds'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operations_backup_jobs ADD COLUMN file_name VARCHAR(255) NULL AFTER duration_seconds',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND COLUMN_NAME = 'file_name'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operations_backup_jobs ADD COLUMN file_size_bytes BIGINT UNSIGNED NULL AFTER file_name',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND COLUMN_NAME = 'file_size_bytes'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operations_backup_jobs ADD COLUMN verified TINYINT(1) NOT NULL DEFAULT 0 AFTER file_size_bytes',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND COLUMN_NAME = 'verified'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operations_backup_jobs ADD COLUMN result_payload_json LONGTEXT NULL AFTER verified',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND COLUMN_NAME = 'result_payload_json'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backups: vínculo explícito com rotina e job para impedir callback duplicado.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE system_backups ADD COLUMN routine_id BIGINT UNSIGNED NULL AFTER status',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_backups' AND COLUMN_NAME = 'routine_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE system_backups ADD COLUMN backup_job_id BIGINT UNSIGNED NULL AFTER routine_id',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_backups' AND COLUMN_NAME = 'backup_job_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE system_backups ADD COLUMN execution_uuid CHAR(36) NULL AFTER backup_job_id',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_backups' AND COLUMN_NAME = 'execution_uuid'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Preenche UUIDs nos jobs legados antes do índice único.
UPDATE operations_backup_jobs
SET execution_uuid = UUID()
WHERE execution_uuid IS NULL OR execution_uuid = '';

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE UNIQUE INDEX uq_backup_jobs_execution_uuid ON operations_backup_jobs (execution_uuid)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND INDEX_NAME = 'uq_backup_jobs_execution_uuid'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE UNIQUE INDEX uq_system_backups_job ON system_backups (backup_job_id)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'system_backups' AND INDEX_NAME = 'uq_system_backups_job'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_backup_jobs_active_routine ON operations_backup_jobs (routine_id, status, requested_at)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND INDEX_NAME = 'idx_backup_jobs_active_routine'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Jobs antigos que aparentavam estar executando mesmo já encerrados não são sucesso.
UPDATE operations_backup_jobs
SET status = 'timeout',
    finished_at = COALESCE(finished_at, NOW()),
    error_message = 'Execução antiga encerrada sem callback de resultado confirmado.'
WHERE status IN ('requested', 'running')
  AND (
      finished_at IS NOT NULL
      OR COALESCE(started_at, requested_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
  );

-- Garante no banco que uma rotina tenha no máximo um job solicitado/em execução.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE operations_backup_jobs ADD COLUMN active_slot TINYINT GENERATED ALWAYS AS (CASE WHEN status IN (''requested'',''running'') THEN 1 ELSE NULL END) STORED',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND COLUMN_NAME = 'active_slot'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE UNIQUE INDEX uq_backup_jobs_one_active ON operations_backup_jobs (routine_id, active_slot)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'operations_backup_jobs' AND INDEX_NAME = 'uq_backup_jobs_one_active'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Arquiva somente rotinas inativas duplicadas quando existe uma versão ativa mais recente com o mesmo nome.
UPDATE operations_backup_routines old_routine
INNER JOIN operations_backup_routines active_routine
    ON active_routine.name = old_routine.name
   AND active_routine.status = 'active'
   AND active_routine.id > old_routine.id
SET old_routine.archived_at = COALESCE(old_routine.archived_at, NOW())
WHERE old_routine.status = 'inactive';

-- O último sucesso passa a vir exclusivamente de um backup real vinculado a job concluído.
UPDATE operations_backup_routines routine
LEFT JOIN (
    SELECT job.routine_id, MAX(COALESCE(backup.finished_at, backup.created_at)) AS real_last_success
    FROM operations_backup_jobs job
    INNER JOIN system_backups backup ON backup.id = job.backup_id
    WHERE job.status = 'success'
      AND backup.status = 'success'
      AND backup.size_bytes >= 1024
      AND backup.verified_at IS NOT NULL
    GROUP BY job.routine_id
) success_data ON success_data.routine_id = routine.id
SET routine.last_success_at = success_data.real_last_success;

-- Registra a atualização no histórico operacional.
INSERT INTO system_incidents (event, severity, message, context_json, created_by)
SELECT
    'operations.backup_reliability_enabled',
    'info',
    'RS Connect v36.3.0: rotina de backup confiável e callback idempotente habilitados.',
    JSON_OBJECT('migration', '047_backup_automation_reliability.sql'),
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM system_incidents WHERE event = 'operations.backup_reliability_enabled' LIMIT 1
);
