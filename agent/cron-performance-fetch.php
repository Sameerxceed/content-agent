<?php
/**
 * Daily performance snapshot per site.
 * - GSC organic page-level metrics → post_performance (channel=cms)
 * - Social channel metrics from post_channels.metrics → post_performance (channel=linkedin/twitter/reddit)
 *
 * Run via: cron-runner.php performance-fetch
 */

require_once __DIR__ . '/../includes/performance.php';

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

$sites = cron_get_sites($db, $site_id_filter);
echo "Performance fetch for " . count($sites) . " sites\n";

foreach ($sites as $site) {
    $sid = (int)$site['id'];
    echo "  site #{$sid} {$site['domain']}...\n";

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid) {
        $organic = performance_snapshot_organic($db, $sid);
        $social  = performance_snapshot_social($db, $sid);
        return [
            'organic_rows' => $organic['rows'] ?? 0,
            'organic_ok'   => !empty($organic['success']),
            'organic_err'  => $organic['error'] ?? null,
            'social_rows'  => $social['rows'] ?? 0,
        ];
    });
}

echo "Performance fetch complete.\n";
