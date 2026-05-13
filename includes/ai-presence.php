<?php
/**
 * AI Presence Builder — discover conversations across platforms
 * and generate ready-to-post responses.
 *
 * Uses direct APIs where available (Reddit, HN, StackExchange, YouTube).
 * Falls back to web search for others.
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
 * Scan a single platform for conversations.
 * Uses direct APIs where available, falls back to web search.
 */
function presence_scan_platform(string $platform_key, array $search_terms): array
{
    if (!isset(PRESENCE_PLATFORMS[$platform_key])) return [];

    $conversations = [];

    foreach ($search_terms as $term) {
        switch ($platform_key) {
            case 'reddit':
                $found = _presence_reddit_search($term);
                break;
            case 'hackernews':
                $found = _presence_hn_search($term);
                break;
            case 'stackoverflow':
                $found = _presence_stackexchange_search($term);
                break;
            case 'youtube':
                $found = _presence_youtube_search($term);
                break;
            case 'github':
                $found = _presence_github_search($term);
                break;
            default:
                $found = _presence_web_search($term, PRESENCE_PLATFORMS[$platform_key]['search_domain']);
                break;
        }
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

    // Filter by recency (1 month for fast platforms, 3 months for others)
    $unique = _presence_filter_recent($unique, $platform_key);

    return array_slice($unique, 0, 8);
}

/**
 * Build search terms from a site's data.
 */
function presence_build_search_terms(array $site, PDO $db): array
{
    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];
    $stmt = $db->prepare("SELECT keyword FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY priority DESC LIMIT 10");
    $stmt->execute([$site['id']]);
    $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Use AI to generate short, broad search terms that will actually find conversations
    $site_label = $site['name'] . ' (' . $site['domain'] . ')';
    $context = "Topics: " . implode(', ', $topics) . "\nKeywords: " . implode(', ', array_slice($keywords, 0, 8));

    $ai = haiku_chat(
        "Given this business, generate 5 short search terms (2-4 words each) that people would discuss on Reddit, Quora, or forums. "
        . "Focus on the INDUSTRY and PROBLEMS the business solves, not the brand name. "
        . "Output ONLY a JSON array of strings. Example: [\"web development trends\", \"best CRM software\", \"ecommerce platform comparison\"]",
        "Business: {$site_label}\n{$context}",
        256
    );

    $terms = [];
    if ($ai['success']) {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $ai['content']);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $parsed = json_decode(trim($content), true);
        if (is_array($parsed)) {
            $terms = array_slice($parsed, 0, 5);
        }
    }

    // Fallback: use short versions of topics/keywords
    if (empty($terms)) {
        foreach ($topics as $t) {
            $terms[] = $t;
        }
        // Extract short (2-4 word) keywords only
        foreach ($keywords as $kw) {
            if (str_word_count($kw) <= 4) {
                $terms[] = $kw;
            }
        }
        if (empty($terms)) {
            $terms[] = $site['name'];
        }
    }

    return array_unique(array_slice($terms, 0, 5));
}

/**
 * Search all platforms (used for full scan).
 */
function presence_scan_conversations(array $site, PDO $db, array $platforms = []): array
{
    $search_terms = presence_build_search_terms($site, $db);

    if (empty($platforms)) {
        $platforms = array_keys(PRESENCE_PLATFORMS);
    }

    $results = [];
    foreach ($platforms as $platform_key) {
        $conversations = presence_scan_platform($platform_key, $search_terms);
        $results[$platform_key] = [
            'platform' => PRESENCE_PLATFORMS[$platform_key] ?? [],
            'conversations' => $conversations,
            'count' => count($conversations),
        ];
    }

    return $results;
}

// ── Helpers ──────────────────────────────────────────────

// Fast-moving platforms: 1 month. Others: 3 months.
const PLATFORM_RECENCY = [
    'reddit' => '-1 month', 'hackernews' => '-1 month', 'twitter' => '-1 month',
    'producthunt' => '-1 month', 'youtube' => '-3 months',
    'stackoverflow' => '-3 months', 'quora' => '-3 months', 'linkedin' => '-3 months',
    'medium' => '-3 months', 'github' => '-3 months', 'facebook' => '-3 months',
    'wikipedia' => '-6 months',
];

