<?php
/**
 * GMC nightly diagnostics sync — runs across every site that has a
 * gmc_merchant_id set in sites.notes.
 *
 * Usage:
 *   php agent/cron-gmc-audit.php [--site=N]
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gmc_api.php';

$opts = getopt('', ['site::']);
$single = isset($opts['site']) ? (int)$opts['site'] : null;

$db = require __DIR__ . '/../includes/db.php';

if ($single) {
    $stmt = $db->prepare("SELECT id, notes FROM sites WHERE id = ?");
    $stmt->execute([$single]);
} else {
    $stmt = $db->query("SELECT id, notes FROM sites WHERE notes LIKE '%gmc_merchant_id%'");
}
$sites = $stmt->fetchAll();

foreach ($sites as $site) {
    $notes = json_decode($site['notes'] ?? '{}', true) ?: [];
    $mid = (string)($notes['gmc_merchant_id'] ?? '');
    if ($mid === '') continue;

    $t0 = microtime(true);
    echo "[" . date('Y-m-d H:i:s') . "] gmc audit site={$site['id']} merchant={$mid}\n";
    $r = gmc_audit_site($db, (int)$site['id'], $mid);
    $dur = round(microtime(true) - $t0, 1);
    if (!empty($r['success'])) {
        echo "  done in {$dur}s — products={$r['products_synced']} issues={$r['issues_found']}\n";
    } else {
        echo "  FAILED in {$dur}s — " . ($r['error'] ?? 'unknown') . "\n";
    }
}
exit(0);
