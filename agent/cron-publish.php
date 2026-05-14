<?php
/**
 * Publishing scheduler / retrier.
 *
 * Picks up post_channels rows that are:
 *   - status='queued' AND (scheduled_for IS NULL OR scheduled_for <= NOW())
 *   - attempts < 3
 * and runs them through the channel adapter.
 *
 * Run via: cron-runner.php publish
 * Recommended cadence: every 15 min.
 */

require_once __DIR__ . '/../includes/channels/registry.php';

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

$registry = channels_registry();

// Pick rows due now, up to 50 per run (don't lock up the server)
$stmt = $db->prepare("SELECT pc.id, pc.post_id, pc.channel, pc.attempts, p.site_id
    FROM post_channels pc
    JOIN posts p ON pc.post_id = p.id
    WHERE pc.status = 'queued'
      AND (pc.scheduled_for IS NULL OR pc.scheduled_for <= NOW())
      AND pc.attempts < 3
      " . ($site_id_filter ? "AND p.site_id = " . (int)$site_id_filter : "") . "
    ORDER BY pc.scheduled_for ASC, pc.id ASC
    LIMIT 50");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Nothing to publish.\n";
    return;
}

echo "Found " . count($rows) . " row(s) to publish.\n";

$processed = 0;
$succeeded = 0;
$failed = 0;

foreach ($rows as $r) {
    echo "  → row #{$r['id']} site={$r['site_id']} channel={$r['channel']} attempt=" . ($r['attempts'] + 1) . " ... ";
    try {
        $result = $registry->publish_row($db, (int)$r['id']);
        if (!empty($result['success'])) {
            echo "✓ published\n";
            $succeeded++;
        } else {
            echo "✗ " . ($result['error'] ?? 'unknown') . " (next: " . ($result['next_status'] ?? '?') . ")\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "✗ exception: " . $e->getMessage() . "\n";
        $failed++;
        $db->prepare('UPDATE post_channels SET status = "failed", error = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$e->getMessage(), $r['id']]);
    }
    $processed++;
}

// Log
$db->prepare('INSERT INTO agent_runs (site_id, job_type, status, result_summary, triggered_by, started_at, finished_at) VALUES (?, ?, "done", ?, "cron", NOW(), NOW())')->execute([
    $site_id_filter, 'publish',
    json_encode(['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed]),
]);

echo "Publish run complete: {$succeeded} ok, {$failed} fail, {$processed} total.\n";