/** Filter out old conversations based on platform recency */
function _presence_filter_recent(array $conversations, string $platform = ''): array
{
    $window = PLATFORM_RECENCY[$platform] ?? '-3 months';
    $cutoff = strtotime($window);
    return array_values(array_filter($conversations, function($c) use ($cutoff) {
        $date = $c['created'] ?? '';
        if (!$date) return true; // keep if no date
        $ts = strtotime($date);
        return $ts && $ts >= $cutoff;
    }));
}

// ── Shared search engines ────────────────────────────────

/**
 * Google Custom Search Engine — works for any platform via site: filter.
 * Free: 100 queries/day. Returns real search results with dates.
 */
function _presence_google_cse_search(string $query, string $site_domain = ''): array
{
    $api_key = config('google_cse_api_key');
    $cx = config('google_cse_cx');
    if (!$api_key || !$cx) return [];

    $params = [
        'key' => $api_key,
        'cx' => $cx,
        'q' => $query . ($site_domain ? ' site:' . $site_domain : ''),
        'num' => 8,
        'dateRestrict' => 'm3', // last 3 months
    ];
    $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query($params);
    $result = scraper_fetch($url, 10);
    if ($result['status'] !== 200) return [];

    $data = json_decode($result['body'], true);
    if (empty($data['items'])) return [];

    $conversations = [];
    foreach ($data['items'] as $item) {
        // Extract date from snippet or metadata
        $created = '';
        if (!empty($item['pagemap']['metatags'][0]['article:published_time'])) {
            $created = date('Y-m-d', strtotime($item['pagemap']['metatags'][0]['article:published_time']));
        } elseif (preg_match('/(\d{1,2}\s+\w+\s+\d{4}|\w+\s+\d{1,2},?\s+\d{4})/', $item['snippet'] ?? '', $m)) {
            $ts = strtotime($m[1]);
            if ($ts) $created = date('Y-m-d', $ts);
        }

        $conversations[] = [
            'url' => $item['link'],
            'title' => $item['title'] ?? '',
            'snippet' => mb_substr(strip_tags($item['snippet'] ?? ''), 0, 150),
            'created' => $created,
        ];
    }
    return $conversations;
}

/**
 * Reddit OAuth search — authenticated, reliable, not blocked.
 */
function _presence_reddit_oauth_search(string $query, string $client_id, string $client_secret): array
{
    // Get application-only OAuth token
    $ch = curl_init('https://www.reddit.com/api/v1/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_USERPWD => $client_id . ':' . $client_secret,
        CURLOPT_USERAGENT => 'linux:contentagent:v1.0 (by /u/contentagent_bot)',
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) return [];

    $auth = json_decode($body, true);
    $token = $auth['access_token'] ?? '';
    if (!$token) return [];

    // Search using OAuth token
    $url = 'https://oauth.reddit.com/search?q=' . urlencode($query) . '&sort=new&limit=10&t=month&type=link';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'linux:contentagent:v1.0 (by /u/contentagent_bot)',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) return [];

    $data = json_decode($body, true);
    if (empty($data['data']['children'])) return [];

    $conversations = [];
    foreach ($data['data']['children'] as $child) {
        $post = $child['data'];
        $conversations[] = [
            'url' => 'https://www.reddit.com' . $post['permalink'],
            'title' => $post['title'],
            'snippet' => mb_substr(strip_tags(html_entity_decode($post['selftext'] ?? '', ENT_QUOTES, 'UTF-8')), 0, 150),
            'subreddit' => $post['subreddit'] ?? '',
            'score' => $post['score'] ?? 0,
            'num_comments' => $post['num_comments'] ?? 0,
            'created' => date('Y-m-d', $post['created_utc'] ?? time()),
        ];
    }
    return $conversations;
}

// ── Direct API searches ──────────────────────────────────

/**
 * Reddit search — uses OAuth if configured, falls back to Google CSE.
 */
