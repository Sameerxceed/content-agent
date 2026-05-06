<?php
/**
 * AI Presence Builder — discover conversations across platforms
 * and generate ready-to-post responses.
 *
 * Platforms are organized into tiers:
 * - Tier 1: API-capable (Reddit, LinkedIn) — can auto-post later
 * - Tier 2: Web search (Quora, Twitter, forums, blogs) — copy-paste only
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/scraper.php';
require_once __DIR__ . '/haiku.php';

// All platforms we track
define('PRESENCE_PLATFORMS', [
    'reddit' => [
        'name' => 'Reddit',
        'icon' => 'R',
        'color' => '#FF4500',
        'search_domain' => 'reddit.com',
        'can_auto_post' => true,
        'description' => '#1 source cited by AI models. Subreddit discussions carry massive weight.',
        'impact' => 'Very High',
    ],
    'quora' => [
        'name' => 'Quora',
        'icon' => 'Q',
        'color' => '#B92B27',
        'search_domain' => 'quora.com',
        'can_auto_post' => false,
        'description' => 'Q&A platform. Answers get indexed by Google and cited by AI.',
        'impact' => 'High',
    ],
    'linkedin' => [
        'name' => 'LinkedIn',
        'icon' => 'in',
        'color' => '#0A66C2',
        'search_domain' => 'linkedin.com',
        'can_auto_post' => true,
        'description' => 'Professional network. Great for B2B visibility and thought leadership.',
        'impact' => 'High',
    ],
    'twitter' => [
        'name' => 'Twitter / X',
        'icon' => 'X',
        'color' => '#000000',
        'search_domain' => 'twitter.com',
        'can_auto_post' => false,
        'description' => 'Real-time conversations. Trending topics get picked up by AI models.',
        'impact' => 'Medium',
    ],
    'stackoverflow' => [
        'name' => 'Stack Overflow',
        'icon' => 'SO',
        'color' => '#F48024',
        'search_domain' => 'stackoverflow.com',
        'can_auto_post' => false,
        'description' => 'Technical Q&A. Top answers are heavily cited by AI coding assistants.',
        'impact' => 'High (for tech)',
    ],
    'medium' => [
        'name' => 'Medium',
        'icon' => 'M',
        'color' => '#000000',
        'search_domain' => 'medium.com',
        'can_auto_post' => false,
        'description' => 'Publishing platform. Articles rank well and get cited by AI.',
        'impact' => 'Medium',
    ],
    'youtube' => [
        'name' => 'YouTube',
        'icon' => 'YT',
        'color' => '#FF0000',
        'search_domain' => 'youtube.com',
        'can_auto_post' => false,
        'description' => 'Video comments and descriptions. YouTube is the 2nd largest search engine.',
        'impact' => 'Medium',
    ],
    'hackernews' => [
        'name' => 'Hacker News',
        'icon' => 'HN',
        'color' => '#FF6600',
        'search_domain' => 'news.ycombinator.com',
        'can_auto_post' => false,
        'description' => 'Tech community. Posts here get massive visibility and AI model attention.',
        'impact' => 'High (for tech)',
    ],
    'producthunt' => [
        'name' => 'Product Hunt',
        'icon' => 'PH',
        'color' => '#DA552F',
        'search_domain' => 'producthunt.com',
        'can_auto_post' => false,
        'description' => 'Product launches. Great for SaaS and tech product visibility.',
        'impact' => 'Medium',
    ],
    'github' => [
        'name' => 'GitHub',
        'icon' => 'GH',
        'color' => '#24292E',
        'search_domain' => 'github.com',
        'can_auto_post' => false,
        'description' => 'Code discussions, issues, and READMEs are cited by AI coding tools.',
        'impact' => 'High (for tech)',
    ],
    'facebook' => [
        'name' => 'Facebook Groups',
        'icon' => 'FB',
        'color' => '#1877F2',
        'search_domain' => 'facebook.com',
        'can_auto_post' => false,
        'description' => 'Group discussions. Niche communities have active conversations.',
        'impact' => 'Low',
    ],
    'wikipedia' => [
        'name' => 'Wikipedia',
        'icon' => 'W',
        'color' => '#636466',
        'search_domain' => 'wikipedia.org',
        'can_auto_post' => false,
        'description' => 'The ultimate authority source. Getting mentioned here is gold for AI visibility.',
        'impact' => 'Very High',
    ],
]);

/**
 * Search for conversations across all platforms using web search.
 * Returns array of conversations found per platform.
 */
