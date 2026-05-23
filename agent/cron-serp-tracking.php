<?php
/**
 * Weekly SERP rank tracking via the serp_search() abstraction.
 *
 * For every active keyword on each site, fetches the current Google SERP
 * and stores our domain's position. Complements GSC sync: GSC only shows
 * impressions Google actually served, so keywords with no impressions yet
 * stay '—'. This cron fills those in by asking the SERP provider live.
 *
 * Routed via includes/serp.php so it tries Brave (free) first and falls
 * back to DataForSEO (paid) when Brave rate-limits or runs out of monthly
 * quota.
 *
 * Run via: cron-runner.php serp-tracking
 */

require_once __DIR__ . '/../includes/serp.php';

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

if (!serp_active_provider()) {
    echo "No SERP provider configured (need Brave Search or DataForSEO) — skipping.\n";
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

        // Normalize our own domain once so we can scan SERP URLs for it.
        $own = strtolower(preg_replace('#^(https?://)?(www\.)?#i', '', $domain));
        $own = rtrim($own, '/');

        $checked = 0; $ranked = 0; $errors = 0;
        $provider_tally = [];
        foreach ($rows as $r) {
            $serp = serp_search($r['keyword'], 100);
            if (!empty($serp['provider'])) {
                $provider_tally[$serp['provider']] = ($provider_tally[$serp['provider']] ?? 0) + 1;
            }
            if (empty($serp['results'])) {
                $errors++;
                $checked++;
                continue;
            }

            // Find our domain in the SERP and grab the position.
            $pos = null;
            foreach ($serp['results'] as $item) {
                $host = strtolower(preg_replace('#^https?://#i', '', $item['url'] ?? ''));
                $host = preg_replace('#^www\.#', '', $host);
                $host = strtolower(rtrim(explode('/', $host)[0], '/'));
                if ($host === $own || str_ends_with($host, '.' . $own)) {
                    $pos = (int)$item['position'];
                    break;
                }
            }

            if ($pos !== null) {
                $upd->execute([$pos, $r['id']]);
                $ranked++;
            } else {
                $upd->execute([null, $r['id']]);
            }
            $checked++;

            // Brave free tier: 1 req/sec. The provider itself paces page-fetches
            // internally; we add a small extra gap between keywords to be safe.
            usleep(200000);
        }

        $tally_str = '';
        foreach ($provider_tally as $p => $n) $tally_str .= "{$p}={$n} ";
        echo "    checked {$checked} | ranked top 100: {$ranked} | providers: {$tally_str}\n";
        return ['checked' => $checked, 'ranked' => $ranked, 'errors' => $errors, 'providers' => $provider_tally];
    });
}

echo "SERP tracking complete.\n";
