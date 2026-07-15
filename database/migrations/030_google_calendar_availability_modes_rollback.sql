-- Rollback opcional do ZIP 28.
-- Atenção: remove somente a tabela de logs. As colunas são mantidas para não apagar vínculos de agenda já usados.
DROP TABLE IF EXISTS calendar_google_sync_logs;
