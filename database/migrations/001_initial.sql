-- ContentAgent: Initial Schema
-- MySQL 8+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- Users / Auth
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `email`         VARCHAR(255)    NOT NULL,
    `password_hash` VARCHAR(255)    NOT NULL,
    `name`          VARCHAR(100)    NOT NULL,
    `plan`          ENUM('starter','growth','agency') NOT NULL DEFAULT 'starter',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Customer Websites
-- ============================================================
CREATE TABLE IF NOT EXISTS `sites` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `name`          VARCHAR(255)    NOT NULL,
    `domain`        VARCHAR(255)    NOT NULL,
    `platform`      VARCHAR(50)     DEFAULT NULL COMMENT 'wordpress, shopify, opencart, custom',
    `brand_colors`  JSON            DEFAULT NULL,
    `brand_fonts`   JSON            DEFAULT NULL,
    `brand_tone`    TEXT            DEFAULT NULL,
    `topics`        JSON            DEFAULT NULL,
    `keywords`      JSON            DEFAULT NULL,
    `agent_mode`    ENUM('auto','manual') NOT NULL DEFAULT 'manual',
    `blog_path`     VARCHAR(100)    NOT NULL DEFAULT '/blog',
    `rss_feeds`     JSON            DEFAULT NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `scanned_at`    DATETIME        DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sites_user` (`user_id`),
    KEY `idx_sites_domain` (`domain`),
    CONSTRAINT `fk_sites_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Blog Posts + News
-- ============================================================
CREATE TABLE IF NOT EXISTS `posts` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`           INT UNSIGNED    NOT NULL,
    `title`             VARCHAR(500)    NOT NULL,
    `slug`              VARCHAR(500)    NOT NULL,
    `body`              LONGTEXT        NOT NULL,
    `excerpt`           TEXT            DEFAULT NULL,
    `seo_title`         VARCHAR(70)     DEFAULT NULL,
    `seo_description`   VARCHAR(170)    DEFAULT NULL,
    `seo_keywords`      VARCHAR(500)    DEFAULT NULL,
    `type`              ENUM('blog','news') NOT NULL DEFAULT 'blog',
    `tags`              JSON            DEFAULT NULL,
    `status`            ENUM('draft','approved','published','rejected') NOT NULL DEFAULT 'draft',
    `source_url`        VARCHAR(2048)   DEFAULT NULL COMMENT 'original URL for news articles',
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `published_at`      DATETIME        DEFAULT NULL,
    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_posts_site` (`site_id`),
    KEY `idx_posts_status` (`status`),
    KEY `idx_posts_type` (`type`),
    KEY `idx_posts_published` (`published_at`),
    UNIQUE KEY `uk_posts_site_slug` (`site_id`, `slug`(191)),
    CONSTRAINT `fk_posts_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Social Media Posts
-- ============================================================
CREATE TABLE IF NOT EXISTS `social_posts` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `post_id`       INT UNSIGNED    NOT NULL,
    `site_id`       INT UNSIGNED    NOT NULL,
    `platform`      ENUM('linkedin','twitter','facebook','instagram') NOT NULL,
    `content`       TEXT            NOT NULL,
    `status`        ENUM('draft','scheduled','posted','failed') NOT NULL DEFAULT 'draft',
    `scheduled_at`  DATETIME        DEFAULT NULL,
    `posted_at`     DATETIME        DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_social_post` (`post_id`),
    KEY `idx_social_site` (`site_id`),
    KEY `idx_social_status` (`status`),
    CONSTRAINT `fk_social_post` FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_social_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Agent Activity Log
-- ============================================================
CREATE TABLE IF NOT EXISTS `agent_log` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`       INT UNSIGNED    DEFAULT NULL,
    `action`        VARCHAR(100)    NOT NULL COMMENT 'scan, write_blog, scrape_news, seo_audit, etc.',
    `details`       TEXT            DEFAULT NULL,
    `status`        ENUM('success','fail','skipped') NOT NULL DEFAULT 'success',
    `duration_ms`   INT UNSIGNED    DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_agentlog_site` (`site_id`),
    KEY `idx_agentlog_action` (`action`),
    KEY `idx_agentlog_created` (`created_at`),
    CONSTRAINT `fk_agentlog_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Keyword Tracking
-- ============================================================
CREATE TABLE IF NOT EXISTS `keywords` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`       INT UNSIGNED    NOT NULL,
    `keyword`       VARCHAR(255)    NOT NULL,
    `search_volume` INT UNSIGNED    DEFAULT NULL,
    `difficulty`    TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100 score',
    `current_rank`  SMALLINT UNSIGNED DEFAULT NULL,
    `target_rank`   SMALLINT UNSIGNED DEFAULT NULL,
    `cluster`       VARCHAR(100)    DEFAULT NULL COMMENT 'topic cluster name',
    `priority`      TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT '0-100',
    `last_checked`  DATETIME        DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_keywords_site` (`site_id`),
    KEY `idx_keywords_priority` (`priority`),
    UNIQUE KEY `uk_keywords_site_kw` (`site_id`, `keyword`(191)),
    CONSTRAINT `fk_keywords_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Technical SEO Audits
-- ============================================================
CREATE TABLE IF NOT EXISTS `seo_audits` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`       INT UNSIGNED    NOT NULL,
    `score`         TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 health score',
    `total_issues`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `critical`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `warnings`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `passed`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `pages_crawled` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `duration_ms`   INT UNSIGNED    DEFAULT NULL,
    `run_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audits_site` (`site_id`),
    KEY `idx_audits_run` (`run_at`),
    CONSTRAINT `fk_audits_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Individual SEO Issues
-- ============================================================
CREATE TABLE IF NOT EXISTS `seo_issues` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `audit_id`      INT UNSIGNED    NOT NULL,
    `site_id`       INT UNSIGNED    NOT NULL,
    `type`          ENUM(
                        'broken_link','missing_meta','missing_schema',
                        'missing_sitemap','missing_robots','redirect_chain',
                        'missing_alt','ssl_error','missing_canonical',
                        'missing_og','auth_error','speed_issue',
                        'duplicate_meta','mobile_issue'
                    ) NOT NULL,
    `severity`      ENUM('critical','warning','info') NOT NULL DEFAULT 'warning',
    `url`           VARCHAR(2048)   NOT NULL,
    `description`   TEXT            NOT NULL,
    `suggested_fix` TEXT            DEFAULT NULL,
    `status`        ENUM('open','fix_proposed','fix_applied','resolved','ignored') NOT NULL DEFAULT 'open',
    `fixed_at`      DATETIME        DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_issues_audit` (`audit_id`),
    KEY `idx_issues_site` (`site_id`),
    KEY `idx_issues_type` (`type`),
    KEY `idx_issues_severity` (`severity`),
    KEY `idx_issues_status` (`status`),
    CONSTRAINT `fk_issues_audit` FOREIGN KEY (`audit_id`) REFERENCES `seo_audits`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_issues_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
