-- RS Connect 36.11.1 — Hardening de sessão, CSRF, login e webhooks
-- Compatível com MySQL 8/9 e MariaDB modernos.
-- Pode ser executada mais de uma vez.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `security_rate_limits` (
  `bucket_key` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `window_started_at` datetime NOT NULL,
  `hits` int unsigned NOT NULL DEFAULT 0,
  `blocked_until` datetime NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_security_rate_limits_scope` (`scope`),
  KEY `idx_security_rate_limits_blocked` (`blocked_until`),
  KEY `idx_security_rate_limits_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limpeza segura de buckets antigos. O rate limit usa janelas curtas e não
-- precisa manter histórico indefinidamente.
DELETE FROM `security_rate_limits`
WHERE `updated_at` < (UTC_TIMESTAMP() - INTERVAL 7 DAY);

INSERT INTO `security_events` (`event`, `severity`, `context_json`, `ip_address`)
SELECT
  'security.v36_11_1_applied',
  'info',
  JSON_OBJECT('migration', '072_security_session_webhook_hardening'),
  NULL
WHERE EXISTS (
  SELECT 1
  FROM information_schema.tables
  WHERE table_schema = DATABASE()
    AND table_name = 'security_events'
)
AND NOT EXISTS (
  SELECT 1
  FROM `security_events`
  WHERE `event` = 'security.v36_11_1_applied'
);
