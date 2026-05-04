<?php
/**
 * Social Poster Agent
 * Repurposes blog posts into social media content.
 * Generates LinkedIn, Twitter/X, and Facebook versions.
 *
 * CLI Usage: php agent/social-poster.php --site=1
 *            php agent/social-poster.php --post=5
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/haiku.php';

$db = require __DIR__ . '/../includes/db.php';

$opts = getopt('', ['site:', 'post:']);
$site_id = $opts['site'] ?? null;
$post_id = $opts['post'] ?? null;

if (!$site_id && !$post_id) {
    echo "Usage: php social-poster.php --site=1  (process latest published posts)\n";
    echo "       php social-poster.php --post=5  (repurpose specific post)\n";
    exit(1);
}

$start_time = microtime(true);

// Get posts to repurpose
if ($post_id) {
    $stmt = $db->prepare('SELECT p.*, s.domain, s.brand_tone FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ?');
    $stmt->execute([$post_id]);
    $posts = $stmt->fetchAll();
} else {
    // Get recent published posts that don't have social posts yet
    $stmt = $db->prepare('
        SELECT p.*, s.domain, s.brand_tone
        FROM posts p
        JOIN sites s ON p.site_id = s.id
        WHERE p.site_id = ? AND p.status = "published" AND p.type = "blog"
          AND p.id NOT IN (SELECT DISTINCT post_id FROM social_posts WHERE site_id = ?)
        ORDER BY p.published_at DESC
        LIMIT 5
    ');
    $stmt->execute([$site_id, $site_id]);
    $posts = $stmt->fetchAll();
}

if (empty($posts)) {
    echo "No posts to repurpose.\n";
    exit(0);
}

echo "Social Poster: " . count($posts) . " posts to repurpose\n";
echo str_repeat('=', 60) . "\n";

$platforms = ['linkedin', 'twitter', 'facebook'];
$total_created = 0;

foreach ($posts as $post) {
    echo "\nPost: {$post['title']}\n";
    $content = strip_tags($post['body']);
    $tone = $post['brand_tone'] ?? 'professional';
    $url = "https://{$post['domain']}{$post['slug']}";

    foreach ($platforms as $platform) {
        echo "  Generating {$platform}...\n";

        $social_content = generate_social_content($platform, $post['title'], $content, $tone, $url);

        if ($social_content) {
            $stmt = $db->prepare('INSERT INTO social_posts (post_id, site_id, platform, content, status) VALUES (?, ?, ?, ?, "draft")');
            $stmt->execute([
                $post['id'],
                $post['site_id'],
                $platform,
                $social_content,
            ]);
            $total_created++;
            echo "    OK (" . mb_strlen($social_content) . " chars)\n";
        } else {
            echo "    SKIPPED (AI unavailable)\n";
        }
    }
}

$duration = round((microtime(true) - $start_time) * 1000);
echo "\nDone! Created {$total_created} social posts in {$duration}ms\n";

// Log
$log_site = $site_id ?: ($posts[0]['site_id'] ?? null);
if ($log_site) {
    $stmt = $db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $log_site,
        'social_poster',
        json_encode(['posts_processed' => count($posts), 'social_created' => $total_created]),
        'success',
        $duration,
    ]);
}

// ── Functions ───────────────────────────────────────────

function generate_social_content(string $platform, string $title, string $content, string $tone, string $url): ?string
{
    $preview = mb_substr($content, 0, 2000);

    $prompts = [
        'linkedin' => [
            'system' => "You are a LinkedIn content specialist. Write in a {$tone} tone. Output ONLY the post text, no explanation.",
            'user'   => "Write a LinkedIn post (200-250 words) promoting this article. Include a hook opening line, 2-3 key takeaways as bullet points, a call to action, and end with the URL.\n\nTitle: {$title}\nURL: {$url}\nContent: {$preview}",
        ],
        'twitter' => [
            'system' => "You are a Twitter/X content specialist. Write in a {$tone} tone. Output ONLY the tweets, numbered 1-5, each under 280 characters. No explanation.",
            'user'   => "Create a 5-tweet thread promoting this article. Tweet 1 = hook with emoji. Tweets 2-4 = key insights. Tweet 5 = CTA + URL.\n\nTitle: {$title}\nURL: {$url}\nContent: {$preview}",
        ],
        'facebook' => [
            'system' => "You are a Facebook content specialist. Write in a {$tone} tone. Output ONLY the post text, no explanation.",
            'user'   => "Write a Facebook post (100-150 words) promoting this article. Conversational, engaging, with a question to drive comments. Include the URL.\n\nTitle: {$title}\nURL: {$url}\nContent: {$preview}",
        ],
    ];

    if (!isset($prompts[$platform])) return null;

    $result = haiku_chat($prompts[$platform]['system'], $prompts[$platform]['user'], 512);

    if (!$result['success']) {
        // Fallback: simple template
        return generate_fallback($platform, $title, $url, $preview);
    }

    return trim($result['content']);
}

function generate_fallback(string $platform, string $title, string $url, string $content): string
{
    $excerpt = truncate($content, 200);

    switch ($platform) {
        case 'linkedin':
            return "📣 New article: {$title}\n\n{$excerpt}\n\n👉 Read more: {$url}\n\n#business #technology";

        case 'twitter':
            return "🆕 {$title}\n\n{$excerpt}\n\n🔗 {$url}";

        case 'facebook':
            return "We just published a new article! 🎉\n\n{$title}\n\n{$excerpt}\n\nRead the full article here: {$url}";

        default:
            return "{$title}\n\n{$url}";
    }
}
