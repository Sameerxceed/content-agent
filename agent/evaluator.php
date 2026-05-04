<?php
/**
 * Agent Evaluator
 * Reviews performance, re-evaluates keywords, and adjusts strategy.
 * Runs weekly via cron.
 *
 * CLI Usage: php agent/evaluator.php --site=1
 *            php agent/evaluator.php --all
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/haiku.php';

$db = require __DIR__ . '/../includes/db.php';

$opts = getopt('', ['site:', 'all']);
$site_id  = $opts['site'] ?? null;
$all_sites = isset($opts['all']);

if (!$site_id && !$all_sites) {
    echo "Usage: php evaluator.php --site=1\n";
    echo "       php evaluator.php --all\n";
    exit(1);
}

if ($all_sites) {
    $stmt = $db->query('SELECT * FROM sites WHERE is_active = 1');
    $sites = $stmt->fetchAll();
} else {
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $sites = $stmt->fetchAll();
}

foreach ($sites as $site) {
    $start_time = microtime(true);
    echo "\nEvaluating: {$site['domain']} (#{$site['id']})\n";
    echo str_repeat('-', 50) . "\n";

    // ── Gather stats ────────────────────────────────────
    // Posts in last 30 days
    $stmt = $db->prepare('SELECT type, status, COUNT(*) as cnt FROM posts WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY type, status');
    $stmt->execute([$site['id']]);
    $post_stats = $stmt->fetchAll();

    // Latest audit score
    $stmt = $db->prepare('SELECT score, total_issues, critical, run_at FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 2');
    $stmt->execute([$site['id']]);
    $audits = $stmt->fetchAll();

    // Keyword coverage
    $stmt = $db->prepare('SELECT COUNT(*) as total, COUNT(cluster) as clustered FROM keywords WHERE site_id = ?');
    $stmt->execute([$site['id']]);
    $kw_stats = $stmt->fetch();

    // Agent activity
    $stmt = $db->prepare('SELECT action, COUNT(*) as cnt, SUM(CASE WHEN status = "fail" THEN 1 ELSE 0 END) as failures FROM agent_log WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY action');
    $stmt->execute([$site['id']]);
    $activity = $stmt->fetchAll();

    // ── Print report ────────────────────────────────────
    echo "\n  Content (last 30 days):\n";
    foreach ($post_stats as $ps) {
        echo "    {$ps['type']}/{$ps['status']}: {$ps['cnt']}\n";
    }

    if (!empty($audits)) {
        echo "\n  SEO Score: {$audits[0]['score']}/100";
        if (isset($audits[1])) {
            $diff = $audits[0]['score'] - $audits[1]['score'];
            $arrow = $diff >= 0 ? '+' : '';
            echo " ({$arrow}{$diff} from previous)";
        }
        echo "\n    Issues: {$audits[0]['total_issues']} ({$audits[0]['critical']} critical)\n";
    }

    echo "\n  Keywords: {$kw_stats['total']} total, {$kw_stats['clustered']} clustered\n";

    echo "\n  Agent Activity (last 7 days):\n";
    foreach ($activity as $act) {
        echo "    {$act['action']}: {$act['cnt']} runs ({$act['failures']} failures)\n";
    }

    // ── AI-powered strategy recommendation ──────────────
    echo "\n  Generating strategy recommendations...\n";

    $context = json_encode([
        'domain'      => $site['domain'],
        'post_stats'  => $post_stats,
        'seo_score'   => $audits[0]['score'] ?? null,
        'seo_issues'  => $audits[0]['total_issues'] ?? 0,
        'keywords'    => $kw_stats,
        'activity'    => $activity,
        'brand_tone'  => $site['brand_tone'],
    ]);

    $result = haiku_chat(
        'You are a content strategist AI. Analyze the site performance data and give 3-5 actionable recommendations. Be specific and concise. Output as a numbered list.',
        "Analyze this site's content performance and suggest next actions:\n\n{$context}",
        512
    );

    if ($result['success']) {
        echo "\n  Recommendations:\n";
        $lines = explode("\n", trim($result['content']));
        foreach ($lines as $line) {
            if (trim($line)) echo "    {$line}\n";
        }
    }

    $duration = round((microtime(true) - $start_time) * 1000);

    $stmt = $db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $site['id'],
        'evaluate',
        json_encode([
            'seo_score'  => $audits[0]['score'] ?? null,
            'post_count' => array_sum(array_column($post_stats, 'cnt')),
            'keywords'   => $kw_stats['total'],
        ]),
        'success',
        $duration,
    ]);

    echo "\n  Done ({$duration}ms)\n";
}
