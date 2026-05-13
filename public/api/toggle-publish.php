<?php
/**
 * Toggle CMS auto-publish for a site.
 * POST JSON: { site_id, enable }
 *
 * - enable=false clears cms_url + cms_api_key (disables CMS push)
 * - enable=true is not supported here — user must enter credentials via Site Edit
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
$enable = $input['enable'] ?? null;
$snippet_mode = $input['snippet_mode'] ?? null;

if (!$site_id) { http_response_code(400); echo json_encode(['error' => 'site_id required']); exit; }

// Verify ownership
$stmt = $db->prepare('SELECT id FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

// Snippet mode toggle
if ($snippet_mode !== null) {
    if (!in_array($snippet_mode, ['fill_only', 'override'])) {
        http_response_code(400); echo json_encode(['error' => 'Invalid snippet_mode']); exit;
    }
    $stmt = $db->prepare('UPDATE sites SET snippet_mode = ? WHERE id = ?');
    $stmt->execute([$snippet_mode, $site_id]);
    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site_id, 'snippet_mode_changed', json_encode(['new_mode' => $snippet_mode, 'by_user' => $user_id]), 'success'
    ]);
    echo json_encode(['success' => true, 'snippet_mode' => $snippet_mode]);
    exit;
}

if (!$enable) {
    // Disable: clear CMS credentials
    $stmt = $db->prepare('UPDATE sites SET cms_url = NULL, cms_api_key = NULL WHERE id = ?');
    $stmt->execute([$site_id]);

    // Log it
    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site_id, 'cms_publish_disabled', json_encode(['by_user' => $user_id]), 'success'
    ]);

    echo json_encode(['success' => true, 'message' => 'CMS auto-publish disabled']);
    exit;
}

// Enabling requires credentials — redirect user to sites.php edit form
echo json_encode(['success' => false, 'error' => 'To enable, please enter CMS credentials in Site Settings']);
