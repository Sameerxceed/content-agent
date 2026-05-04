<?php
/**
 * Blog Writer Agent
 * Generates SEO blog posts from keyword clusters using Haiku.
 *
 * CLI Usage: php agent/blog-writer.php --site=1
 *            php agent/blog-writer.php --site=1 --topic="keyword or topic"
 *            php agent/blog-writer.php --site=1 --count=2
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/haiku.php';

$db = require __DIR__ . '/../includes/db.php';

// ── Parse CLI arguments ──────────────────────────────────
$opts = getopt('', ['site:', 'topic:', 'count:']);
$site_id = $opts['site'] ?? null;
$topic   = $opts['topic'] ?? null;
$count   = (int)($opts['count'] ?? 1);

if (!$site_id) {
    echo "Usage: php blog-writer.php --site=1 [--topic=\"topic\"] [--count=2]\n";
    exit(1);
}

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
$stmt->execute([$site_id]);
$site = $stmt->fetch();

if (!$site) {
    echo "Site #{$site_id} not found.\n";
    exit(1);
}

$start_time = microtime(true);
$brand_tone = $site['brand_tone'] ?? 'professional and informative';
$min_words  = config('agent_min_word_count');
$max_words  = config('agent_max_word_count');

echo "Blog Writer for: {$site['domain']}\n";
echo "Tone: {$brand_tone}\n";
echo "Posts to generate: {$count}\n";
echo str_repeat('=', 60) . "\n";

$posts_created = 0;

for ($i = 0; $i < $count; $i++) {
    echo "\n[Post " . ($i + 1) . "/{$count}]\n";

    // ── Pick a topic ────────────────────────────────────
    if ($topic) {
        $current_topic = $topic;
        $keywords = [$topic];
    } else {
        // Pick highest priority keyword cluster that hasn't been written about recently
        $kw_data = pick_next_topic($db, $site_id);
        if (!$kw_data) {
            echo "  No keywords available. Run keyword-research.php first.\n";
            break;
        }
        $current_topic = $kw_data['topic'];
        $keywords = $kw_data['keywords'];
    }

    echo "  Topic: {$current_topic}\n";
    echo "  Keywords: " . implode(', ', array_slice($keywords, 0, 5)) . "\n";

    // ── Check for existing similar posts ────────────────
    $existing = check_similar_post($db, $site_id, $current_topic);
    if ($existing) {
        echo "  SKIPPED: Similar post already exists — \"{$existing['title']}\"\n";
        continue;
    }

    // ── Get existing posts for internal linking ─────────
    $existing_posts = get_site_posts($db, $site_id, 20);
    $internal_links_context = '';
    if (!empty($existing_posts)) {
        $links = array_map(fn($p) => "- {$p['title']} ({$site['blog_path']}/{$p['slug']})", $existing_posts);
        $internal_links_context = "\n\nExisting blog posts you can link to:\n" . implode("\n", $links);
    }

    // ── Generate the post ───────────────────────────────
    echo "  Writing with Haiku...\n";

    $word_count = rand($min_words, $max_words);
    $result = haiku_write_blog($current_topic, $brand_tone, $keywords, $word_count);

    if (!$result['success']) {
        echo "  ERROR: {$result['error']}\n";
        log_action($db, $site_id, 'write_blog', "Failed: {$result['error']}", 'fail');
        continue;
    }

    $post = $result['parsed'] ?? null;

    if (!$post || empty($post['title']) || empty($post['body'])) {
        echo "  ERROR: Could not parse AI response\n";
        log_action($db, $site_id, 'write_blog', 'Failed: unparseable response', 'fail');
        continue;
    }

    // ── Save to database ────────────────────────────────
    // Keep slug short: max 6 words from title
    $slug_words = array_slice(explode('-', slugify($post['title'])), 0, 6);
    $slug = implode('-', $slug_words);

    // Ensure unique slug
    $slug = ensure_unique_slug($db, $site_id, $slug);

    $insert = $db->prepare('INSERT INTO posts (site_id, title, slug, body, excerpt, seo_title, seo_description, seo_keywords, type, tags, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $insert->execute([
        $site_id,
        $post['title'],
        $slug,
        $post['body'],
        $post['excerpt'] ?? truncate(strip_tags($post['body']), 200),
        $post['seo_title'] ?? truncate($post['title'], 60, ''),
        $post['seo_description'] ?? truncate(strip_tags($post['body']), 160),
        implode(', ', $keywords),
        'blog',
        json_encode($post['tags'] ?? []),
        'draft',
    ]);

    $post_id = $db->lastInsertId();
    $posts_created++;

    $actual_words = str_word_count(strip_tags($post['body']));
    echo "  Created: \"{$post['title']}\" ({$actual_words} words)\n";
    echo "  Slug: {$slug}\n";
    echo "  Status: draft (awaiting approval)\n";
    echo "  Post ID: #{$post_id}\n";

    // Log usage
    $input_tokens  = $result['usage']['input_tokens'] ?? 0;
    $output_tokens = $result['usage']['output_tokens'] ?? 0;

    log_action($db, $site_id, 'write_blog', json_encode([
        'post_id'    => $post_id,
        'title'      => $post['title'],
        'words'      => $actual_words,
        'tokens_in'  => $input_tokens,
        'tokens_out' => $output_tokens,
        'topic'      => $current_topic,
    ]), 'success');

    // Brief pause between posts
    if ($i < $count - 1) {
        sleep(2);
    }
}

$duration = round((microtime(true) - $start_time) * 1000);
echo "\nDone! Created {$posts_created} posts in {$duration}ms\n";

// ── Helper Functions ────────────────────────────────────

/**
 * Pick the next best topic based on keyword priority and recency.
 */
