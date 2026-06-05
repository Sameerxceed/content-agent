<?php
/**
 * GSC daily performance fetch — runs nightly, pulls yesterday's data
 * for every site that has google_search_console integration active.
 *
 * Usage:
 *   php agent/cron-gsc-fetch.php [--site=N] [--backfill=30]
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gsc_api.php';

$opts = getopt('', ['site::', 'backfill::']);
$single = isset($opts['site']) ? (int)$opts['site'] : null;
$backfill = isset($opts['backfill']) ? (int)$opts['backfill'] : 1;

$db = require __DIR__ . '/../includes/db.php';

if ($single) {
    $sites = [$single];
} else {
    $stmt = $db->query("SELECT site_id FROM integrations
        WHERE platform = 'google_search_console' AND is_active = 1");
    $sites = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

foreach ($sites as $site_id) {
    $t0 = microtime(true);
    echo "[" . date('Y-m-d H:i:s') . "] gsc fetch site={$site_id} backfill={$backfill}d\n";
    $r = gsc_fetch_performance($db, (int)$site_id, $backfill);
    $dur = round(microtime(true) - $t0, 1);
    if (!empty($r['success'])) {
        echo "  done in {$dur}s — days={$r['days_fetched']} rows={$r['rows_upserted']}\n";
    } else {
        echo "  FAILED in {$dur}s — " . ($r['error'] ?? 'unknown') . "\n";
    }
}
exit(0);
