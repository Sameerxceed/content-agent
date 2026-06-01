<?php
/**
 * Cron scheduler — the in-app job runner.
 *
 * Backs cron-master.php (single per-minute crontab entry) and the
 * /dashboard/cron-jobs.php management UI.
 *
 * Public API:
 *   cron_scheduler_due_jobs(PDO $db): array
 *   cron_scheduler_dispatch(PDO $db, array $job): void
 *   cron_scheduler_reap_zombies(PDO $db): int
 *   cron_scheduler_recompute_next_run(array $job, DateTime $from): DateTime
 *   cron_scheduler_run_now(PDO $db, int $schedule_id): int          — fire-and-forget; returns cron_runs.id
 *   cron_scheduler_all(PDO $db): array
 *   cron_scheduler_summarize_schedule(array $job): string           — human-readable "Every X at Y IST"
 *
 * Time semantics: every datetime in this module is server-clock (UTC on
 * Linode). The UI presents IST. Conversion: IST = UTC + 5:30.
 */

require_once __DIR__ . '/helpers.php';

const CRON_SCHED_DEFAULT_TIMEZONE  = 'Asia/Kolkata';   // IST
const CRON_SCHED_ZOMBIE_MULTIPLIER = 2;                // >2× the interval = mark failed

/** Returns due jobs (enabled + next_run_at <= NOW()). */
function cron_scheduler_due_jobs(PDO $db): array
{
    $stmt = $db->query("SELECT * FROM cron_schedules WHERE enabled = 1 AND next_run_at <= NOW() ORDER BY next_run_at ASC LIMIT 20");
    return $stmt->fetchAll();
}

function cron_scheduler_all(PDO $db): array
{
    $stmt = $db->query("SELECT * FROM cron_schedules ORDER BY enabled DESC, label ASC");
    return $stmt->fetchAll();
}

/**
 * Run a job: insert cron_runs row, exec the cron-runner.php script as a
 * background process, advance next_run_at on the schedule row. We
 * deliberately fire-and-forget so a slow job doesn't block subsequent
 * dispatches on the same tick.
 */
function cron_scheduler_dispatch(PDO $db, array $job): void
{
    $sched_id = (int)$job['id'];

    // Mark scheduled run started
    $db->prepare("INSERT INTO cron_runs (schedule_id, started_at, status) VALUES (?, NOW(), 'running')")
       ->execute([$sched_id]);
    $run_id = (int)$db->lastInsertId();

    $db->prepare("UPDATE cron_schedules SET last_run_at = NOW(), last_status = 'running' WHERE id = ?")
       ->execute([$sched_id]);

    // Advance next_run_at BEFORE firing the script so a long-running job
    // doesn't queue up multiple copies of itself.
    $next = cron_scheduler_recompute_next_run($job, new DateTime('now', new DateTimeZone('UTC')));
    $db->prepare("UPDATE cron_schedules SET next_run_at = ? WHERE id = ?")
       ->execute([$next->format('Y-m-d H:i:s'), $sched_id]);

    // Fire the underlying cron-runner.php JOB script in background
    $job_name = (string)$job['job_name'];
    $php      = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
    $script   = realpath(__DIR__ . '/../agent/cron-runner.php');
    $log      = (config('log_path') ?: __DIR__ . '/../storage/logs') . '/cron-' . $job_name . '.log';
    $sentinel = (config('log_path') ?: __DIR__ . '/../storage/logs') . '/cron-runs/run-' . $run_id;

    @mkdir(dirname($sentinel), 0755, true);

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf('start /B "" "%s" "%s" %s --run=%d', $php, $script, escapeshellarg($job_name), $run_id);
        pclose(popen($cmd, 'r'));
    } else {
        // Use a wrapper that captures exit + writes the sentinel so we can detect completion.
        $cmd = sprintf(
            'nohup sh -c %s >> %s 2>&1 &',
            escapeshellarg(sprintf(
                '%s %s %s --run=%d ; echo "exit=$?" > %s',
                escapeshellarg($php),
                escapeshellarg($script),
                escapeshellarg($job_name),
                $run_id,
                escapeshellarg($sentinel)
            )),
            escapeshellarg($log)
        );
        exec($cmd);
    }
}

/**
 * Mark old running rows as failed if they've been "running" for more than
 * 2× their interval (probably crashed mid-run).
 */
