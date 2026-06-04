-- Register the weekly Website Hygiene job in the in-app cron scheduler.
-- Runs Sundays at 04:00 IST (= 22:30 UTC Saturday). Picks a low-traffic window
-- so harvest + live-check requests don't compete with customer-facing traffic.
--
-- The job iterates every active site and runs the full pipeline:
--   harvest → live-check → site-crawl → redirect-build
-- per site, with a 30-second polite gap between sites.

INSERT INTO cron_schedules (
    job_name, label, description,
    interval_kind, interval_value, run_hour_ist, run_minute, run_day_of_week,
    enabled, next_run_at, created_at
) VALUES (
    'website-hygiene',
    'Website Hygiene: redirect map + archive refresh',
    'Weekly maintenance pass per site. Pulls the latest archive history from search-engine indexes, re-checks every URL''s live status, refreshes the live-URL inventory, and rebuilds the redirect map for any newly-discovered dead URLs. Keeps your redirect coverage current without you having to remember to click anything.',
    'weeks', 1, 4, 0, 0,  -- weekly, Sunday 04:00 IST
    1,
    DATE_ADD(DATE(CURDATE() + INTERVAL (7 - WEEKDAY(CURDATE())) DAY), INTERVAL '04:00' HOUR_MINUTE),
    NOW()
)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    enabled = 1;
