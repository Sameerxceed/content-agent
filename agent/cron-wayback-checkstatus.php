<?php
/**
 * Wayback Live-Check CLI — HEAD each historical URL to fill in current_status_code.
 * Backgrounded by public/api/wayback-checkstatus.php.
 *
 * Loops in batches of 500 so even a multi-thousand-URL site finishes in one run.
 * 0.8s between requests = polite even against small customer servers.
 *
 * Usage:
 *   php agent/cron-wayback-checkstatus.php --site=N [--batch=500]
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/wayback_harvester.php';

$opts = getopt('', ['site:', 'batch::']);
$site_id = (int)($opts['site'] ?? 0);
$batch   = max(50, (int)($opts['batch'] ?? 500));
if ($site_id <= 0) { fwrite(STDERR, "Usage: cron-wayback-checkstatus.php --site=N [--batch=500]\n"); exit(1); }

$db = require __DIR__ . '/../includes/db.php';

$stmt = $db->prepare("SELECT id, name, domain FROM sites WHERE id = ?");
$stmt->execute([$site_id]);
$site = $stmt->fetch();
if (!$site) { fwrite(STDERR, "site {$site_id} not found\n"); exit(2); }

$t0 = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] live-check start — site={$site['id']} ({$site['domain']}) batch={$batch}\n";

$total_checked = 0; $total_dead = 0; $total_alive = 0; $total_errors = 0;
while (true) {
    $r = wayback_check_pending($db, $site_id, $batch);
    $total_checked += $r['checked'];
    $total_dead    += $r['dead'];
    $total_alive   += $r['alive'];
    $total_errors  += $r['errors'];
    echo "  batch: checked={$r['checked']} dead={$r['dead']} alive={$r['alive']} errors={$r['errors']}\n";
    if ($r['checked'] < $batch) break; // no more pending
}

$dur = round(microtime(true) - $t0, 1);
echo "[" . date('Y-m-d H:i:s') . "] done in {$dur}s — checked={$total_checked} dead={$total_dead} alive={$total_alive} errors={$total_errors}\n";
exit(0);
