-- RS Connect v36.6.38 — diagnóstico do status Evolution
-- Este arquivo não exclui registros nem altera vínculos.

SET @instance_name = 'rafaela';

-- 1. Localize cadastros repetidos e identifique a empresa de cada um.
SELECT
    i.id,
    i.tenant_id,
    t.name AS tenant_name,
    i.name AS internal_name,
    i.instance_name,
    i.base_url,
    i.status,
    i.connection_state,
    i.connection_reason,
    i.last_status_check_at,
    i.connection_updated_at,
    i.last_webhook_at,
    (SELECT COUNT(*) FROM contacts c WHERE c.evolution_instance_id = i.id) AS contacts_count,
    (SELECT COUNT(*) FROM conversations c WHERE c.evolution_instance_id = i.id) AS conversations_count
FROM evolution_instances i
LEFT JOIN tenants t ON t.id = i.tenant_id
WHERE LOWER(i.instance_name) = LOWER(@instance_name)
ORDER BY i.id;

-- 2. Execute somente depois do deploy v36.6.38 para obrigar nova consulta imediata.
UPDATE evolution_instances
SET status = 'pending',
    connection_state = NULL,
    connection_reason = NULL,
    last_status_check_at = NULL
WHERE LOWER(instance_name) = LOWER(@instance_name);

-- 3. Após abrir a tela Conexões WhatsApp, consulte novamente.
SELECT
    id,
    tenant_id,
    instance_name,
    status,
    connection_state,
    connection_reason,
    last_status_check_at,
    connection_updated_at
FROM evolution_instances
WHERE LOWER(instance_name) = LOWER(@instance_name)
ORDER BY id;
