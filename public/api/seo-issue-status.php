<?php
/**
 * Mark a seo_issues row as resolved / ignored / reopened.
 * POST JSON: { issue_id, status }
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$issue_id = (int)($input['issue_id'] ?? 0);
$status   = $input['status'] ?? '';

$allowed = ['open', 'fix_proposed', 'fix_applied', 'resolved', 'ignored'];
if (!in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status']);
    exit;
}

$stmt = $db->prepare('
    UPDATE seo_issues i
    JOIN sites s ON i.site_id = s.id
    SET i.status = ?
    WHERE i.id = ? AND s.user_id = ?
');
$stmt->execute([$status, $issue_id, $user_id]);

echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
