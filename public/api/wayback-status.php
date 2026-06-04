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

echo json_encode(wayback_site_summary($db, $site_id));
