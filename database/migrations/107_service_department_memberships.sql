-- RS Connect 36.30.0 — vínculo entre equipe e setores de atendimento
-- Idempotente: pode ser executada pelo bin/migrate.php junto ao manifesto canônico.

CREATE TABLE IF NOT EXISTS service_department_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_department_member (department_id, user_id),
    KEY idx_service_department_members_tenant_user (tenant_id, user_id),
    KEY idx_service_department_members_tenant_department (tenant_id, department_id),
    CONSTRAINT fk_service_department_members_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_department_members_department FOREIGN KEY (department_id) REFERENCES service_departments(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_department_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Garante que exista no máximo um setor principal por usuário dentro da empresa.
-- A UI atual não exige setor principal, mas a coluna já prepara distribuição automática futura.
UPDATE service_department_members
SET is_primary = 0
WHERE is_primary NOT IN (0, 1);
