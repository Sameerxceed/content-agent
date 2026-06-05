-- 047_image_audits.sql
-- Image SEO audit results, one row per image found on a published post.
-- The auditor extracts <img> tags from posts.body_html and grades each
-- on: alt text presence, alt text quality, filename quality, dimensions
-- set, file-size estimate (from HEAD if HTTP).
--
-- Status: 'good' (passes all checks), 'needs_alt' (no alt), 'weak_alt'
-- (generic / filename-as-alt), 'no_dims' (missing width/height),
-- 'oversized' (>500KB based on HEAD Content-Length), 'broken' (404).

CREATE TABLE IF NOT EXISTS image_audits (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         INT UNSIGNED NOT NULL,
    post_id         BIGINT UNSIGNED NULL,
    image_url       VARCHAR(1000) NOT NULL,
    image_url_hash  CHAR(40) NOT NULL,
    alt_text        VARCHAR(500) NULL,
    width           INT NULL,
    height          INT NULL,
    file_bytes      INT NULL,
    status          ENUM('good','needs_alt','weak_alt','no_dims','oversized','broken') NOT NULL DEFAULT 'good',
    issue_notes     VARCHAR(500) NULL,
    last_audited_at DATETIME NOT NULL,
    dismissed_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_site_url (site_id, image_url_hash),
    KEY idx_status (site_id, status),
    KEY idx_post   (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
