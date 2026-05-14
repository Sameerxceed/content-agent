<?php
/**
 * Weekly GSC re-sync per site.
 * Pulls fresh impressions/clicks/CTR/position from Search Console for every
 * site that has the integration connected. Records ranking deltas as alerts
 * when a previously-good rank drops.
 *
 * Run via: cron-runner.php gsc-sync
 */

require_once __DIR__ . '/../includes/integrations/google.php';

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

$sites = cron_get_sites($db, $site_id_filter);
echo "Scanning " . count($sites) . " sites for GSC sync\n";

foreach ($sites as $site) {
    $sid = (int)$site['id'];

    // Only sync sites with GSC integration active
    $stmt = $db->prepare('SELECT id FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
    $stmt->execute([$sid]);
    if (!$stmt->fetch()) {
        echo "  skip site #{$sid} ({$site['domain']}): no GSC connected\n";
        continue;
    }

    echo "  syncing #{$sid} {$site['domain']}...\n";

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid, $site) {
        // Snapshot current positions BEFORE sync (for delta detection)
        $stmt = $db->prepare('SELECT keyword, gsc_position, impressions FROM keywords WHERE site_id = ? AND gsc_synced_at IS NOT NULL');
        $stmt->execute([$sid]);
        $previous = [];
        foreach ($stmt->fetchAll() as $r) {
            $previous[$r['keyword']] = ['pos' => $r['gsc_position'], 'imp' => $r['impressions']];
        }

        $result = google_update_rankings($db, $sid);
        if (empty($result['success'])) {
            throw new \RuntimeException($result['error'] ?? 'sync failed');
        }

        // Delta detection — alert when a previously-page-1 keyword drops to page 2+
        $stmt = $db->prepare('SELECT keyword, gsc_position, impressions FROM keywords WHERE site_id = ? AND gsc_synced_at IS NOT NULL');
        $stmt->execute([$sid]);
        $drops = [];
        foreach ($stmt->fetchAll() as $r) {
            $prev = $previous[$r['keyword']] ?? null;
            if (!$prev || !$prev['pos']) continue;
            $prev_pos = (float)$prev['pos'];
            $now_pos  = (float)$r['gsc_position'];
            // Was top 10, now beyond 10 → real drop
            if ($prev_pos <= 10 && $now_pos > 10) {
                $drops[] = ['keyword' => $r['keyword'], 'from' => round($prev_pos, 1), 'to' => round($now_pos, 1)];
            }
        }

        if (!empty($drops)) {
            $top = array_slice($drops, 0, 5);
            $lines = array_map(fn($d) => '"' . $d['keyword'] . '" #' . $d['from'] . ' → #' . $d['to'], $top);
            alert_create($db, $sid, 'rank_drop',
                count($drops) . ' keyword' . (count($drops) > 1 ? 's' : '') . ' dropped off page 1',
                implode("\n", $lines),
                '/dashboard/keywords.php?site=' . $sid,
                'warning',
                ['drops' => $drops]
            );
        }

        return [
            'matched_url' => $result['matched_url'] ?? null,
            'updated'     => $result['updated'] ?? 0,
            'inserted'    => $result['inserted'] ?? 0,
            'drops'       => count($drops),
        ];
    });
}

echo "GSC sync complete.\n";
