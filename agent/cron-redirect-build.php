<?php
/**
 * Redirect Map Builder CLI — for each dead historical URL, pick the best
 * living target via heuristic + Claude fuzzy match. Idempotent.
 *
 * Usage:
 *   php agent/cron-redirect-build.php --site=N [--limit=200]
 *
 * Requires the site crawler to have populated current_site_urls already.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/redirect_map_builder.php';

$opts = getopt('', ['site:', 'limit::']);
$site_id = (int)($opts['site'] ?? 0);
$limit   = isset($opts['limit']) ? (int)$opts['limit'] : null;
if ($site_id <= 0) { fwrite(STDERR, "Usage: cron-redirect-build.php --site=N [--limit=N]\n"); exit(1); }

$db = require __DIR__ . '/../includes/db.php';
$stmt = $db->prepare("SELECT id, name FROM sites WHERE id = ?");
$stmt->execute([$site_id]);
$site = $stmt->fetch();
if (!$site) { fwrite(STDERR, "site {$site_id} not found\n"); exit(2); }

$t0 = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] redirect build start — site={$site['id']} ({$site['name']}) limit=" . ($limit ?? 'all') . "\n";

$r = rmb_build_map($db, (int)$site['id'], $limit, function (array $p) {
    if ($p['processed'] % 10 === 0) {
        echo "  processed={$p['processed']} hits={$p['hits']} no_target={$p['no_target']}\n";
    }
});
$dur = round(microtime(true) - $t0, 1);

if (!empty($r['error'])) {
    echo "[" . date('Y-m-d H:i:s') . "] FAILED in {$dur}s: {$r['error']}\n";
    exit(3);
}
echo "[" . date('Y-m-d H:i:s') . "] done in {$dur}s — processed={$r['processed']} hits={$r['hits']} no_target={$r['no_target']} errors={$r['errors']} run_id={$r['run_id']}\n";
exit(0);
