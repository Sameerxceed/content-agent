<?php
/**
 * API — Post to social media accounts.
 * POST /api/social-post.php
 * Body: { "post_id": 1, "platforms": ["linkedin", "twitter", "facebook"] }
 *
 * Generates platform-specific content from the blog post and posts to connected accounts.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/haiku.php';

auth_start();
if (!auth_check()) json_response(['error' => 'Unauthorized'], 401);

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true);
$post_id = (int)($input['post_id'] ?? 0);
$platforms = $input['platforms'] ?? ['linkedin', 'twitter', 'facebook'];

if (!$post_id) json_response(['error' => 'post_id required'], 400);

// Get post with site info
$stmt = $db->prepare('SELECT p.*, s.domain, s.brand_tone, s.blog_path FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ? AND s.user_id = ?');
$stmt->execute([$post_id, $user_id]);
$post = $stmt->fetch();

if (!$post) json_response(['error' => 'Post not found'], 404);

$blog_url = 'https://' . $post['domain'] . ($post['blog_path'] ?: '/blog') . '/' . $post['slug'];
$content = strip_tags($post['body']);
$tone = $post['brand_tone'] ?? 'professional';

$results = [];

foreach ($platforms as $platform) {
    // Check if connected
    $stmt = $db->prepare('SELECT * FROM integrations WHERE site_id = ? AND platform = ? AND is_active = 1');
    $stmt->execute([$post['site_id'], $platform]);
    $integration = $stmt->fetch();

    if (!$integration) {
        $results[$platform] = ['success' => false, 'error' => 'Not connected'];
        continue;
    }

    // Generate platform-specific content
    $social_text = generate_social_text($platform, $post['title'], $content, $tone, $blog_url);

    // Post to platform
    switch ($platform) {
        case 'linkedin':
            require_once __DIR__ . '/../../includes/integrations/linkedin.php';
            $result = linkedin_post($integration['access_token'], $integration['account_id'], $social_text, $blog_url);
            break;

        case 'twitter':
            require_once __DIR__ . '/../../includes/integrations/twitter.php';
            // Check token expiry and refresh
            if (strtotime($integration['token_expires_at']) < time() && $integration['refresh_token']) {
                $new_tokens = twitter_refresh_token($integration['refresh_token']);
                if ($new_tokens) {
                    $integration['access_token'] = $new_tokens['access_token'];
                    $db->prepare('UPDATE integrations SET access_token = ?, token_expires_at = ? WHERE id = ?')
                        ->execute([$new_tokens['access_token'], date('Y-m-d H:i:s', time() + 7200), $integration['id']]);
                }
            }
            $result = twitter_post_tweet($integration['access_token'], $social_text);
            break;

        case 'facebook':
            require_once __DIR__ . '/../../includes/integrations/facebook.php';
            $pages = json_decode($integration['extra_data'] ?? '[]', true);
            if (empty($pages)) {
                $result = ['success' => false, 'error' => 'No Facebook pages found'];
            } else {
                $page = $pages[0]; // Post to first page
                $result = facebook_post_to_page($page['access_token'], $page['id'], $social_text, $blog_url);
            }
            break;

        default:
            $result = ['success' => false, 'error' => 'Unknown platform'];
    }

    $results[$platform] = $result;

    // Save to social_posts table
    $status = $result['success'] ? 'posted' : 'failed';
    $db->prepare('INSERT INTO social_posts (post_id, site_id, platform, content, status, posted_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute([$post_id, $post['site_id'], $platform, $social_text, $status]);
}

// Log
$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $post['site_id'], 'social_post',
    json_encode(['post_id' => $post_id, 'results' => $results]),
    'success',
]);

json_response([
    'success' => true,
    'results' => $results,
]);

function generate_social_text(string $platform, string $title, string $content, string $tone, string $url): string
{
    $preview = mb_substr($content, 0, 1500);

    $prompts = [
        'linkedin' => "Write a LinkedIn post (200 words max) promoting this article. Professional tone, include key takeaway, end with the URL.\nTitle: {$title}\nURL: {$url}\nContent: {$preview}",
        'twitter'  => "Write a tweet (max 250 chars) promoting this article. Include the URL. Be punchy.\nTitle: {$title}\nURL: {$url}",
        'facebook' => "Write a Facebook post (100 words max) promoting this article. Conversational, include a question.\nTitle: {$title}\nURL: {$url}\nContent: {$preview}",
    ];

    $result = haiku_chat(
        "You are a social media manager. Write in a {$tone} tone. Output ONLY the post text, nothing else.",
        $prompts[$platform] ?? $prompts['linkedin'],
        256
    );

    if ($result['success']) {
        return trim($result['content']);
    }

    // Fallback
    switch ($platform) {
        case 'twitter': return mb_substr("📣 {$title}\n\n{$url}", 0, 280);
        case 'facebook': return "New article: {$title}\n\n" . mb_substr($content, 0, 200) . "...\n\nRead more: {$url}";
        default: return "📣 {$title}\n\n" . mb_substr($content, 0, 300) . "...\n\n👉 Read more: {$url}";
    }
}
