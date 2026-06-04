-- Content freshness audit per post — flags evergreen posts that are getting
-- stale and queues a refresh recommendation. Pairs with Content Plan: every
-- "needs_refresh" row can become a plan item with content_type=refresh.

CREATE TABLE IF NOT EXISTS `content_freshness` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `post_id`         INT UNSIGNED    NOT NULL,
    `last_audited_at` DATETIME        NOT NULL,
    `age_days`        INT UNSIGNED    NOT NULL  COMMENT 'days since post.published_at',
    `staleness_score` TINYINT UNSIGNED NOT NULL  COMMENT '0-100 — combines age, traffic decline, factual drift signals',
    `signals`         JSON            DEFAULT NULL COMMENT 'breakdown: {age_pts, traffic_decline_pts, year_mentions_pts, ...}',
    `needs_refresh`   TINYINT(1)      NOT NULL DEFAULT 0,
    `refresh_reason`  TEXT            DEFAULT NULL COMMENT 'human-readable why (mentions outdated years, performance decline, etc.)',
    `queued_plan_item_id` INT UNSIGNED DEFAULT NULL COMMENT 'set when refresh item created in content plan',
    `dismissed_at`    DATETIME        DEFAULT NULL  COMMENT 'user said "no, keep as is"',
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_post` (`site_id`, `post_id`),
    KEY `idx_site_needs` (`site_id`, `needs_refresh`),
    CONSTRAINT `fk_cf_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cf_post` FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
