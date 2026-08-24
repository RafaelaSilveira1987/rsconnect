USE rs_connect;

-- RS Connect 36.6.35 — Prompt Studio guiado e versionamento de prompts.

CREATE TABLE IF NOT EXISTS ai_prompt_studio_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    agent_id BIGINT UNSIGNED NULL,
    answers_json JSON NULL,
    generated_prompt MEDIUMTEXT NULL,
    validation_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prompt_studio_draft (tenant_id, user_id, agent_id),
    KEY idx_prompt_studio_tenant (tenant_id, updated_at),
    CONSTRAINT fk_prompt_studio_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_prompt_studio_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_prompt_studio_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_prompt_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    source ENUM('onboarding','prompt_studio','manual','restored','system') NOT NULL DEFAULT 'manual',
    title VARCHAR(160) NULL,
    prompt_text MEDIUMTEXT NOT NULL,
    answers_json JSON NULL,
    validation_json JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_prompt_version (agent_id, version_number),
    KEY idx_prompt_versions_tenant (tenant_id, created_at),
    KEY idx_prompt_versions_agent (agent_id, created_at),
    CONSTRAINT fk_prompt_version_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_prompt_version_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_prompt_version_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registra a configuração atual como versão inicial sem duplicar agentes já versionados.
INSERT INTO ai_agent_prompt_versions
    (tenant_id, agent_id, version_number, source, title, prompt_text, created_by, created_at)
SELECT a.tenant_id, a.id, 1, 'system', 'Prompt existente antes do Prompt Studio', a.system_prompt, NULL, NOW()
FROM ai_agents a
WHERE TRIM(COALESCE(a.system_prompt, '')) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM ai_agent_prompt_versions v WHERE v.agent_id = a.id
  );
