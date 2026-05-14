<?php
/**
 * Manual publish/retry for one channel.
 *
 * POST JSON: { post_id, channel }
 * Creates the post_channels row if missing (status=queued), then runs the
 * adapter synchronously. Used by the "Publish" and "Retry" buttons on the
 * post edit page.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/channels/registry.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$post_id = (int)($input['post_id'] ?? 0);
$channel = trim($input['channel'] ?? '');
if (!$post_id || !$channel) {
    http_response_code(400);
    echo json_encode(['error' => 'post_id and channel required']);
    exit;
}

// Verify ownership: this user owns the site that owns this post.
$stmt = $db->prepare('SELECT p.id FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ? AND s.user_id = ?');
$stmt->execute([$post_id, $user_id]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Post not found']);
    exit;
}

$registry = channels_registry();
if (!$registry->get($channel)) {
    http_response_code(400);
    echo json_encode(['error' => "Unknown channel '{$channel}'"]);
    exit;
}

try {
    // Queue (or refresh) the post_channels row, then publish immediately
    $ids = $registry->queue_publish($db, $post_id, [$channel]);
    $row_id = $ids[$channel] ?? null;
    if (!$row_id) {
        echo json_encode(['success' => false, 'error' => 'Channel not configured for this site']);
        exit;
    }
    $result = $registry->publish_row($db, $row_id);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
