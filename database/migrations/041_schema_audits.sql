-- Live schema.org auditor — verifies that the JSON-LD ContentAgent emitted
-- still actually appears on the live page. Critical because: a theme update,
-- CMS plugin conflict, or sloppy edit can silently strip <script type="application/ld+json">
-- tags. Without this check, we'd never know our schema bundle bled away.

CREATE TABLE IF NOT EXISTS `schema_audits` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `url`             VARCHAR(2048)   NOT NULL,
    `url_hash`        CHAR(40)        NOT NULL,
    `expected_types`  JSON            DEFAULT NULL COMMENT 'array of schema types expected on this URL e.g. ["Article","FAQPage","BreadcrumbList"]',
    `found_types`     JSON            DEFAULT NULL COMMENT 'what we actually parsed from the live page',
    `missing_types`   JSON            DEFAULT NULL COMMENT 'expected minus found — these silently disappeared',
    `extra_types`     JSON            DEFAULT NULL COMMENT 'on the page but not expected — usually fine (theme adds Organization)',
    `block_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `has_json_ld`     TINYINT(1)      NOT NULL DEFAULT 0,
    `parse_errors`    JSON            DEFAULT NULL,
    `last_status`     ENUM('ok','degraded','broken','fetch_failed') NOT NULL DEFAULT 'ok'
                       COMMENT 'ok = all expected present; degraded = some missing; broken = NO json-ld at all',
    `last_checked_at` DATETIME        DEFAULT NULL,
    `notes`           TEXT            DEFAULT NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_url` (`site_id`, `url_hash`),
    KEY `idx_site_status` (`site_id`, `last_status`),
    KEY `idx_last_checked` (`last_checked_at`),
    CONSTRAINT `fk_sa_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
