-- Weekly schema audit cron. Tuesday 04:30 IST (separate window from Sunday Hygiene
-- + Monday PSI so the three heavy network jobs don't compete).

INSERT INTO cron_schedules (
    job_name, label, description,
    interval_kind, interval_value, run_hour_ist, run_minute, run_day_of_week,
    enabled, next_run_at, created_at
) VALUES (
    'schema-audit',
    'Schema audit: JSON-LD persistence check',
    'Weekly per-site check that the Schema.org JSON-LD we emit on published posts is still present on the live site. Catches theme updates / CMS plugin conflicts that silently strip <script type="application/ld+json"> tags.',
    'weekly', 1, 4, 30, 2,  -- Tuesday 04:30 IST
    1,
    DATE_ADD(DATE(CURDATE() + INTERVAL (9 - WEEKDAY(CURDATE())) DAY), INTERVAL '04:30' HOUR_MINUTE),
    NOW()
)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description), enabled = 1;
