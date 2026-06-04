<?php
/**
 * Wayback Harvester CLI — pulls a site's full archive URL history into
 * historical_urls. Backgrounded by public/api/wayback-start.php.
 *
 * Usage:
 *   php agent/cron-wayback-harvest.php --site=N
 *   php agent/cron-wayback-harvest.php --site=N --run=R   (resume an existing wayback_runs row)
 *
 * Designed to be long-running (1-15 min depending on domain age + size).
 * NEVER call from a PHP-FPM request — only from CLI or backgrounded exec.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/wayback_harvester.php';

$opts = getopt('', ['site:', 'run::']);
$site_id = (int)($opts['site'] ?? 0);
if ($site_id <= 0) {
    fwrite(STDERR, "Usage: cron-wayback-harvest.php --site=N [--run=R]\n");
    exit(1);
}

$db = require __DIR__ . '/../includes/db.php';

$stmt = $db->prepare("SELECT id, name, domain FROM sites WHERE id = ?");
$stmt->execute([$site_id]);
$site = $stmt->fetch();
if (!$site) { fwrite(STDERR, "site {$site_id} not found\n"); exit(2); }

$t0 = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] wayback harvest start — site={$site['id']} ({$site['domain']})\n";

$res = wayback_harvest_site($db, (int)$site['id'], (string)$site['domain'], function (array $p) {
    echo "  page {$p['page']} → fetched={$p['fetched']} new={$p['new']} more=" . ($p['resume'] ? 'yes' : 'no') . "\n";
});

$dur = round(microtime(true) - $t0, 1);
if ($res['error']) {
    echo "[" . date('Y-m-d H:i:s') . "] FAILED in {$dur}s: {$res['error']}\n";
    exit(3);
}
echo "[" . date('Y-m-d H:i:s') . "] done in {$dur}s — urls_fetched={$res['urls_fetched']} new={$res['urls_new']} pages={$res['pages']} run_id={$res['run_id']}\n";
exit(0);
