-- Historical URL inventory from the Internet Archive's Wayback CDX API.
-- Captures every URL Google / Bing / other crawlers ever recorded against a
-- domain, even if the page is now dead. This is the foundation for the 301
-- redirect map builder (next module) — every row here that returns 404 today
-- is a candidate for a redirect to a living target.
--
-- No auth, no cost — CDX API is free and unlimited (we self-rate-limit to be
-- polite). Each domain typically returns 100s to 10K+ URLs depending on age.

CREATE TABLE IF NOT EXISTS `historical_urls` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`               INT UNSIGNED    NOT NULL,
    `url`                   VARCHAR(2048)   NOT NULL,
    `url_hash`              CHAR(40)        NOT NULL  COMMENT 'sha1 of normalised url for unique-key',
    `path`                  VARCHAR(1024)   DEFAULT NULL COMMENT 'just the path portion for grouping',
    `first_seen`            DATETIME        DEFAULT NULL COMMENT 'earliest Wayback timestamp',
    `last_seen`             DATETIME        DEFAULT NULL COMMENT 'most recent Wayback timestamp',
    `snapshot_count`        INT UNSIGNED    NOT NULL DEFAULT 0,
    `current_status_code`   SMALLINT UNSIGNED DEFAULT NULL COMMENT 'live check: 200 = still works, 404 = dead, etc.',
    `current_checked_at`    DATETIME        DEFAULT NULL,
    `has_backlinks`         TINYINT(1)      DEFAULT NULL COMMENT 'will be populated when we wire ahrefs/serp data',
    `is_dead`               TINYINT(1) GENERATED ALWAYS AS (
        `current_status_code` IS NOT NULL AND `current_status_code` >= 400
    ) STORED COMMENT 'derived: true when last live check returned an error',
    `notes`                 TEXT            DEFAULT NULL,
    `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_url` (`site_id`, `url_hash`),
    KEY `idx_site_dead` (`site_id`, `is_dead`),
    KEY `idx_site_path` (`site_id`, `path`(191)),
    CONSTRAINT `fk_hu_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-site harvest run state — when we last pulled, how many URLs, any error.
-- Lets the dashboard show "Last archive pull: 3h ago · 8,243 URLs known".
CREATE TABLE IF NOT EXISTS `wayback_runs` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`           INT UNSIGNED    NOT NULL,
    `started_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at`       DATETIME        DEFAULT NULL,
    `status`            ENUM('running','done','failed') NOT NULL DEFAULT 'running',
    `urls_fetched`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `urls_new`          INT UNSIGNED    NOT NULL DEFAULT 0,
    `pages_paginated`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `error`             TEXT            DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_site_started` (`site_id`, `started_at` DESC),
    CONSTRAINT `fk_wbr_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
