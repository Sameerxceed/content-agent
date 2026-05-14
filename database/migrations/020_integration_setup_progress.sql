-- Tracks each user's progress through an integration setup wizard.
-- Lets them leave halfway, come back, pick up where they were.
-- Final values still go to config.php (global keys) or integrations table (per-site OAuth).
-- This table is the *journey*, not the destination.

CREATE TABLE IF NOT EXISTS `integration_setup_progress` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED    NOT NULL,
    `integration`      VARCHAR(50)     NOT NULL  COMMENT 'google_cse, reddit_oauth, resend, linkedin_oauth, twitter_oauth, gsc_oauth, claude',
    `current_step`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `status`           ENUM('in_progress','tested_ok','failed') NOT NULL DEFAULT 'in_progress',
    `state_json`       JSON            DEFAULT NULL COMMENT 'in-flight values being collected from steps',
    `last_test_result` JSON            DEFAULT NULL COMMENT 'what happened on the last test() call',
    `last_attempted_at` DATETIME       DEFAULT NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_integration` (`user_id`, `integration`),
    KEY `idx_status` (`status`, `last_attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
