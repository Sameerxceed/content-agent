<?php
/**
 * Website Scanner Agent
 * Fetches and analyzes a customer's website.
 *
 * CLI Usage: php agent/scanner.php --site=1
 * Or:        php agent/scanner.php --url=https://example.com --user=1
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/haiku.php';

$db = require __DIR__ . '/../includes/db.php';

// ── Parse CLI arguments ──────────────────────────────────
$opts = getopt('', ['site:', 'url:', 'user:']);
$site_id  = $opts['site'] ?? null;
$scan_url = $opts['url'] ?? null;
$user_id  = $opts['user'] ?? null;

if (!$site_id && !$scan_url) {
    echo "Usage:\n";
    echo "  php scanner.php --site=1          (scan existing site)\n";
    echo "  php scanner.php --url=https://example.com --user=1  (new site)\n";
    exit(1);
}

$start_time = microtime(true);

// ── Load or create site record ───────────────────────────
if ($site_id) {
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    if (!$site) {
        echo "Site #{$site_id} not found.\n";
        exit(1);
    }
    $scan_url = 'https://' . ltrim($site['domain'], 'https://');
} else {
    // Create a new site record
    $parsed = parse_url($scan_url);
    $domain = $parsed['host'] ?? $scan_url;
    $domain = preg_replace('/^www\./', '', $domain);

    $stmt = $db->prepare('INSERT INTO sites (user_id, name, domain) VALUES (?, ?, ?)');
    $stmt->execute([$user_id, $domain, $domain]);
    $site_id = $db->lastInsertId();

    echo "Created site #{$site_id} for {$domain}\n";
}

$domain = $scan_url;
if (!preg_match('/^https?:\/\//', $domain)) {
    $domain = 'https://' . $domain;
}
$domain = rtrim($domain, '/');

echo "Scanning: {$domain}\n";

// ── Step 1: Fetch homepage ───────────────────────────────
echo "  [1/7] Fetching homepage...\n";
$homepage = scraper_fetch($domain);

if ($homepage['error'] || $homepage['status'] >= 400) {
    $error = $homepage['error'] ?: "HTTP {$homepage['status']}";
    echo "  ERROR: Could not fetch homepage: {$error}\n";
    log_agent_action($db, $site_id, 'scan', "Failed: {$error}", 'fail', $start_time);
    exit(1);
}

$html = $homepage['body'];
$doc  = scraper_parse_html($html);

// ── Step 2: Detect platform ─────────────────────────────
echo "  [2/7] Detecting platform...\n";
$platform = scraper_detect_platform($html, $homepage['headers']);
echo "         Platform: {$platform}\n";

// ── Step 3: Extract brand elements ──────────────────────
echo "  [3/7] Extracting brand elements...\n";
$title  = scraper_get_title($doc);
$meta   = scraper_get_meta($doc);
$colors = scraper_extract_colors($html);
$fonts  = scraper_extract_fonts($html);

echo "         Title: {$title}\n";
echo "         Colors: " . implode(', ', $colors) . "\n";
echo "         Fonts: " . implode(', ', $fonts) . "\n";

// ── Step 4: Extract links & content ─────────────────────
echo "  [4/7] Extracting links & content...\n";
$links    = scraper_get_links($doc, $domain);
$images   = scraper_get_images($doc, $domain);
$headings = scraper_get_headings($doc);
$text     = scraper_get_text($doc);
$social   = scraper_get_social_links($links);

$internal_links = array_filter($links, fn($l) => $l['internal']);
$external_links = array_filter($links, fn($l) => !$l['internal']);

echo "         Links: " . count($internal_links) . " internal, " . count($external_links) . " external\n";
echo "         Images: " . count($images) . "\n";
echo "         Social: " . implode(', ', array_keys($social)) . "\n";

// ── Step 5: Check blog existence ────────────────────────
echo "  [5/7] Checking for existing blog...\n";
$blog_paths = ['/blog', '/news', '/articles', '/insights', '/resources'];
$blog_found = null;

foreach ($blog_paths as $path) {
    $check = scraper_fetch($domain . $path, 10);
    if ($check['status'] >= 200 && $check['status'] < 400) {
        $blog_found = $path;
        echo "         Blog found at: {$path}\n";
        break;
    }
}

if (!$blog_found) {
    echo "         No existing blog detected\n";
}

// ── Step 6: Check technical SEO basics ──────────────────
echo "  [6/7] Checking technical SEO...\n";
$sitemap  = scraper_check_sitemap($domain);
$robots   = scraper_check_robots($domain);
$ssl      = scraper_check_ssl($domain);
$schema   = scraper_get_schema($doc);
$canonical = scraper_get_canonical($doc);
$has_viewport = scraper_check_viewport($doc);

echo "         Sitemap: " . ($sitemap['exists'] ? 'Yes' : 'Missing') . "\n";
echo "         Robots.txt: " . ($robots['exists'] ? 'Yes' : 'Missing') . "\n";
echo "         SSL: " . ($ssl['valid'] ? "Valid ({$ssl['days_left']} days left)" : 'INVALID') . "\n";
echo "         Schema: " . (count($schema) > 0 ? count($schema) . ' found' : 'Missing') . "\n";
echo "         Viewport: " . ($has_viewport ? 'Yes' : 'Missing') . "\n";
echo "         Canonical: " . ($canonical ?: 'Missing') . "\n";

// ── Step 7: AI brand analysis ───────────────────────────
echo "  [7/7] Analyzing brand with AI...\n";
$brand_analysis = haiku_analyze_brand($text);
$brand_data = null;

if ($brand_analysis['success']) {
    $brand_data = json_decode($brand_analysis['content'], true);
    if ($brand_data) {
        echo "         Tone: " . ($brand_data['tone'] ?? 'unknown') . "\n";
        echo "         Topics: " . implode(', ', $brand_data['topics'] ?? []) . "\n";
        echo "         Audience: " . ($brand_data['audience'] ?? 'unknown') . "\n";
    }
} else {
    echo "         AI analysis failed: {$brand_analysis['error']}\n";
}

// ── Save results to database ────────────────────────────
echo "\nSaving results...\n";

$stmt = $db->prepare('UPDATE sites SET
    platform     = ?,
    brand_colors = ?,
    brand_fonts  = ?,
    brand_tone   = ?,
    topics       = ?,
    blog_path    = ?,
    scanned_at   = NOW()
    WHERE id = ?');

$stmt->execute([
    $platform,
    json_encode($colors),
    json_encode($fonts),
    $brand_data['tone'] ?? null,
    json_encode($brand_data['topics'] ?? []),
    $blog_found ?: '/blog',
    $site_id,
]);

// Log the scan
$duration = round((microtime(true) - $start_time) * 1000);
log_agent_action($db, $site_id, 'scan', json_encode([
    'platform'       => $platform,
    'colors'         => $colors,
    'fonts'          => $fonts,
    'internal_links' => count($internal_links),
    'external_links' => count($external_links),
    'images'         => count($images),
    'social'         => $social,
    'blog_path'      => $blog_found,
    'sitemap'        => $sitemap['exists'],
    'robots'         => $robots['exists'],
    'ssl_valid'      => $ssl['valid'],
    'schema_count'   => count($schema),
    'has_viewport'   => $has_viewport,
]), 'success', $start_time);

echo "Done! Site #{$site_id} scanned in {$duration}ms\n";

// ── Helper ──────────────────────────────────────────────
function log_agent_action(PDO $db, int $site_id, string $action, string $details, string $status, float $start_time): void
{
    $duration = round((microtime(true) - $start_time) * 1000);
    $stmt = $db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$site_id, $action, $details, $status, $duration]);
}
