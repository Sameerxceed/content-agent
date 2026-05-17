<?php
/**
 * Find published posts whose slug is over 80 chars (long-slug bug from before
 * the cap was added). Shortens each in the local DB, then re-pushes to the
 * CMS so a fresh INSERT happens under the short slug.
 *
 * POST JSON: { site_id }
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cms-connector.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);

if (!auth_can_access_site($db, $site_id)) {
    http_response_code(404); echo json_encode(['error' => 'Site not found']); exit;
}

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
$stmt->execute([$site_id]);
$site = $stmt->fetch();

if (empty($site['cms_url']) || empty($site['cms_api_key'])) {
    http_response_code(400); echo json_encode(['error' => 'CMS not configured for this site']); exit;
}

try {
    $stmt = $db->prepare("SELECT * FROM posts WHERE site_id = ? AND status = 'published' AND CHAR_LENGTH(slug) > 80");
    $stmt->execute([$site_id]);
    $posts = $stmt->fetchAll();

    if (empty($posts)) {
        echo json_encode(['success' => true, 'fixed' => 0, 'message' => 'No long-slug posts found.']);
        exit;
    }

    $upd_slug = $db->prepare('UPDATE posts SET slug = ? WHERE id = ?');
    $check    = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND slug = ? AND id != ?');

    $fixed = 0; $failed = 0; $reports = [];
    foreach ($posts as $post) {
        $old_slug = $post['slug'];

        // Generate a shorter slug — same logic as the news-scraper cap
        $new_slug = substr($old_slug, 0, 80);
        $last_dash = strrpos($new_slug, '-');
        if ($last_dash !== false && $last_dash > 40) {
            $new_slug = substr($new_slug, 0, $last_dash);
        }

        // De-duplicate within this site
        $base = $new_slug; $i = 2;
        while (true) {
            $check->execute([$site_id, $new_slug, $post['id']]);
            if ($check->fetchColumn() == 0) break;
            $new_slug = $base . '-' . $i++;
        }

        $upd_slug->execute([$new_slug, $post['id']]);

        // Push to CMS with the new short slug → fresh INSERT
        $push_payload = $post;
        $push_payload['slug'] = $new_slug;
        $result = cms_push_post($push_payload, $site['cms_url'], $site['cms_api_key']);

        if (!empty($result['success'])) {
            $fixed++;
            $reports[] = ['old_slug' => $old_slug, 'new_slug' => $new_slug, 'status' => 'ok'];
        } else {
            $failed++;
            $reports[] = ['old_slug' => $old_slug, 'new_slug' => $new_slug, 'status' => 'push_failed', 'error' => $result['error'] ?? 'unknown'];
        }
        usleep(200000);
    }

    echo json_encode([
        'success' => true,
        'total'   => count($posts),
        'fixed'   => $fixed,
        'failed'  => $failed,
        'reports' => $reports,
    ]);
} catch (Throwable $e) {
    error_log('[fix-long-slugs] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
