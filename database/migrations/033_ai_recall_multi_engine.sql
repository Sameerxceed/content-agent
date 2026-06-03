-- AI Industry Recall (brand awareness in AI training data).
-- Different from AEO citation tracking — recall measures whether AI models
-- remember the brand when asked generic industry questions WITHOUT web search.
-- Per-engine, per-site snapshots.

CREATE TABLE IF NOT EXISTS `ai_recall_snapshots` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `engine`          VARCHAR(30)     NOT NULL COMMENT 'claude / openai / gemini',
    `snapshot_date`   DATE            NOT NULL,
    `score`           SMALLINT UNSIGNED NOT NULL COMMENT '0-100 percentage of questions where brand was mentioned',
    `mentioned`       SMALLINT UNSIGNED NOT NULL,
    `total_questions` SMALLINT UNSIGNED NOT NULL,
    `results_json`    LONGTEXT        DEFAULT NULL COMMENT 'array of {type, query, mentioned, response_text}',
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_engine_date` (`site_id`, `engine`, `snapshot_date`),
    KEY `idx_site_date` (`site_id`, `snapshot_date` DESC),
    CONSTRAINT `fk_recall_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
