<?php
/**
 * Weekly SERP rank tracking via DataForSEO.
 *
 * For every active keyword on each site, fetches the current Google SERP
 * and stores our domain's position. Complements GSC sync: GSC only shows
 * impressions Google actually served, so keywords with no impressions yet
 * stay '—'. This cron fills those in by asking DFSO for the live SERP.
 *
 * Run via: cron-runner.php serp-tracking
 *
 * Cost: ~$0.005 per keyword. 200 keywords/site × 4 sites × 4 weeks = ~$16/month
 * at scale. Throttles 200ms between calls.
 */

require_once __DIR__ . '/../includes/dataforseo.php';

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

if (empty(config('dataforseo_login')) || empty(config('dataforseo_password'))) {
    echo "DataForSEO not configured — skipping SERP tracking.\n";
    return;
}

$sites = cron_get_sites($db, $site_id_filter);
echo "SERP tracking for " . count($sites) . " sites\n";

foreach ($sites as $site) {
    $sid    = (int)$site['id'];
    $domain = trim($site['domain']);
    if ($domain === '') { echo "  skip #{$sid}: no domain\n"; continue; }

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid, $domain) {
        // Prioritise keywords that don't already have a fresh rank.
        // Skip keywords checked in the last 5 days to avoid burning credit.
        $stmt = $db->prepare("
            SELECT id, keyword
            FROM keywords
            WHERE site_id = ? AND status = 'active'
              AND (last_checked IS NULL OR last_checked < DATE_SUB(NOW(), INTERVAL 5 DAY))
            ORDER BY priority DESC, impressions DESC
            LIMIT 200
        ");
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo "    nothing to check (all fresh)\n";
            return ['checked' => 0];
        }

        $upd = $db->prepare('UPDATE keywords SET gsc_position = ?, last_checked = NOW() WHERE id = ?');

        $checked = 0; $ranked = 0; $errors = 0;
        foreach ($rows as $r) {
            $pos = dataforseo_serp_position($r['keyword'], $domain);
            if ($pos !== null) {
                $upd->execute([$pos, $r['id']]);
                $ranked++;
            } else {
                // Not in top 100 — clear stale rank, mark as checked
                $upd->execute([null, $r['id']]);
            }
            $checked++;
            usleep(200000); // 200ms between SERP calls
        }

        echo "    checked {$checked} | ranked in top 100: {$ranked}\n";
        return ['checked' => $checked, 'ranked' => $ranked, 'errors' => $errors];
    });
}

echo "SERP tracking complete.\n";
