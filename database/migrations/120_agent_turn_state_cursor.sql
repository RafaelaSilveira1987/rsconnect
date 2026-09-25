-- RS Connect 36.36.26
-- Cursor persistente do turno de entrada. Garante que bursts de mensagens sejam
-- processados em ordem, uma única vez, sem reinterpretar respostas anteriores como
-- se fossem a próxima etapa da triagem.

ALTER TABLE conversation_triage_sessions
    ADD COLUMN last_processed_incoming_id BIGINT UNSIGNED NULL AFTER last_intent,
    ADD INDEX idx_conversation_triage_last_incoming (tenant_id, conversation_id, last_processed_incoming_id);
