<?php
/**
 * Blog Engine — Single post page.
 * URL: /blog/post-slug
 */

require_once __DIR__ . '/../../includes/helpers.php';

$db = require __DIR__ . '/../../includes/db.php';

// Determine site by domain
$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/^www\./', '', $host);

$stmt = $db->prepare('SELECT * FROM sites WHERE domain = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$host]);
$site = $stmt->fetch();

if (!$site) {
    http_response_code(404);
    exit('Site not found.');
}

// Get post by slug
$slug = $_GET['slug'] ?? '';
$slug = trim($slug, '/');

$stmt = $db->prepare('SELECT * FROM posts WHERE site_id = ? AND slug = ? AND status = "published" LIMIT 1');
$stmt->execute([$site['id'], $slug]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Post Not Found</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;"><h1>Post Not Found</h1><p><a href="' . e($site['blog_path'] ?: '/blog') . '">Back to blog</a></p></body></html>';
    exit;
}

$blog_path = $site['blog_path'] ?: '/blog';
$tags = json_decode($post['tags'] ?? '[]', true) ?: [];
$colors = json_decode($site['brand_colors'] ?? '[]', true) ?: [];
$primary_color = $colors[0] ?? '#1B3A6B';
$accent_color = $colors[1] ?? '#CC3300';
$fonts = json_decode($site['brand_fonts'] ?? '[]', true) ?: [];
$font_family = !empty($fonts) ? "'" . implode("', '", $fonts) . "', " : '';

// Related posts
$stmt = $db->prepare('SELECT title, slug, published_at FROM posts WHERE site_id = ? AND status = "published" AND id != ? ORDER BY published_at DESC LIMIT 3');
$stmt->execute([$site['id'], $post['id']]);
$related = $stmt->fetchAll();

$post_url = "https://{$site['domain']}{$blog_path}/{$post['slug']}";
$word_count = str_word_count(strip_tags($post['body']));
$read_time = max(1, round($word_count / 200));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($post['seo_title'] ?: $post['title']) ?></title>
    <meta name="description" content="<?= e($post['seo_description'] ?: truncate(strip_tags($post['body']), 160)) ?>">
    <?php if ($post['seo_keywords']): ?>
    <meta name="keywords" content="<?= e($post['seo_keywords']) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= e($post_url) ?>">

    <!-- Open Graph -->
    <meta property="og:title" content="<?= e($post['seo_title'] ?: $post['title']) ?>">
    <meta property="og:description" content="<?= e($post['seo_description'] ?: truncate(strip_tags($post['body']), 160)) ?>">
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?= e($post_url) ?>">
    <meta property="article:published_time" content="<?= $post['published_at'] ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= e($post['seo_title'] ?: $post['title']) ?>">
    <meta name="twitter:description" content="<?= e($post['seo_description'] ?: truncate(strip_tags($post['body']), 160)) ?>">

    <!-- JSON-LD -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "<?= $post['type'] === 'news' ? 'NewsArticle' : 'BlogPosting' ?>",
        "headline": <?= json_encode($post['title']) ?>,
        "description": <?= json_encode($post['seo_description'] ?: truncate(strip_tags($post['body']), 160)) ?>,
        "url": <?= json_encode($post_url) ?>,
        "datePublished": "<?= $post['published_at'] ?>",
        "dateModified": "<?= $post['updated_at'] ?>",
        "wordCount": <?= $word_count ?>,
        "publisher": {
            "@type": "Organization",
            "name": <?= json_encode($site['name']) ?>
        }
    }
    </script>

    <style>
        :root {
            --primary: <?= e($primary_color) ?>;
            --accent: <?= e($accent_color) ?>;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: <?= $font_family ?>-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #fafafa;
            color: #333;
            line-height: 1.7;
        }
        .container { max-width: 720px; margin: 0 auto; padding: 0 20px; }
        header {
            background: var(--primary);
            color: #fff;
            padding: 16px 0;
        }
        header a { color: #fff; text-decoration: none; font-size: 14px; }
        header a:hover { text-decoration: underline; }
        article { background: #fff; padding: 32px; margin: 20px 0; border-radius: 8px; border: 1px solid #e5e7eb; }
        article h1 { font-size: 26px; line-height: 1.3; margin-bottom: 10px; color: #1a1a1a; }
        .post-meta { font-size: 13px; color: #888; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #eee; }
        .prose { font-size: 16px; }
        .prose h2 { font-size: 20px; margin: 24px 0 10px; color: #1a1a1a; }
        .prose h3 { font-size: 17px; margin: 20px 0 8px; }
        .prose p { margin-bottom: 14px; }
        .prose ul, .prose ol { margin: 10px 0 14px 24px; }
        .prose li { margin-bottom: 4px; }
        .prose a { color: var(--accent); }
        .prose blockquote {
            border-left: 3px solid var(--primary);
            padding: 8px 16px;
            margin: 14px 0;
            background: #f8f9fa;
            font-style: italic;
        }
        .prose img { max-width: 100%; height: auto; border-radius: 6px; margin: 10px 0; }
        .prose code { background: #f1f5f9; padding: 1px 4px; border-radius: 3px; font-size: 14px; }
        .prose pre { background: #f1f5f9; padding: 14px; border-radius: 6px; overflow-x: auto; margin: 14px 0; }
        .tags { margin-top: 20px; padding-top: 14px; border-top: 1px solid #eee; }
        .tags span { display: inline-block; background: #f0f0f0; padding: 2px 8px; border-radius: 3px; font-size: 12px; margin-right: 4px; color: #666; }
        .related { padding: 20px 0; }
        .related h3 { font-size: 16px; margin-bottom: 10px; color: var(--primary); }
        .related a { display: block; color: #333; text-decoration: none; padding: 6px 0; font-size: 14px; }
        .related a:hover { color: var(--accent); }
        .source-link { margin-top: 14px; padding: 10px 14px; background: #f8f9fa; border-radius: 6px; font-size: 13px; }
        .source-link a { color: var(--accent); }
        footer { text-align: center; padding: 16px; font-size: 11px; color: #aaa; }
    </style>
</head>
<body>

<header>
    <div class="container">
        <a href="<?= e($blog_path) ?>">&laquo; <?= e($site['name']) ?> Blog</a>
    </div>
</header>

<div class="container">
    <article>
        <h1><?= e($post['title']) ?></h1>
        <div class="post-meta">
            <?= format_date($post['published_at'], 'd M Y') ?>
            &bull; <?= $read_time ?> min read
            &bull; <?= $word_count ?> words
            <span style="background:#f0f0f0;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;"><?= $post['type'] ?></span>
        </div>

        <div class="prose">
            <?= $post['body'] ?>
        </div>

        <?php if ($post['source_url']): ?>
        <div class="source-link">
            Source: <a href="<?= e($post['source_url']) ?>" target="_blank" rel="noopener"><?= e(parse_url($post['source_url'], PHP_URL_HOST)) ?></a>
        </div>
        <?php endif; ?>

        <?php if (!empty($tags)): ?>
        <div class="tags">
            <?php foreach ($tags as $tag): ?>
                <span><?= e($tag) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </article>

    <?php if (!empty($related)): ?>
    <div class="related">
        <h3>More from <?= e($site['name']) ?></h3>
        <?php foreach ($related as $r): ?>
            <a href="<?= e($blog_path) ?>/<?= e($r['slug']) ?>">
                <?= e($r['title']) ?>
                <span style="color:#aaa;font-size:12px;"><?= format_date($r['published_at'], 'd M Y') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<footer>
    &copy; <?= date('Y') ?> <?= e($site['name']) ?>. Powered by ContentAgent.
</footer>

</body>
</html>
