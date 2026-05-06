<?php
/**
 * API — Publish post to external CMS.
 * POST /api/publish.php
 * Body: { "post_id": 1 }
 *
 * Pushes the post to the site's configured CMS, then marks it as published.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cms-connector.php';

auth_start();

if (!auth_check()) {
    json_response(['error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$post_id = (int)($input['post_id'] ?? 0);

if (!$post_id) {
    json_response(['error' => 'post_id is required'], 400);
}

// Get post with site info
$stmt = $db->prepare('
    SELECT p.*, s.domain, s.cms_url, s.cms_api_key
    FROM posts p
    JOIN sites s ON p.site_id = s.id
    WHERE p.id = ? AND s.user_id = ?
');
$stmt->execute([$post_id, $user_id]);
$post = $stmt->fetch();

if (!$post) {
    json_response(['error' => 'Post not found'], 404);
}

$cms_url = $post['cms_url'];
$cms_api_key = $post['cms_api_key'];

if (empty($cms_url) || empty($cms_api_key)) {
    json_response(['error' => 'CMS not configured for this site. Edit site settings to add CMS URL and API key.'], 400);
}

// Detect connector type and push
$platform = strtolower($post['platform'] ?? '');

if ($platform === 'wordpress' || strpos($cms_url, 'wp-json') !== false) {
    require_once __DIR__ . '/../../includes/connectors/wordpress.php';
    $result = wp_push_post($post, $cms_url, $cms_api_key);
} elseif ($platform === 'shopify' || strpos($cms_url, 'myshopify.com') !== false) {
    require_once __DIR__ . '/../../includes/connectors/shopify.php';
    $result = shopify_push_post($post, $cms_url, $cms_api_key);
} else {
    // Default: generic REST connector (Xceed CMS style)
    $result = cms_push_post($post, $cms_url, $cms_api_key);
}

if ($result['success']) {
    // Mark as published locally
    $db->prepare('UPDATE posts SET status = "published", published_at = NOW() WHERE id = ?')->execute([$post_id]);

    // Regenerate llms.txt with new post included
    require_once __DIR__ . '/../../includes/ai-seo.php';
    $stmt_site = $db->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt_site->execute([$post['site_id']]);
    $pub_site = $stmt_site->fetch();
    if ($pub_site) {
        regenerate_llms_txt($pub_site, $db);
    }

    // Log
    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $post['site_id'],
        'publish_to_cms',
        json_encode(['post_id' => $post_id, 'slug' => $result['slug'] ?? $post['slug'], 'cms' => $cms_url]),
        'success',
    ]);

    json_response([
        'success' => true,
        'message' => 'Post published to ' . $post['domain'],
        'slug'    => $result['slug'] ?? $post['slug'],
        'url'     => 'https://' . $post['domain'] . '/blog/' . ($result['slug'] ?? $post['slug']),
    ]);
} else {
    json_response([
        'success' => false,
        'error'   => $result['error'],
    ], 500);
}
