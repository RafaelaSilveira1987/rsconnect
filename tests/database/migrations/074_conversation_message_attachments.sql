USE rs_connect;

-- v36.13.0 — Áudios, imagens e documentos nas conversas
-- Idempotente. Executar após a migration 073.

CREATE TABLE IF NOT EXISTS conversation_message_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    evolution_message_id VARCHAR(190) NULL,
    direction ENUM('incoming','outgoing') NOT NULL,
    attachment_kind ENUM('image','audio','document','video','other') NOT NULL DEFAULT 'other',
    original_name VARCHAR(190) NOT NULL,
    stored_name VARCHAR(190) NULL,
    mime_type VARCHAR(120) NOT NULL,
    extension VARCHAR(20) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    storage_disk VARCHAR(40) NOT NULL DEFAULT 'local_private',
    storage_path VARCHAR(500) NULL,
    sha256 CHAR(64) NULL,
    status ENUM('pending','ready','failed','purged') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    metadata_json JSON NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    purged_at DATETIME NULL,
    CONSTRAINT fk_conversation_attachments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_attachments_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_attachments_message
        FOREIGN KEY (message_id) REFERENCES conversation_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_attachments_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_conversation_attachment_uuid (uuid),
    INDEX idx_conversation_attachments_message (message_id, status),
    INDEX idx_conversation_attachments_conversation (tenant_id, conversation_id, id),
    INDEX idx_conversation_attachments_retention (tenant_id, created_at, purged_at),
    INDEX idx_conversation_attachments_external (tenant_id, evolution_message_id)
) ENGINE=InnoDB;
