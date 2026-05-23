<?php
/**
 * News Scraper Agent
 * Pulls relevant news from RSS feeds, deduplicates, and stores as posts.
 *
 * CLI Usage: php agent/news-scraper.php --site=1
 *            php agent/news-scraper.php --all   (process all active sites)
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/rss.php';
require_once __DIR__ . '/../includes/cms-connector.php';

$db = require __DIR__ . '/../includes/db.php';

// ── Parse CLI arguments ──────────────────────────────────
$opts = getopt('', ['site:', 'all']);
$site_id  = $opts['site'] ?? null;
$all_sites = isset($opts['all']);

if (!$site_id && !$all_sites) {
    echo "Usage: php news-scraper.php --site=1\n";
    echo "       php news-scraper.php --all\n";
    exit(1);
}

$default_feeds = require __DIR__ . '/../config/rss-feeds.php';
$max_news_per_day = config('agent_news_per_day');

// ── Get sites to process ────────────────────────────────
if ($all_sites) {
    $stmt = $db->query('SELECT * FROM sites WHERE is_active = 1');
    $sites = $stmt->fetchAll();
    echo "Processing " . count($sites) . " active sites\n";
} else {
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $sites = $stmt->fetchAll();
    if (empty($sites)) {
        echo "Site #{$site_id} not found.\n";
        exit(1);
    }
}

$total_saved = 0;

foreach ($sites as $site) {
    $start_time = microtime(true);
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "Site: {$site['domain']} (#{$site['id']})\n";

    // ── Determine feeds to use ──────────────────────────
    $site_feeds = json_decode($site['rss_feeds'] ?? '[]', true) ?: [];

    // If no custom feeds, try to match by topics
    if (empty($site_feeds)) {
        $topics = json_decode($site['topics'] ?? '[]', true) ?: [];
        foreach ($topics as $topic) {
            $topic_lower = strtolower($topic);
            foreach ($default_feeds as $category => $feeds) {
                if (strpos($topic_lower, $category) !== false || strpos($category, $topic_lower) !== false) {
                    $site_feeds = array_merge($site_feeds, $feeds);
                }
            }
        }
        // Fallback to general feeds
        if (empty($site_feeds)) {
            $site_feeds = $default_feeds['general'] ?? [];
        }
    }

    $site_feeds = array_unique($site_feeds);
    echo "  Feeds: " . count($site_feeds) . "\n";

    // ── Get site keywords for relevance filtering ───────
    $kw_stmt = $db->prepare("SELECT keyword FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY priority DESC LIMIT 20");
    $kw_stmt->execute([$site['id']]);
    $keywords = $kw_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Also use topics as keywords
    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];
    $filter_keywords = array_merge($keywords, $topics);

    if (empty($filter_keywords)) {
        // Use domain as fallback keyword
        $filter_keywords = [str_replace(['.com', '.co.uk', '.in', '-'], ' ', $site['domain'])];
    }

    // Add individual words from topics for broader matching
    foreach ($topics as $topic) {
        $words = explode(' ', strtolower($topic));
        foreach ($words as $w) {
            if (strlen($w) >= 4 && !in_array($w, ['with', 'that', 'this', 'from', 'have', 'been', 'were', 'they', 'their', 'about', 'which', 'would', 'there', 'could'])) {
                $filter_keywords[] = $w;
            }
        }
    }
    $filter_keywords = array_unique($filter_keywords);

    echo "  Filter keywords: " . implode(', ', array_slice($filter_keywords, 0, 5)) . "...\n";

    // ── Fetch all feeds ─────────────────────────────────
    $all_items = [];

    foreach ($site_feeds as $feed_url) {
        echo "  Fetching: {$feed_url}...\n";
        $feed = rss_fetch($feed_url, 15);

        if ($feed['error']) {
            echo "    ERROR: {$feed['error']}\n";
            continue;
        }

        echo "    Got " . count($feed['items']) . " items\n";
        $all_items = array_merge($all_items, $feed['items']);

        usleep(300000); // 300ms delay
    }

    echo "  Total items fetched: " . count($all_items) . "\n";

    // ── Filter by relevance ─────────────────────────────
    // Profile-aware threshold: a specialised niche business wants tight matches
    // (so it doesn't drown in generic industry news); a generalist or
    // category-leader can afford looser filtering to catch trends early.
    require_once __DIR__ . '/../includes/business_profile.php';
    $news_profile = profile_get($db, (int)$site['id']);
    $relevance_threshold = 0.1; // default (current behaviour)
    if ($news_profile) {
        $size      = $news_profile['size_tier'] ?? '';
        $maturity  = $news_profile['maturity_tier'] ?? '';
        $industry_sub = trim($news_profile['industry_sub'] ?? '');
        if ($industry_sub !== '' && in_array($size, ['solo', 'small'], true)) {
            $relevance_threshold = 0.18; // tighter — only highly-relevant items
        } elseif (in_array($maturity, ['category_leader', 'public_company'], true)) {
            $relevance_threshold = 0.07; // looser — catch industry-wide trends
        }
    }
    $relevant = rss_filter_by_relevance($all_items, $filter_keywords, $relevance_threshold);
    echo "  Relevant items: " . count($relevant) . " (threshold=" . $relevance_threshold . ")\n";

    // ── Deduplicate ─────────────────────────────────────
    $unique = rss_deduplicate($relevant);
    echo "  After dedup: " . count($unique) . "\n";

    // ── Check against existing posts ────────────────────
    $new_items = [];
    foreach ($unique as $item) {
        if (!news_exists($db, $site['id'], $item['guid'], $item['title'])) {
            $new_items[] = $item;
        }
    }

    echo "  New items: " . count($new_items) . "\n";

    // ── Save top N as news posts ────────────────────────
    $to_save = array_slice($new_items, 0, $max_news_per_day);
    $saved = 0;
    $pushed = 0;
    $push_failed = 0;

    $cms_ready = !empty($site['cms_url']) && !empty($site['cms_api_key']);

    foreach ($to_save as $item) {
        $slug = slugify($item['title']);
        // Cap at 80 chars to avoid CMSes that silently truncate (breaks the
        // PUT-by-slug update path on re-pushes). Cut at the last hyphen so
        // we don't slice mid-word.
        if (strlen($slug) > 80) {
            $slug = substr($slug, 0, 80);
            $last_dash = strrpos($slug, '-');
            if ($last_dash !== false && $last_dash > 40) $slug = substr($slug, 0, $last_dash);
        }
        $slug = ensure_news_slug($db, $site['id'], $slug);

        $body = format_news_body($item);
        $title = mb_substr($item['title'], 0, 500);
        $excerpt = truncate($item['description'], 200);
        $seo_title = truncate($item['title'], 60, '');
        $seo_desc = truncate($item['description'], 160);
        $tags_json = json_encode($item['categories'] ?? []);
        $source_url = mb_substr($item['link'], 0, 2048);

        // Use the RSS item's actual publication date when present so
        // the post on the website shows when the news was originally
        // published, not when we happened to scrape it.
        $article_date = !empty($item['date']) ? $item['date'] : date('Y-m-d H:i:s');

        // Push to CMS FIRST (if configured). Only mark published locally if
        // the push succeeded, otherwise leave as 'draft' with the error in the body
        // so the user can see it on the posts page and retry.
        $status = 'draft';
        $push_error = null;

        if ($cms_ready) {
            $push_payload = [
                'title'           => $title,
                'slug'            => $slug,
                'excerpt'         => $excerpt,
                'body'            => $body,
                'tags'            => $tags_json,
                'seo_title'       => $seo_title,
                'seo_description' => $seo_desc,
                'seo_keywords'    => '',
                'published_at'    => $article_date,
                'type'            => 'news',
            ];
            $result = cms_push_post($push_payload, $site['cms_url'], $site['cms_api_key']);
            if (!empty($result['success'])) {
                $status = 'published';
                $pushed++;
            } else {
                $push_error = $result['error'] ?? 'Unknown CMS error';
                $push_failed++;
                echo "    ✗ CMS push failed for \"{$title}\": {$push_error}\n";
            }
        } else {
            // No CMS configured — save as published locally (legacy behaviour)
            $status = 'published';
        }

        $stmt = $db->prepare('INSERT INTO posts (site_id, title, slug, body, excerpt, seo_title, seo_description, type, tags, status, source_url, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $stmt->execute([
            $site['id'],
            $title,
            $slug,
            $body,
            $excerpt,
            $seo_title,
            $seo_desc,
            'news',
            $tags_json,
            $status,
            $source_url,
            $status === 'published' ? $article_date : null,
        ]);

        $saved++;
        $marker = $status === 'published' ? '✓' : '⏸';
        echo "    {$marker} {$title}" . ($push_error ? " (CMS error: {$push_error})" : '') . "\n";
    }

    $total_saved += $saved;
    $duration = round((microtime(true) - $start_time) * 1000);

    echo "  Saved: {$saved} news posts ({$duration}ms)\n";

    // Log
    $stmt = $db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $site['id'],
        'scrape_news',
        json_encode([
            'feeds'    => count($site_feeds),
            'fetched'  => count($all_items),
            'relevant' => count($relevant),
            'saved'    => $saved,
        ]),
        'success',
        $duration,
    ]);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Total news posts saved: {$total_saved}\n";

// ── Helper Functions ────────────────────────────────────

/**
 * Check if a news item already exists.
 */
