-- 301 Redirect map.
--
-- For each dead historical URL (from Module 2's historical_urls table), we
-- find the best living target on the current site and store a proposed
-- redirect. High-confidence redirects auto-approve; lower-confidence go to
-- a review queue. Approved redirects get pushed to the customer's platform:
--   - Shopify: URL Redirects Admin API (Module 1 dependency) or CSV export
--   - Next.js: generated next.config.js redirects block (paste-into-repo)
--   - WordPress: REST API / .htaccess
--   - Other: manual checklist with copy buttons

-- NOTE: named `redirect_map` (not `redirects`) because a pre-existing
-- `redirects` table from the older SEO auto-fix path (auto-fix-all.php,
-- seo-data.php) uses different columns (to_url, type, hits). Keeping them
-- separate avoids breakage; we may consolidate later.
CREATE TABLE IF NOT EXISTS `redirect_map` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`           INT UNSIGNED    NOT NULL,
    `from_path`         VARCHAR(2048)   NOT NULL  COMMENT 'dead URL path (no host)',
    `from_path_hash`    CHAR(40)        NOT NULL  COMMENT 'sha1 of from_path for unique-key',
    `to_path`           VARCHAR(2048)   DEFAULT NULL COMMENT 'living target path; NULL means "no good target — consider 410 Gone"',
    `source`            ENUM('wayback','gsc_404','ahrefs_backlink','manual') NOT NULL DEFAULT 'wayback',
    `source_ref`        VARCHAR(255)    DEFAULT NULL COMMENT 'optional pointer back to source row (historical_urls.id, seo_issues.id, etc.)',
    `confidence`        TINYINT UNSIGNED DEFAULT NULL  COMMENT '0-100, set by the builder (Claude + slug similarity)',
    `match_method`      VARCHAR(40)     DEFAULT NULL  COMMENT 'how the target was found: slug_exact / claude_fuzzy / claude_branch / manual',
    `reasoning`         TEXT            DEFAULT NULL  COMMENT 'Claude rationale for the chosen target (shown in the review UI)',
    `status`            ENUM('pending','approved','rejected','applied','reverted') NOT NULL DEFAULT 'pending',
    `auto_approved`     TINYINT(1)      NOT NULL DEFAULT 0,
    `applied_at`        DATETIME        DEFAULT NULL  COMMENT 'when it was pushed to the platform',
    `applied_via`       VARCHAR(40)     DEFAULT NULL  COMMENT 'shopify_api / next_config / wp_api / manual_csv',
    `external_id`       VARCHAR(255)    DEFAULT NULL  COMMENT 'platform-side redirect id when applicable',
    `notes`             TEXT            DEFAULT NULL,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_from` (`site_id`, `from_path_hash`),
    KEY `idx_site_status` (`site_id`, `status`),
    CONSTRAINT `fk_red_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory of URLs that exist on the LIVE site today. Powers the fuzzy
-- match — for each dead URL we ask: "which current_site_urls row is the best
-- target?" Refreshed via a light crawler (sitemap.xml first, then in-page
-- link extraction).
CREATE TABLE IF NOT EXISTS `current_site_urls` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`           INT UNSIGNED    NOT NULL,
    `url`               VARCHAR(2048)   NOT NULL,
    `url_hash`          CHAR(40)        NOT NULL,
    `path`              VARCHAR(1024)   DEFAULT NULL,
    `title`             VARCHAR(500)    DEFAULT NULL  COMMENT 'page title for similarity match',
    `url_type`          VARCHAR(40)     DEFAULT NULL  COMMENT 'product / collection / page / blog / home / other',
    `last_crawled_at`   DATETIME        DEFAULT NULL,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_url` (`site_id`, `url_hash`),
    KEY `idx_site_type` (`site_id`, `url_type`),
    KEY `idx_site_path` (`site_id`, `path`(191)),
    CONSTRAINT `fk_csu_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redirect_runs` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`           INT UNSIGNED    NOT NULL,
    `kind`              ENUM('crawl','build','apply') NOT NULL,
    `started_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at`       DATETIME        DEFAULT NULL,
    `status`            ENUM('running','done','failed') NOT NULL DEFAULT 'running',
    `items_processed`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `items_succeeded`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `items_failed`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `error`             TEXT            DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_site_kind_started` (`site_id`, `kind`, `started_at` DESC),
    CONSTRAINT `fk_rr_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
