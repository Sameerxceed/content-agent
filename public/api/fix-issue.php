<?php
/**
 * API — Auto-fix SEO issues.
 * POST /api/fix-issue.php
 * Body: { "issue_id": 1 }  or  { "audit_id": 1 } (fix all)
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/scraper.php';
require_once __DIR__ . '/../../includes/haiku.php';

auth_start();

if (!auth_check()) {
    json_response(['error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$issue_id = (int)($input['issue_id'] ?? 0);
$audit_id = (int)($input['audit_id'] ?? 0);

// Fix single issue
if ($issue_id) {
    // Verify ownership
    $stmt = $db->prepare('
        SELECT i.*, s.domain, s.name as site_name
        FROM seo_issues i
        JOIN sites s ON i.site_id = s.id
        WHERE i.id = ? AND s.user_id = ?
    ');
    $stmt->execute([$issue_id, $user_id]);
    $issue = $stmt->fetch();

    if (!$issue) {
        json_response(['error' => 'Issue not found'], 404);
    }

    $result = fix_single_issue($db, $issue);
    json_response($result);
}

// Fix all issues in an audit
if ($audit_id) {
    $stmt = $db->prepare('
        SELECT i.*, s.domain, s.name as site_name
        FROM seo_issues i
        JOIN seo_audits a ON i.audit_id = a.id
        JOIN sites s ON i.site_id = s.id
        WHERE i.audit_id = ? AND s.user_id = ? AND i.status = "open"
        ORDER BY FIELD(i.severity, "critical", "warning", "info")
    ');
    $stmt->execute([$audit_id, $user_id]);
    $issues = $stmt->fetchAll();

    if (empty($issues)) {
        json_response(['error' => 'No open issues to fix', 'fixed' => 0]);
    }

    $fixed = 0;
    $results = [];

    foreach ($issues as $issue) {
        $result = fix_single_issue($db, $issue);
        $results[] = $result;
        if ($result['success']) $fixed++;
    }

    json_response([
        'success' => true,
        'fixed'   => $fixed,
        'total'   => count($issues),
        'results' => $results,
    ]);
}

json_response(['error' => 'Provide issue_id or audit_id'], 400);

// ─────────────────────────────────────────────────────────

function fix_single_issue(PDO $db, array $issue): array
{
    $type = $issue['type'];
    $url  = $issue['url'];
    $domain = 'https://' . $issue['domain'];

    $fix = null;

    switch ($type) {
        case 'missing_canonical':
            $fix = generate_canonical_fix($url);
            break;

        case 'missing_meta':
            $fix = generate_meta_fix($issue);
            break;

        case 'duplicate_meta':
            $fix = generate_meta_fix($issue);
            break;

        case 'missing_og':
            $fix = generate_og_fix($url, $issue);
            break;

        case 'missing_schema':
            $fix = generate_schema_fix($url, $issue);
            break;

        case 'missing_alt':
            $fix = generate_alt_fix($url, $issue);
            break;

        case 'missing_sitemap':
            $fix = generate_sitemap_fix($domain);
            break;

        case 'missing_robots':
            $fix = generate_robots_fix($domain);
            break;

        case 'broken_link':
            $fix = generate_broken_link_fix($issue, $domain);
            break;

        case 'redirect_chain':
            $fix = generate_redirect_fix($issue);
            break;

        case 'mobile_issue':
            $fix = generate_mobile_fix();
            break;

        case 'ssl_error':
            $fix = [
                'suggested_fix' => "Install or renew SSL certificate:\n\n"
                    . "# Using Let's Encrypt (free):\n"
                    . "sudo certbot --nginx -d {$issue['domain']}\n\n"
                    . "# Auto-renewal:\n"
                    . "sudo certbot renew --dry-run",
                'needs_ai' => false,
            ];
            break;

        case 'speed_issue':
            $fix = [
                'suggested_fix' => "Reduce page size:\n"
                    . "1. Minify HTML/CSS/JS\n"
                    . "2. Compress images (use WebP format)\n"
                    . "3. Enable GZIP compression in server config\n"
                    . "4. Lazy-load images below the fold\n"
                    . "5. Move inline CSS/JS to external files",
                'needs_ai' => false,
            ];
            break;

        case 'auth_error':
            $fix = [
                'suggested_fix' => "Page returns 401/403. Options:\n"
                    . "1. If page should be public: check .htaccess or middleware auth rules\n"
                    . "2. If intentionally private: exclude from sitemap.xml\n"
                    . "3. Add noindex meta tag: <meta name=\"robots\" content=\"noindex\">",
                'needs_ai' => false,
            ];
            break;

        default:
            return ['success' => false, 'error' => "No auto-fix available for type: {$type}", 'issue_id' => $issue['id']];
    }

    if (!$fix) {
        return ['success' => false, 'error' => 'Could not generate fix', 'issue_id' => $issue['id']];
    }

    // Update the issue
    $stmt = $db->prepare('UPDATE seo_issues SET suggested_fix = ?, status = "fix_proposed" WHERE id = ?');
    $stmt->execute([$fix['suggested_fix'], $issue['id']]);

    return [
        'success'  => true,
        'issue_id' => $issue['id'],
        'type'     => $type,
        'fix'      => $fix['suggested_fix'],
        'needs_ai' => $fix['needs_ai'] ?? false,
    ];
}

// ── Fix generators ──────────────────────────────────────

function generate_canonical_fix(string $url): array
{
    $canonical = rtrim($url, '/');
    return [
        'suggested_fix' => "Add this inside <head>:\n\n<link rel=\"canonical\" href=\"{$canonical}\">",
        'needs_ai' => false,
    ];
}

function generate_meta_fix(array $issue): array
{
    $desc = $issue['description'];

    // Title too long — try to shorten it
    if (strpos($desc, 'too long') !== false) {
        preg_match('/"([^"]+)"/', $desc, $m);
        $original = $m[1] ?? '';

        // Try AI first
        $result = haiku_chat(
            'You are an SEO specialist. Shorten this title tag to under 60 characters while keeping the main keyword and meaning. Output ONLY the shortened title, nothing else.',
            "Shorten this title: {$original}",
            64
        );

        if ($result['success']) {
            $shortened = trim($result['content'], '"\'');
            return [
                'suggested_fix' => "Shorten title to:\n\n<title>{$shortened}</title>\n\nOriginal ({$original})\nNew (" . mb_strlen($shortened) . " chars)",
                'needs_ai' => true,
            ];
        }

        // Fallback: simple truncation
        $short = mb_substr($original, 0, 57) . '...';
        return [
            'suggested_fix' => "Shorten title to under 60 chars:\n\n<title>{$short}</title>\n\n(AI unavailable — add API key for smarter suggestions)",
            'needs_ai' => false,
        ];
    }

    // Missing meta description
    if (strpos($desc, 'no meta description') !== false) {
        // Try fetching page content for AI
        $result = haiku_generate_meta($issue['url'], '');
        if ($result['success']) {
            $meta = json_decode($result['content'], true);
            if ($meta) {
                return [
                    'suggested_fix' => "Add this inside <head>:\n\n<meta name=\"description\" content=\"" . ($meta['seo_description'] ?? $meta['description'] ?? '') . "\">",
                    'needs_ai' => true,
                ];
            }
        }

        return [
            'suggested_fix' => "Add a meta description inside <head>:\n\n<meta name=\"description\" content=\"YOUR DESCRIPTION HERE (120-160 chars)\">\n\n(Add Haiku API key for auto-generated descriptions)",
            'needs_ai' => false,
        ];
    }

    // Missing title
    if (strpos($desc, 'no <title>') !== false) {
        return [
            'suggested_fix' => "Add a title tag inside <head>:\n\n<title>Page Title — Site Name</title>\n\nKeep it under 60 characters with your main keyword first.",
            'needs_ai' => false,
        ];
    }

    return [
        'suggested_fix' => $issue['suggested_fix'] ?? 'Review and fix the meta tag issue manually.',
        'needs_ai' => false,
    ];
}

function generate_og_fix(string $url, array $issue): array
{
    $result = haiku_chat(
        'You are a social media SEO specialist. Generate Open Graph and Twitter Card meta tags for this page. Output ONLY the HTML tags, no explanation.',
        "Generate OG tags for: {$url}",
        256
    );

    if ($result['success']) {
        return [
            'suggested_fix' => "Add these inside <head>:\n\n" . $result['content'],
            'needs_ai' => true,
        ];
    }

    return [
        'suggested_fix' => "Add these inside <head>:\n\n"
            . "<meta property=\"og:title\" content=\"PAGE TITLE\">\n"
            . "<meta property=\"og:description\" content=\"PAGE DESCRIPTION\">\n"
            . "<meta property=\"og:type\" content=\"website\">\n"
            . "<meta property=\"og:url\" content=\"{$url}\">\n"
            . "<meta property=\"og:image\" content=\"URL_TO_IMAGE\">\n"
            . "<meta name=\"twitter:card\" content=\"summary\">\n"
            . "<meta name=\"twitter:title\" content=\"PAGE TITLE\">\n"
            . "<meta name=\"twitter:description\" content=\"PAGE DESCRIPTION\">\n\n"
            . "(Add Haiku API key for auto-generated tags)",
        'needs_ai' => false,
    ];
}

function generate_schema_fix(string $url, array $issue): array
{
    $result = haiku_generate_schema('WebPage', $url, '');
    if ($result['success']) {
        return [
            'suggested_fix' => "Add this before </head>:\n\n<script type=\"application/ld+json\">\n" . $result['content'] . "\n</script>",
            'needs_ai' => true,
        ];
    }

    return [
        'suggested_fix' => "Add JSON-LD structured data before </head>:\n\n"
            . "<script type=\"application/ld+json\">\n"
            . "{\n"
            . "  \"@context\": \"https://schema.org\",\n"
            . "  \"@type\": \"WebPage\",\n"
            . "  \"name\": \"PAGE TITLE\",\n"
            . "  \"url\": \"{$url}\",\n"
            . "  \"description\": \"PAGE DESCRIPTION\"\n"
            . "}\n"
            . "</script>\n\n"
            . "(Add Haiku API key for page-specific schema)",
        'needs_ai' => false,
    ];
}

function generate_alt_fix(string $url, array $issue): array
{
    $result = haiku_generate_alt_text($url, '');
    if ($result['success']) {
        return [
            'suggested_fix' => "Add alt text to image:\n\n<img src=\"...\" alt=\"" . trim($result['content']) . "\">",
            'needs_ai' => true,
        ];
    }

    $filename = basename(parse_url($url, PHP_URL_PATH) ?? $url);
    $guess = str_replace(['-', '_', '.jpg', '.png', '.webp', '.svg'], [' ', ' ', '', '', '', ''], $filename);
    return [
        'suggested_fix' => "Add descriptive alt text:\n\n<img src=\"...\" alt=\"{$guess}\">\n\n(Add Haiku API key for AI-generated alt text)",
        'needs_ai' => false,
    ];
}

function generate_sitemap_fix(string $domain): array
{
    // Try to crawl and build sitemap
    $result = scraper_fetch($domain, 15);
    $urls = [$domain . '/'];

    if ($result['status'] === 200) {
        $doc = scraper_parse_html($result['body']);
        $links = scraper_get_links($doc, $domain);
        foreach ($links as $link) {
            if ($link['internal']) {
                $urls[] = $link['url'];
            }
        }
    }

    $urls = array_unique($urls);
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach (array_slice($urls, 0, 50) as $u) {
        $xml .= "  <url><loc>" . htmlspecialchars($u) . "</loc></url>\n";
    }
    $xml .= "</urlset>";

    return [
        'suggested_fix' => "Create sitemap.xml at your site root with this content:\n\n{$xml}\n\nAlso add to robots.txt:\nSitemap: {$domain}/sitemap.xml",
        'needs_ai' => false,
    ];
}

function generate_robots_fix(string $domain): array
{
    return [
        'suggested_fix' => "Create robots.txt at your site root:\n\n"
            . "User-agent: *\n"
            . "Allow: /\n"
            . "Disallow: /admin/\n"
            . "Disallow: /api/\n\n"
            . "Sitemap: {$domain}/sitemap.xml",
        'needs_ai' => false,
    ];
}

function generate_broken_link_fix(array $issue, string $domain): array
{
    $desc = $issue['description'];

    if (strpos($desc, 'External broken link') !== false) {
        return [
            'suggested_fix' => "Remove or replace this broken external link.\n\nBroken URL: {$issue['url']}\n\nOptions:\n1. Remove the link entirely\n2. Find the updated URL and replace it\n3. Link to an alternative resource",
            'needs_ai' => false,
        ];
    }

    // Internal broken link — try to find correct URL from sitemap
    $sitemap = scraper_check_sitemap($domain);
    if ($sitemap['exists']) {
        $sitemap_urls = scraper_parse_sitemap($sitemap['body']);
        $broken_path = parse_url($issue['url'], PHP_URL_PATH) ?? '';
        $suggestions = [];

        foreach ($sitemap_urls as $s_url) {
            $s_path = parse_url($s_url, PHP_URL_PATH) ?? '';
            similar_text($broken_path, $s_path, $pct);
            if ($pct > 50) {
                $suggestions[] = $s_url;
            }
        }

        if (!empty($suggestions)) {
            $list = implode("\n", array_slice($suggestions, 0, 3));
            return [
                'suggested_fix' => "Broken: {$issue['url']}\n\nPossible correct URLs from sitemap:\n{$list}\n\nUpdate internal links to point to the correct page.",
                'needs_ai' => false,
            ];
        }
    }

    return [
        'suggested_fix' => "Page returns 404. Options:\n1. Create a redirect from this URL to the correct page\n2. Update all internal links pointing to this URL\n3. Create the missing page",
        'needs_ai' => false,
    ];
}

function generate_redirect_fix(array $issue): array
{
    preg_match('/to (.+)$/', $issue['description'], $m);
    $final = $m[1] ?? 'the final URL';

    return [
        'suggested_fix' => "Reduce redirect chain. Update links to point directly to:\n{$final}\n\nIn your server config (Nginx/Apache), replace intermediate redirects with a single 301.",
        'needs_ai' => false,
    ];
}

function generate_mobile_fix(): array
{
    return [
        'suggested_fix' => "Add viewport meta tag inside <head>:\n\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">",
        'needs_ai' => false,
    ];
}
