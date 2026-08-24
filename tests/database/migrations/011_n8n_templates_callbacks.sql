-- ============================================================
-- RS CONNECT - ZIP 10
-- Templates n8n por segmento e callback de execução
-- ============================================================

CREATE TABLE IF NOT EXISTS `n8n_flow_callback_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `flow_id` bigint unsigned DEFAULT NULL,
  `event` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('success','error','info') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `external_id` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` varchar(700) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_n8n_callback_tenant_date` (`tenant_id`,`created_at`),
  KEY `idx_n8n_callback_flow_date` (`flow_id`,`created_at`),
  KEY `idx_n8n_callback_event` (`event`),
  CONSTRAINT `fk_n8n_callback_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_n8n_callback_flow` FOREIGN KEY (`flow_id`) REFERENCES `n8n_tenant_flows` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `n8n_flow_logs`
  ADD COLUMN IF NOT EXISTS `callback_expected` tinyint(1) NOT NULL DEFAULT 0 AFTER `payload_json`;

ALTER TABLE `n8n_tenant_flows`
  ADD COLUMN IF NOT EXISTS `template_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `flow_key`;