function news_exists(PDO $db, int $site_id, string $guid, string $title): bool
{
    // Check by source URL (guid)
    $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND source_url = ? AND type = "news"');
    $stmt->execute([$site_id, $guid]);
    if ($stmt->fetchColumn() > 0) return true;

    // Check by similar title
    $stmt = $db->prepare('SELECT title FROM posts WHERE site_id = ? AND type = "news" AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $stmt->execute([$site_id]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $normalized = strtolower(preg_replace('/[^a-z0-9]/', '', $title));
    foreach ($existing as $existing_title) {
        $existing_norm = strtolower(preg_replace('/[^a-z0-9]/', '', $existing_title));
        similar_text($normalized, $existing_norm, $percent);
        if ($percent > 80) return true;
    }

    return false;
}

/**
 * Format a news item into HTML body.
 */
function format_news_body(array $item): string
{
    $html = '<article class="news-item">';
    $html .= '<p>' . e($item['description']) . '</p>';
    $html .= '<p class="news-source">Source: <a href="' . e($item['link']) . '" target="_blank" rel="noopener">' . e($item['source'] ?: 'Original Article') . '</a></p>';

    if (!empty($item['matched_keywords'])) {
        $html .= '<p class="news-tags">Related: ' . e(implode(', ', $item['matched_keywords'])) . '</p>';
    }

    $html .= '</article>';
    return $html;
}

/**
 * Ensure unique slug for news posts.
 */
function ensure_news_slug(PDO $db, int $site_id, string $slug): string
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND slug = ?');
    $stmt->execute([$site_id, $slug]);

    if ($stmt->fetchColumn() == 0) return $slug;

    $i = 2;
    while (true) {
        $new_slug = $slug . '-' . $i;
        $stmt->execute([$site_id, $new_slug]);
        if ($stmt->fetchColumn() == 0) return $new_slug;
        $i++;
    }
}
