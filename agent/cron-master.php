<?php
/**
 * Master cron dispatcher — runs every minute, decides which jobs are due.
 *
 * The ONE crontab entry needed on Linode (auto-added on first successful test
 * via /dashboard/cron-jobs.php; manual install is also shown there):
 *
 *   * * * * *  /usr/bin/php8.3 /opt/contentagent/agent/cron-master.php >> /var/log/cron-master.log 2>&1
 *
 * Once that's running, all job schedules live in the `cron_schedules` table
 * (managed via the dashboard). Users add/edit/disable jobs from the UI —
 * no SSH ever needed again.
 *
 * Schedule semantics:
 *   - Each cron_schedules row has a `next_run_at` DATETIME.
 *   - At each minute, we pick rows with next_run_at <= NOW() AND enabled=1.
 *   - For each, we record a run in `cron_runs` (start + end + status), invoke
 *     agent/cron-runner.php JOB_NAME, then advance next_run_at by the row's
 *     interval (minutes / hours / days / dom).
 *
 * Backstop: if a job's last run started >2× its interval ago and is still
 * marked 'running', we mark it 'failed' so the next tick can re-fire.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cron_scheduler.php';

$db = require __DIR__ . '/../includes/db.php';

$started_at = date('Y-m-d H:i:s');

// 1. Mark zombie runs (running for >2× interval) as failed so the next tick can fire
cron_scheduler_reap_zombies($db);

// 2. Find jobs whose next_run_at is now-or-past + enabled
$due = cron_scheduler_due_jobs($db);
if (empty($due)) {
    // Quiet exit when nothing to do (this script runs every minute)
    exit(0);
}

echo "[{$started_at}] cron-master: " . count($due) . " due job(s)\n";

foreach ($due as $job) {
    cron_scheduler_dispatch($db, $job);
}
