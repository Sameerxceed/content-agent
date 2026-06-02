-- Legal docs auto-generation feature.
-- Scanner detects missing standard legal pages (privacy / terms / cookies /
-- refund / disclaimer); Claude drafts them; CMS push deploys them. Each
-- site has at most one row per doc_type (UNIQUE), updated in place across
-- detection rescans / regenerations.

CREATE TABLE IF NOT EXISTS `legal_docs` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_id`         INT UNSIGNED    NOT NULL,
    `doc_type`        ENUM('privacy','terms','cookies','refund','disclaimer') NOT NULL,
    `status`          ENUM('missing','generating','drafted','approved','published','failed') NOT NULL DEFAULT 'missing',

    -- Detection
    `detected_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expected_paths`  JSON            DEFAULT NULL  COMMENT 'URL paths scanner checked, e.g. ["/privacy","/privacy-policy"]',
    `found_url`       VARCHAR(2048)   DEFAULT NULL  COMMENT 'live URL if site already has one (skip generation)',
    `relevance`       ENUM('required','recommended','optional') NOT NULL DEFAULT 'required'
                                      COMMENT 'cookies+disclaimer are conditional on business profile',

    -- Generated content
    `title`           VARCHAR(500)    DEFAULT NULL,
    `body_html`       LONGTEXT        DEFAULT NULL,
    `slug`            VARCHAR(255)    DEFAULT NULL,

    -- Jurisdictions covered (JSON array: ["IN","EU","US-CA","UK","AU"])
    `jurisdictions`   JSON            DEFAULT NULL,

    -- Lifecycle timestamps + auth
    `generated_at`    DATETIME        DEFAULT NULL,
    `approved_at`     DATETIME        DEFAULT NULL,
    `approved_by`     INT UNSIGNED    DEFAULT NULL,
    `published_at`    DATETIME        DEFAULT NULL,
    `published_url`   VARCHAR(2048)   DEFAULT NULL,

    -- Versioning + error
    `version`         INT UNSIGNED    NOT NULL DEFAULT 1,
    `last_error`      TEXT            DEFAULT NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_site_doctype` (`site_id`, `doc_type`),
    KEY `idx_status` (`status`),
    KEY `idx_site_status` (`site_id`, `status`),
    CONSTRAINT `fk_legal_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
