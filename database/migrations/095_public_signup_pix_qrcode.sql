-- RS Connect 36.25.0 — Pix QR Code no cadastro público

SET @has_pix_enabled := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_settings' AND column_name = 'pix_enabled'
);
SET @sql_pix_enabled := IF(
    @has_pix_enabled = 0,
    'ALTER TABLE public_signup_settings ADD COLUMN pix_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled',
    'DO 0'
);
PREPARE stmt_pix_enabled FROM @sql_pix_enabled;
EXECUTE stmt_pix_enabled;
DEALLOCATE PREPARE stmt_pix_enabled;

SET @has_payment_method := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND column_name = 'payment_method'
);
SET @sql_payment_method := IF(
    @has_payment_method = 0,
    'ALTER TABLE public_signup_sessions ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT ''credit_card'' AFTER status',
    'DO 0'
);
PREPARE stmt_payment_method FROM @sql_payment_method;
EXECUTE stmt_payment_method;
DEALLOCATE PREPARE stmt_payment_method;

SET @has_bonus_days := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND column_name = 'bonus_days'
);
SET @sql_bonus_days := IF(
    @has_bonus_days = 0,
    'ALTER TABLE public_signup_sessions ADD COLUMN bonus_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_method',
    'DO 0'
);
PREPARE stmt_bonus_days FROM @sql_bonus_days;
EXECUTE stmt_bonus_days;
DEALLOCATE PREPARE stmt_bonus_days;

UPDATE public_signup_sessions
SET payment_method = 'credit_card'
WHERE payment_method IS NULL OR payment_method = '';
