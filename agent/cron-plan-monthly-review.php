<?php
/**
 * Monthly plan review — runs 1st of each month at 03:00 IST.
 *
 * For each active plan whose last_review_at is >= 30 days ago (or null),
 * generates a monthly performance review. The review is inserted into
 * plan_reviews with status='proposed' and an alert fires for the user.
 *
 * Also expires stale proposed reviews (>7 days old) so they don't pile up.
 *
 * Usage:
 *   php agent/cron-plan-monthly-review.php           — run for all due plans
 *   php agent/cron-plan-monthly-review.php --site=N  — one site only
 *   php agent/cron-plan-monthly-review.php --plan=N  — one specific plan
 *   php agent/cron-plan-monthly-review.php --force   — ignore the 30d gate
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/plan_review.php';

$db = require __DIR__ . '/../includes/db.php';

$opts    = getopt('', ['site:', 'plan:', 'force']);
$site_id = (int)($opts['site'] ?? 0);
$plan_id = (int)($opts['plan'] ?? 0);
$force   = array_key_exists('force', $opts);

// First sweep stale proposed reviews
$expired = review_expire_stale($db);
if ($expired > 0) echo "Expired {$expired} stale review document(s).\n";

if ($plan_id) {
    $sql = "SELECT id FROM content_plans WHERE id = ? AND status = 'active'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$plan_id]);
} elseif ($site_id) {
    $sql = "SELECT id FROM content_plans WHERE site_id = ? AND status = 'active'";
    $stmt = $db->prepare($sql);
    $stmt->execute([$site_id]);
} else {
    $sql = "SELECT id FROM content_plans WHERE status = 'active'";
    if (!$force) $sql .= " AND (last_review_at IS NULL OR last_review_at < DATE_SUB(NOW(), INTERVAL 28 DAY))";
    $stmt = $db->prepare($sql);
    $stmt->execute();
}
$plan_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Monthly review: " . count($plan_ids) . " plan(s) due.\n";

$success = 0; $fail = 0;
foreach ($plan_ids as $pid) {
    try {
        $review_id = review_generate($db, (int)$pid);
        echo "  ✓ plan={$pid} → review={$review_id}\n";
        $success++;
    } catch (Throwable $e) {
        error_log("[cron-plan-monthly-review] plan={$pid}: " . $e->getMessage());
        echo "  ✗ plan={$pid}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "Done. success={$success} fail={$fail}\n";
