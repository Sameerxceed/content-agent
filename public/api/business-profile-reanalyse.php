<?php
/**
 * On-demand re-run of the AI business profile inference for one site.
 * Triggered by the "Re-analyse with AI" button on the Business Profile UI.
 *
 * Synchronous — the caller is a user-clicked button so it's fine to wait
 * the 10-20 seconds for Claude. Returns the new inferred fields so the
 * caller can reload and show fresh ✨ tags.
 *
 * Skips inference if the user has already confirmed the profile (use
 * --force on the CLI for that case). The button on the UI is hidden when
 * the profile is confirmed for the same reason.
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/business_profile.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

function bpr_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        $stray = ob_get_clean();
        if (trim($stray) !== '') error_log('[business-profile-reanalyse] stray output: ' . substr($stray, 0, 500));
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
$force   = !empty($input['force']);

if (!$site_id) bpr_respond(['error' => 'site_id required'], 400);

$site = auth_get_accessible_site($db, $site_id);
if (!$site) bpr_respond(['error' => 'Site not found'], 404);

try {
    $pages = profile_fetch_pages($site['domain']);
    if (empty($pages)) bpr_respond(['success' => false, 'error' => 'Could not reach any of homepage / about / team pages'], 200);

    $result = profile_infer($site, $pages);
    if (!$result['success']) bpr_respond(['success' => false, 'error' => $result['error'] ?? 'inference failed', 'raw' => $result['raw'] ?? null], 200);

    // If the user wants to overwrite a confirmed profile, flip it off so save() will write.
    if ($force) {
        $db->prepare('UPDATE sites SET profile_confirmed = 0 WHERE id = ?')->execute([$site_id]);
    }

    profile_save($db, $site_id, $result);

    bpr_respond(['success' => true, 'fields' => $result['fields'], 'confidence' => $result['confidence']]);
} catch (Throwable $e) {
    error_log('[business-profile-reanalyse] ' . $e->getMessage());
    bpr_respond(['success' => false, 'error' => $e->getMessage()], 500);
}
