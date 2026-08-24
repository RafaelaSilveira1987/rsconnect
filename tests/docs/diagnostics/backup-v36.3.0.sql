-- Diagnóstico da rotina de backup RS Connect v36.3.0

SELECT
    id,
    name,
    status,
    frequency,
    preferred_time,
    timezone,
    storage_path,
    retention_days,
    last_requested_at,
    last_success_at,
    last_error_at,
    last_error,
    archived_at
FROM operations_backup_routines
ORDER BY id DESC;

SELECT
    id,
    routine_id,
    execution_uuid,
    status,
    trigger_type,
    requested_at,
    started_at,
    acknowledged_at,
    finished_at,
    callback_received_at,
    duration_seconds,
    backup_id,
    file_name,
    file_size_bytes,
    verified,
    error_message
FROM operations_backup_jobs
ORDER BY id DESC
LIMIT 30;

SELECT
    id,
    routine_id,
    backup_job_id,
    execution_uuid,
    backup_type,
    storage_type,
    status,
    file_name,
    location,
    size_bytes,
    checksum,
    verified_at,
    started_at,
    finished_at,
    created_at
FROM system_backups
ORDER BY id DESC
LIMIT 30;

-- Inconsistências que devem retornar zero linhas.
SELECT *
FROM operations_backup_jobs
WHERE status IN ('requested', 'running')
  AND finished_at IS NOT NULL;

SELECT *
FROM operations_backup_jobs
WHERE status = 'success'
  AND (backup_id IS NULL OR verified = 0 OR file_size_bytes < 1024);

SELECT backup_job_id, COUNT(*) AS total
FROM system_backups
WHERE backup_job_id IS NOT NULL
GROUP BY backup_job_id
HAVING COUNT(*) > 1;
