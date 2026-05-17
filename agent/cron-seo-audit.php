<?php
/**
 * Weekly SEO audit — re-crawls every active site so dashboards stay honest
 * after content edits or new pages land. Without this, the score on the
 * overview can sit stale for weeks until someone clicks "Re-scan."
 *
 * Run via: cron-runner.php seo-audit
 *
 * Cost: zero external API spend — the auditor is local crawling + Haiku calls
 * already budgeted in the per-site agent allowance.
 */

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

$sites = cron_get_sites($db, $site_id_filter);
echo "SEO audit for " . count($sites) . " sites\n";

$php_bin = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
$auditor = realpath(__DIR__ . '/seo-auditor.php');

foreach ($sites as $site) {
    $sid    = (int)$site['id'];
    $domain = trim($site['domain']);
    if ($domain === '') { echo "  skip #{$sid}: no domain\n"; continue; }

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid, $php_bin, $auditor) {
        // Reuse the same CLI auditor the on-demand UI shells out to, so cron
        // and manual scans produce identical seo_audits rows.
        $cmd = "\"{$php_bin}\" \"{$auditor}\" --site={$sid} --max-pages=30 2>&1";
        $output = shell_exec($cmd);

        // Read the audit row the auditor just wrote
        $stmt = $db->prepare('SELECT score, total_issues, pages_crawled FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
        $stmt->execute([$sid]);
        $a = $stmt->fetch();

        if (!$a) {
            throw new RuntimeException('Auditor did not produce a seo_audits row. Output: ' . substr((string)$output, 0, 500));
        }

        echo "    site #{$sid}: score={$a['score']}/100, issues={$a['total_issues']}, pages={$a['pages_crawled']}\n";
        return [
            'score'         => (int)$a['score'],
            'total_issues'  => (int)$a['total_issues'],
            'pages_crawled' => (int)$a['pages_crawled'],
        ];
    });
}

echo "SEO audit complete.\n";
