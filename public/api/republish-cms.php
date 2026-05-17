<?php
/**
 * Republish a post to its site's CMS — for fixing posts that show as
 * "published" locally but aren't actually on the customer's website
 * (e.g. older news-scraper output before the auto-push fix).
 *
 * POST JSON:
 *   { post_id: 21 }                — republish one post
 *   { site_id: 1, all_news: true } — republish every published news post
 *                                    that hasn't been confirmed on the CMS
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cms-connector.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

function _push_one(PDO $db, array $post): array
{
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$post['site_id']]);
    $site = $stmt->fetch();
    if (!$site)                              return ['success' => false, 'error' => 'Site not found'];
    if (empty($site['cms_url']))             return ['success' => false, 'error' => 'CMS URL not set for site'];
    if (empty($site['cms_api_key']))         return ['success' => false, 'error' => 'CMS API key not set for site'];

    $result = cms_push_post([
        'title'           => $post['title'],
        'slug'            => $post['slug'],
        'excerpt'         => $post['excerpt'] ?? '',
        'body'            => $post['body'],
        'tags'            => $post['tags'] ?? '[]',
        'seo_title'       => $post['seo_title'] ?? $post['title'],
        'seo_description' => $post['seo_description'] ?? '',
        'seo_keywords'    => $post['seo_keywords'] ?? '',
        'published_at'    => $post['published_at'] ?? null,
        'type'            => $post['type'] ?? 'blog',
    ], $site['cms_url'], $site['cms_api_key']);

    // Always verify after — even if push reported success, confirm the CMS
    // actually serves the post by slug. This catches CMSes that 201 but don't
    // really persist, or that store a different slug than we sent.
    $verify = cms_verify_post($post['slug'], $site['cms_url'], $site['cms_api_key']);
    $result['verify'] = $verify;

    // If the CMS doesn't return the post by slug after a "successful" push,
    // we should NOT call it a success.
    if (!empty($result['success']) && !$verify['found']) {
        $result['success'] = false;
        $result['error']   = 'CMS accepted the push but the post is not retrievable by slug. Likely the CMS stored a different slug or rejected silently. Verify HTTP=' . $verify['http_status'];
    }

    return $result;
}

try {
    if (!empty($input['post_id'])) {
        $post_id = (int)$input['post_id'];
        $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();
        if (!$post)                                       { http_response_code(404); echo json_encode(['error' => 'Post not found']); exit; }
        if (!auth_can_access_site($db, (int)$post['site_id'])) { http_response_code(403); echo json_encode(['error' => 'Access denied']); exit; }

        $result = _push_one($db, $post);
        echo json_encode($result);
        exit;
    }

    if (!empty($input['all_news']) && !empty($input['site_id'])) {
        $site_id = (int)$input['site_id'];
        if (!auth_can_access_site($db, $site_id)) {
            http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
        }
        // Re-push every published news post on this site (idempotent —
        // cms_push_post handles 409 duplicate via update).
        $stmt = $db->prepare("SELECT * FROM posts WHERE site_id = ? AND type = 'news' AND status = 'published' ORDER BY created_at DESC");
        $stmt->execute([$site_id]);
        $posts = $stmt->fetchAll();

        $pushed = 0; $failed = 0; $errors = [];
        foreach ($posts as $post) {
            $r = _push_one($db, $post);
            if (!empty($r['success'])) {
                $pushed++;
            } else {
                $failed++;
                if (count($errors) < 5) $errors[] = $post['slug'] . ': ' . ($r['error'] ?? 'unknown');
            }
            usleep(150000); // 150ms throttle
        }
        echo json_encode(['success' => true, 'pushed' => $pushed, 'failed' => $failed, 'errors' => $errors, 'total' => count($posts)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Provide post_id OR (site_id + all_news=true)']);
} catch (Throwable $e) {
    error_log('[republish-cms] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
