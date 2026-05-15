-- One-time password-reset tokens. Self-service forgot-password flow inserts a
-- row, emails the link, and the reset page consumes it. Tokens expire after
-- 60 minutes and are single-use (used_at sets when consumed).

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `token`       VARCHAR(64) NOT NULL,
    `expires_at`  DATETIME NOT NULL,
    `used_at`     DATETIME DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token` (`token`),
    KEY `idx_user_expires` (`user_id`, `expires_at`),
    CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
