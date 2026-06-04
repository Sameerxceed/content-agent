<?php
/**
 * Content Freshness CLI — re-audits every published post per site.
 *
 * Usage:
 *   php agent/cron-content-freshness.php --site=N
 *   php agent/cron-content-freshness.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/content_freshness.php';

$opts = getopt('', ['site::']);
$site_filter = isset($opts['site']) ? (int)$opts['site'] : 0;

$db = require __DIR__ . '/../includes/db.php';

$where = "is_active = 1";
$args  = [];
if ($site_filter) { $where .= " AND id = ?"; $args[] = $site_filter; }

$sites = $db->prepare("SELECT id, name FROM sites WHERE {$where} ORDER BY id");
$sites->execute($args);
$rows = $sites->fetchAll();

$t0 = microtime(true);
foreach ($rows as $site) {
    $sid = (int)$site['id'];
    echo "[" . date('Y-m-d H:i:s') . "] freshness audit site={$sid} ({$site['name']})\n";
    try {
        $r = cf_audit_site($db, $sid);
        echo "  audited={$r['audited']} needs_refresh={$r['needs_refresh']}\n";
    } catch (Throwable $e) {
        echo "  FAIL: " . $e->getMessage() . "\n";
    }
}
$dur = round(microtime(true) - $t0, 1);
echo "[" . date('Y-m-d H:i:s') . "] done in {$dur}s\n";
exit(0);
