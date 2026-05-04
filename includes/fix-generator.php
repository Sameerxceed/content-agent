<?php
/**
 * Fix Generator — Platform-aware SEO fix file generator.
 * Generates actual template files for different CMS platforms.
 */

/**
 * Get platform-specific paths and template info.
 */
function fix_get_platform_info(array $site): array
{
    $platform = $site['platform'] ?? 'custom';
    $theme = $site['theme_name'] ?? 'default';

    $platforms = [
        'opencart' => [
            'header_path' => "catalog/view/theme/{$theme}/template/common/header.twig",
            'language' => 'twig',
            'comment_open' => '{# ',
            'comment_close' => ' #}',
            'sitemap_path' => 'sitemap.xml',
            'robots_path' => 'robots.txt',
        ],
        'wordpress' => [
            'header_path' => "wp-content/themes/{$theme}/header.php",
            'functions_path' => "wp-content/themes/{$theme}/functions.php",
            'language' => 'php',
            'comment_open' => '<!-- ',
            'comment_close' => ' -->',
            'sitemap_path' => 'sitemap.xml',
            'robots_path' => 'robots.txt',
        ],
        'shopify' => [
            'header_path' => 'layout/theme.liquid',
            'language' => 'liquid',
            'comment_open' => '{% comment %}',
            'comment_close' => '{% endcomment %}',
            'sitemap_path' => 'sitemap.xml',
            'robots_path' => 'robots.txt',
        ],
        'nextjs' => [
            'header_path' => 'src/app/layout.tsx',
            'language' => 'tsx',
            'comment_open' => '{/* ',
            'comment_close' => ' */}',
            'sitemap_path' => 'public/sitemap.xml',
            'robots_path' => 'public/robots.txt',
        ],
        'custom' => [
            'header_path' => 'index.html',
            'language' => 'html',
            'comment_open' => '<!-- ',
            'comment_close' => ' -->',
            'sitemap_path' => 'sitemap.xml',
            'robots_path' => 'robots.txt',
        ],
    ];

    return $platforms[$platform] ?? $platforms['custom'];
}

/**
 * Generate header SEO snippet for the platform's template language.
 * This is the code to insert before </head> in the platform's header template.
 */
