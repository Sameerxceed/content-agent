-- Bundle A + B: Automation foundation + Strategic Grounding
--
-- Generalises gap_runs into agent_runs (one table for all scheduled jobs).
-- Adds alerts (things the user should know about), AI Visibility snapshots
-- (for delta tracking), brand_mentions (daily scan results), and persona+USP
-- on sites for deeper AI grounding.

-- ── agent_runs: tracks every scheduled job execution ─────────────
CREATE TABLE IF NOT EXISTS `agent_runs` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    DEFAULT NULL  COMMENT 'NULL for global jobs',
    `job_type`        VARCHAR(50)     NOT NULL      COMMENT 'gsc_sync, competitor_redetect, competitor_pages_check, brand_monitor, ai_visibility, gap_analysis, weekly_digest, news_scrape',
    `status`          ENUM('queued','running','done','failed','skipped') NOT NULL DEFAULT 'queued',
    `progress`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `current_step`    VARCHAR(255)    DEFAULT NULL,
    `result_summary`  JSON            DEFAULT NULL  COMMENT 'counts, deltas, what changed',
    `error`           TEXT            DEFAULT NULL,
    `triggered_by`    ENUM('cron','manual') NOT NULL DEFAULT 'manual',
    `started_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at`     DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_agentruns_lookup` (`site_id`, `job_type`, `started_at`),
    KEY `idx_agentruns_status` (`status`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── alerts: things to surface to the user ────────────────────────
CREATE TABLE IF NOT EXISTS `alerts` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`        INT UNSIGNED    NOT NULL,
    `type`           VARCHAR(50)     NOT NULL  COMMENT 'new_competitor, competitor_post, brand_mention, visibility_drop, score_drop, rank_drop, gsc_sync_done, gap_analysis_done',
    `severity`       ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    `title`          VARCHAR(500)    NOT NULL,
    `detail`         TEXT            DEFAULT NULL,
    `link_url`       VARCHAR(2048)   DEFAULT NULL  COMMENT 'Where to go to act on this alert',
    `data`           JSON            DEFAULT NULL,
    `read_at`        DATETIME        DEFAULT NULL,
    `detected_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_alerts_unread` (`site_id`, `read_at`, `detected_at`),
    KEY `idx_alerts_recent` (`site_id`, `detected_at`),
    CONSTRAINT `fk_alerts_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ai_visibility_snapshots: weekly state for delta tracking ────
CREATE TABLE IF NOT EXISTS `ai_visibility_snapshots` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`        INT UNSIGNED    NOT NULL,
    `score`          TINYINT UNSIGNED DEFAULT NULL  COMMENT '0-100',
    `mentions_count` INT UNSIGNED    NOT NULL DEFAULT 0,
    `queries_tested` INT UNSIGNED    NOT NULL DEFAULT 0,
    `result_json`    JSON            DEFAULT NULL  COMMENT 'Full audit output for diffing',
    `taken_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_visibility_site_time` (`site_id`, `taken_at`),
    CONSTRAINT `fk_visibility_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── brand_mentions: results of daily brand-name scans ───────────
CREATE TABLE IF NOT EXISTS `brand_mentions` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`       INT UNSIGNED    NOT NULL,
    `source_domain` VARCHAR(255)    DEFAULT NULL,
    `url`           VARCHAR(2048)   NOT NULL,
    `title`         VARCHAR(500)    DEFAULT NULL,
    `snippet`       TEXT            DEFAULT NULL,
    `sentiment`     ENUM('positive','neutral','negative','unknown') DEFAULT 'unknown',
    `status`        ENUM('new','seen','ignored') NOT NULL DEFAULT 'new',
    `found_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_mention_url` (`site_id`, `url`(191)),
    KEY `idx_mention_status` (`site_id`, `status`, `found_at`),
    CONSTRAINT `fk_mention_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bundle B: strategic grounding fields on sites ────────────────
ALTER TABLE `sites`
ADD COLUMN `persona` TEXT DEFAULT NULL COMMENT 'Ideal customer description (used in AI prompts)' AFTER `business_description`,
ADD COLUMN `usp` TEXT DEFAULT NULL COMMENT 'Unique selling proposition / what makes us different' AFTER `persona`,
ADD COLUMN `email_digest` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Weekly email digest opt-in (default ON)' AFTER `usp`,
ADD COLUMN `last_digest_sent` DATETIME DEFAULT NULL AFTER `email_digest`;
