-- Bundle C — Publishing Pipeline foundation
--
-- One post can be published to many channels (CMS, Reddit, LinkedIn, Twitter,
-- newsletter, etc). Each (post, channel) pair has its own status, scheduled time,
-- channel-specific variant content, external IDs, and metrics.
--
-- A "channel adapter" class in includes/channels/ knows how to publish to each.

CREATE TABLE IF NOT EXISTS `post_channels` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `post_id`          INT UNSIGNED    NOT NULL,
    `channel`          VARCHAR(30)     NOT NULL COMMENT 'cms, reddit, linkedin, twitter, newsletter, instagram',
    `variant_content`  LONGTEXT        DEFAULT NULL COMMENT 'channel-specific rendering (e.g. tweet thread)',
    `variant_meta`     JSON            DEFAULT NULL COMMENT 'channel-specific extras (e.g. subreddit, LI profile)',
    `external_id`      VARCHAR(255)    DEFAULT NULL COMMENT 'ID returned by the channel after publish',
    `external_url`     VARCHAR(2048)   DEFAULT NULL,
    `status`           ENUM('draft','queued','publishing','published','failed','cancelled') NOT NULL DEFAULT 'draft',
    `scheduled_for`    DATETIME        DEFAULT NULL,
    `published_at`     DATETIME        DEFAULT NULL,
    `error`            TEXT            DEFAULT NULL,
    `attempts`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `metrics`          JSON            DEFAULT NULL COMMENT 'channel-specific stats (impressions/likes/etc)',
    `metrics_synced_at` DATETIME       DEFAULT NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_post_channel` (`post_id`, `channel`),
    KEY `idx_status_schedule` (`status`, `scheduled_for`),
    KEY `idx_channel_status` (`channel`, `status`),
    CONSTRAINT `fk_pc_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-site default channels (which channels does this site usually publish to)
ALTER TABLE `sites`
ADD COLUMN `default_channels` JSON DEFAULT NULL COMMENT 'Default channel selection for new posts, e.g. ["cms","linkedin"]' AFTER `last_digest_sent`;