function fix_generate_header_snippet(array $site, PDO $db): array
{
    $info = fix_get_platform_info($site);
    $domain = 'https://' . $site['domain'];
    $co = $info['comment_open'];
    $cc = $info['comment_close'];
    $lang = $info['language'];

    $lines = [];
    $lines[] = '';
    $lines[] = "{$co}ContentAgent SEO Fixes — Auto-generated{$cc}";

    // Viewport
    $lines[] = "{$co}Viewport for mobile{$cc}";
    if ($lang === 'twig') {
        $lines[] = '{% if not viewport_set is defined %}';
        $lines[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $lines[] = '{% endif %}';
    } elseif ($lang === 'php') {
        $lines[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    } elseif ($lang === 'liquid') {
        $lines[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    } else {
        $lines[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    }

    // Canonical
    $lines[] = '';
    $lines[] = "{$co}Canonical URL{$cc}";
    if ($lang === 'twig') {
        $lines[] = '{% if not canonical_set is defined %}';
        $lines[] = '<link rel="canonical" href="{{ base }}{{ request.get.route ? "index.php?route=" ~ request.get.route : "" }}" />';
        $lines[] = '{% endif %}';
    } elseif ($lang === 'php') {
        $lines[] = '<?php if (!defined(\'CANONICAL_SET\')): ?>';
        $lines[] = '<link rel="canonical" href="<?php echo (isset($_SERVER[\'HTTPS\']) ? \'https\' : \'http\') . \'://\' . $_SERVER[\'HTTP_HOST\'] . $_SERVER[\'REQUEST_URI\']; ?>" />';
        $lines[] = '<?php endif; ?>';
    } elseif ($lang === 'liquid') {
        $lines[] = '{% unless canonical_url == blank %}';
        $lines[] = '<link rel="canonical" href="{{ canonical_url }}" />';
        $lines[] = '{% endunless %}';
    } else {
        $lines[] = '<link rel="canonical" href="" /><!-- Set canonical URL -->';
    }

    // Open Graph
    $lines[] = '';
    $lines[] = "{$co}Open Graph tags{$cc}";
    if ($lang === 'twig') {
        $lines[] = '<meta property="og:type" content="website" />';
        $lines[] = '<meta property="og:title" content="{{ title }}" />';
        $lines[] = '{% if description %}<meta property="og:description" content="{{ description }}" />{% endif %}';
        $lines[] = '<meta property="og:url" content="{{ base }}{{ request.get.route ? "index.php?route=" ~ request.get.route : "" }}" />';
        $lines[] = '<meta property="og:site_name" content="' . htmlspecialchars($site['name']) . '" />';
    } elseif ($lang === 'php') {
        $lines[] = '<meta property="og:type" content="website" />';
        $lines[] = '<meta property="og:title" content="<?php echo htmlspecialchars($this->document->getTitle()); ?>" />';
        $lines[] = '<meta property="og:description" content="<?php echo htmlspecialchars($this->document->getDescription()); ?>" />';
        $lines[] = '<meta property="og:url" content="<?php echo (isset($_SERVER[\'HTTPS\']) ? \'https\' : \'http\') . \'://\' . $_SERVER[\'HTTP_HOST\'] . $_SERVER[\'REQUEST_URI\']; ?>" />';
        $lines[] = '<meta property="og:site_name" content="' . htmlspecialchars($site['name']) . '" />';
    } elseif ($lang === 'liquid') {
        $lines[] = '<meta property="og:type" content="website" />';
        $lines[] = '<meta property="og:title" content="{{ page_title }}" />';
        $lines[] = '<meta property="og:description" content="{{ page_description }}" />';
        $lines[] = '<meta property="og:url" content="{{ canonical_url }}" />';
        $lines[] = '<meta property="og:site_name" content="{{ shop.name }}" />';
    } else {
        $lines[] = '<meta property="og:type" content="website" />';
        $lines[] = '<meta property="og:title" content="" />';
        $lines[] = '<meta property="og:description" content="" />';
        $lines[] = '<meta property="og:url" content="" />';
        $lines[] = '<meta property="og:site_name" content="' . htmlspecialchars($site['name']) . '" />';
    }

    // Twitter Card
    $lines[] = '<meta name="twitter:card" content="summary" />';

    // Schema JSON-LD
    $lines[] = '';
    $lines[] = "{$co}Schema.org structured data{$cc}";
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $site['name'],
        'url' => $domain,
    ];
    $lines[] = '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>';

    $lines[] = '';

    $snippet = implode("\n", $lines);

    return [
        'filename' => basename($info['header_path']),
        'path' => $info['header_path'],
        'content' => $snippet,
        'instructions' => "Insert this code before </head> in:\n{$info['header_path']}",
        'type' => 'header_snippet',
    ];
}

/**
 * Generate sitemap.xml from crawled pages.
 */
function fix_generate_sitemap(array $site, PDO $db): array
{
    $domain = 'https://' . $site['domain'];

    // Get URLs from latest audit's issues (they contain crawled URLs)
    $stmt = $db->prepare('SELECT DISTINCT url FROM seo_issues WHERE site_id = ? ORDER BY url');
    $stmt->execute([$site['id']]);
    $urls = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Also add the homepage
    $all_urls = [$domain . '/'];
    foreach ($urls as $url) {
        $normalized = rtrim($url, '/');
        if (!in_array($normalized, $all_urls) && !in_array($normalized . '/', $all_urls)) {
            // Only include same-domain URLs
            if (strpos($url, $site['domain']) !== false) {
                $all_urls[] = $url;
            }
        }
    }

    // Remove duplicates and sort
    $all_urls = array_unique($all_urls);
    sort($all_urls);

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($all_urls as $url) {
        // Skip non-page URLs
        if (preg_match('/\.(jpg|jpeg|png|gif|svg|pdf|css|js|zip)(\?|$)/i', $url)) continue;

        $xml .= "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($url) . "</loc>\n";
        $xml .= "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
        $xml .= "    <changefreq>weekly</changefreq>\n";
        $xml .= "    <priority>0.8</priority>\n";
        $xml .= "  </url>\n";
    }

    $xml .= '</urlset>' . "\n";

    $info = fix_get_platform_info($site);

    return [
        'filename' => 'sitemap.xml',
        'path' => $info['sitemap_path'],
        'content' => $xml,
        'instructions' => "Upload to the root of your website:\n{$info['sitemap_path']}",
        'type' => 'sitemap',
    ];
}

/**
 * Generate robots.txt with sitemap reference.
 */
function fix_generate_robots(array $site): array
{
    $domain = 'https://' . $site['domain'];
    $info = fix_get_platform_info($site);

    $content = "User-agent: *\n";
    $content .= "Allow: /\n";
    $content .= "\n";
    $content .= "# Block admin/system paths\n";

    $platform = $site['platform'] ?? 'custom';
    if ($platform === 'opencart') {
        $content .= "Disallow: /admin/\n";
        $content .= "Disallow: /system/\n";
        $content .= "Disallow: /catalog/\n";
    } elseif ($platform === 'wordpress') {
        $content .= "Disallow: /wp-admin/\n";
        $content .= "Disallow: /wp-includes/\n";
    } elseif ($platform === 'shopify') {
        $content .= "Disallow: /admin/\n";
        $content .= "Disallow: /cart/\n";
        $content .= "Disallow: /checkout/\n";
    }

    $content .= "\n";
    $content .= "Sitemap: {$domain}/sitemap.xml\n";

    return [
        'filename' => 'robots.txt',
        'path' => $info['robots_path'],
        'content' => $content,
        'instructions' => "Upload to the root of your website:\n{$info['robots_path']}",
        'type' => 'robots',
    ];
}

/**
 * Generate all fix files for a site.
 * Returns array of fix files ready for download/deploy.
 */
function fix_generate_all(array $site, PDO $db): array
{
    $fixes = [];

    // 1. Header SEO snippet
    $fixes[] = fix_generate_header_snippet($site, $db);

    // 2. Sitemap
    $fixes[] = fix_generate_sitemap($site, $db);

    // 3. Robots.txt
    $fixes[] = fix_generate_robots($site);

    return $fixes;
}
