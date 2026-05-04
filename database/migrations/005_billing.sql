-- Billing / Stripe integration

CREATE TABLE IF NOT EXISTS `billing` (
    `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`                 INT UNSIGNED NOT NULL,
    `stripe_customer_id`      VARCHAR(255) DEFAULT NULL,
    `stripe_subscription_id`  VARCHAR(255) DEFAULT NULL,
    `plan`                    ENUM('free','starter','growth','agency') NOT NULL DEFAULT 'free',
    `status`                  ENUM('active','canceled','past_due','trialing') NOT NULL DEFAULT 'trialing',
    `trial_ends_at`           DATETIME DEFAULT NULL,
    `current_period_end`      DATETIME DEFAULT NULL,
    `sites_limit`             INT UNSIGNED NOT NULL DEFAULT 1,
    `posts_limit`             INT UNSIGNED NOT NULL DEFAULT 8,
    `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_billing_user` (`user_id`),
    KEY `idx_billing_stripe` (`stripe_customer_id`),
    CONSTRAINT `fk_billing_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billing_events` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `event_type`      VARCHAR(100) NOT NULL,
    `stripe_event_id` VARCHAR(255) DEFAULT NULL,
    `amount_cents`    INT DEFAULT NULL,
    `currency`        VARCHAR(3) DEFAULT 'usd',
    `details`         JSON DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_be_user` (`user_id`),
    CONSTRAINT `fk_be_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
