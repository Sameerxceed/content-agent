<?php
/**
 * Cron dispatcher — single entry point for all scheduled jobs.
 *
 * Usage: php agent/cron-runner.php JOB_NAME [--site=ID]
 *
 * JOB_NAME ∈ {
 *   gsc-sync, competitor-redetect, competitor-pages-check, brand-monitor,
 *   ai-visibility, gap-analysis, weekly-digest, news-scrape
 * }
 *
 * Crontab example (Linux, www-data user, IST schedule converted to UTC):
 *
 *   # Daily 9 AM IST = 3:30 AM UTC
 *   30 3 * * * /usr/bin/php8.3 /opt/contentagent/agent/cron-runner.php brand-monitor
 *   30 3 * * * /usr/bin/php8.3 /opt/contentagent/agent/cron-runner.php competitor-pages-check
 *
 *   # Weekly Monday 4 AM IST
 *   0 22 * * 0 /usr/bin/php8.3 /opt/contentagent/agent/cron-runner.php gsc-sync
 *   30 22 * * 0 /usr/bin/php8.3 /opt/contentagent/agent/cron-runner.php competitor-redetect
 *   0 23 * * 0 /usr/bin/php8.3 /opt/contentagent/agent/cron-runner.php ai-visibility
 *   0 4 * * 1 /usr/bin/php8.3 /opt/contentagent/agent/cron-runner.php weekly-digest
 *
 *   # Monthly 1st 5 AM IST
 *   30 23 1 * * /usr/bin/php8.3 /opt/contentagent/agent/cron-runner.php gap-analysis
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/alerts.php';

$db = require __DIR__ . '/../includes/db.php';

/**
 * Helper used by per-site cron scripts: start an agent_runs row, run a closure,
 * then mark done/failed with the result summary.
 */
function cron_run_site_job(PDO $db, int $site_id, string $job_type, callable $fn): void
{
    $stmt = $db->prepare('INSERT INTO agent_runs (site_id, job_type, status, current_step, triggered_by, started_at) VALUES (?, ?, "running", "Starting", "cron", NOW())');
    $stmt->execute([$site_id, $job_type]);
    $run_id = (int)$db->lastInsertId();

    try {
        $summary = $fn($run_id) ?: [];
        $db->prepare('UPDATE agent_runs SET status = "done", progress = 100, current_step = "Done", result_summary = ?, finished_at = NOW() WHERE id = ?')
            ->execute([json_encode($summary), $run_id]);
    } catch (\Throwable $e) {
        $db->prepare('UPDATE agent_runs SET status = "failed", error = ?, finished_at = NOW() WHERE id = ?')
            ->execute([$e->getMessage(), $run_id]);
        echo "  ERROR on site {$site_id}: " . $e->getMessage() . "\n";
    }
}

/** Get sites to process — either one if filter is set, or all active sites. */
function cron_get_sites(PDO $db, ?int $site_id_filter): array
{
    if ($site_id_filter) {
        $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND is_active = 1');
        $stmt->execute([$site_id_filter]);
    } else {
        $stmt = $db->prepare('SELECT * FROM sites WHERE is_active = 1');
        $stmt->execute();
    }
    return $stmt->fetchAll();
}

$job = $argv[1] ?? '';

// Parse --site=N from anywhere in argv (PHP's getopt stops at first positional arg)
$site_id_filter = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--site=(\d+)$/', $a, $m)) { $site_id_filter = (int)$m[1]; break; }
}

$valid_jobs = [
    'gsc-sync', 'competitor-redetect', 'competitor-pages-check', 'brand-monitor',
    'ai-visibility', 'gap-analysis', 'weekly-digest', 'news-scrape',
    'publish', 'performance-fetch',
];

if (!in_array($job, $valid_jobs, true)) {
    echo "Usage: php cron-runner.php JOB [--site=ID]\n";
    echo "Jobs: " . implode(', ', $valid_jobs) . "\n";
    exit(1);
}

$script = __DIR__ . '/cron-' . $job . '.php';
if (!file_exists($script)) {
    echo "Script not found: {$script}\n";
    exit(1);
}

$job_type = str_replace('-', '_', $job);

echo "[" . date('Y-m-d H:i:s') . "] cron-runner: starting {$job}";
if ($site_id_filter) echo " (site={$site_id_filter})";
echo "\n";

$start_time = microtime(true);
try {
    require $script;
    $duration = round(microtime(true) - $start_time, 2);
    echo "[" . date('Y-m-d H:i:s') . "] cron-runner: {$job} finished in {$duration}s\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] cron-runner: {$job} FAILED — " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
