<?php
/**
 * Internal linking suggestions.
 *
 * For a given post, find published posts on the same site with topical overlap
 * (Claude scores the matches), and return suggested anchor-text + URL pairs.
 *
 * POST JSON: { post_id }
 * Response:  { success, suggestions: [{ anchor, url, target_post_id, reason }] }
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/haiku.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$post_id = (int)($input['post_id'] ?? 0);
if (!$post_id) { http_response_code(400); echo json_encode(['error' => 'post_id required']); exit; }

// Verify ownership
$stmt = $db->prepare('SELECT p.* FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ? AND s.user_id = ?');
$stmt->execute([$post_id, $user_id]);
$post = $stmt->fetch();
if (!$post) { http_response_code(404); echo json_encode(['error' => 'Post not found']); exit; }

$site_stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
$site_stmt->execute([$post['site_id']]);
$site = $site_stmt->fetch();

// Other published posts on the same site (candidates)
$stmt = $db->prepare("SELECT id, title, slug, excerpt FROM posts
    WHERE site_id = ? AND id != ? AND status = 'published'
    ORDER BY published_at DESC LIMIT 30");
$stmt->execute([$post['site_id'], $post_id]);
$candidates = $stmt->fetchAll();

if (count($candidates) < 1) {
    echo json_encode(['success' => true, 'suggestions' => [], 'note' => 'Need at least 1 other published post on this site to suggest internal links.']);
    exit;
}

// Build a short candidate list for Claude
$cand_text = '';
foreach ($candidates as $c) {
    $cand_text .= "[{$c['id']}] {$c['title']}";
    if (!empty($c['excerpt'])) $cand_text .= " — " . mb_substr(strip_tags($c['excerpt']), 0, 120);
    $cand_text .= "\n";
}

$body_excerpt = mb_substr(trim(strip_tags($post['body'])), 0, 2500);

$system = "You are an SEO internal-linking expert. "
    . "Given a NEW post and a list of EXISTING published posts on the same site, suggest 2-4 internal links the new post should add. "
    . "Each suggestion must be relevant — same topic family, complementary perspective, or natural reference. "
    . "Output ONLY valid JSON: an array of objects {\"target_post_id\": number, \"anchor\": string, \"reason\": string}. "
    . "Anchor text = the exact phrase that should be linked in the new post (must already exist in the new post body OR be a natural sentence to insert). "
    . "Skip any candidates that aren't a strong fit. If none are good, return [].";

$prompt = "NEW POST:\nTitle: " . $post['title'] . "\nExcerpt: " . ($post['excerpt'] ?? '') . "\nBody: " . $body_excerpt . "\n\n"
    . "EXISTING POSTS:\n" . $cand_text . "\n\nReturn 2-4 high-quality internal link suggestions as JSON.";

$ai = haiku_chat($system, $prompt, 1024);
$suggestions = [];

if ($ai['success']) {
    $content = preg_replace('/^```(?:json)?\s*/m', '', $ai['content']);
    $content = preg_replace('/\s*```\s*$/m', '', $content);
    if (preg_match('/\[.*\]/s', $content, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) {
            $domain = ltrim($site['domain'], 'https://');
            foreach ($parsed as $s) {
                $tid = (int)($s['target_post_id'] ?? 0);
                if (!$tid) continue;
                // Match candidate
                $cand = null;
                foreach ($candidates as $c) {
                    if ((int)$c['id'] === $tid) { $cand = $c; break; }
                }
                if (!$cand) continue;
                $suggestions[] = [
                    'target_post_id' => $tid,
                    'anchor'         => trim($s['anchor'] ?? ''),
                    'reason'         => trim($s['reason'] ?? ''),
                    'url'            => 'https://' . $domain . '/blog/' . $cand['slug'],
                    'title'          => $cand['title'],
                ];
            }
        }
    }
}

echo json_encode(['success' => true, 'suggestions' => $suggestions]);
