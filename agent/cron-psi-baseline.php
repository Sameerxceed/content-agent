<?php
/**
 * PSI baseline CLI — runs page-speed + CWV snapshot for a site's curated URLs.
 *
 * Usage:
 *   php agent/cron-psi-baseline.php --site=N      (run for one site)
 *   php agent/cron-psi-baseline.php               (run for every active site)
 *
 * If the site has no rows in cwv_baseline_urls yet, this seeds them from
 * current_site_urls first. Long-running — at 2 devices × 10 URLs × ~15s per
 * PSI call + 1.5s delays, ~5-7 minutes per site.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/psi_runner.php';

$opts = getopt('', ['site::']);
$site_filter = isset($opts['site']) ? (int)$opts['site'] : 0;

$db = require __DIR__ . '/../includes/db.php';

$where = "is_active = 1";
$args  = [];
if ($site_filter) { $where .= " AND id = ?"; $args[] = $site_filter; }

$sites = $db->prepare("SELECT id, name, domain FROM sites WHERE {$where} ORDER BY id");
$sites->execute($args);
$rows = $sites->fetchAll();

$t0 = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] psi baseline start — sites=" . count($rows) . "\n";

foreach ($rows as $site) {
    $sid = (int)$site['id'];
    $domain = (string)$site['domain'];
    if ($domain === '') continue;
    echo "\n— site={$sid} {$site['name']} ({$domain}) —\n";

    // Seed if no baseline URLs yet
    $existing = (int)$db->query("SELECT COUNT(*) FROM cwv_baseline_urls WHERE site_id = {$sid}")->fetchColumn();
    if ($existing === 0) {
        $picks = psi_pick_baseline_urls($db, $sid, $domain);
        if (empty($picks)) { echo "  no current_site_urls to seed from — run a crawl first; skipping.\n"; continue; }
        $added = psi_seed_baseline_urls($db, $sid, $picks);
        echo "  seeded {$added} baseline URLs\n";
    }

    try {
        $r = psi_run_baseline($db, $sid);
        echo "  baseline: checked={$r['checked']} ok={$r['success']} errors={$r['errors']}\n";
    } catch (Throwable $e) {
        echo "  baseline FAIL: " . $e->getMessage() . "\n";
    }
}

$dur = round(microtime(true) - $t0, 1);
echo "\n[" . date('Y-m-d H:i:s') . "] psi baseline done in {$dur}s\n";
exit(0);
