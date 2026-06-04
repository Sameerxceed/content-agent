<?php
/**
 * Schema Audit CLI — re-checks every tracked URL's JSON-LD persistence
 * for one site (or all sites if no --site flag).
 *
 * Also registers any newly-published posts as URLs to track.
 *
 * Usage:
 *   php agent/cron-schema-audit.php --site=N
 *   php agent/cron-schema-audit.php   (all active sites)
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/schema_auditor.php';

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
echo "[" . date('Y-m-d H:i:s') . "] schema audit start — sites=" . count($rows) . "\n";

$grand = ['checked' => 0, 'ok' => 0, 'degraded' => 0, 'broken' => 0, 'fetch_failed' => 0];
foreach ($rows as $site) {
    $sid = (int)$site['id'];
    echo "\n— site={$sid} {$site['name']} —\n";

    // (re)register posts so newly-published items get tracked
    $reg = sch_register_posts($db, $sid);
    echo "  registered={$reg} post URLs\n";

    try {
        $r = sch_audit_site($db, $sid, 200);
        foreach ($r as $k => $v) $grand[$k] = ($grand[$k] ?? 0) + $v;
        echo "  audit: checked={$r['checked']} ok={$r['ok']} degraded={$r['degraded']} broken={$r['broken']} fetch_failed={$r['fetch_failed']}\n";
    } catch (Throwable $e) {
        echo "  audit FAIL: " . $e->getMessage() . "\n";
    }
}

$dur = round(microtime(true) - $t0, 1);
echo "\n[" . date('Y-m-d H:i:s') . "] schema audit done in {$dur}s — checked={$grand['checked']} ok={$grand['ok']} degraded={$grand['degraded']} broken={$grand['broken']}\n";
exit(0);
