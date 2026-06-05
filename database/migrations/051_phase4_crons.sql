-- 051_phase4_crons.sql
-- Seed cron schedules for the two Phase-4 modules that need recurring runs:
--   gsc-fetch  — daily, pulls yesterday's GSC performance per connected site
--   gmc-audit  — nightly, re-syncs Merchant Center diagnostics per site that
--                has a gmc_merchant_id configured
--
-- Both are idempotent: skip sites that don't have the integration
-- (gsc-fetch loops over integrations.platform='google_search_console';
--  gmc-audit loops over sites.notes LIKE '%gmc_merchant_id%').

INSERT INTO cron_schedules (
    job_name, label, description,
    interval_kind, interval_value, run_hour_ist, run_minute, run_day_of_week,
    enabled, next_run_at, created_at
) VALUES (
    'gsc-fetch',
    'Search Console daily pull',
    'Daily nightly job — pulls yesterday clicks/impressions/ctr/position by page+query from Google Search Console for every site that has an active GSC integration. Backfills on first connect; incremental thereafter.',
    'daily', 1, 1, 0, NULL,  -- 01:00 IST every day
    1,
    DATE_ADD(DATE(CURDATE() + INTERVAL 1 DAY), INTERVAL '01:00' HOUR_MINUTE),
    NOW()
)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description), enabled = 1;

INSERT INTO cron_schedules (
    job_name, label, description,
    interval_kind, interval_value, run_hour_ist, run_minute, run_day_of_week,
    enabled, next_run_at, created_at
) VALUES (
    'gmc-audit',
    'Merchant Center diagnostics sync',
    'Nightly job — re-syncs the product catalogue + per-product issue list from Google Merchant Center for every site that has a merchant_id configured. Issue counts on the GMC dashboard refresh from this run.',
    'daily', 1, 2, 0, NULL,  -- 02:00 IST every day (after GSC)
    1,
    DATE_ADD(DATE(CURDATE() + INTERVAL 1 DAY), INTERVAL '02:00' HOUR_MINUTE),
    NOW()
)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description), enabled = 1;
