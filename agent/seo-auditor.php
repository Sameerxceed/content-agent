<?php
/**
 * Technical SEO Auditor Agent
 * Crawls a site and checks for SEO issues.
 *
 * CLI Usage: php agent/seo-auditor.php --site=1
 *            php agent/seo-auditor.php --site=1 --max-pages=50
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/haiku.php';

$db = require __DIR__ . '/../includes/db.php';

// ── Parse CLI arguments ──────────────────────────────────
$opts = getopt('', ['site:', 'max-pages:']);
$site_id   = $opts['site'] ?? null;
$max_pages = (int)($opts['max-pages'] ?? 100);

if (!$site_id) {
    echo "Usage: php seo-auditor.php --site=1 [--max-pages=100]\n";
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
$domain = 'https://' . ltrim($site['domain'], 'https://');
$domain = rtrim($domain, '/');

echo "SEO Audit: {$domain} (max {$max_pages} pages)\n";
echo str_repeat('=', 60) . "\n";

$issues = [];
$pages_crawled = 0;
$crawled_urls = [];
$urls_to_crawl = [$domain . '/'];

// ── Phase 1: Site-wide checks ───────────────────────────
echo "\n[Phase 1] Site-wide checks\n";

// Check sitemap
echo "  Checking sitemap.xml...\n";
$sitemap = scraper_check_sitemap($domain);
if (!$sitemap['exists']) {
    $issues[] = make_issue('missing_sitemap', 'critical', $domain . '/sitemap.xml',
        'sitemap.xml is missing or invalid',
        'Generate a sitemap.xml listing all important pages. Place it at the root of your domain.');
} else {
    echo "    OK: Sitemap found\n";
    // Use sitemap URLs to expand crawl list
    $sitemap_urls = scraper_parse_sitemap($sitemap['body']);
    foreach (array_slice($sitemap_urls, 0, $max_pages) as $url) {
        if (!in_array($url, $urls_to_crawl)) {
            $urls_to_crawl[] = $url;
        }
    }
    echo "    Found " . count($sitemap_urls) . " URLs in sitemap\n";
}

// Check robots.txt
echo "  Checking robots.txt...\n";
$robots = scraper_check_robots($domain);
if (!$robots['exists']) {
    $issues[] = make_issue('missing_robots', 'warning', $domain . '/robots.txt',
        'robots.txt is missing',
        'Create a robots.txt with sensible defaults. Allow all crawlers and reference your sitemap.');
} else {
    foreach ($robots['issues'] as $robot_issue) {
        $severity = strpos($robot_issue, 'blocks all') !== false ? 'critical' : 'warning';
        $issues[] = make_issue('missing_robots', $severity, $domain . '/robots.txt',
            $robot_issue,
            'Review your robots.txt rules to ensure important pages are accessible to search engines.');
    }
    if (empty($robots['issues'])) {
        echo "    OK: robots.txt looks good\n";
    }
}

// Check SSL
echo "  Checking SSL certificate...\n";
$ssl = scraper_check_ssl($domain);
if (!$ssl['valid']) {
    $issues[] = make_issue('ssl_error', 'critical', $domain,
        'SSL certificate issue: ' . ($ssl['error'] ?? 'invalid'),
        'Install or renew your SSL certificate. Use Let\'s Encrypt for free certificates.');
} elseif ($ssl['days_left'] < 30) {
    $issues[] = make_issue('ssl_error', 'warning', $domain,
        "SSL certificate expires in {$ssl['days_left']} days ({$ssl['expires']})",
        'Renew your SSL certificate before it expires. Set up auto-renewal with certbot.');
} else {
    echo "    OK: SSL valid ({$ssl['days_left']} days remaining)\n";
}

// ── Phase 2: Page-by-page crawl ────────────────────────
echo "\n[Phase 2] Crawling pages...\n";

$meta_titles = [];
$meta_descriptions = [];

while (!empty($urls_to_crawl) && $pages_crawled < $max_pages) {
    $url = array_shift($urls_to_crawl);

    // Skip if already crawled
    $normalized = rtrim($url, '/');
    if (in_array($normalized, $crawled_urls)) continue;
    $crawled_urls[] = $normalized;

    // Only crawl same-domain pages
    if (!scraper_is_same_domain($url, $domain)) continue;

    // Skip non-HTML resources
    if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp|pdf|css|js|zip|mp4|mp3|woff|woff2|ttf|eot)(\?|$)/i', $url)) {
        continue;
    }

    $pages_crawled++;
    echo "  [{$pages_crawled}/{$max_pages}] {$url}\n";

    $result = scraper_fetch($url, 15);

    // Check for HTTP errors
    if ($result['error']) {
        $issues[] = make_issue('broken_link', 'critical', $url,
            "Connection error: {$result['error']}",
            'Check if the page is accessible. The server may be blocking requests or the page may not exist.');
        continue;
    }

    if ($result['status'] === 404 || $result['status'] === 410) {
        $issues[] = make_issue('broken_link', 'critical', $url,
            "Page returns HTTP {$result['status']}",
            'Remove links pointing to this URL or create a redirect to the correct page.');
        continue;
    }

    if ($result['status'] === 401 || $result['status'] === 403) {
        $issues[] = make_issue('auth_error', 'warning', $url,
            "Page returns HTTP {$result['status']} (unauthorized/forbidden)",
            'This page is blocked. If it should be public, check your authentication settings. If intentional, ensure it\'s excluded from sitemap.');
        continue;
    }

    if ($result['redirect_count'] > 2) {
        $issues[] = make_issue('redirect_chain', 'warning', $url,
            "Redirect chain: {$result['redirect_count']} redirects to {$result['final_url']}",
            'Reduce redirect chains to a single redirect. Update internal links to point directly to the final URL.');
    }

    if ($result['status'] >= 500) {
        $issues[] = make_issue('broken_link', 'critical', $url,
            "Server error: HTTP {$result['status']}",
            'Investigate server-side errors. Check application logs for details.');
        continue;
    }

    if ($result['status'] >= 300) {
        continue; // Skip other redirects
    }

    // Parse the page
    $doc  = scraper_parse_html($result['body']);
    $meta = scraper_get_meta($doc);

    // ── Check meta title ────────────────────────────────
    $title = scraper_get_title($doc);
    if (empty($title)) {
        $issues[] = make_issue('missing_meta', 'critical', $url,
            'Page has no <title> tag',
            'Add a unique, descriptive title tag (50-60 characters) that includes your target keyword.');
    } elseif (mb_strlen($title) > 70) {
        $issues[] = make_issue('missing_meta', 'warning', $url,
            "Title tag is too long (" . mb_strlen($title) . " chars): \"{$title}\"",
            'Shorten the title to under 60 characters so it displays fully in search results.');
    }

    // Check for duplicate titles
    if (!empty($title)) {
        if (isset($meta_titles[$title])) {
            $issues[] = make_issue('duplicate_meta', 'warning', $url,
                "Duplicate title with: {$meta_titles[$title]}",
                'Each page should have a unique title tag to avoid confusion in search results.');
        }
        $meta_titles[$title] = $url;
    }

    // ── Check meta description ──────────────────────────
    $description = $meta['description'] ?? '';
    if (empty($description)) {
        $issues[] = make_issue('missing_meta', 'warning', $url,
            'Page has no meta description',
            'Add a compelling meta description (120-160 characters) that summarizes the page content.');
    } elseif (mb_strlen($description) > 170) {
        $issues[] = make_issue('missing_meta', 'info', $url,
            "Meta description is too long (" . mb_strlen($description) . " chars)",
            'Shorten the meta description to under 160 characters.');
    }

    // Check for duplicate descriptions
    if (!empty($description)) {
        if (isset($meta_descriptions[$description])) {
            $issues[] = make_issue('duplicate_meta', 'warning', $url,
                "Duplicate meta description with: {$meta_descriptions[$description]}",
                'Each page should have a unique meta description.');
        }
        $meta_descriptions[$description] = $url;
    }

    // ── Check canonical ─────────────────────────────────
    $canonical = scraper_get_canonical($doc);
    if (!$canonical) {
        $issues[] = make_issue('missing_canonical', 'warning', $url,
            'Page has no canonical tag',
            'Add a canonical tag pointing to the preferred version of this URL to avoid duplicate content.');
    }

    // ── Check Open Graph tags ───────────────────────────
    $has_og = !empty($meta['og:title']) || !empty($meta['og:description']);
    $has_twitter = !empty($meta['twitter:title']) || !empty($meta['twitter:card']);
    if (!$has_og && !$has_twitter) {
        $issues[] = make_issue('missing_og', 'info', $url,
            'Page is missing Open Graph and Twitter Card tags',
            'Add og:title, og:description, og:image tags for better social media sharing previews.');
    }

    // ── Check structured data ───────────────────────────
    $schemas = scraper_get_schema($doc);
    if (empty($schemas)) {
        $issues[] = make_issue('missing_schema', 'info', $url,
            'Page has no structured data (JSON-LD)',
            'Add JSON-LD schema markup to help search engines understand your content.');
    }

    // ── Check viewport (mobile) ─────────────────────────
    if (!scraper_check_viewport($doc)) {
        $issues[] = make_issue('mobile_issue', 'critical', $url,
            'Page is missing viewport meta tag',
            'Add <meta name="viewport" content="width=device-width, initial-scale=1"> for mobile compatibility.');
    }

    // ── Check images for alt text ───────────────────────
    $images = scraper_get_images($doc, $domain);
    foreach ($images as $img) {
        if (!$img['has_alt']) {
            $issues[] = make_issue('missing_alt', 'warning', $img['src'],
                'Image missing alt text: ' . basename($img['src']),
                'Add descriptive alt text to improve accessibility and image SEO.');
        }
    }

    // ── Check internal links (discover more pages) ──────
    $page_links = scraper_get_links($doc, $domain);
    foreach ($page_links as $link) {
        if ($link['internal'] && !in_array(rtrim($link['url'], '/'), $crawled_urls) && !in_array($link['url'], $urls_to_crawl)) {
            $urls_to_crawl[] = $link['url'];
        }
    }

    // ── Check for large page size (speed) ───────────────
    $page_size_kb = strlen($result['body']) / 1024;
    if ($page_size_kb > 500) {
        $issues[] = make_issue('speed_issue', 'warning', $url,
            "Page HTML is very large (" . round($page_size_kb) . " KB)",
            'Reduce page size by minifying HTML, removing inline CSS/JS, and lazy-loading images.');
    }
}

// ── Phase 3: Broken link check (external links sample) ──
echo "\n[Phase 3] Checking external links (sample)...\n";
$external_links_checked = 0;
$external_link_limit = 20;

// Collect unique external links found during crawl
$all_external = [];
foreach ($crawled_urls as $crawled_url) {
    // We already crawled these, just check status codes of external links found on homepage
}

// Re-fetch homepage to get external links
$homepage = scraper_fetch($domain, 15);
if ($homepage['status'] === 200) {
    $doc = scraper_parse_html($homepage['body']);
    $homepage_links = scraper_get_links($doc, $domain);

    foreach ($homepage_links as $link) {
        if (!$link['internal'] && $external_links_checked < $external_link_limit) {
            $ext_result = scraper_fetch($link['url'], 10);
            $external_links_checked++;

            if ($ext_result['status'] === 404 || $ext_result['status'] === 410) {
                $issues[] = make_issue('broken_link', 'warning', $link['url'],
                    "External broken link (HTTP {$ext_result['status']}) — linked from homepage with text: \"{$link['text']}\"",
                    'Remove or update this broken external link.');
            }
        }
    }
}
echo "  Checked {$external_links_checked} external links\n";

// ── Check for deployed fixes (page_seo + snippet) ──────
// If fixes exist in page_seo for this site, those issues are resolved
$stmt = $db->prepare('SELECT url_path FROM page_seo WHERE site_id = ?');
$stmt->execute([$site_id]);
$fixed_paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Normalize fixed paths to full URLs for comparison
$fixed_urls = [];
foreach ($fixed_paths as $path) {
    $fixed_urls[] = rtrim($domain, '/') . '/' . ltrim($path, '/');
    $fixed_urls[] = rtrim($domain, '/') . '/' . ltrim($path, '/');
    // Also match www variant
    $www_domain = str_replace('https://', 'https://www.', $domain);
    $fixed_urls[] = rtrim($www_domain, '/') . '/' . ltrim($path, '/');
}
$fixed_urls = array_map(fn($u) => rtrim($u, '/'), $fixed_urls);

$resolved_count = 0;
$open_issues = [];
foreach ($issues as $issue) {
    $issue_url = rtrim($issue['url'], '/');
    $issue_type = $issue['type'];
    // These issue types are fixable by our snippet
    $snippet_fixable = ['missing_meta', 'missing_canonical', 'missing_og', 'missing_schema', 'duplicate_meta'];
    if (in_array($issue_type, $snippet_fixable) && in_array($issue_url, $fixed_urls)) {
        $resolved_count++;
        $issue['status'] = 'fixed_by_snippet';
        $open_issues[] = $issue; // Still save it but mark as fixed
    } else {
        $issue['status'] = 'open';
        $open_issues[] = $issue;
    }
}
$issues = $open_issues;

echo "\n  Snippet fixes applied: {$resolved_count} issues resolved by ContentAgent snippet\n";

// ── Calculate score & save ──────────────────────────────
// Only count unfixed issues against the score
$unfixed_issues = array_filter($issues, fn($i) => ($i['status'] ?? 'open') === 'open');
$critical_count = count(array_filter($unfixed_issues, fn($i) => $i['severity'] === 'critical'));
$warning_count  = count(array_filter($unfixed_issues, fn($i) => $i['severity'] === 'warning'));
$info_count     = count(array_filter($unfixed_issues, fn($i) => $i['severity'] === 'info'));
$total_issues   = count($unfixed_issues);

// Score: percentage-based — checks passed vs total checks
// Each page has ~10 checks (title, meta, canonical, og, schema, viewport, alt, speed, headings, links)
$checks_per_page = 10;
$total_checks = $pages_crawled * $checks_per_page + 3; // +3 for site-wide (sitemap, robots, ssl)

// Weight issues by severity
$weighted_issues = ($critical_count * 3) + ($warning_count * 1) + ($info_count * 0.3);
$passed = max(0, $total_checks - $total_issues);

// Score = percentage of checks that passed, with severity penalty
$score = $total_checks > 0 ? round(($passed / $total_checks) * 100) : 0;

// Apply a penalty for criticals (max -15 points)
$critical_penalty = min(15, $critical_count * 5);
$score = max(0, min(100, $score - $critical_penalty));

$duration_ms = round((microtime(true) - $start_time) * 1000);

echo "\n" . str_repeat('=', 60) . "\n";
echo "AUDIT COMPLETE\n";
echo "  Score:    {$score}/100\n";
echo "  Pages:   {$pages_crawled}\n";
echo "  Issues:  {$total_issues} ({$critical_count} critical, {$warning_count} warnings, {$info_count} info)\n";
echo "  Time:    {$duration_ms}ms\n";
echo str_repeat('=', 60) . "\n";

// Save audit record
$stmt = $db->prepare('INSERT INTO seo_audits (site_id, score, total_issues, critical, warnings, passed, pages_crawled, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([$site_id, $score, $total_issues, $critical_count, $warning_count, $passed, $pages_crawled, $duration_ms]);
$audit_id = $db->lastInsertId();

// Save individual issues
$issue_stmt = $db->prepare('INSERT INTO seo_issues (audit_id, site_id, type, severity, url, description, suggested_fix, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($issues as $issue) {
    $issue_stmt->execute([
        $audit_id,
        $site_id,
        $issue['type'],
        $issue['severity'],
        $issue['url'],
        $issue['description'],
        $issue['suggested_fix'],
        $issue['status'] ?? 'open',
    ]);
}

// Log agent action
$stmt = $db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([
    $site_id,
    'seo_audit',
    json_encode(['score' => $score, 'issues' => $total_issues, 'pages' => $pages_crawled]),
    'success',
    $duration_ms,
]);

echo "\nAudit saved (ID: #{$audit_id})\n";

// Print top issues
if ($critical_count > 0) {
    echo "\nCRITICAL ISSUES:\n";
    foreach ($issues as $issue) {
        if ($issue['severity'] === 'critical') {
            echo "  ! {$issue['description']}\n";
            echo "    URL: {$issue['url']}\n";
            echo "    Fix: {$issue['suggested_fix']}\n\n";
        }
    }
}

// ── Helper ──────────────────────────────────────────────
function make_issue(string $type, string $severity, string $url, string $description, string $fix): array
{
    return [
        'type'          => $type,
        'severity'      => $severity,
        'url'           => mb_substr($url, 0, 2048),
        'description'   => $description,
        'suggested_fix' => $fix,
    ];
}
