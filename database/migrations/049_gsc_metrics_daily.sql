-- 049_gsc_metrics_daily.sql
-- Daily Google Search Console performance metrics, one row per
-- (site, date, page, query). Pulled via the Search Console API
-- (searchanalytics.query endpoint) and stored for time-series analysis.
--
-- We aggregate at fetch time — daily page-query rows are the finest
-- granularity GSC returns and what the dashboard pivots on.
--
-- gsc_index_status (separate table below) tracks per-URL indexing
-- coverage state, refreshed less frequently than performance.

CREATE TABLE IF NOT EXISTS gsc_metrics_daily (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         INT UNSIGNED NOT NULL,
    metric_date     DATE NOT NULL,
    page            VARCHAR(1000) NOT NULL,
    page_hash       CHAR(40) NOT NULL,
    query           VARCHAR(500) NOT NULL,
    query_hash      CHAR(40) NOT NULL,
    clicks          INT NOT NULL DEFAULT 0,
    impressions     INT NOT NULL DEFAULT 0,
    ctr             DECIMAL(6,4) NOT NULL DEFAULT 0,
    position        DECIMAL(6,2) NOT NULL DEFAULT 0,
    fetched_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_site_day_page_query (site_id, metric_date, page_hash, query_hash),
    KEY idx_site_date (site_id, metric_date),
    KEY idx_page (site_id, page_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gsc_index_status (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         INT UNSIGNED NOT NULL,
    page            VARCHAR(1000) NOT NULL,
    page_hash       CHAR(40) NOT NULL,
    coverage_state  VARCHAR(64) NULL,
    indexing_state  VARCHAR(64) NULL,
    last_crawl_at   DATETIME NULL,
    last_fetched_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_site_page (site_id, page_hash),
    KEY idx_site_state (site_id, coverage_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
