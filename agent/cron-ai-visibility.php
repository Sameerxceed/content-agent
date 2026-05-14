<?php
/**
 * Weekly AI Visibility re-check.
 *
 * Re-runs the existing AI Visibility audit (queries Claude with industry
 * questions and checks if the site is mentioned). Stores snapshot, compares
 * with previous week, alerts on significant drop.
 *
 * Run via: cron-runner.php ai-visibility
 */

require_once __DIR__ . '/../includes/ai-visibility.php';

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

$sites = cron_get_sites($db, $site_id_filter);
echo "AI Visibility scanning " . count($sites) . " sites\n";

foreach ($sites as $site) {
    $sid = (int)$site['id'];

    // Skip if Business Focus not confirmed (AI cannot run without topics)
    if (empty($site['topics_confirmed'])) {
        echo "  skip #{$sid}: Business Focus not confirmed\n";
        continue;
    }

    echo "  scanning #{$sid} {$site['domain']}...\n";

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid, $site) {
        // Previous snapshot (most recent)
        $stmt = $db->prepare('SELECT score, mentions_count FROM ai_visibility_snapshots WHERE site_id = ? ORDER BY taken_at DESC LIMIT 1');
        $stmt->execute([$sid]);
        $prev = $stmt->fetch();

        // Run the audit
        if (!function_exists('check_ai_visibility')) {
            throw new \RuntimeException('check_ai_visibility() not available');
        }
        $result = check_ai_visibility($site, $db);

        $score = (int)($result['score'] ?? 0);
        $mentions = (int)($result['mentioned'] ?? 0);
        $queries = (int)($result['total'] ?? 0);

        // Persist snapshot
        $db->prepare('INSERT INTO ai_visibility_snapshots (site_id, score, mentions_count, queries_tested, result_json, taken_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$sid, $score, $mentions, $queries, json_encode($result)]);

        // Delta detection: alert if score dropped 15+ points OR mentions halved
        if ($prev) {
            $score_drop = (int)$prev['score'] - $score;
            $mentions_drop = (int)$prev['mentions_count'] - $mentions;
            if ($score_drop >= 15 || ($prev['mentions_count'] >= 4 && $mentions <= (int)$prev['mentions_count'] / 2)) {
                alert_create($db, $sid, 'visibility_drop',
                    'AI Visibility dropped (' . (int)$prev['score'] . ' → ' . $score . ')',
                    "Mentions: {$prev['mentions_count']} → {$mentions} across {$queries} queries.\nLikely cause: AI models updated their training data, or competitors gained citations.",
                    '/dashboard/ai-visibility.php?site=' . $sid,
                    'warning',
                    ['prev_score' => (int)$prev['score'], 'now_score' => $score]
                );
            }
        }

        return ['score' => $score, 'mentions' => $mentions, 'queries' => $queries, 'prev_score' => $prev['score'] ?? null];
    });
}

echo "AI Visibility complete.\n";
