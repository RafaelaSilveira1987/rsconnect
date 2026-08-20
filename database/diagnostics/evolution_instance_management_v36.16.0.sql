-- RS Connect 36.16.0 — diagnóstico do gerenciamento nativo da Evolution API
-- Execute no banco do RS Connect após a migration 076.

SELECT
    COUNT(*) AS colunas_encontradas,
    CASE WHEN COUNT(*) = 20 THEN 'OK' ELSE 'PENDENTE' END AS situacao
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'evolution_instances'
  AND COLUMN_NAME IN (
      'management_mode','integration','remote_instance_id','remote_hash_encrypted',
      'webhook_enabled','webhook_events','receive_messages','ignore_groups',
      'ignore_status','ignore_broadcast','ignore_newsletters','ignore_from_me',
      'reject_calls','reject_call_message','always_online','read_messages',
      'read_status','sync_full_history','remote_created_at','last_settings_sync_at'
  );

SELECT
    management_mode,
    COUNT(*) AS total,
    SUM(webhook_enabled = 1) AS webhook_ativo,
    SUM(ignore_groups = 1) AS ignorando_grupos,
    SUM(reject_calls = 1) AS rejeitando_chamadas,
    SUM(last_settings_sync_at IS NOT NULL) AS sincronizadas
FROM evolution_instances
GROUP BY management_mode
ORDER BY management_mode;

SELECT
    id,
    tenant_id,
    name,
    instance_name,
    management_mode,
    integration,
    status,
    connection_state,
    webhook_enabled,
    JSON_LENGTH(COALESCE(webhook_events, JSON_ARRAY())) AS quantidade_eventos,
    receive_messages,
    ignore_groups,
    ignore_status,
    ignore_broadcast,
    ignore_newsletters,
    ignore_from_me,
    reject_calls,
    always_online,
    read_messages,
    read_status,
    sync_full_history,
    remote_created_at,
    last_settings_sync_at
FROM evolution_instances
ORDER BY tenant_id, id;

SELECT
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'evolution_instances'
  AND INDEX_NAME = 'idx_instances_management'
GROUP BY INDEX_NAME;
