-- 050_gmc_audit.sql
-- Google Merchant Center feed audit. Module 4.
-- Pulls per-product issues from the Content API and stores them so the
-- merchant can see what's wrong with their feed + apply per-product fixes.
--
-- gmc_products = product inventory snapshot.
-- gmc_issues   = per-product per-issue rows. One product can have N issues.

CREATE TABLE IF NOT EXISTS gmc_products (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         INT UNSIGNED NOT NULL,
    merchant_id     VARCHAR(64) NOT NULL,
    product_id      VARCHAR(255) NOT NULL,
    offer_id        VARCHAR(255) NULL,
    title           VARCHAR(500) NULL,
    link            VARCHAR(1000) NULL,
    image_link      VARCHAR(1000) NULL,
    price           VARCHAR(64) NULL,
    availability    VARCHAR(32) NULL,
    condition_state VARCHAR(32) NULL,
    issue_count     INT NOT NULL DEFAULT 0,
    last_fetched_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_site_product (site_id, merchant_id, product_id),
    KEY idx_site_issues (site_id, issue_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gmc_issues (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         INT UNSIGNED NOT NULL,
    merchant_id     VARCHAR(64) NOT NULL,
    product_id      VARCHAR(255) NOT NULL,
    issue_code      VARCHAR(128) NOT NULL,
    severity        ENUM('error','warning','suggestion') NOT NULL DEFAULT 'warning',
    destination     VARCHAR(64) NULL,
    description     VARCHAR(1000) NULL,
    detail          TEXT NULL,
    documentation   VARCHAR(500) NULL,
    detected_at     DATETIME NOT NULL,
    resolved_at     DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_issue (site_id, merchant_id, product_id, issue_code),
    KEY idx_site_severity (site_id, severity, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
