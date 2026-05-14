<?php
/**
 * Alerts helper — central place to record things the user should know.
 * Cron jobs call alert_create() when they detect changes.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Create an alert. Deduplicates: same (site_id, type, title) within 24h is a no-op.
 */
function alert_create(PDO $db, int $site_id, string $type, string $title, ?string $detail = null, ?string $link_url = null, string $severity = 'info', ?array $data = null): bool
{
    // Dedupe: skip if same alert was raised in last 24h
    $stmt = $db->prepare('SELECT id FROM alerts WHERE site_id = ? AND type = ? AND title = ? AND detected_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1');
    $stmt->execute([$site_id, $type, $title]);
    if ($stmt->fetch()) return false;

    $stmt = $db->prepare('INSERT INTO alerts (site_id, type, severity, title, detail, link_url, data, detected_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $site_id, $type, $severity,
        mb_substr($title, 0, 500),
        $detail ? mb_substr($detail, 0, 4000) : null,
        $link_url,
        $data ? json_encode($data) : null,
    ]);
    return true;
}

/** Count unread alerts for a site. */
function alerts_unread_count(PDO $db, int $site_id): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM alerts WHERE site_id = ? AND read_at IS NULL');
    $stmt->execute([$site_id]);
    return (int)$stmt->fetchColumn();
}

/** Recent alerts for digest emails (last $hours). */
function alerts_recent(PDO $db, int $site_id, int $hours = 168): array
{
    $stmt = $db->prepare('SELECT * FROM alerts WHERE site_id = ? AND detected_at > DATE_SUB(NOW(), INTERVAL ? HOUR) ORDER BY severity = "critical" DESC, severity = "warning" DESC, detected_at DESC LIMIT 50');
    $stmt->execute([$site_id, $hours]);
    return $stmt->fetchAll();
}
