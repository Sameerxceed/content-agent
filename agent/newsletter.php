<?php
/**
 * Newsletter Agent
 * Generates and sends weekly digest emails to subscribers.
 *
 * CLI Usage: php agent/newsletter.php --site=1
 *            php agent/newsletter.php --all
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/newsletter.php';

$db = require __DIR__ . '/../includes/db.php';

$opts = getopt('', ['site:', 'all', 'preview']);
$site_id = $opts['site'] ?? null;
$all_sites = isset($opts['all']);
$preview = isset($opts['preview']);

if (!$site_id && !$all_sites) {
    echo "Usage: php newsletter.php --site=1 [--preview]\n";
    echo "       php newsletter.php --all\n";
    exit(1);
}

if ($all_sites) {
    $stmt = $db->query('SELECT id FROM sites WHERE is_active = 1');
    $site_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $site_ids = [$site_id];
}

foreach ($site_ids as $sid) {
    $start = microtime(true);
    echo "\nSite #{$sid}\n";

    $sub_count = newsletter_subscriber_count($db, $sid);
    echo "  Subscribers: {$sub_count}\n";

    if ($sub_count === 0 && !$preview) {
        echo "  SKIP: No subscribers\n";
        continue;
    }

    $digest = newsletter_generate_digest($db, $sid);

    if (!$digest) {
        echo "  SKIP: No posts this week\n";
        continue;
    }

    echo "  Subject: {$digest['subject']}\n";
    echo "  Posts: {$digest['posts']}\n";

    if ($preview) {
        echo "\n--- EMAIL PREVIEW ---\n";
        echo "Subject: {$digest['subject']}\n\n";
        echo strip_tags(str_replace(['</td>', '</tr>', '</p>', '<br>'], ["\n", "\n", "\n", "\n"], $digest['body']));
        echo "\n--- END PREVIEW ---\n";
        continue;
    }

    $result = newsletter_send($db, $sid, $digest['subject'], $digest['body']);
    $duration = round((microtime(true) - $start) * 1000);

    echo "  Sent: {$result['sent']}/{$result['total']}";
    if ($result['failed'] > 0) echo " ({$result['failed']} failed)";
    echo " in {$duration}ms\n";

    $db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)')->execute([
        $sid, 'newsletter',
        json_encode(['sent' => $result['sent'], 'total' => $result['total'], 'subject' => $digest['subject']]),
        $result['failed'] > 0 ? 'fail' : 'success',
        $duration,
    ]);
}
