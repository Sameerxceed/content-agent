<?php
/**
 * Monthly content-gap re-run for every site with active competitors.
 * Delegates to the existing CLI agent at agent/gap-analysis.php so we get
 * the same memory-safe batched scraping and the same gap_runs row format.
 *
 * Run via: cron-runner.php gap-analysis
 */

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

$sites = cron_get_sites($db, $site_id_filter);
echo "Gap analysis re-run for " . count($sites) . " sites\n";

$php_bin = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
$script  = realpath(__DIR__ . '/gap-analysis.php');

foreach ($sites as $site) {
    $sid = (int)$site['id'];

    // Need at least 2 active competitors for gap analysis to be useful
    $stmt = $db->prepare("SELECT COUNT(*) FROM competitors WHERE site_id = ? AND status = 'active'");
    $stmt->execute([$sid]);
    if ((int)$stmt->fetchColumn() < 2) {
        echo "  skip #{$sid}: fewer than 2 active competitors\n";
        continue;
    }

    // Don't run if one already in flight
    $stmt = $db->prepare("SELECT id FROM gap_runs WHERE site_id = ? AND status IN ('queued','running') ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([$sid]);
    if ($stmt->fetchColumn()) {
        echo "  skip #{$sid}: gap analysis already in flight\n";
        continue;
    }

    echo "  triggering gap analysis for #{$sid} {$site['domain']}\n";

    // Snapshot existing open-gap count for delta
    $stmt = $db->prepare("SELECT COUNT(*) FROM content_gaps WHERE site_id = ? AND status = 'open'");
    $stmt->execute([$sid]);
    $prev_open = (int)$stmt->fetchColumn();

    // Create the gap_runs row first so gap-analysis.php can update it
    $db->prepare('INSERT INTO gap_runs (site_id, status, current_step) VALUES (?, "queued", "Triggered by cron")')->execute([$sid]);
    $run_id = (int)$db->lastInsertId();

    // Run synchronously here (cron job has time — no need to fork further).
    // The output goes to the cron log.
    $cmd = escapeshellarg($php_bin) . ' ' . escapeshellarg($script) . " --site={$sid} --run={$run_id} 2>&1";
    $out = shell_exec($cmd);
    echo $out;

    // Read result and alert if new gaps appeared
    $stmt = $db->prepare("SELECT COUNT(*) FROM content_gaps WHERE site_id = ? AND status = 'open'");
    $stmt->execute([$sid]);
    $now_open = (int)$stmt->fetchColumn();
    $new_gaps = max(0, $now_open - $prev_open);

    if ($new_gaps > 0) {
        // Sample some top new gaps
        $stmt = $db->prepare("SELECT topic FROM content_gaps WHERE site_id = ? AND status = 'open' ORDER BY competitor_count DESC, estimated_demand DESC LIMIT 5");
        $stmt->execute([$sid]);
        $samples = $stmt->fetchAll(PDO::FETCH_COLUMN);
        alert_create($db, $sid, 'content_gaps_found',
            "{$new_gaps} new content gap" . ($new_gaps > 1 ? 's' : '') . ' detected',
            "Top gaps:\n - " . implode("\n - ", $samples),
            '/dashboard/content-gaps.php?site=' . $sid,
            'info',
            ['new' => $new_gaps]
        );
    }

    // Log a top-level agent_runs row separately (the gap-analysis script uses gap_runs)
    $db->prepare('INSERT INTO agent_runs (site_id, job_type, status, current_step, result_summary, triggered_by, started_at, finished_at) VALUES (?, ?, "done", "Done", ?, "cron", NOW(), NOW())')
        ->execute([$sid, 'gap_analysis', json_encode(['prev_open' => $prev_open, 'now_open' => $now_open, 'new_gaps' => $new_gaps])]);
}

echo "Gap analysis re-run complete.\n";
