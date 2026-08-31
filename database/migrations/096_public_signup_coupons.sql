-- RS Connect 36.26.0 — cupons promocionais na inscrição pública

CREATE TABLE IF NOT EXISTS public_signup_coupons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    discount_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    discount_value DECIMAL(12,2) NOT NULL,
    duration ENUM('first_charge','recurring') NOT NULL DEFAULT 'first_charge',
    payment_method ENUM('all','credit_card','pix') NOT NULL DEFAULT 'all',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    max_redemptions INT UNSIGNED NULL,
    max_redemptions_per_email SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    minimum_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_public_signup_coupon_code (code),
    KEY idx_public_signup_coupon_active_period (active, starts_at, ends_at),
    CONSTRAINT fk_public_signup_coupon_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_signup_coupon_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_original_amount := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND column_name = 'original_amount'
);
SET @sql_original_amount := IF(
    @has_original_amount = 0,
    'ALTER TABLE public_signup_sessions ADD COLUMN original_amount DECIMAL(12,2) NULL AFTER amount',
    'DO 0'
);
PREPARE stmt_original_amount FROM @sql_original_amount;
EXECUTE stmt_original_amount;
DEALLOCATE PREPARE stmt_original_amount;

SET @has_coupon_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND column_name = 'coupon_id'
);
SET @sql_coupon_id := IF(
    @has_coupon_id = 0,
    'ALTER TABLE public_signup_sessions ADD COLUMN coupon_id BIGINT UNSIGNED NULL AFTER original_amount',
    'DO 0'
);
PREPARE stmt_coupon_id FROM @sql_coupon_id;
EXECUTE stmt_coupon_id;
DEALLOCATE PREPARE stmt_coupon_id;

SET @has_coupon_code := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND column_name = 'coupon_code'
);
SET @sql_coupon_code := IF(
    @has_coupon_code = 0,
    'ALTER TABLE public_signup_sessions ADD COLUMN coupon_code VARCHAR(50) NULL AFTER coupon_id',
    'DO 0'
);
PREPARE stmt_coupon_code FROM @sql_coupon_code;
EXECUTE stmt_coupon_code;
DEALLOCATE PREPARE stmt_coupon_code;

SET @has_discount_amount := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND column_name = 'discount_amount'
);
SET @sql_discount_amount := IF(
    @has_discount_amount = 0,
    'ALTER TABLE public_signup_sessions ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER coupon_code',
    'DO 0'
);
PREPARE stmt_discount_amount FROM @sql_discount_amount;
EXECUTE stmt_discount_amount;
DEALLOCATE PREPARE stmt_discount_amount;

SET @has_discount_scope := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND column_name = 'discount_scope'
);
SET @sql_discount_scope := IF(
    @has_discount_scope = 0,
    'ALTER TABLE public_signup_sessions ADD COLUMN discount_scope VARCHAR(20) NULL AFTER discount_amount',
    'DO 0'
);
PREPARE stmt_discount_scope FROM @sql_discount_scope;
EXECUTE stmt_discount_scope;
DEALLOCATE PREPARE stmt_discount_scope;

SET @has_coupon_redeemed_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND column_name = 'coupon_redeemed_at'
);
SET @sql_coupon_redeemed_at := IF(
    @has_coupon_redeemed_at = 0,
    'ALTER TABLE public_signup_sessions ADD COLUMN coupon_redeemed_at DATETIME NULL AFTER checkout_completed_at',
    'DO 0'
);
PREPARE stmt_coupon_redeemed_at FROM @sql_coupon_redeemed_at;
EXECUTE stmt_coupon_redeemed_at;
DEALLOCATE PREPARE stmt_coupon_redeemed_at;

SET @has_discount_restored_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND column_name = 'discount_restored_at'
);
SET @sql_discount_restored_at := IF(
    @has_discount_restored_at = 0,
    'ALTER TABLE public_signup_sessions ADD COLUMN discount_restored_at DATETIME NULL AFTER coupon_redeemed_at',
    'DO 0'
);
PREPARE stmt_discount_restored_at FROM @sql_discount_restored_at;
EXECUTE stmt_discount_restored_at;
DEALLOCATE PREPARE stmt_discount_restored_at;

SET @has_coupon_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'public_signup_sessions' AND index_name = 'idx_public_signup_coupon'
);
SET @sql_coupon_index := IF(
    @has_coupon_index = 0,
    'ALTER TABLE public_signup_sessions ADD KEY idx_public_signup_coupon (coupon_id, status, created_at)',
    'DO 0'
);
PREPARE stmt_coupon_index FROM @sql_coupon_index;
EXECUTE stmt_coupon_index;
DEALLOCATE PREPARE stmt_coupon_index;

SET @has_coupon_fk := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'public_signup_sessions'
      AND constraint_name = 'fk_public_signup_session_coupon' AND constraint_type = 'FOREIGN KEY'
);
SET @sql_coupon_fk := IF(
    @has_coupon_fk = 0,
    'ALTER TABLE public_signup_sessions ADD CONSTRAINT fk_public_signup_session_coupon FOREIGN KEY (coupon_id) REFERENCES public_signup_coupons(id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt_coupon_fk FROM @sql_coupon_fk;
EXECUTE stmt_coupon_fk;
DEALLOCATE PREPARE stmt_coupon_fk;

UPDATE public_signup_sessions
SET original_amount = amount
WHERE original_amount IS NULL OR original_amount <= 0;
