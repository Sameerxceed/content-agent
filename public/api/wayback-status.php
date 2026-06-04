<?php
/**
 * Wayback Harvester — status poll endpoint.
 *
 * GET ?site_id=N → { total_urls, dead_urls, last_run: {...} }
 *
 * Cheap read for the dashboard UI to refresh the harvest progress bar.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/wayback_harvester.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site_id'] ?? 0);
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

$summary = wayback_site_summary($db, $site_id);

// Is the live-check actively running? We define "running" as: at least one
// row got checked in the last 30 seconds. That's the most reliable signal
// without hitting the process table (which doesn't work behind PHP-FPM).
$stmt = $db->prepare("SELECT MAX(current_checked_at) AS m FROM historical_urls WHERE site_id = ?");
$stmt->execute([$site_id]);
$last_check = $stmt->fetchColumn();
$is_running = false;
$last_check_age = null;
if ($last_check) {
    $last_check_age = time() - strtotime($last_check);
    $is_running = $last_check_age < 30;
}

echo json_encode($summary + [
    'is_running'     => $is_running,
    'last_check_at'  => $last_check,
    'last_check_age' => $last_check_age,
]);
