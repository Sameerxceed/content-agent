-- Weekly content freshness audit. Wednesday 04:30 IST so it lands after the
-- Tuesday schema audit. Each job has its own window.

INSERT INTO cron_schedules (
    job_name, label, description,
    interval_kind, interval_value, run_hour_ist, run_minute, run_day_of_week,
    enabled, next_run_at, created_at
) VALUES (
    'content-freshness',
    'Content freshness audit',
    'Weekly per-site scan of published posts. Combines age, stale-year mentions, and traffic-decline signals into a staleness score. Posts at >=60 surface as refresh candidates that the user can one-click into the Content Plan.',
    'weekly', 1, 4, 30, 3,  -- Wednesday 04:30 IST
    1,
    DATE_ADD(DATE(CURDATE() + INTERVAL (10 - WEEKDAY(CURDATE())) DAY), INTERVAL '04:30' HOUR_MINUTE),
    NOW()
)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description), enabled = 1;
