<?php
/**
 * API — Posts CRUD.
 * GET  /api/posts.php?site_id=1          — list posts
 * GET  /api/posts.php?id=1               — single post
 * POST /api/posts.php  { action: "approve", id: 1 }
 * POST /api/posts.php  { action: "publish", id: 1 }
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();

if (!auth_check() && !auth_api_verify()) {
    json_response(['error' => 'Unauthorized'], 401);
}

$db = require __DIR__ . '/../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $post_id = $_GET['id'] ?? null;
    $site_id = $_GET['site_id'] ?? null;
    $status = $_GET['status'] ?? null;
    $type = $_GET['type'] ?? null;
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));

    if ($post_id) {
        $stmt = $db->prepare('SELECT p.*, s.domain FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ?');
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();

        if (!$post) json_response(['error' => 'Post not found'], 404);

        $post['tags'] = json_decode($post['tags'] ?? '[]', true);
        json_response(['post' => $post]);
    }

    $where = ['1=1'];
    $params = [];

    if ($site_id) { $where[] = 'p.site_id = ?'; $params[] = (int)$site_id; }
    if ($status) { $where[] = 'p.status = ?'; $params[] = $status; }
    if ($type) { $where[] = 'p.type = ?'; $params[] = $type; }

    // If session auth, restrict to user's sites
    if (auth_check()) {
        $where[] = 's.user_id = ?';
        $params[] = auth_user_id();
    }

    $where_sql = implode(' AND ', $where);
    $params[] = $limit;

    $stmt = $db->prepare("SELECT p.id, p.site_id, p.title, p.slug, p.type, p.status, p.created_at, p.published_at, s.domain FROM posts p JOIN sites s ON p.site_id = s.id WHERE {$where_sql} ORDER BY p.created_at DESC LIMIT ?");
    $stmt->execute($params);

    json_response(['posts' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';
    $post_id = (int)($input['id'] ?? 0);

    if (!$post_id || !$action) {
        json_response(['error' => 'id and action are required'], 400);
    }

    // Verify ownership
    if (auth_check()) {
        $stmt = $db->prepare('SELECT p.id FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ? AND s.user_id = ?');
        $stmt->execute([$post_id, auth_user_id()]);
        if (!$stmt->fetch()) {
            json_response(['error' => 'Post not found'], 404);
        }
    }

    $valid_actions = ['approve', 'publish', 'reject', 'draft'];
    if (!in_array($action, $valid_actions)) {
        json_response(['error' => 'Invalid action. Use: ' . implode(', ', $valid_actions)], 400);
    }

    $status_map = [
        'approve' => 'approved',
        'publish' => 'published',
        'reject'  => 'rejected',
        'draft'   => 'draft',
    ];

    $new_status = $status_map[$action];
    $extra = $action === 'publish' ? ', published_at = NOW()' : '';

    $db->prepare("UPDATE posts SET status = ?{$extra} WHERE id = ?")->execute([$new_status, $post_id]);

    json_response(['success' => true, 'status' => $new_status]);
}

json_response(['error' => 'Method not allowed'], 405);
