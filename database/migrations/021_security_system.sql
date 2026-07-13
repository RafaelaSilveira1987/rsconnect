-- RS Connect ZIP 19 — Segurança do Sistema
-- Execute uma vez após aplicar o ZIP 19.

CREATE TABLE IF NOT EXISTS `security_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NULL,
  `user_id` int unsigned NULL,
  `event` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('info','warning','error','critical') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `context_json` json NULL,
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_security_events_tenant` (`tenant_id`),
  KEY `idx_security_events_user` (`user_id`),
  KEY `idx_security_events_event` (`event`),
  KEY `idx_security_events_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NULL,
  `user_id` int unsigned NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `reason` varchar(120) COLLATE utf8mb4_unicode_ci NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_email_ip` (`email`, `ip_address`),
  KEY `idx_login_attempts_success_created` (`success`, `created_at`),
  KEY `idx_login_attempts_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `session_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NULL,
  `expires_at` timestamp NULL,
  `revoked_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_sessions_session` (`session_id`),
  KEY `idx_user_sessions_user` (`user_id`),
  KEY `idx_user_sessions_last_seen` (`last_seen_at`),
  KEY `idx_user_sessions_revoked` (`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_webhook_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generic',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `last_used_at` timestamp NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_security_webhook_tokens_tenant` (`tenant_id`),
  KEY `idx_security_webhook_tokens_scope` (`scope`),
  KEY `idx_security_webhook_tokens_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, name, description, category)
VALUES
  ('security.view', 'Visualizar segurança', 'Acessar painel de segurança, auditoria e sessões.', 'Segurança'),
  ('security.manage', 'Gerenciar segurança', 'Revogar sessões e aplicar controles de segurança.', 'Segurança');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 0
FROM permissions p
WHERE p.permission_key IN ('security.view', 'security.manage')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
  );

INSERT INTO security_events (event, severity, context_json, ip_address)
VALUES ('security.zip19_applied', 'info', JSON_OBJECT('migration', '021_security_system'), NULL);
