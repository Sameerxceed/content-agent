<?php
/**
 * Blog Engine — RSS Feed.
 * URL: /blog/rss.xml
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

$blog_path = $site['blog_path'] ?: '/blog';
$base_url = 'https://' . $site['domain'];

// Get latest published posts
$stmt = $db->prepare('SELECT * FROM posts WHERE site_id = ? AND status = "published" ORDER BY published_at DESC LIMIT 20');
$stmt->execute([$site['id']]);
$posts = $stmt->fetchAll();

header('Content-Type: application/rss+xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?= e($site['name']) ?> Blog</title>
    <link><?= e($base_url . $blog_path) ?></link>
    <description>Latest articles from <?= e($site['name']) ?></description>
    <language>en</language>
    <lastBuildDate><?= date('r') ?></lastBuildDate>
    <atom:link href="<?= e($base_url . $blog_path) ?>/rss.xml" rel="self" type="application/rss+xml"/>
    <?php foreach ($posts as $post): ?>
    <item>
        <title><?= e($post['title']) ?></title>
        <link><?= e($base_url . $blog_path . '/' . $post['slug']) ?></link>
        <description><?= e($post['excerpt'] ?: truncate(strip_tags($post['body']), 300)) ?></description>
        <pubDate><?= date('r', strtotime($post['published_at'])) ?></pubDate>
        <guid isPermaLink="true"><?= e($base_url . $blog_path . '/' . $post['slug']) ?></guid>
        <category><?= e($post['type']) ?></category>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
