<?php
/**
 * Daily news scrape — pulls fresh items from each site's configured RSS feeds.
 * Reuses the existing agent/news-scraper.php logic. Runs sequentially per site.
 *
 * Run via: cron-runner.php news-scrape
 */

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

$sites = cron_get_sites($db, $site_id_filter);
echo "News scrape for " . count($sites) . " sites\n";

$php_bin = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
$script  = realpath(__DIR__ . '/news-scraper.php');

foreach ($sites as $site) {
    $sid = (int)$site['id'];
    $rss = json_decode($site['rss_feeds'] ?? '[]', true) ?: [];
    if (empty($rss)) {
        echo "  skip #{$sid}: no RSS feeds configured\n";
        continue;
    }

    echo "  scraping news for #{$sid} {$site['domain']}...\n";

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid, $php_bin, $script) {
        $cmd = escapeshellarg($php_bin) . ' ' . escapeshellarg($script) . " --site={$sid} 2>&1";
        $out = shell_exec($cmd);
        echo $out;
        return ['output_preview' => mb_substr((string)$out, 0, 500)];
    });
}

echo "News scrape complete.\n";
