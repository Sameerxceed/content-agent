-- Register weekly PSI baseline cron. Runs Mondays 05:30 IST (= 00:00 UTC).
-- Picks a low-traffic window separate from the Sunday Website Hygiene job
-- so the two heavy-network jobs don't compete.

INSERT INTO cron_schedules (
    job_name, label, description,
    interval_kind, interval_value, run_hour_ist, run_minute, run_day_of_week,
    enabled, next_run_at, created_at
) VALUES (
    'psi-baseline',
    'Page Speed: weekly baseline',
    'Weekly Core Web Vitals snapshot for each site''s curated baseline URLs (10 URLs × mobile + desktop). Trend chart lives on the Page Speed dashboard; regressions surface in alerts.',
    'weekly', 1, 5, 30, 1,  -- Monday 05:30 IST
    1,
    DATE_ADD(DATE(CURDATE() + INTERVAL (8 - WEEKDAY(CURDATE())) DAY), INTERVAL '05:30' HOUR_MINUTE),
    NOW()
)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description), enabled = 1;
