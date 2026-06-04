<?php
/**
 * Site Crawler CLI — discover live URLs on a customer site. Backgrounded by
 * public/api/redirect-crawl.php. Foundation for Module 3 redirect builder.
 *
 * Usage:
 *   php agent/cron-site-crawl.php --site=N [--titles]
 *
 * Titles pass is optional; off by default for speed.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/site_crawler.php';

$opts = getopt('', ['site:', 'titles::']);
$site_id = (int)($opts['site'] ?? 0);
$titles = array_key_exists('titles', $opts);
if ($site_id <= 0) { fwrite(STDERR, "Usage: cron-site-crawl.php --site=N [--titles]\n"); exit(1); }

$db = require __DIR__ . '/../includes/db.php';
$stmt = $db->prepare("SELECT id, name, domain FROM sites WHERE id = ?");
$stmt->execute([$site_id]);
$site = $stmt->fetch();
if (!$site) { fwrite(STDERR, "site {$site_id} not found\n"); exit(2); }

$t0 = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] site crawl start — site={$site['id']} ({$site['domain']}) titles=" . ($titles ? 'on' : 'off') . "\n";

$r = sc_crawl_site($db, (int)$site['id'], (string)$site['domain'], $titles);
$dur = round(microtime(true) - $t0, 1);

if ($r['error']) {
    echo "[" . date('Y-m-d H:i:s') . "] FAILED in {$dur}s: {$r['error']}\n";
    exit(3);
}
echo "[" . date('Y-m-d H:i:s') . "] done in {$dur}s — source={$r['source']} found={$r['urls_found']} stored={$r['urls_stored']} run_id={$r['run_id']}\n";
exit(0);
