-- RS Connect 36.27.0 — round-robin transacional por canal para agentes de IA.
-- Mantém prioridade de pin por conversa e keywords/especialistas; somente conversas
-- genéricas consomem o cursor. A linha por canal é usada com SELECT ... FOR UPDATE.

CREATE TABLE IF NOT EXISTS ai_agent_routing_state (
    tenant_id BIGINT UNSIGNED NOT NULL,
    instance_id BIGINT UNSIGNED NOT NULL,
    last_agent_id BIGINT UNSIGNED NULL,
    last_conversation_id BIGINT UNSIGNED NULL,
    assignment_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, instance_id),
    KEY idx_ai_agent_routing_last_agent (last_agent_id),
    CONSTRAINT fk_ai_agent_routing_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_agent_routing_instance
        FOREIGN KEY (instance_id) REFERENCES evolution_instances(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_agent_routing_last_agent
        FOREIGN KEY (last_agent_id) REFERENCES ai_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB;