function pick_next_topic(PDO $db, int $site_id): ?array
{
    // Get highest priority cluster that we haven't written about recently
    $stmt = $db->prepare('
        SELECT k.cluster, GROUP_CONCAT(k.keyword ORDER BY k.priority DESC) as keywords
        FROM keywords k
        WHERE k.site_id = ?
          AND k.cluster IS NOT NULL
          AND k.keyword NOT IN (
              SELECT p.seo_keywords FROM posts p
              WHERE p.site_id = ? AND p.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
          )
        GROUP BY k.cluster
        ORDER BY AVG(k.priority) DESC
        LIMIT 1
    ');
    $stmt->execute([$site_id, $site_id]);
    $row = $stmt->fetch();

    if (!$row) {
        // Fallback: pick single highest priority keyword
        $stmt = $db->prepare('SELECT keyword FROM keywords WHERE site_id = ? ORDER BY priority DESC LIMIT 1');
        $stmt->execute([$site_id]);
        $kw = $stmt->fetchColumn();
        if (!$kw) return null;

        return ['topic' => $kw, 'keywords' => [$kw]];
    }

    $keywords = explode(',', $row['keywords']);
    return [
        'topic'    => $row['cluster'],
        'keywords' => array_slice($keywords, 0, 5),
    ];
}

/**
 * Check if a similar post already exists.
 */
function check_similar_post(PDO $db, int $site_id, string $topic): ?array
{
    $stmt = $db->prepare('SELECT id, title FROM posts WHERE site_id = ? AND (title LIKE ? OR seo_keywords LIKE ?) AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY) LIMIT 1');
    $like = '%' . mb_substr($topic, 0, 50) . '%';
    $stmt->execute([$site_id, $like, $like]);
    return $stmt->fetch() ?: null;
}

/**
 * Get existing published posts for internal linking context.
 */
function get_site_posts(PDO $db, int $site_id, int $limit = 20): array
{
    $stmt = $db->prepare('SELECT title, slug FROM posts WHERE site_id = ? AND status IN ("approved", "published") ORDER BY published_at DESC LIMIT ?');
    $stmt->execute([$site_id, $limit]);
    return $stmt->fetchAll();
}

/**
 * Ensure slug is unique for this site.
 */
function ensure_unique_slug(PDO $db, int $site_id, string $slug): string
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

function log_action(PDO $db, int $site_id, string $action, string $details, string $status): void
{
    $stmt = $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)');
    $stmt->execute([$site_id, $action, $details, $status]);
}