function presence_scan_conversations(array $site, PDO $db, array $platforms = []): array
{
    $name = $site['name'];
    $domain = $site['domain'];
    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];
    $stmt = $db->prepare('SELECT keyword FROM keywords WHERE site_id = ? ORDER BY priority DESC LIMIT 5');
    $stmt->execute([$site['id']]);
    $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Build search queries
    $search_terms = [];
    if (!empty($keywords)) {
        $search_terms[] = $keywords[0]; // top keyword
    }
    if (!empty($topics)) {
        $search_terms[] = $topics[0]; // top topic
    }
    $search_terms[] = $name; // brand name

    // If no platforms specified, scan all
    if (empty($platforms)) {
        $platforms = array_keys(PRESENCE_PLATFORMS);
    }

    $results = [];

    foreach ($platforms as $platform_key) {
        if (!isset(PRESENCE_PLATFORMS[$platform_key])) continue;
        $platform = PRESENCE_PLATFORMS[$platform_key];

        $conversations = [];

        foreach ($search_terms as $term) {
            $found = _presence_web_search($term, $platform['search_domain']);
            $conversations = array_merge($conversations, $found);
        }

        // Deduplicate by URL
        $seen = [];
        $unique = [];
        foreach ($conversations as $c) {
            $url = $c['url'] ?? '';
            if (!empty($url) && !isset($seen[$url])) {
                $seen[$url] = true;
                $c['platform'] = $platform_key;
                $unique[] = $c;
            }
        }

        $results[$platform_key] = [
            'platform' => $platform,
            'conversations' => array_slice($unique, 0, 5), // max 5 per platform
            'count' => count($unique),
        ];
    }

    return $results;
}

/**
 * Web search for conversations on a specific platform.
 */
function _presence_web_search(string $query, string $site_domain): array
{
    $search_url = 'https://www.google.com/search?q=' . urlencode($query . ' site:' . $site_domain) . '&num=5';

    $result = scraper_fetch($search_url, 10);
    if ($result['status'] !== 200 || empty($result['body'])) {
        return [];
    }

    $conversations = [];
    $html = $result['body'];

    // Parse Google search results
    if (preg_match_all('/<a[^>]+href="\/url\?q=([^"&]+)[^"]*"[^>]*>.*?<\/a>/s', $html, $matches)) {
        foreach ($matches[1] as $url) {
            $url = urldecode($url);
            if (strpos($url, $site_domain) === false) continue;
            if (strpos($url, 'google.com') !== false) continue;

            $conversations[] = [
                'url' => $url,
                'title' => '',
                'snippet' => '',
            ];
        }
    }

    // Alternative: parse h3 titles
    if (preg_match_all('/<h3[^>]*>(.*?)<\/h3>/s', $html, $h3_matches)) {
        foreach ($h3_matches[1] as $i => $title) {
            if (isset($conversations[$i])) {
                $conversations[$i]['title'] = strip_tags($title);
            }
        }
    }

    // If Google blocks us, try a simpler approach — fetch the platform directly
    if (empty($conversations) && $site_domain === 'reddit.com') {
        return _presence_reddit_search($query);
    }

    return $conversations;
}

/**
 * Direct Reddit search via their JSON API (no auth needed for search).
 */
function _presence_reddit_search(string $query): array
{
    $url = 'https://www.reddit.com/search.json?q=' . urlencode($query) . '&sort=relevance&limit=5&type=link';

    $result = scraper_fetch($url, 10);
    if ($result['status'] !== 200) return [];

    $data = json_decode($result['body'], true);
    if (empty($data['data']['children'])) return [];

    $conversations = [];
    foreach ($data['data']['children'] as $child) {
        $post = $child['data'];
        $conversations[] = [
            'url' => 'https://www.reddit.com' . $post['permalink'],
            'title' => $post['title'],
            'snippet' => substr($post['selftext'] ?? '', 0, 200),
            'subreddit' => $post['subreddit'],
            'score' => $post['score'],
            'num_comments' => $post['num_comments'],
            'created' => date('Y-m-d', $post['created_utc']),
        ];
    }

    return $conversations;
}

/**
 * Generate an AI reply for a specific conversation.
 */
