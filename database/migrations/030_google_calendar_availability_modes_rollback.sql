-- Rollback opcional do ZIP 28.
-- Atenção: remove apenas as três tabelas novas. Colunas adicionadas a tabelas existentes são mantidas para evitar perda acidental.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS calendar_google_sync_logs;
DROP TABLE IF EXISTS calendar_google_event_links;
DROP TABLE IF EXISTS smart_calendar_google_settings;
SET FOREIGN_KEY_CHECKS = 1;
