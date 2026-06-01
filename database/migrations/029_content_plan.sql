-- Content Plan v1 — the planning + adaptation layer on top of Keywords.
--
-- Architecture: one rolling plan per site. Plan is structured as 8-12
-- persistent topic clusters; each cluster owns a pool of keywords; a subset
-- of those keywords get scheduled as plan_items in the visible pipeline
-- (rolling 12 weeks ahead). Monthly performance reviews extend the horizon
-- and adapt the pipeline based on actual GSC results.
--
-- See docs/content-plan-v1-for-review.docx for the full architecture.

-- ── content_plans — one active per site, rolls forever via monthly reviews ──
CREATE TABLE IF NOT EXISTS `content_plans` (
    `id`                                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`                           INT UNSIGNED    NOT NULL,
    `generated_at`                      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `starts_on`                         DATE            NOT NULL  COMMENT 'first item publish date',
    `pipeline_extends_to`               DATE            NOT NULL  COMMENT 'rolls forward each monthly review',
    `cadence_posts_per_week`            TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `rolling_horizon_weeks`             TINYINT UNSIGNED NOT NULL DEFAULT 12  COMMENT 'pipeline always extends this many weeks ahead',
    `forecast_horizon_weeks`            TINYINT UNSIGNED NOT NULL DEFAULT 26  COMMENT 'forward projection range for the North-Star number',
    `goal`                              TEXT            DEFAULT NULL,
    `status`                            ENUM('active','archived') NOT NULL DEFAULT 'active',
    `total_clusters`                    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `total_items_scheduled`             INT UNSIGNED    NOT NULL DEFAULT 0,
    `total_items_published`             INT UNSIGNED    NOT NULL DEFAULT 0,
    `estimated_clicks_at_horizon_low`   INT UNSIGNED    DEFAULT NULL,
    `estimated_clicks_at_horizon_high`  INT UNSIGNED    DEFAULT NULL,
    `last_drift_applied_at`             DATETIME        DEFAULT NULL,
    `last_review_at`                    DATETIME        DEFAULT NULL,
    `next_review_due_at`                DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_active` (`site_id`, `status`),
    KEY `idx_due_for_review` (`status`, `next_review_due_at`),
    CONSTRAINT `fk_plan_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── content_plan_clusters — 8-12 persistent topic groups per plan ──
CREATE TABLE IF NOT EXISTS `content_plan_clusters` (
    `id`                            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `plan_id`                       INT UNSIGNED    NOT NULL,
    `site_id`                       INT UNSIGNED    NOT NULL,
    `position`                      TINYINT UNSIGNED NOT NULL,
    `name`                          VARCHAR(160)    NOT NULL,
    `angle`                         TEXT            DEFAULT NULL  COMMENT 'cluster positioning sentence',
    `pillar_keyword_id`             INT UNSIGNED    DEFAULT NULL  COMMENT 'the head term (also referenced on the pillar item)',
    `estimated_cluster_clicks_low`  INT UNSIGNED    DEFAULT NULL,
    `estimated_cluster_clicks_high` INT UNSIGNED    DEFAULT NULL,
    `created_at`                    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_plan` (`plan_id`, `position`),
    CONSTRAINT `fk_cluster_plan` FOREIGN KEY (`plan_id`)  REFERENCES `content_plans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cluster_site` FOREIGN KEY (`site_id`)  REFERENCES `sites`(`id`)         ON DELETE CASCADE,
    CONSTRAINT `fk_cluster_kw`   FOREIGN KEY (`pillar_keyword_id`) REFERENCES `keywords`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── content_plan_items — items scheduled in the rolling pipeline ──
CREATE TABLE IF NOT EXISTS `content_plan_items` (
    `id`                        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `plan_id`                   INT UNSIGNED    NOT NULL,
    `cluster_id`                INT UNSIGNED    NOT NULL,
    `site_id`                   INT UNSIGNED    NOT NULL,
    `position`                  INT             NOT NULL,
    `target_week`               TINYINT UNSIGNED NOT NULL,
    `target_publish_date`       DATE            NOT NULL,
    `role`                      ENUM('pillar','supporting') NOT NULL DEFAULT 'supporting',
    `content_type`              ENUM('pillar','blog','comparison','guide','service_page','glossary','news')
                                NOT NULL DEFAULT 'blog',
    `bucket`                    ENUM('quick_win','new_content','aeo_gap','long_tail') NOT NULL,
    `primary_keyword_id`        INT UNSIGNED    NOT NULL,
    `secondary_keyword_ids`     JSON            DEFAULT NULL,
    `refresh_target_url`        VARCHAR(1024)   DEFAULT NULL  COMMENT 'set for Quick Wins — refresh existing URL instead of creating new',
    `proposed_title`            VARCHAR(500)    DEFAULT NULL,
    `proposed_angle`            TEXT            DEFAULT NULL,
    `recommended_word_count`    SMALLINT UNSIGNED DEFAULT NULL,
    `channels`                  JSON            NOT NULL  COMMENT '["cms","linkedin","twitter","reddit","newsletter","schema","llms"]',
    `lock_state`                ENUM('pipeline','committed','drafted','published') NOT NULL DEFAULT 'pipeline',
    `estimated_rank`            TINYINT UNSIGNED DEFAULT NULL,
    `estimated_clicks_at_6mo`   INT UNSIGNED    DEFAULT NULL,
    `confidence`                TINYINT UNSIGNED DEFAULT NULL,
    `post_id`                   INT UNSIGNED    DEFAULT NULL,
    `drafted_at`                DATETIME        DEFAULT NULL,
    `published_at`              DATETIME        DEFAULT NULL,
    `created_at`                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pub` (`site_id`, `target_publish_date`, `lock_state`),
    KEY `idx_cluster` (`cluster_id`, `position`),
    KEY `idx_plan_pos` (`plan_id`, `position`),
    CONSTRAINT `fk_item_plan`    FOREIGN KEY (`plan_id`)    REFERENCES `content_plans`(`id`)          ON DELETE CASCADE,
    CONSTRAINT `fk_item_cluster` FOREIGN KEY (`cluster_id`) REFERENCES `content_plan_clusters`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_item_site`    FOREIGN KEY (`site_id`)    REFERENCES `sites`(`id`)                  ON DELETE CASCADE,
    CONSTRAINT `fk_item_kw`      FOREIGN KEY (`primary_keyword_id`) REFERENCES `keywords`(`id`)      ON DELETE RESTRICT,
    CONSTRAINT `fk_item_post`    FOREIGN KEY (`post_id`)    REFERENCES `posts`(`id`)                  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── content_plan_cluster_keywords — pool of keywords per cluster ──
-- Each cluster has a pool. Some are scheduled as items; the rest sit waiting
-- for monthly reviews to schedule them as the horizon extends.
CREATE TABLE IF NOT EXISTS `content_plan_cluster_keywords` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `cluster_id`        INT UNSIGNED    NOT NULL,
    `keyword_id`        INT UNSIGNED    NOT NULL,
    `role`              ENUM('pillar_candidate','supporting','reserve') NOT NULL DEFAULT 'supporting',
    `is_scheduled`      TINYINT(1)      NOT NULL DEFAULT 0,
    `scheduled_item_id` INT UNSIGNED    DEFAULT NULL,
    `added_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_cluster_kw` (`cluster_id`, `keyword_id`),
    KEY `idx_cluster` (`cluster_id`, `is_scheduled`),
    KEY `idx_keyword` (`keyword_id`),
    CONSTRAINT `fk_ck_cluster` FOREIGN KEY (`cluster_id`)        REFERENCES `content_plan_clusters`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ck_keyword` FOREIGN KEY (`keyword_id`)        REFERENCES `keywords`(`id`)              ON DELETE CASCADE,
    CONSTRAINT `fk_ck_item`    FOREIGN KEY (`scheduled_item_id`) REFERENCES `content_plan_items`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── plan_drift_log — audit trail of every adaptation ──
CREATE TABLE IF NOT EXISTS `plan_drift_log` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `plan_id`           INT UNSIGNED    NOT NULL,
    `item_id`           INT UNSIGNED    DEFAULT NULL,
    `change_type`       ENUM('swap','add','remove','reschedule','cluster_rebalance','monthly_review') NOT NULL,
    `from_keyword_id`   INT UNSIGNED    DEFAULT NULL,
    `to_keyword_id`     INT UNSIGNED    DEFAULT NULL,
    `reason`            VARCHAR(500)    DEFAULT NULL,
    `review_id`         INT UNSIGNED    DEFAULT NULL  COMMENT 'links to plan_reviews if this change came from monthly review',
    `applied_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_plan` (`plan_id`, `applied_at`),
    CONSTRAINT `fk_drift_plan` FOREIGN KEY (`plan_id`) REFERENCES `content_plans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── plan_reviews — monthly performance review documents ──
-- AI proposes, user approves. 7-day auto-expiry. Never auto-applied.
CREATE TABLE IF NOT EXISTS `plan_reviews` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `plan_id`               INT UNSIGNED    NOT NULL,
    `site_id`               INT UNSIGNED    NOT NULL,
    `period_start`          DATE            NOT NULL,
    `period_end`            DATE            NOT NULL,
    `summary`               JSON            NOT NULL  COMMENT 'posts published, clicks gained, winners, underperformers',
    `learnings`             JSON            NOT NULL  COMMENT 'patterns AI identified',
    `proposed_changes`      JSON            NOT NULL  COMMENT 'swaps, reschedules, additions, removals each with reasons',
    `forecast_update`       JSON            DEFAULT NULL  COMMENT 'previous + updated forecast ranges with drivers',
    `status`                ENUM('proposed','approved','partially_approved','rejected','expired') NOT NULL DEFAULT 'proposed',
    `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`            DATETIME        NOT NULL  COMMENT 'auto-expire after 7 days if user does nothing',
    `reviewed_at`           DATETIME        DEFAULT NULL,
    `reviewed_by`           INT UNSIGNED    DEFAULT NULL,
    `applied_change_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_plan_pending` (`plan_id`, `status`),
    KEY `idx_expiring` (`status`, `expires_at`),
    CONSTRAINT `fk_review_plan` FOREIGN KEY (`plan_id`) REFERENCES `content_plans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_review_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Extend keywords with the protection flag ──
-- Find Keywords wipe step excludes rows where this is set, so planned
-- keywords survive re-runs of keyword research.
ALTER TABLE `keywords`
ADD COLUMN `protected_by_plan` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Find Keywords wipe step excludes rows with this set';
