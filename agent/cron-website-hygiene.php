<?php
/**
 * Weekly Website Hygiene — keeps the historical_urls + current_site_urls +
 * redirect_map data fresh for every active site, without the user having to
 * remember to click anything.
 *
 * Pipeline per site (in order):
 *   1. Wayback CDX harvest      — pull any newly-archived URLs since last week
 *   2. Live-status check        — HEAD every unchecked URL (new + still-pending)
 *   3. Site crawl               — re-discover live URLs (sitemap.xml drift)
 *   4. Redirect map build       — match any NEW dead URLs to living targets
 *
 * Pace + safety:
 *   - 30-second pause between sites to spread provider load
 *   - Per-site try/catch so one broken domain doesn't take down the whole run
 *   - Skips inactive sites
 *   - Live-check capped at batch=500 per site per run (multi-week catch-up)
 *
 * Registered in cron_schedules (migration adds the row): runs weekly Sundays.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/wayback_harvester.php';
require_once __DIR__ . '/../includes/site_crawler.php';
require_once __DIR__ . '/../includes/redirect_map_builder.php';

$db = require __DIR__ . '/../includes/db.php';

$site_filter = (int)($_SERVER['argv'][1] ?? 0);  // optional --site=N alt syntax
$opts = getopt('', ['site::']);
if (!empty($opts['site'])) $site_filter = (int)$opts['site'];

$where = "is_active = 1";
$args  = [];
if ($site_filter) { $where .= " AND id = ?"; $args[] = $site_filter; }

$sites = $db->prepare("SELECT id, name, domain FROM sites WHERE {$where} ORDER BY id");
$sites->execute($args);
$rows = $sites->fetchAll();

$t0 = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] hygiene run start — sites=" . count($rows) . "\n";

$total = ['sites' => 0, 'harvested' => 0, 'checked' => 0, 'crawled' => 0, 'redirects' => 0, 'errors' => 0];

foreach ($rows as $site) {
    $sid = (int)$site['id'];
    $domain = (string)$site['domain'];
    if (trim($domain) === '') continue;
    $total['sites']++;
    echo "\n— site={$sid} {$site['name']} ({$domain}) —\n";

    // 1. Wayback harvest (idempotent; updates first_seen/last_seen)
    try {
        $r = wayback_harvest_site($db, $sid, $domain);
        echo "  harvest: fetched={$r['urls_fetched']} new={$r['urls_new']} pages={$r['pages']}\n";
        $total['harvested'] += (int)$r['urls_new'];
        if (!empty($r['error'])) echo "    note: {$r['error']}\n";
    } catch (Throwable $e) {
        $total['errors']++;
        echo "  harvest FAIL: " . $e->getMessage() . "\n";
    }

    // 2. Live status check — capped at 500 URLs per site per weekly run
    try {
        $r = wayback_check_pending($db, $sid, 500);
        echo "  live-check: checked={$r['checked']} dead={$r['dead']} alive={$r['alive']} errors={$r['errors']}\n";
        $total['checked'] += (int)$r['checked'];
    } catch (Throwable $e) {
        $total['errors']++;
        echo "  live-check FAIL: " . $e->getMessage() . "\n";
    }

    // 3. Site crawl — refresh live URL inventory (titles off for speed)
    try {
        $r = sc_crawl_site($db, $sid, $domain, false);
        echo "  crawl: source={$r['source']} found={$r['urls_found']} stored={$r['urls_stored']}\n";
        $total['crawled'] += (int)$r['urls_stored'];
        if (!empty($r['error'])) echo "    note: {$r['error']}\n";
    } catch (Throwable $e) {
        $total['errors']++;
        echo "  crawl FAIL: " . $e->getMessage() . "\n";
    }

    // 4. Redirect map build — only does NEW dead URLs (idempotent)
    try {
        $r = rmb_build_map($db, $sid, null);
        echo "  redirect-build: processed={$r['processed']} hits={$r['hits']} no_target={$r['no_target']}\n";
        $total['redirects'] += (int)$r['processed'];
        if (!empty($r['error'])) echo "    note: {$r['error']}\n";
    } catch (Throwable $e) {
        $total['errors']++;
        echo "  redirect-build FAIL: " . $e->getMessage() . "\n";
    }

    sleep(30); // polite gap between sites
}

$dur = round(microtime(true) - $t0, 1);
echo "\n[" . date('Y-m-d H:i:s') . "] hygiene done in {$dur}s — sites_run={$total['sites']} new_harvested={$total['harvested']} live_checked={$total['checked']} live_urls_stored={$total['crawled']} redirects_processed={$total['redirects']} errors={$total['errors']}\n";
exit($total['errors'] > 0 ? 1 : 0);
