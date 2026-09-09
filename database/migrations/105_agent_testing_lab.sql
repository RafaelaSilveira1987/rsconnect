USE rs_connect;

-- RS Connect 36.29.0 — Laboratório de testes de assistentes e regressão conversacional.

CREATE TABLE IF NOT EXISTS agent_test_scenarios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    description VARCHAR(500) NULL,
    mode ENUM('quick','real') NOT NULL DEFAULT 'quick',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    steps_json JSON NOT NULL,
    expectations_json JSON NULL,
    source_conversation_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_agent_test_scenarios_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_agent_test_scenarios_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE SET NULL,
    CONSTRAINT fk_agent_test_scenarios_conversation FOREIGN KEY (source_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
    CONSTRAINT fk_agent_test_scenarios_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_agent_test_scenario_slug (tenant_id, slug),
    INDEX idx_agent_test_scenarios_agent (tenant_id, agent_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agent_test_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    scenario_id BIGINT UNSIGNED NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    mode ENUM('quick','real') NOT NULL DEFAULT 'quick',
    status ENUM('running','passed','failed','error') NOT NULL DEFAULT 'running',
    passed_steps INT UNSIGNED NOT NULL DEFAULT 0,
    failed_steps INT UNSIGNED NOT NULL DEFAULT 0,
    provider VARCHAR(40) NULL,
    model VARCHAR(120) NULL,
    summary_json JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_agent_test_runs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_agent_test_runs_scenario FOREIGN KEY (scenario_id) REFERENCES agent_test_scenarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_agent_test_runs_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_agent_test_runs_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_agent_test_runs_lookup (tenant_id, agent_id, created_at),
    INDEX idx_agent_test_runs_scenario (scenario_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agent_test_run_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT UNSIGNED NOT NULL,
    step_order INT UNSIGNED NOT NULL,
    user_message TEXT NOT NULL,
    assistant_response TEXT NULL,
    result_status ENUM('passed','failed','error','info') NOT NULL DEFAULT 'info',
    decision_code VARCHAR(120) NULL,
    decision_label VARCHAR(190) NULL,
    triage_json JSON NULL,
    assertions_json JSON NULL,
    provider_usage_json JSON NULL,
    error_message VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_agent_test_run_steps_run FOREIGN KEY (run_id) REFERENCES agent_test_runs(id) ON DELETE CASCADE,
    INDEX idx_agent_test_run_steps_run (run_id, step_order)
) ENGINE=InnoDB;
