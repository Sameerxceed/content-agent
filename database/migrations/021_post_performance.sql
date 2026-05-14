-- Performance Loop — daily snapshots of how each post is doing on each channel.
-- The loop reads this back to identify winners (do more) and losers (refresh or kill).
--
-- For organic (channel='cms') we pull GSC metrics keyed on the post's slug/URL.
-- For social channels (linkedin, twitter, reddit) the channel adapter's
-- fetch_metrics() populates post_channels.metrics; this table snapshots those daily
-- so trends can be drawn.

CREATE TABLE IF NOT EXISTS `post_performance` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `post_id`         INT UNSIGNED NOT NULL,
    `channel`         VARCHAR(30)  NOT NULL  COMMENT 'cms (organic via GSC) / linkedin / twitter / reddit / newsletter',
    `snapshot_date`   DATE         NOT NULL,
    `impressions`     INT UNSIGNED DEFAULT 0,
    `clicks`          INT UNSIGNED DEFAULT 0,
    `ctr`             DECIMAL(6,4) DEFAULT NULL,
    `avg_position`    DECIMAL(6,2) DEFAULT NULL,
    `engagement`      INT UNSIGNED DEFAULT 0    COMMENT 'likes + comments + shares for social channels',
    `raw_metrics`     JSON         DEFAULT NULL COMMENT 'channel-specific raw response',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_post_channel_day` (`post_id`, `channel`, `snapshot_date`),
    KEY `idx_post_channel` (`post_id`, `channel`),
    KEY `idx_snapshot_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track which posts the user has acted on (refreshed / dismissed) so we don't keep nagging.
CREATE TABLE IF NOT EXISTS `performance_actions` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `post_id`         INT UNSIGNED NOT NULL,
    `action`          ENUM('refresh_queued','refresh_done','dismiss','queue_similar') NOT NULL,
    `note`            VARCHAR(500) DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_post`    (`post_id`),
    KEY `idx_action`  (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