function _presence_reddit_search(string $query): array
{
    // Try OAuth first
    $client_id = config('reddit_client_id');
    $client_secret = config('reddit_client_secret');

    if ($client_id && $client_secret) {
        $results = _presence_reddit_oauth_search($query, $client_id, $client_secret);
        if (!empty($results)) return $results;
    }

    // Fall back to Google CSE if available
    $results = _presence_google_cse_search($query, 'reddit.com');
    if (!empty($results)) return $results;

    // Last resort: direct API (may be blocked on some servers)
    $url = 'https://www.reddit.com/search.json?q=' . urlencode($query) . '&sort=new&limit=10&t=month&type=link';
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
            'snippet' => mb_substr(strip_tags(html_entity_decode($post['selftext'] ?? '', ENT_QUOTES, 'UTF-8')), 0, 150),
            'subreddit' => $post['subreddit'] ?? '',
            'score' => $post['score'] ?? 0,
            'num_comments' => $post['num_comments'] ?? 0,
            'created' => date('Y-m-d', $post['created_utc'] ?? time()),
        ];
    }
    return $conversations;
}

/**
 * Hacker News via Algolia API (free, no auth).
 */
function _presence_hn_search(string $query): array
{
    $three_months_ago = strtotime('-3 months');
    $url = 'https://hn.algolia.com/api/v1/search_by_date?query=' . urlencode($query) . '&tags=story&hitsPerPage=10&numericFilters=created_at_i>' . $three_months_ago;
    $result = scraper_fetch($url, 10);
    if ($result['status'] !== 200) return [];

    $data = json_decode($result['body'], true);
    if (empty($data['hits'])) return [];

    $conversations = [];
    foreach ($data['hits'] as $hit) {
        $conversations[] = [
            'url' => 'https://news.ycombinator.com/item?id=' . $hit['objectID'],
            'title' => $hit['title'] ?? '',
            'snippet' => mb_substr(strip_tags(html_entity_decode($hit['story_text'] ?? $hit['comment_text'] ?? '', ENT_QUOTES, 'UTF-8')), 0, 150),
            'score' => $hit['points'] ?? 0,
            'num_comments' => $hit['num_comments'] ?? 0,
            'created' => date('Y-m-d', strtotime($hit['created_at'] ?? 'now')),
            'author' => $hit['author'] ?? '',
        ];
    }
    return $conversations;
}

/**
 * Stack Exchange API (free, no auth for basic queries).
 */
function _presence_stackexchange_search(string $query): array
{
    $three_months_ago = strtotime('-3 months');
    $url = 'https://api.stackexchange.com/2.3/search/advanced?order=desc&sort=activity&q='
        . urlencode($query) . '&site=stackoverflow&pagesize=10&fromdate=' . $three_months_ago . '&filter=!nNPvSNdWme';
    $result = scraper_fetch($url, 10);
    if ($result['status'] !== 200) return [];

    $data = json_decode($result['body'], true);
    if (empty($data['items'])) return [];

    $conversations = [];
    foreach ($data['items'] as $item) {
        $conversations[] = [
            'url' => $item['link'] ?? '',
            'title' => html_entity_decode($item['title'] ?? '', ENT_QUOTES, 'UTF-8'),
            'snippet' => mb_substr(strip_tags(html_entity_decode($item['body_markdown'] ?? $item['body'] ?? '', ENT_QUOTES, 'UTF-8')), 0, 150),
            'score' => $item['score'] ?? 0,
            'num_comments' => $item['answer_count'] ?? 0,
            'created' => date('Y-m-d', $item['creation_date'] ?? time()),
            'tags' => implode(', ', $item['tags'] ?? []),
        ];
    }
    return $conversations;
}

/**
 * YouTube search via page scraping (no API key needed).
 */
