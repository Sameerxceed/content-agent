<?php
/**
 * CLI: infer business profile for one site (or all when called via cron).
 *
 * Usage:
 *   php agent/business-profile-infer.php --site=1
 *   php agent/business-profile-infer.php --site=1 --force      (re-infer even if confirmed)
 *
 * Used by:
 *   - The CLI scanner (agent/scanner.php) after the scan completes
 *   - The web onboarding scan handler (shells out)
 *   - The manual "Re-analyse with AI" button on the Business Profile UI
 *
 * Cost: ~$0.005 per site via Haiku.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/business_profile.php';

$db = require __DIR__ . '/../includes/db.php';

$opts    = getopt('', ['site:', 'force']);
$site_id = (int)($opts['site'] ?? 0);
$force   = isset($opts['force']);

if (!$site_id) {
    fwrite(STDERR, "Usage: php business-profile-infer.php --site=N [--force]\n");
    exit(1);
}

$stmt = $db->prepare('SELECT id, name, domain, profile_confirmed FROM sites WHERE id = ?');
$stmt->execute([$site_id]);
$site = $stmt->fetch();

if (!$site) {
    fwrite(STDERR, "Site #{$site_id} not found\n");
    exit(1);
}

if (!$force && (int)$site['profile_confirmed'] === 1) {
    echo "Site #{$site_id} ({$site['name']}): profile_confirmed=1 — skipping inference (use --force to override).\n";
    exit(0);
}

echo "Inferring business profile for #{$site_id}: {$site['name']} ({$site['domain']})\n";

echo "  Fetching pages...\n";
$pages = profile_fetch_pages($site['domain']);
if (empty($pages)) {
    fwrite(STDERR, "  ERROR: no pages reachable\n");
    exit(1);
}
echo '  Got ' . count($pages) . ' page(s): ' . implode(', ', array_keys($pages)) . "\n";

echo "  Calling Claude...\n";
$result = profile_infer($site, $pages);
if (!$result['success']) {
    fwrite(STDERR, "  ERROR: " . ($result['error'] ?? 'unknown') . "\n");
    if (!empty($result['raw'])) fwrite(STDERR, "  Raw: " . $result['raw'] . "\n");
    exit(1);
}

profile_save($db, $site_id, $result);

echo "  Inferred:\n";
foreach ($result['fields'] as $key => $val) {
    if ($val === null) continue;
    $conf = $result['confidence'][$key] ?? null;
    $conf_str = $conf !== null ? ' (conf=' . number_format((float)$conf, 2) . ')' : '';
    echo "    {$key}: {$val}{$conf_str}\n";
}

echo "Done.\n";
