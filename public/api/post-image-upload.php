<?php
/**
 * Upload a user-supplied hero image for a post.
 *
 * Multipart form: hero_image (file), post_id (int)
 * Returns: { success, url, alt }
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/image_gen.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

function piu_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) { $stray = ob_get_clean(); if (trim($stray) !== '') error_log('[post-image-upload] stray: ' . substr($stray, 0, 500)); }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';

$post_id = (int)($_POST['post_id'] ?? 0);
if (!$post_id) piu_respond(['error' => 'post_id required'], 400);
if (empty($_FILES['hero_image'])) piu_respond(['error' => 'hero_image file required'], 400);

$stmt = $db->prepare("SELECT site_id FROM posts WHERE id = ?");
$stmt->execute([$post_id]);
$site_id = (int)$stmt->fetchColumn();
if (!$site_id) piu_respond(['error' => 'Post not found'], 404);
if (!auth_can_access_site($db, $site_id)) piu_respond(['error' => 'Access denied'], 403);

$result = image_save_upload($db, $post_id, $_FILES['hero_image']);
if (isset($result['error'])) piu_respond(['error' => $result['error']], 400);

piu_respond(['success' => true, 'url' => $result['url'], 'alt' => $result['alt']]);