function cron_scheduler_reap_zombies(PDO $db): int
{
    $count = 0;
    $rows = $db->query("SELECT s.id AS sched_id, s.interval_kind, s.interval_value, r.id AS run_id, r.started_at
        FROM cron_runs r
        JOIN cron_schedules s ON r.schedule_id = s.id
        WHERE r.status = 'running'
          AND r.finished_at IS NULL
          AND r.started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchAll();
    foreach ($rows as $r) {
        // Hour-based heuristic: anything stuck >1 hour is dead. (Most jobs finish in minutes.)
        $db->prepare("UPDATE cron_runs SET status='failed', error='Reaped — never reported completion', finished_at=NOW() WHERE id=?")
           ->execute([(int)$r['run_id']]);
        $db->prepare("UPDATE cron_schedules SET last_status='failed', last_error='Last run never completed (reaped after 1h)' WHERE id=?")
           ->execute([(int)$r['sched_id']]);
        $count++;
    }
    return $count;
}

/**
 * Compute the next next_run_at from a schedule row. Server clock is UTC;
 * run_hour_ist is interpreted in Asia/Kolkata then converted.
 */
function cron_scheduler_recompute_next_run(array $job, DateTime $from): DateTime
{
    $kind  = (string)$job['interval_kind'];
    $value = max(1, (int)($job['interval_value'] ?? 1));
    $now   = clone $from;

    if ($kind === 'minutes') {
        return $now->modify('+' . $value . ' minutes');
    }
    if ($kind === 'hours') {
        return $now->modify('+' . $value . ' hours');
    }

    // For daily / weekly / monthly we anchor to the IST hour/minute then bump
    $ist_hour = isset($job['run_hour_ist']) ? (int)$job['run_hour_ist'] : 0;
    $ist_min  = isset($job['run_minute'])   ? (int)$job['run_minute']   : 0;

    $ist_now = (clone $from)->setTimezone(new DateTimeZone(CRON_SCHED_DEFAULT_TIMEZONE));
    $candidate = (clone $ist_now)->setTime($ist_hour, $ist_min);

    if ($kind === 'days') {
        // First future occurrence of ($ist_hour:$ist_min) at the interval cadence
        if ($candidate <= $ist_now) $candidate->modify('+' . $value . ' days');
        else if ($value > 1)         $candidate->modify('+' . ($value - 1) . ' days');
    } elseif ($kind === 'weekly') {
        $dow = isset($job['run_day_of_week']) ? (int)$job['run_day_of_week'] : 0;
        // Walk forward to next matching dow at or after current time
        $diff = ($dow - (int)$candidate->format('w') + 7) % 7;
        $candidate->modify('+' . $diff . ' days');
        if ($candidate <= $ist_now) $candidate->modify('+7 days');
    } elseif ($kind === 'monthly') {
        $dom = isset($job['run_day_of_month']) ? max(1, min(28, (int)$job['run_day_of_month'])) : 1;
        $candidate->setDate((int)$candidate->format('Y'), (int)$candidate->format('m'), $dom);
        if ($candidate <= $ist_now) {
            $candidate->modify('first day of next month')->setDate(
                (int)$candidate->format('Y'),
                (int)$candidate->format('m'),
                $dom
            )->setTime($ist_hour, $ist_min);
        }
    }

    return $candidate->setTimezone(new DateTimeZone('UTC'));
}

/** Fire one schedule immediately (for the "Run now" button). Returns cron_runs.id. */
function cron_scheduler_run_now(PDO $db, int $schedule_id): int
{
    $stmt = $db->prepare("SELECT * FROM cron_schedules WHERE id = ?");
    $stmt->execute([$schedule_id]);
    $job = $stmt->fetch();
    if (!$job) throw new RuntimeException("Schedule not found");

    // Capture state before dispatch so we can read the run_id back
    $db->prepare("INSERT INTO cron_runs (schedule_id, started_at, status) VALUES (?, NOW(), 'running')")
       ->execute([$schedule_id]);
    $run_id = (int)$db->lastInsertId();
    $db->prepare("UPDATE cron_schedules SET last_run_at = NOW(), last_status = 'running' WHERE id = ?")
       ->execute([$schedule_id]);

    $php    = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
    $script = realpath(__DIR__ . '/../agent/cron-runner.php');
    $log    = (config('log_path') ?: __DIR__ . '/../storage/logs') . '/cron-' . $job['job_name'] . '.log';
    @mkdir(dirname($log), 0755, true);

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf('start /B "" "%s" "%s" %s --run=%d', $php, $script, escapeshellarg($job['job_name']), $run_id);
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = sprintf('nohup %s %s %s --run=%d >> %s 2>&1 &',
            escapeshellarg($php), escapeshellarg($script), escapeshellarg($job['job_name']), $run_id, escapeshellarg($log));
        exec($cmd);
    }

    return $run_id;
}

/** Human-readable summary like "Daily at 02:00 IST" / "Monthly on day 1 at 03:00 IST". */
function cron_scheduler_summarize_schedule(array $job): string
{
    $kind  = (string)$job['interval_kind'];
    $value = max(1, (int)($job['interval_value'] ?? 1));
    $hour  = isset($job['run_hour_ist']) ? (int)$job['run_hour_ist'] : null;
    $min   = isset($job['run_minute'])   ? (int)$job['run_minute']   : 0;
    $time  = $hour !== null ? sprintf('%02d:%02d IST', $hour, $min) : null;

    if ($kind === 'minutes') return "Every {$value} minute" . ($value === 1 ? '' : 's');
    if ($kind === 'hours')   return "Every {$value} hour"   . ($value === 1 ? '' : 's');
    if ($kind === 'days') {
        return ($value === 1 ? "Daily" : "Every {$value} days") . ($time ? " at {$time}" : '');
    }
    if ($kind === 'weekly') {
        $dow = isset($job['run_day_of_week']) ? (int)$job['run_day_of_week'] : 0;
        $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        return "Weekly on {$days[$dow]}" . ($time ? " at {$time}" : '');
    }
    if ($kind === 'monthly') {
        $dom = isset($job['run_day_of_month']) ? (int)$job['run_day_of_month'] : 1;
        $suffix = match ($dom % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
        if (in_array($dom % 100, [11, 12, 13], true)) $suffix = 'th';
        return "Monthly on the {$dom}{$suffix}" . ($time ? " at {$time}" : '');
    }
    return "Unknown schedule";
}
