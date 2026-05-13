-- Phase 1: Competitor Discovery
--
-- competitors: domains that show up alongside the site in Google search results
--              for the site's tracked keywords. Auto-detected via Google CSE,
--              or manually added by the user.
--
-- competitor_keyword_rankings: for each (competitor, keyword) pair, the position
--                              we observed on the SERP and the URL that ranked.

CREATE TABLE IF NOT EXISTS `competitors` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`          INT UNSIGNED    NOT NULL,
    `domain`           VARCHAR(255)    NOT NULL,
    `name`             VARCHAR(255)    DEFAULT NULL COMMENT 'Display name (AI-extracted or user-set)',
    `source`           ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    `status`           ENUM('active','ignored') NOT NULL DEFAULT 'active',
    `overlap_score`    TINYINT UNSIGNED DEFAULT 0 COMMENT '0-100: % of analyzed kw they appear on',
    `shared_keywords`  INT UNSIGNED    NOT NULL DEFAULT 0,
    `detected_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_analysed_at` DATETIME        DEFAULT NULL,
    `notes`            TEXT            DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_competitor` (`site_id`, `domain`),
    KEY `idx_competitor_status` (`site_id`, `status`),
    CONSTRAINT `fk_competitors_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `competitor_keyword_rankings` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `competitor_id` INT UNSIGNED    NOT NULL,
    `keyword_id`    INT UNSIGNED    NOT NULL,
    `position`      TINYINT UNSIGNED DEFAULT NULL COMMENT '1-10 from CSE top 10',
    `url`           VARCHAR(2048)   DEFAULT NULL COMMENT 'URL that ranked',
    `title`         VARCHAR(500)    DEFAULT NULL,
    `last_seen_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_comp_kw` (`competitor_id`, `keyword_id`),
    KEY `idx_ckr_keyword` (`keyword_id`),
    CONSTRAINT `fk_ckr_comp` FOREIGN KEY (`competitor_id`) REFERENCES `competitors` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ckr_kw` FOREIGN KEY (`keyword_id`) REFERENCES `keywords` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
