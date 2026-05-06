<?php
/**
 * Blog Engine — Listing page.
 * Serves posts for a customer's site based on domain.
 */

require_once __DIR__ . '/../../includes/helpers.php';

$db = require __DIR__ . '/../../includes/db.php';

// Determine which site this blog belongs to
$test_site_id = (int)($_GET['site'] ?? 0);
if ($test_site_id) {
    // Test mode: allow viewing blog by site ID
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$test_site_id]);
} else {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = preg_replace('/^www\./', '', $host);
    $stmt = $db->prepare('SELECT * FROM sites WHERE domain = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$host]);
}
$site = $stmt->fetch();

if (!$site) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Blog Not Found</title></head><body><h1>Blog not found for this domain.</h1></body></html>';
    exit;
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = config('blog_posts_per_page');
$offset = ($page - 1) * $per_page;

// Get published posts
$stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND status = "published"');
$stmt->execute([$site['id']]);
$total = $stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

$stmt = $db->prepare('SELECT * FROM posts WHERE site_id = ? AND status = "published" ORDER BY published_at DESC LIMIT ? OFFSET ?');
$stmt->execute([$site['id'], $per_page, $offset]);
$posts = $stmt->fetchAll();

// Brand colors
$colors = json_decode($site['brand_colors'] ?? '[]', true) ?: [];
$primary_color = $colors[0] ?? '#1B3A6B';
$accent_color = $colors[1] ?? '#CC3300';
$fonts = json_decode($site['brand_fonts'] ?? '[]', true) ?: [];
$font_family = !empty($fonts) ? "'" . implode("', '", $fonts) . "', " : '';
$blog_path = $site['blog_path'] ?: '/blog';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog — <?= e($site['name']) ?></title>
    <meta name="description" content="Latest articles and news from <?= e($site['name']) ?>">
    <link rel="canonical" href="https://<?= e($site['domain']) ?><?= e($blog_path) ?>">

    <!-- Open Graph -->
    <meta property="og:title" content="Blog — <?= e($site['name']) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://<?= e($site['domain']) ?><?= e($blog_path) ?>">

    <!-- JSON-LD -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Blog",
        "name": "<?= e($site['name']) ?> Blog",
        "url": "https://<?= e($site['domain']) ?><?= e($blog_path) ?>"
    }
    </script>

    <link rel="alternate" type="application/rss+xml" title="<?= e($site['name']) ?> RSS" href="<?= e($blog_path) ?>/rss.xml">

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
            line-height: 1.6;
        }
        .container { max-width: 800px; margin: 0 auto; padding: 0 20px; }
        header {
            background: var(--primary);
            color: #fff;
            padding: 12px 0;
        }
        header .header-inner { display:flex; align-items:center; justify-content:space-between; }
        header .brand { display:flex; align-items:center; gap:10px; text-decoration:none; color:#fff; }
        header .brand img { height:36px; width:auto; }
        header .brand span { font-size:16px; font-weight:600; letter-spacing:0.5px; }
        header nav a { color:rgba(255,255,255,0.8); text-decoration:none; font-size:13px; margin-left:16px; }
        header nav a:hover { color:#fff; }
        .posts { padding: 24px 0; }
        .post-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
            transition: box-shadow 0.15s;
        }
        .post-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .post-card h2 {
            font-size: 18px;
            margin-bottom: 6px;
        }
        .post-card h2 a {
            color: #1a1a1a;
            text-decoration: none;
        }
        .post-card h2 a:hover { color: var(--primary); }
        .post-meta {
            font-size: 12px;
            color: #888;
            margin-bottom: 8px;
        }
        .post-meta .tag {
            display: inline-block;
            background: #f0f0f0;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 11px;
            margin-left: 4px;
        }
        .post-excerpt {
            font-size: 14px;
            color: #555;
            margin-bottom: 8px;
        }
        .read-more {
            font-size: 13px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }
        .read-more:hover { text-decoration: underline; }
        .pagination {
            text-align: center;
            padding: 20px 0;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 6px 12px;
            margin: 0 2px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
        }
        .pagination a { color: var(--primary); border: 1px solid #ddd; }
        .pagination a:hover { background: var(--primary); color: #fff; }
        .pagination .current { background: var(--primary); color: #fff; }
        footer {
            text-align: center;
            padding: 16px;
            font-size: 11px;
            color: #aaa;
            border-top: 1px solid #eee;
        }
    </style>
</head>
<body>

<header>
    <div class="container header-inner">
        <a href="https://<?= e($site['domain']) ?>" class="brand">
            <img src="https://www.google.com/s2/favicons?domain=<?= e($site['domain']) ?>&sz=64" alt="<?= e($site['name']) ?>" onerror="this.style.display='none'">
            <span><?= e($site['name']) ?></span>
        </a>
        <nav>
            <a href="https://<?= e($site['domain']) ?>">Home</a>
            <a href="<?= e($blog_path) ?>?site=<?= $site['id'] ?>">Blog</a>
            <a href="https://<?= e($site['domain']) ?>/contact">Contact</a>
        </nav>
    </div>
</header>

<div class="container">
    <div class="posts">
        <?php if (empty($posts)): ?>
            <p style="text-align:center; color:#888; padding:40px 0;">No posts yet. Check back soon!</p>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
            <article class="post-card">
                <h2><a href="<?= e($blog_path) ?>/<?= e($post['slug']) ?>"><?= e($post['title']) ?></a></h2>
                <div class="post-meta">
                    <?= format_date($post['published_at'], 'd M Y') ?>
                    <span class="tag"><?= $post['type'] ?></span>
                </div>
                <?php if ($post['excerpt']): ?>
                    <p class="post-excerpt"><?= e($post['excerpt']) ?></p>
                <?php endif; ?>
                <a href="<?= e($blog_path) ?>/<?= e($post['slug']) ?>" class="read-more">Read more</a>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">&laquo; Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page + 1 ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<footer style="text-align:center;padding:20px;font-size:12px;color:#888;background:#f8fafc;border-top:1px solid #e5e7eb;margin-top:20px;">
    <div>&copy; <?= date('Y') ?> <a href="https://<?= e($site['domain']) ?>" style="color:var(--primary);text-decoration:none;"><?= e($site['name']) ?></a></div>
    <div style="margin-top:4px;font-size:10px;color:#bbb;">Powered by <a href="https://contentagent.xceedtech.in" style="color:#bbb;text-decoration:none;">ContentAgent</a></div>
</footer>

</body>
</html>
