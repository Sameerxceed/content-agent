-- AEO (Answer Engine Optimization) tracking.
-- For each tracked query, we ask AI search engines (Perplexity initially, Claude/GPT later)
-- and record which sources they cite. Tracks whether the site's own domain appears in
-- the citations and at what position, plus competitor citation share.

CREATE TABLE IF NOT EXISTS `aeo_queries` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `query_text`      VARCHAR(500)    NOT NULL,
    `category`        VARCHAR(50)     DEFAULT NULL COMMENT 'brand / industry / how-to / comparison / location',
    `status`          ENUM('active','paused') NOT NULL DEFAULT 'active',
    `source`          ENUM('manual','suggested','imported') NOT NULL DEFAULT 'manual',
    `last_checked_at` DATETIME        DEFAULT NULL,
    `last_cited`      TINYINT(1)      DEFAULT NULL COMMENT 'was the site cited on the most recent check?',
    `last_position`   SMALLINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_query` (`site_id`, `query_text`),
    KEY `idx_site_status` (`site_id`, `status`),
    KEY `idx_last_checked` (`last_checked_at`),
    CONSTRAINT `fk_aeo_query_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aeo_results` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `query_id`        INT UNSIGNED    NOT NULL,
    `engine`          VARCHAR(30)     NOT NULL  COMMENT 'perplexity / claude_web / gpt_search',
    `snapshot_date`   DATE            NOT NULL,
    `response_text`   TEXT            DEFAULT NULL,
    `citations`       JSON            DEFAULT NULL COMMENT '[{url, domain, title?, position}]',
    `our_cited`       TINYINT(1)      NOT NULL DEFAULT 0,
    `our_position`    SMALLINT UNSIGNED DEFAULT NULL,
    `competitor_domains` JSON         DEFAULT NULL,
    `error`           TEXT            DEFAULT NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_query_engine_day` (`query_id`, `engine`, `snapshot_date`),
    KEY `idx_query` (`query_id`),
    KEY `idx_snapshot_date` (`snapshot_date`),
    CONSTRAINT `fk_aeo_result_query` FOREIGN KEY (`query_id`) REFERENCES `aeo_queries`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
