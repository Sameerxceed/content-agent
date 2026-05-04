-- Newsletter & subscriber tables
CREATE TABLE IF NOT EXISTS `subscribers` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `email`           VARCHAR(255)    NOT NULL,
    `name`            VARCHAR(100)    DEFAULT NULL,
    `status`          ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
    `token`           VARCHAR(64)     NOT NULL,
    `subscribed_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `unsubscribed_at` DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sub_site_email` (`site_id`, `email`),
    KEY `idx_sub_status` (`status`),
    CONSTRAINT `fk_sub_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `newsletters` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`     INT UNSIGNED    NOT NULL,
    `subject`     VARCHAR(500)    NOT NULL,
    `body`        LONGTEXT        NOT NULL,
    `status`      ENUM('draft','sent') NOT NULL DEFAULT 'draft',
    `sent_count`  INT UNSIGNED    DEFAULT 0,
    `sent_at`     DATETIME        DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nl_site` (`site_id`),
    CONSTRAINT `fk_nl_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
