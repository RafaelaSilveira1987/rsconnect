-- RS Connect v36.20.16 — ENT-027 / PA-005
-- Registro canônico das migrations aplicadas.
-- A tabela é aditiva e não altera dados comerciais.

CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sequence_no INT UNSIGNED NOT NULL,
    migration VARCHAR(190) NOT NULL,
    checksum CHAR(64) NOT NULL,
    batch INT UNSIGNED NOT NULL DEFAULT 0,
    source ENUM('runner','baseline','install','bootstrap') NOT NULL DEFAULT 'runner',
    execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
    applied_by VARCHAR(190) NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_migration (migration),
    UNIQUE KEY uq_schema_migrations_sequence (sequence_no),
    KEY idx_schema_migrations_batch (batch, sequence_no),
    KEY idx_schema_migrations_applied_at (applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