function _presence_youtube_search(string $query): array
{
    $url = 'https://www.youtube.com/results?search_query=' . urlencode($query);
    $result = scraper_fetch($url, 10);
    if ($result['status'] !== 200) return [];

    $conversations = [];
    // Extract video data from the initial page data
    if (preg_match('/var ytInitialData = ({.*?});<\/script>/s', $result['body'], $m)) {
        $data = json_decode($m[1], true);
        $contents = $data['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'] ?? [];

        foreach (array_slice($contents, 0, 5) as $item) {
            $video = $item['videoRenderer'] ?? null;
            if (!$video) continue;

            $title = '';
            foreach (($video['title']['runs'] ?? []) as $run) $title .= $run['text'];

            $snippet = '';
            foreach (($video['detailedMetadataSnippets'][0]['snippetText']['runs'] ?? $video['descriptionSnippet']['runs'] ?? []) as $run) $snippet .= $run['text'];

            $conversations[] = [
                'url' => 'https://www.youtube.com/watch?v=' . $video['videoId'],
                'title' => $title,
                'snippet' => mb_substr($snippet, 0, 150),
                'score' => $video['viewCountText']['simpleText'] ?? '',
                'created' => $video['publishedTimeText']['simpleText'] ?? '',
                'author' => $video['ownerText']['runs'][0]['text'] ?? '',
            ];
        }
    }
    return $conversations;
}

/**
 * GitHub search via API (no auth for basic, rate limited).
 */
function _presence_github_search(string $query): array
{
    $url = 'https://api.github.com/search/repositories?q=' . urlencode($query) . '&sort=stars&per_page=5';
    // GitHub API needs Accept header — use curl directly
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'ContentAgent/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github.v3+json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) return [];
    $result = ['body' => $body];
    $data = json_decode($result['body'], true);
    if (empty($data['items'])) return [];

    $data = json_decode($result['body'], true);
    if (empty($data['items'])) return [];

    $conversations = [];
    foreach ($data['items'] as $repo) {
        $conversations[] = [
            'url' => $repo['html_url'] ?? '',
            'title' => $repo['full_name'] ?? '',
            'snippet' => mb_substr($repo['description'] ?? '', 0, 150),
            'score' => ($repo['stargazers_count'] ?? 0) . ' stars',
            'num_comments' => $repo['open_issues_count'] ?? 0,
            'created' => date('Y-m-d', strtotime($repo['updated_at'] ?? 'now')),
            'tags' => implode(', ', array_slice($repo['topics'] ?? [], 0, 5)),
        ];
    }
    return $conversations;
}

/**
 * Web search fallback for platforms without direct APIs.
 */
function _presence_web_search(string $query, string $site_domain): array
{
    // Try Google CSE first (most reliable)
    $cse_results = _presence_google_cse_search($query, $site_domain);
    if (!empty($cse_results)) return $cse_results;

    // Fall back to DuckDuckGo HTML
    $search_url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query . ' site:' . $site_domain);
    $result = scraper_fetch($search_url, 10);
    if ($result['status'] !== 200 || empty($result['body'])) return [];

    $conversations = [];
    $html = $result['body'];

    // Parse DuckDuckGo results
    if (preg_match_all('/<a[^>]+class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER)) {
        foreach (array_slice($matches, 0, 5) as $match) {
            $url = $match[1];
            // DDG wraps URLs in a redirect — extract actual URL
            if (preg_match('/uddg=([^&]+)/', $url, $u)) {
                $url = urldecode($u[1]);
            }
            if (strpos($url, $site_domain) === false) continue;

            $title = strip_tags($match[2]);

            // Get snippet
            $snippet = '';
            $conversations[] = [
                'url' => $url,
                'title' => $title,
                'snippet' => $snippet,
            ];
        }
    }

    // Also try DuckDuckGo snippet extraction
    if (preg_match_all('/<a class="result__snippet"[^>]*>(.*?)<\/a>/s', $html, $snip_matches)) {
        foreach ($snip_matches[1] as $i => $snip) {
            if (isset($conversations[$i])) {
                $conversations[$i]['snippet'] = mb_substr(strip_tags($snip), 0, 150);
            }
        }
    }

    return $conversations;
}

// ── Reply & Storage functions ────────────────────────────

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
        'github' => "Write a technically relevant comment or issue description. Mention {$name} only if it provides a solution.",
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
