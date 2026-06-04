-- Core Web Vitals + PageSpeed Insights baseline per site.
--
-- Per-site, we pick ~10 representative URLs (home, top collection, top
-- product, top blog, contact, etc.) and snapshot mobile + desktop scores
-- weekly. Trend chart slots into existing Health Report.
--
-- PSI API at https://www.googleapis.com/pagespeedonline/v5/runPagespeed
-- (no key needed at low volume; we recommend a key for production cron via
-- a wizard later). Returns lab + field data (CrUX) so we capture both.

CREATE TABLE IF NOT EXISTS `cwv_baseline_urls` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `url`             VARCHAR(2048)   NOT NULL,
    `label`           VARCHAR(120)    NOT NULL  COMMENT 'home / top_collection / top_product / etc.',
    `priority`        TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_url` (`site_id`, `url`(191)),
    KEY `idx_site_priority` (`site_id`, `priority` DESC),
    CONSTRAINT `fk_cbu_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cwv_baseline` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `url`             VARCHAR(2048)   NOT NULL,
    `device`          ENUM('mobile','desktop') NOT NULL,
    `snapshot_date`   DATE            NOT NULL,
    `perf_score`      TINYINT UNSIGNED DEFAULT NULL  COMMENT 'PSI Performance category score 0-100',
    `seo_score`       TINYINT UNSIGNED DEFAULT NULL,
    `accessibility_score` TINYINT UNSIGNED DEFAULT NULL,
    `best_practices_score` TINYINT UNSIGNED DEFAULT NULL,
    `lcp_ms`          INT UNSIGNED    DEFAULT NULL  COMMENT 'Largest Contentful Paint ms',
    `inp_ms`          INT UNSIGNED    DEFAULT NULL  COMMENT 'Interaction to Next Paint ms (CrUX field data)',
    `fid_ms`          INT UNSIGNED    DEFAULT NULL  COMMENT 'First Input Delay ms (legacy field data)',
    `cls`             DECIMAL(5,3)    DEFAULT NULL  COMMENT 'Cumulative Layout Shift',
    `fcp_ms`          INT UNSIGNED    DEFAULT NULL  COMMENT 'First Contentful Paint ms',
    `ttfb_ms`         INT UNSIGNED    DEFAULT NULL  COMMENT 'Time To First Byte ms',
    `field_loading`   VARCHAR(20)     DEFAULT NULL  COMMENT 'CrUX FAST / AVERAGE / SLOW',
    `field_lcp_ms`    INT UNSIGNED    DEFAULT NULL  COMMENT 'CrUX real-user LCP p75',
    `field_inp_ms`    INT UNSIGNED    DEFAULT NULL,
    `field_cls`       DECIMAL(5,3)    DEFAULT NULL,
    `lab_only`        TINYINT(1)      NOT NULL DEFAULT 0  COMMENT 'true when CrUX has no real-user data',
    `error`           TEXT            DEFAULT NULL,
    `raw_excerpt`     TEXT            DEFAULT NULL  COMMENT 'small slice of opportunities for debugging',
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_url_device_date` (`site_id`, `url`(191), `device`, `snapshot_date`),
    KEY `idx_site_date` (`site_id`, `snapshot_date` DESC),
    CONSTRAINT `fk_cb_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
