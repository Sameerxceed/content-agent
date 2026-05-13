<?php
/**
 * Content Planner Agent
 * Combines keywords, business context, trending news, and AI search trends
 * to generate a weekly content plan, then auto-writes and publishes.
 *
 * This is the BRAIN of ContentAgent — it decides what to write and why.
 *
 * CLI Usage: php agent/content-planner.php --site=1
 *            php agent/content-planner.php --site=1 --auto-write
 *            php agent/content-planner.php --site=1 --auto-publish
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/rss.php';
require_once __DIR__ . '/../includes/haiku.php';
require_once __DIR__ . '/../includes/cms-connector.php';

$db = require __DIR__ . '/../includes/db.php';

$opts = getopt('', ['site:', 'auto-write', 'auto-publish', 'count:']);
$site_id = $opts['site'] ?? null;
$auto_write = isset($opts['auto-write']) || isset($opts['auto-publish']);
$auto_publish = isset($opts['auto-publish']);
$count = (int)($opts['count'] ?? 3);

if (!$site_id) {
    echo "Usage: php content-planner.php --site=1 [--auto-write] [--auto-publish] [--count=3]\n";
    exit(1);
}

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
$stmt->execute([$site_id]);
$site = $stmt->fetch();

if (!$site) { echo "Site not found.\n"; exit(1); }

$start_time = microtime(true);
$domain = $site['domain'];
$topics = json_decode($site['topics'] ?? '[]', true) ?: [];
$brand_tone = $site['brand_tone'] ?? 'professional';

echo "Content Planner: {$domain}\n";
echo str_repeat('=', 60) . "\n";

// ── Step 1: Gather all signals ──────────────────────────

echo "\n[1/5] Gathering signals...\n";

// Signal 1: Top keywords not yet covered by content
$stmt = $db->prepare("SELECT keyword, priority, difficulty, cluster FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY priority DESC LIMIT 30");
$stmt->execute([$site_id]);
$keywords = $stmt->fetchAll();

// Signal 2: Existing posts (to avoid duplicates)
$stmt = $db->prepare('SELECT title, slug, seo_keywords FROM posts WHERE site_id = ? ORDER BY created_at DESC');
$stmt->execute([$site_id]);
$existing_posts = $stmt->fetchAll();
$existing_titles = array_map(fn($p) => strtolower($p['title']), $existing_posts);
$covered_kws = [];
foreach ($existing_posts as $p) {
    $kws = array_map('trim', explode(',', strtolower($p['seo_keywords'] ?? '')));
    $covered_kws = array_merge($covered_kws, $kws);
}

// Signal 3: Trending news from RSS feeds
$rss_feeds = json_decode($site['rss_feeds'] ?? '[]', true) ?: [];
$news_items = [];
foreach (array_slice($rss_feeds, 0, 4) as $feed_url) {
    $feed = rss_fetch($feed_url, 10);
    if (!$feed['error']) {
        $news_items = array_merge($news_items, $feed['items']);
    }
}

// Extract trending topics from news
$news_topics = [];
foreach ($news_items as $item) {
    $news_topics[] = $item['title'];
}

echo "  Keywords: " . count($keywords) . "\n";
echo "  Existing posts: " . count($existing_posts) . "\n";
echo "  News items: " . count($news_items) . "\n";
echo "  Topics: " . implode(', ', $topics) . "\n";

// Signal 4: Google trending searches (via autocomplete)
$trending = [];
foreach (array_slice($topics, 0, 3) as $topic) {
    $url = 'https://suggestqueries.google.com/complete/search?client=firefox&q=' . urlencode($topic . ' ' . date('Y'));
    $result = http_get($url, [], 10);
    if ($result['status'] === 200) {
        $data = json_decode($result['body'], true);
        if (!empty($data[1])) {
            $trending = array_merge($trending, array_slice($data[1], 0, 3));
        }
    }
    usleep(300000);
}
echo "  Trending searches: " . count($trending) . "\n";

// ── Step 2: Ask Haiku to create a content plan ──────────

echo "\n[2/5] Creating content plan with AI...\n";

$current_year = date('Y');
$current_date = date('d M Y');

$context = json_encode([
    'business'       => $site['name'] . ' — ' . implode(', ', $topics),
    'brand_tone'     => $brand_tone,
    'domain'         => $domain,
    'top_keywords'   => array_column(array_slice($keywords, 0, 15), 'keyword'),
    'covered_topics' => array_slice($existing_titles, 0, 10),
    'trending_news'  => array_slice($news_topics, 0, 10),
    'trending_searches' => $trending,
    'year'           => $current_year,
], JSON_UNESCAPED_SLASHES);

$plan_result = haiku_chat(
    "You are a content strategist for {$site['name']}. Today is {$current_date}. Year is {$current_year}.

Your job: analyze the business context, keywords, trending news, and search trends to propose {$count} blog post topics.

Rules:
- Each topic must be relevant to the business ({$domain})
- Each topic should target 2-5 keywords from the keyword list
- Avoid topics already covered (see covered_topics)
- Mix: 1 evergreen guide + 1 trend/news-inspired + 1 problem-solving article
- Each topic should be something a CTO or founder would bookmark
- Write from {$site['name']}'s perspective (they build this stuff)

Output ONLY valid JSON array with this structure:
[
  {
    \"title\": \"Blog post title (include {$current_year} where relevant)\",
    \"topic\": \"2-3 sentence description of what to cover\",
    \"target_keywords\": [\"keyword1\", \"keyword2\", \"keyword3\"],
    \"type\": \"evergreen|trend|problem-solving\",
    \"inspired_by\": \"what signal triggered this (keyword/news/search)\",
    \"estimated_impact\": \"high|medium|low\"
  }
]",
    "Create a content plan based on these signals:\n\n{$context}",
    2048
);

$plan = null;
if ($plan_result['success']) {
    $content = $plan_result['content'];
    $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
    $content = preg_replace('/\s*```\s*$/m', '', $content);
    $plan = json_decode(trim($content), true);

    if (!$plan && preg_match('/\[[\s\S]*\]/m', $content, $m)) {
        $plan = json_decode($m[0], true);
    }
}

if (!$plan) {
    echo "  AI planning failed. Using keyword-based fallback.\n";
    $plan = [];
    $uncovered = array_filter($keywords, fn($k) => !in_array(strtolower($k['keyword']), $covered_kws));
    foreach (array_slice($uncovered, 0, $count) as $kw) {
        $plan[] = [
            'title' => ucwords($kw['keyword']),
            'topic' => "Write about {$kw['keyword']} from {$site['name']}'s perspective",
            'target_keywords' => [$kw['keyword']],
            'type' => 'evergreen',
            'inspired_by' => 'keyword research',
            'estimated_impact' => 'medium',
        ];
    }
}

// ── Step 3: Display the plan ────────────────────────────

echo "\n[3/5] Content Plan (" . count($plan) . " posts):\n";
echo str_repeat('-', 60) . "\n";

foreach ($plan as $i => $item) {
    $num = $i + 1;
    echo "\n  #{$num}: {$item['title']}\n";
    echo "      Type: {$item['type']} | Impact: {$item['estimated_impact']}\n";
    echo "      Keywords: " . implode(', ', $item['target_keywords'] ?? []) . "\n";
    echo "      Why: {$item['inspired_by']}\n";
    echo "      What: {$item['topic']}\n";
}

// ── Step 4: Auto-write if requested ─────────────────────

$posts_created = [];

if ($auto_write) {
    echo "\n[4/5] Auto-writing posts...\n";

    foreach ($plan as $i => $item) {
        $num = $i + 1;
        echo "\n  Writing #{$num}: {$item['title']}...\n";

        $result = haiku_write_blog(
            $item['topic'] . '. Title suggestion: ' . $item['title'],
            $brand_tone,
            $item['target_keywords'] ?? [],
            rand(config('agent_min_word_count'), config('agent_max_word_count'))
        );

        if (!$result['success']) {
            echo "    ERROR: {$result['error']}\n";
            continue;
        }

        $post = $result['parsed'] ?? null;
        if (!$post || empty($post['title']) || empty($post['body'])) {
            echo "    ERROR: Could not parse response\n";
            continue;
        }

        $slug_words = array_slice(explode('-', slugify($post['title'])), 0, 6);
        $slug = implode('-', $slug_words);

        // Ensure unique slug
        $check = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND slug = ?');
        $check->execute([$site_id, $slug]);
        if ($check->fetchColumn() > 0) $slug .= '-' . date('md');

        $insert = $db->prepare('INSERT INTO posts (site_id, title, slug, body, excerpt, seo_title, seo_description, seo_keywords, type, tags, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([
            $site_id,
            $post['title'],
            $slug,
            $post['body'],
            $post['excerpt'] ?? truncate(strip_tags($post['body']), 200),
            $post['seo_title'] ?? truncate($post['title'], 60, ''),
            $post['seo_description'] ?? truncate(strip_tags($post['body']), 160),
            implode(', ', $item['target_keywords'] ?? []),
            'blog',
            json_encode($post['tags'] ?? []),
            $auto_publish ? 'published' : 'draft',
        ]);

        $post_id = $db->lastInsertId();
        $words = str_word_count(strip_tags($post['body']));
        echo "    Created: \"{$post['title']}\" ({$words} words) [#{$post_id}]\n";

        $posts_created[] = [
            'id'    => $post_id,
            'title' => $post['title'],
            'slug'  => $slug,
        ];

        sleep(2); // Pause between writes
    }
} else {
    echo "\n[4/5] SKIPPED — Add --auto-write to generate posts\n";
}

// ── Step 5: Auto-publish to CMS if requested ────────────

if ($auto_publish && !empty($posts_created)) {
    echo "\n[5/5] Publishing to CMS...\n";

    $cms_url = $site['cms_url'] ?? '';
    $cms_api_key = $site['cms_api_key'] ?? '';

    if (empty($cms_url) || empty($cms_api_key)) {
        echo "  SKIPPED — No CMS configured for this site\n";
    } else {
        foreach ($posts_created as $pc) {
            $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
            $stmt->execute([$pc['id']]);
            $post_data = $stmt->fetch();

            $result = cms_push_post($post_data, $cms_url, $cms_api_key);
            if ($result['success']) {
                $db->prepare('UPDATE posts SET status = "published", published_at = NOW() WHERE id = ?')->execute([$pc['id']]);
                echo "  Published: {$pc['title']}\n";
            } else {
                echo "  FAILED: {$pc['title']} — {$result['error']}\n";
            }

            sleep(1);
        }
    }
} else {
    echo "\n[5/5] SKIPPED — Add --auto-publish to push to CMS\n";
}

// ── Log & Summary ───────────────────────────────────────

$duration = round((microtime(true) - $start_time) * 1000);

$db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)')->execute([
    $site_id,
    'content_planner',
    json_encode([
        'plan_count'    => count($plan),
        'written'       => count($posts_created),
        'published'     => $auto_publish ? count($posts_created) : 0,
        'signals'       => ['keywords' => count($keywords), 'news' => count($news_items), 'trending' => count($trending)],
    ]),
    'success',
    $duration,
]);

echo "\n" . str_repeat('=', 60) . "\n";
echo "Done in {$duration}ms\n";
echo "  Plan: " . count($plan) . " topics\n";
echo "  Written: " . count($posts_created) . " posts\n";
echo "  Published: " . ($auto_publish ? count($posts_created) : 0) . "\n";
