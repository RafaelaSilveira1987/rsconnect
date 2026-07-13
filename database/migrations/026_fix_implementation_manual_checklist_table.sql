-- Hotfix 22.3 — Corrige tabela dos itens manuais da implantação.
-- Execute no Adminer se o POST /implementation/item continuar retornando 500.
-- Motivo: algumas instalações já tinham tenant_implementation_checklists com outro formato
-- e faltava a tabela singular tenant_implementation_checklist usada pelos itens manuais.

CREATE TABLE IF NOT EXISTS tenant_implementation_checklist (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    item_key VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    label VARCHAR(160) COLLATE utf8mb4_unicode_ci NOT NULL,
    category VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
    manual_status ENUM('auto','pending','complete','skipped','attention') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
    notes TEXT COLLATE utf8mb4_unicode_ci NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_impl_checklist_tenant_item (tenant_id, item_key),
    KEY idx_impl_checklist_tenant_status (tenant_id, manual_status),
    KEY idx_impl_checklist_item_key (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