function presence_draft_reply(array $site, array $conversation, PDO $db): array
{
    $name = $site['name'];
    $domain = $site['domain'];
    $tone = $site['brand_tone'] ?? '';
    $platform = $conversation['platform'] ?? 'forum';

    $platform_guides = [
        'reddit' => "Write like a helpful Reddit user. Be genuine, add value first. Don't sound promotional. Mention {$name} only if it's naturally relevant as a recommendation. Follow Reddiquette.",
        'quora' => "Write a thorough, expert answer. Be detailed and authoritative. You can mention {$name} as one solution among others. Quora rewards comprehensive answers.",
        'linkedin' => "Write a professional, insightful comment. Add a unique perspective. Mention {$name} if relevant to the discussion. Keep it professional but personable.",
        'twitter' => "Write a concise, engaging reply (under 280 chars). Be witty but helpful. Mention @{$domain} only if natural.",
        'stackoverflow' => "Write a technically accurate answer. Code examples if relevant. Mention {$name} only if it solves the specific problem. Stack Overflow bans self-promotion.",
        'medium' => "Write a thoughtful comment on the article. Add to the discussion. Mention {$name} only if directly relevant.",
        'hackernews' => "Write a substantive comment. HN values technical depth and original thinking. Subtle mentions only, no marketing speak.",
        'youtube' => "Write a helpful comment adding to the video's topic. Keep it conversational.",
    ];

    $guide = $platform_guides[$platform] ?? "Write a helpful reply that adds value to the conversation. Mention {$name} naturally if relevant.";

    $system = "You are helping {$name} ({$domain}) engage with online conversations. "
        . "Brand tone: {$tone}. "
        . "Platform: " . (PRESENCE_PLATFORMS[$platform]['name'] ?? $platform) . ". "
        . $guide . " "
        . "Write ONLY the reply text, nothing else. No quotes, no labels, no explanations.";

    $user_msg = "Here's the conversation to reply to:\n\n"
        . "Title: " . ($conversation['source_title'] ?? $conversation['title'] ?? '') . "\n"
        . "Content: " . substr($conversation['source_content'] ?? $conversation['snippet'] ?? '', 0, 500) . "\n\n"
        . "Write a helpful reply:";

    $result = haiku_chat($system, $user_msg, 1024);

    if ($result['success'] && !empty($result['content'])) {
        return ['success' => true, 'reply' => trim($result['content'])];
    }

    return ['success' => false, 'reply' => '', 'error' => $result['error'] ?? 'AI generation failed'];
}

/**
 * Save a discovered conversation to the database.
 */
function presence_save(PDO $db, int $site_id, array $data): int
{
    $stmt = $db->prepare('INSERT INTO ai_presence_content (site_id, platform, source_url, source_title, source_content, reply_content, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $site_id,
        $data['platform'],
        $data['url'] ?? null,
        $data['title'] ?? null,
        $data['snippet'] ?? $data['source_content'] ?? null,
        $data['reply'] ?? null,
        $data['reply'] ? 'reply_drafted' : 'found',
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Update status of a presence content item.
 */
function presence_update_status(PDO $db, int $id, int $site_id, string $status): bool
{
    $stmt = $db->prepare('UPDATE ai_presence_content SET status = ?, posted_at = CASE WHEN ? = "posted" THEN NOW() ELSE posted_at END WHERE id = ? AND site_id = ?');
    return $stmt->execute([$status, $status, $id, $site_id]);
}

/**
 * Update reply content.
 */
function presence_update_reply(PDO $db, int $id, int $site_id, string $reply): bool
{
    $stmt = $db->prepare('UPDATE ai_presence_content SET reply_content = ?, status = "reply_drafted" WHERE id = ? AND site_id = ?');
    return $stmt->execute([$reply, $id, $site_id]);
}

/**
 * Get all stored presence content for a site.
 */
function presence_get_all(PDO $db, int $site_id, ?string $platform = null, ?string $status = null): array
{
    $where = ['site_id = ?'];
    $params = [$site_id];

    if ($platform) { $where[] = 'platform = ?'; $params[] = $platform; }
    if ($status) { $where[] = 'status = ?'; $params[] = $status; }

    $sql = 'SELECT * FROM ai_presence_content WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 100';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get stats for a site's presence content.
 */
function presence_get_stats(PDO $db, int $site_id): array
{
    $stmt = $db->prepare('SELECT platform, status, COUNT(*) as cnt FROM ai_presence_content WHERE site_id = ? GROUP BY platform, status');
    $stmt->execute([$site_id]);
    $rows = $stmt->fetchAll();

    $stats = ['total' => 0, 'drafted' => 0, 'posted' => 0, 'platforms' => []];
    foreach ($rows as $r) {
        $stats['total'] += $r['cnt'];
        if ($r['status'] === 'reply_drafted') $stats['drafted'] += $r['cnt'];
        if ($r['status'] === 'posted') $stats['posted'] += $r['cnt'];
        $stats['platforms'][$r['platform']] = ($stats['platforms'][$r['platform']] ?? 0) + $r['cnt'];
    }
    return $stats;
}
