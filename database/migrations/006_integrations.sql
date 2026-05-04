-- Integrations table for OAuth connections (Google, Social Media)
CREATE TABLE IF NOT EXISTS `integrations` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id`          INT UNSIGNED NOT NULL,
    `platform`         VARCHAR(50) NOT NULL COMMENT 'google_search_console, instagram, linkedin, twitter, facebook',
    `access_token`     TEXT DEFAULT NULL,
    `refresh_token`    TEXT DEFAULT NULL,
    `token_expires_at` DATETIME DEFAULT NULL,
    `account_id`       VARCHAR(255) DEFAULT NULL,
    `account_name`     VARCHAR(255) DEFAULT NULL,
    `extra_data`       JSON DEFAULT NULL,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `connected_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_integration` (`site_id`, `platform`),
    CONSTRAINT `fk_integration_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
