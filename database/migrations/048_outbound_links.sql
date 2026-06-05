-- 048_outbound_links.sql
-- Outbound link check results — one row per (post, external URL).
-- Status: 'ok' (2xx/3xx), 'broken' (4xx/5xx), 'timeout' (no response),
-- 'redirect_chain' (3+ redirects, often a sign of a dying link).

CREATE TABLE IF NOT EXISTS outbound_links (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         INT UNSIGNED NOT NULL,
    post_id         BIGINT UNSIGNED NOT NULL,
    url             VARCHAR(1000) NOT NULL,
    url_hash        CHAR(40) NOT NULL,
    anchor_text     VARCHAR(500) NULL,
    status          ENUM('ok','broken','timeout','redirect_chain') NOT NULL DEFAULT 'ok',
    http_code       INT NULL,
    final_url       VARCHAR(1000) NULL,
    redirect_count  INT NOT NULL DEFAULT 0,
    last_checked_at DATETIME NOT NULL,
    dismissed_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_post_url (post_id, url_hash),
    KEY idx_site_status (site_id, status),
    KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
