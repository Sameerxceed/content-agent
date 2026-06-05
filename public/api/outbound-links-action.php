<?php
/**
 * Outbound link checker — API endpoint.
 *
 * POST JSON: { action, site_id }
 *   action=run      — check every outbound link, return summary
 *   action=dismiss  — dismiss a single outbound_links row
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/outbound_link_checker.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

function obl_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action  = (string)($input['action']  ?? '');
$site_id = (int)   ($input['site_id'] ?? 0);

$site = auth_get_accessible_site($db, $site_id);
if (!$site) obl_respond(['error' => 'Site not found'], 404);

try {
    if ($action === 'run') {
        @set_time_limit(180);
        $r = outbound_check_site($db, $site_id);
        obl_respond(['success' => true] + $r);
    }
    if ($action === 'dismiss') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) obl_respond(['error' => 'id required'], 400);
        $db->prepare("UPDATE outbound_links SET dismissed_at = NOW() WHERE id = ? AND site_id = ?")
           ->execute([$id, $site_id]);
        obl_respond(['success' => true]);
    }
    obl_respond(['error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('[outbound-links-action] ' . $e->getMessage());
    obl_respond(['error' => $e->getMessage()], 500);
}
