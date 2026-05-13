-- Phase 3: Content Gap Analysis
--
-- competitor_pages: each competitor's individual pages with the topic/keywords
--                   Claude extracted from each page.
-- content_gaps:     topics that competitors cover but our site doesn't yet.
--                   Surfaced in the Content Planner as suggested topics.
-- gap_runs:         tracks each background gap-analysis job for status polling.

CREATE TABLE IF NOT EXISTS `competitor_pages` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `competitor_id` INT UNSIGNED    NOT NULL,
    `url`           VARCHAR(2048)   NOT NULL,
    `title`         VARCHAR(500)    DEFAULT NULL,
    `topic`         VARCHAR(255)    DEFAULT NULL COMMENT 'Claude-extracted primary topic',
    `keywords`      JSON            DEFAULT NULL COMMENT 'Secondary keywords extracted',
    `word_count`    INT UNSIGNED    DEFAULT NULL,
    `scraped_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_comp_url` (`competitor_id`, `url`(191)),
    KEY `idx_cp_topic` (`competitor_id`, `topic`(100)),
    CONSTRAINT `fk_cp_comp` FOREIGN KEY (`competitor_id`) REFERENCES `competitors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `content_gaps` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`          INT UNSIGNED    NOT NULL,
    `topic`            VARCHAR(255)    NOT NULL,
    `competitor_count` TINYINT UNSIGNED DEFAULT 0  COMMENT 'How many competitors cover this topic',
    `competitor_ids`   JSON            DEFAULT NULL,
    `sample_titles`    JSON            DEFAULT NULL COMMENT 'Example competitor page titles',
    `estimated_demand` INT UNSIGNED    DEFAULT NULL COMMENT 'Sum of related GSC impressions if any',
    `status`           ENUM('open','planned','published','ignored') NOT NULL DEFAULT 'open',
    `detected_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_gap` (`site_id`, `topic`(191)),
    KEY `idx_gap_status` (`site_id`, `status`),
    CONSTRAINT `fk_gap_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gap_runs` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `status`          ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    `progress`        TINYINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '0-100',
    `current_step`    VARCHAR(255)    DEFAULT NULL,
    `competitors_scanned` INT UNSIGNED NOT NULL DEFAULT 0,
    `pages_scanned`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `gaps_found`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `error`           TEXT            DEFAULT NULL,
    `started_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at`     DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_gr_site` (`site_id`, `started_at`),
    CONSTRAINT `fk_gr_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
