<?php
/**
 * Performance Loop actions.
 *
 * POST JSON: { action, post_id, ... }
 *   - refresh        : ask Claude to rewrite the post body for the existing topic, save as draft (new post or update)
 *   - queue_similar  : create a draft keyword + content idea around the same topic
 *   - dismiss        : mark post as acknowledged (no more nagging)
 *   - fetch_now      : trigger an on-demand performance snapshot for the site
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/performance.php';
require_once __DIR__ . '/../../includes/haiku.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

try {
    if ($action === 'fetch_now') {
        $site_id = (int)($input['site_id'] ?? 0);
        $stmt = $db->prepare('SELECT id FROM sites WHERE id = ? AND user_id = ?');
        $stmt->execute([$site_id, $user_id]);
        if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }
        $org = performance_snapshot_organic($db, $site_id);
        $soc = performance_snapshot_social($db, $site_id);
        echo json_encode(['success' => true, 'organic' => $org, 'social' => $soc]);
        exit;
    }

    $post_id = (int)($input['post_id'] ?? 0);
    if (!$post_id) { http_response_code(400); echo json_encode(['error' => 'post_id required']); exit; }

    $stmt = $db->prepare('SELECT p.*, s.user_id, s.name AS site_name, s.domain
                          FROM posts p JOIN sites s ON p.site_id = s.id
                          WHERE p.id = ? AND s.user_id = ?');
    $stmt->execute([$post_id, $user_id]);
    $post = $stmt->fetch();
    if (!$post) { http_response_code(404); echo json_encode(['error' => 'Post not found']); exit; }

    if ($action === 'dismiss') {
        performance_log_action($db, $post_id, 'dismiss', $input['note'] ?? null);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'refresh') {
        // Ask Claude to rewrite body for better engagement, given what's failing.
        $cms = $db->prepare('SELECT SUM(impressions) imp, SUM(clicks) cl, AVG(ctr) ctr, AVG(avg_position) pos
                             FROM post_performance WHERE post_id = ? AND channel = "cms" AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)');
        $cms->execute([$post_id]);
        $perf = $cms->fetch() ?: [];

        $system = "You are an SEO editor. Rewrite the blog post to increase CTR and dwell time. Keep the topic identical. Punchy intro, scannable sections, a strong takeaway. Return JSON: {\"title\": \"...\", \"seo_title\": \"...\", \"seo_description\": \"...\", \"body\": \"... markdown ...\"}.";
        $why = sprintf(
            "Site: %s (%s)\nCurrent title: %s\nLast 28 days: %d impressions, %d clicks, %s%% CTR, avg position %s\n\nCurrent body:\n%s",
            $post['site_name'], $post['domain'], $post['title'],
            (int)($perf['imp'] ?? 0), (int)($perf['cl'] ?? 0),
            $perf['ctr'] !== null ? round((float)$perf['ctr'] * 100, 2) : 'n/a',
            $perf['pos'] !== null ? round((float)$perf['pos'], 1) : 'n/a',
            $post['body']
        );

        $resp = haiku_chat($system, $why, 4000);
        if (empty($resp['success'])) {
            echo json_encode(['success' => false, 'error' => $resp['error'] ?? 'AI call failed']);
            exit;
        }

        $content = trim($resp['content']);
        // Strip ```json fences if present
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['body'])) {
            echo json_encode(['success' => false, 'error' => 'AI returned unparseable response', 'raw' => $content]);
            exit;
        }

        // Save as a new draft tied to the same site — original stays live until user re-publishes
        $stmt = $db->prepare('INSERT INTO posts (site_id, title, slug, body, excerpt, seo_title, seo_description, type, status, source_url)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)');
        $new_slug = $post['slug'] . '-refresh-' . date('Ymd');
        $stmt->execute([
            $post['site_id'],
            $data['title']           ?? ($post['title'] . ' (refresh)'),
            $new_slug,
            $data['body'],
            substr(strip_tags($data['body']), 0, 200),
            $data['seo_title']       ?? null,
            $data['seo_description'] ?? null,
            $post['type'],
            'refresh-of:' . $post_id,
        ]);
        $new_id = (int)$db->lastInsertId();

        performance_log_action($db, $post_id, 'refresh_queued', 'New draft #' . $new_id);
        echo json_encode(['success' => true, 'new_post_id' => $new_id]);
        exit;
    }

    if ($action === 'queue_similar') {
        // Add a "topic to write about" — simplest path: create a draft keyword from the post title
        $kw = trim($input['keyword'] ?? $post['title']);
        $stmt = $db->prepare('INSERT INTO keywords (site_id, keyword, status, source, priority)
                              VALUES (?, ?, "active", "manual", 80)
                              ON DUPLICATE KEY UPDATE priority = GREATEST(priority, 80), status = "active"');
        $stmt->execute([$post['site_id'], $kw]);
        performance_log_action($db, $post_id, 'queue_similar', 'Keyword queued: ' . $kw);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action: ' . $action]);
} catch (Throwable $e) {
    error_log('[performance-action] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
