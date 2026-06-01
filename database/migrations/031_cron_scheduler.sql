-- Cron scheduler — replaces hand-maintained crontab entries on Linode with
-- an in-app scheduling table. ONE master crontab line runs cron-master.php
-- every minute; cron-master.php reads this table to decide what to fire.
--
-- After this is wired, users add/edit/disable cron jobs from
-- /dashboard/cron-jobs.php with no SSH access required.

CREATE TABLE IF NOT EXISTS `cron_schedules` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `job_name`        VARCHAR(80)     NOT NULL  COMMENT 'matches cron-runner.php job names, e.g. plan-autopilot',
    `label`           VARCHAR(160)    NOT NULL  COMMENT 'human-readable label shown in the UI',
    `description`     TEXT            DEFAULT NULL,
    `interval_kind`   ENUM('minutes','hours','days','weekly','monthly') NOT NULL DEFAULT 'days',
    `interval_value`  SMALLINT UNSIGNED NOT NULL DEFAULT 1  COMMENT 'every N of interval_kind',
    `run_hour_ist`    TINYINT UNSIGNED DEFAULT NULL  COMMENT 'hour-of-day in IST for daily/weekly/monthly jobs (0-23)',
    `run_minute`      TINYINT UNSIGNED DEFAULT 0     COMMENT 'minute-of-hour 0-59',
    `run_day_of_week` TINYINT UNSIGNED DEFAULT NULL  COMMENT 'for weekly: 0=Sunday ... 6=Saturday',
    `run_day_of_month` TINYINT UNSIGNED DEFAULT NULL COMMENT 'for monthly: 1-28',
    `enabled`         TINYINT(1)      NOT NULL DEFAULT 1,
    `next_run_at`     DATETIME        NOT NULL,
    `last_run_at`     DATETIME        DEFAULT NULL,
    `last_status`     ENUM('queued','running','done','failed') DEFAULT NULL,
    `last_duration_seconds` INT UNSIGNED DEFAULT NULL,
    `last_error`      TEXT            DEFAULT NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_jobname` (`job_name`),
    KEY `idx_due` (`enabled`, `next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cron_runs` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `schedule_id` INT UNSIGNED   NOT NULL,
    `started_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at` DATETIME       DEFAULT NULL,
    `status`     ENUM('running','done','failed') NOT NULL DEFAULT 'running',
    `output`     MEDIUMTEXT      DEFAULT NULL COMMENT 'last 100KB of stdout from cron-runner.php',
    `error`      TEXT            DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_schedule` (`schedule_id`, `started_at`),
    CONSTRAINT `fk_run_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `cron_schedules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the two Content Plan jobs (others get migrated from manual crontab
-- entries as users adopt them; nothing forced).
-- Times are in UTC because cron runs in server timezone. Linode is UTC.
-- IST = UTC + 5:30, so 02:00 IST = 20:30 UTC (previous day).

-- plan-autopilot: every day at 02:00 IST → 20:30 UTC
INSERT IGNORE INTO `cron_schedules`
    (`job_name`, `label`, `description`, `interval_kind`, `interval_value`,
     `run_hour_ist`, `run_minute`, `enabled`, `next_run_at`)
VALUES
    ('plan-autopilot',
     'Content Plan: Autopilot drafting',
     'Drafts plan items publishing 5-7 days out. Generates blog body + FAQ + social variants + schema + hero image in one Claude call. Creates draft posts ready for your review.',
     'days', 1, 2, 0, 1, NOW());

-- plan-monthly-review: 1st of each month at 03:00 IST → 21:30 UTC on the last day of prev month
INSERT IGNORE INTO `cron_schedules`
    (`job_name`, `label`, `description`, `interval_kind`, `interval_value`,
     `run_hour_ist`, `run_minute`, `run_day_of_month`, `enabled`, `next_run_at`)
VALUES
    ('plan-monthly-review',
     'Content Plan: Monthly performance review',
     'Analyses the last 30 days of published posts vs forecast, proposes pipeline adjustments based on what actually ranked. You approve the changes on the Plan Review page.',
     'monthly', 1, 3, 0, 1, 1, NOW());
