-- 052_plans.sql
-- Subscription plans + per-site assignment. Drives the cost guardrails
-- so a runaway scenario on one customer can't burn through unlimited AI.
--
-- Master guard: `monthly_ai_budget_usd` is the hard cap on cumulative
-- ai_calls.cost_usd in the current calendar month. Every AI wrapper checks
-- this before firing — over-cap calls return a structured error instead of
-- hitting the API.
--
-- Per-feature caps live as additional columns so they're cheap to read
-- alongside the budget. JSON `feature_flags` covers the binary on/off
-- features per tier (white-label, multi-site, etc.) — kept as JSON so
-- adding a new flag doesn't need a migration.

CREATE TABLE IF NOT EXISTS plans (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                        VARCHAR(32) NOT NULL,
    name                        VARCHAR(64) NOT NULL,
    price_monthly_usd           DECIMAL(8,2) NOT NULL DEFAULT 0,
    -- Hard master guardrail — cumulative AI spend per site per month.
    -- 0 = unlimited (Super only).
    monthly_ai_budget_usd       DECIMAL(8,2) NOT NULL DEFAULT 5.00,
    -- Per-feature ceilings (0 = unlimited)
    max_posts_per_month         INT UNSIGNED NOT NULL DEFAULT 4,
    max_aeo_queries             INT UNSIGNED NOT NULL DEFAULT 5,
    max_images_per_month        INT UNSIGNED NOT NULL DEFAULT 10,
    max_redirect_urls_per_run   INT UNSIGNED NOT NULL DEFAULT 1000,
    max_plan_regens_per_week    INT UNSIGNED NOT NULL DEFAULT 1,
    max_sites_per_user          INT UNSIGNED NOT NULL DEFAULT 1,
    -- Whitelist of model IDs (JSON array). NULL = allow all.
    allowed_models              JSON NULL,
    -- Binary on/off flags. JSON so new flags don't need migrations.
    feature_flags               JSON NULL,
    is_active                   TINYINT(1) NOT NULL DEFAULT 1,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed four tiers. Numbers calibrated for ~65-70% gross margin at each
-- price point after AI cost + infra share + payment processing.
-- AI budget per tier is sized at 4-7× steady-state cost so normal usage
-- doesn't hit the cap; one-time migrations (e.g. 16K-URL redirect map)
-- still need the operator to bump per-site override or charge the
-- $99 migration fee separately.
INSERT IGNORE INTO plans
    (code, name, price_monthly_usd, monthly_ai_budget_usd,
     max_posts_per_month, max_aeo_queries, max_images_per_month,
     max_redirect_urls_per_run, max_plan_regens_per_week, max_sites_per_user,
     allowed_models, feature_flags)
VALUES
    -- Basic: small Shopify boutique / B2B SaaS startup. Haiku only.
    ('starter', 'Basic ($49/mo)', 49.00, 10.00,
     4, 5, 10, 1000, 1, 1,
     '["claude-haiku-4-5-20251001", "claude-haiku-4-5", "gemini-2.0-flash", "imagen-4.0-fast", "imagen-4.0-fast-generate-001"]',
     '{"aeo_multi_engine": false, "gmc_diagnostics": false, "agency_multisite": false, "white_label": false, "channel_staggering": false}'),
    -- Pro: full content engine + multi-engine AEO + GMC. Haiku + Sonnet.
    ('pro', 'Pro ($99/mo)', 99.00, 25.00,
     16, 20, 30, 25000, 4, 1,
     '["claude-haiku-4-5-20251001", "claude-haiku-4-5", "claude-sonnet-4-6", "gemini-2.0-flash", "gpt-4o-search-preview", "gpt-image-1", "imagen-4.0-fast", "imagen-4.0-fast-generate-001", "sonar"]',
     '{"aeo_multi_engine": true, "gmc_diagnostics": true, "agency_multisite": false, "white_label": false, "channel_staggering": true}'),
    -- Agency: up to 5 sites pooled, white-label.
    ('agency', 'Agency ($299/mo, 5 sites)', 299.00, 80.00,
     0, 100, 100, 100000, 8, 5,
     NULL,
     '{"aeo_multi_engine": true, "gmc_diagnostics": true, "agency_multisite": true, "white_label": true, "channel_staggering": true}'),
    -- Super: ContentAgent operator / internal sites. No caps.
    ('super', 'Super Admin (unlimited)', 0.00, 0.00,
     0, 0, 0, 0, 0, 0,
     NULL,
     '{"aeo_multi_engine": true, "gmc_diagnostics": true, "agency_multisite": true, "white_label": true, "channel_staggering": true}');

-- Add plan_id to sites — default 'starter' for any existing site so the
-- guardrails activate immediately. Super-admin can re-assign in the new
-- /dashboard/plans.php page.
ALTER TABLE sites
    ADD COLUMN IF NOT EXISTS plan_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER user_id,
    ADD KEY IF NOT EXISTS idx_plan (plan_id);

-- Per-site overrides — for the "this customer is on Pro but I want to bump
-- their monthly budget to $50 this month only" case. NULL = use plan default.
ALTER TABLE sites
    ADD COLUMN IF NOT EXISTS plan_budget_override_usd DECIMAL(8,2) NULL AFTER plan_id;
