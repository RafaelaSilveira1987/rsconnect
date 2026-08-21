-- Diagnóstico RS Connect 36.17.2 — fotos dos contatos WhatsApp

SELECT
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'ERRO' END AS avatar_checked_at_column,
    COUNT(*) AS found
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'contacts'
  AND COLUMN_NAME = 'avatar_checked_at';

SELECT
    COUNT(*) AS total_contacts,
    SUM(CASE WHEN avatar_url IS NULL THEN 1 ELSE 0 END) AS awaiting_lookup,
    SUM(CASE WHEN avatar_url = '' THEN 1 ELSE 0 END) AS checked_without_photo,
    SUM(CASE WHEN avatar_url LIKE 'http%' THEN 1 ELSE 0 END) AS with_photo_url,
    SUM(CASE WHEN avatar_checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked_contacts
FROM contacts;
