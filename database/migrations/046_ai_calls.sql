-- 046_ai_calls.sql
-- Per-call log of every AI provider hit. Drives the admin cost dashboard
-- AND calibrates the Phase 1 preflight estimator from real usage as data
-- accumulates.
--
-- One row per HTTP call. provider+model captures who got paid. feature is
-- a stable string identifying the caller — keep it short + don't change
-- existing values so the dashboard time-series stays continuous.
--
-- site_id is nullable because some calls (brand analysis at signup,
-- diagnostic prompts) aren't bound to a site yet.
--
-- cost_usd is computed at insert time from ai_cost_for_call() using the
-- rate card in includes/ai_cost.php — don't backfill from a historical
-- rate card if prices change, just let new rows reflect new prices.

CREATE TABLE IF NOT EXISTS ai_calls (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider          VARCHAR(32) NOT NULL,
    model             VARCHAR(64) NOT NULL,
    feature           VARCHAR(64) NOT NULL,
    site_id           INT UNSIGNED NULL,
    post_id           BIGINT UNSIGNED NULL,
    input_tokens      INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens     INT UNSIGNED NOT NULL DEFAULT 0,
    cost_usd          DECIMAL(10,6) NOT NULL DEFAULT 0,
    ms                INT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_site_created   (site_id, created_at),
    KEY idx_feature_created(feature, created_at),
    KEY idx_provider_model (provider, model),
    KEY idx_created        (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
