-- RS Connect 36.6.13 — Canais WhatsApp e roteamento de agentes
-- Permite muitos agentes por canal e muitos canais por agente, preservando compatibilidade
-- com ai_agents.instance_id enquanto a base migra para o novo modelo.

SET @database_name = DATABASE();

CREATE TABLE IF NOT EXISTS ai_agent_instance_bindings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    instance_id BIGINT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    routing_keywords VARCHAR(1000) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_agent_instance_binding_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_agent_instance_binding_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_agent_instance_binding_instance FOREIGN KEY (instance_id) REFERENCES evolution_instances(id) ON DELETE CASCADE,
    UNIQUE KEY uq_agent_instance_binding (agent_id, instance_id),
    KEY idx_agent_instance_route (tenant_id, instance_id, status, is_primary, priority),
    KEY idx_agent_instance_agent (tenant_id, agent_id, status)
) ENGINE=InnoDB;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE conversations ADD COLUMN ai_agent_id BIGINT UNSIGNED NULL AFTER assigned_user_id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'conversations'
      AND COLUMN_NAME = 'ai_agent_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'conversations'
      AND INDEX_NAME = 'idx_conversations_ai_agent'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE conversations ADD KEY idx_conversations_ai_agent (tenant_id, ai_agent_id, status)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @database_name
      AND TABLE_NAME = 'conversations'
      AND CONSTRAINT_NAME = 'fk_conversations_ai_agent'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE conversations ADD CONSTRAINT fk_conversations_ai_agent FOREIGN KEY (ai_agent_id) REFERENCES ai_agents(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Converte o vínculo legado 1 agente -> 1 instância para a tabela N:N.
INSERT INTO ai_agent_instance_bindings
    (tenant_id, agent_id, instance_id, is_primary, priority, routing_keywords, status)
SELECT
    a.tenant_id,
    a.id,
    a.instance_id,
    CASE
        WHEN a.id = (
            SELECT aa.id
            FROM ai_agents aa
            WHERE aa.tenant_id = a.tenant_id
              AND aa.instance_id = a.instance_id
              AND aa.status = 'active'
            ORDER BY aa.is_default DESC, aa.id ASC
            LIMIT 1
        ) THEN 1 ELSE 0
    END AS is_primary,
    CASE WHEN a.is_default = 1 THEN 200 ELSE 100 END AS priority,
    NULL,
    'active'
FROM ai_agents a
WHERE a.instance_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    priority = GREATEST(ai_agent_instance_bindings.priority, VALUES(priority));

-- Se uma empresa possuía um assistente padrão sem instance_id, usa-o nos canais ainda sem vínculo.
INSERT INTO ai_agent_instance_bindings
    (tenant_id, agent_id, instance_id, is_primary, priority, routing_keywords, status)
SELECT
    i.tenant_id,
    a.id,
    i.id,
    1,
    150,
    NULL,
    'active'
FROM evolution_instances i
INNER JOIN ai_agents a
    ON a.tenant_id = i.tenant_id
   AND a.is_default = 1
   AND a.status = 'active'
   AND a.instance_id IS NULL
WHERE NOT EXISTS (
    SELECT 1 FROM ai_agent_instance_bindings b
    WHERE b.instance_id = i.id AND b.status = 'active'
)
ON DUPLICATE KEY UPDATE status = 'active';

-- Garante somente um principal por canal: mantém o de maior prioridade/menor id.
UPDATE ai_agent_instance_bindings b
JOIN (
    SELECT instance_id,
           CAST(SUBSTRING_INDEX(
               GROUP_CONCAT(id ORDER BY is_primary DESC, priority DESC, id ASC), ',', 1
           ) AS UNSIGNED) AS keep_id
    FROM ai_agent_instance_bindings
    WHERE status = 'active'
    GROUP BY instance_id
) chosen ON chosen.instance_id = b.instance_id
SET b.is_primary = CASE WHEN b.id = chosen.keep_id THEN 1 ELSE 0 END
WHERE b.status = 'active';

-- Preserva continuidade nas conversas existentes escolhendo o agente principal atual do canal.
UPDATE conversations c
SET c.ai_agent_id = (
    SELECT b.agent_id
    FROM ai_agent_instance_bindings b
    INNER JOIN ai_agents a ON a.id = b.agent_id
    WHERE b.tenant_id = c.tenant_id
      AND b.instance_id = c.evolution_instance_id
      AND b.status = 'active'
      AND a.status = 'active'
    ORDER BY b.is_primary DESC, b.priority DESC, b.id ASC
    LIMIT 1
)
WHERE c.ai_agent_id IS NULL
  AND EXISTS (
      SELECT 1 FROM ai_agent_instance_bindings bx
      WHERE bx.tenant_id = c.tenant_id
        AND bx.instance_id = c.evolution_instance_id
        AND bx.status = 'active'
  );
