<?php
/**
 * Web scraper utilities for site analysis.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Fetch a URL and return the HTML + response info.
 */
function scraper_fetch(string $url, int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ContentAgent/1.0; +https://contentagent.app)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '', // accept all encodings
        CURLOPT_HEADER         => true,
    ]);

    $response    = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url   = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error       = curl_error($ch);
    $redirect_count = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    curl_close($ch);

    $headers = substr($response, 0, $header_size);
    $body    = substr($response, $header_size);

    return [
        'status'         => $status,
        'headers'        => $headers,
        'body'           => $body,
        'final_url'      => $final_url,
        'redirect_count' => $redirect_count,
        'error'          => $error,
    ];
}

/**
 * Parse HTML and extract DOM document.
 */
function scraper_parse_html(string $html): DOMDocument
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING);
    libxml_clear_errors();
    return $doc;
}

/**
 * Extract the page title.
 */
function scraper_get_title(DOMDocument $doc): string
{
    $titles = $doc->getElementsByTagName('title');
    return $titles->length > 0 ? trim($titles->item(0)->textContent) : '';
}

/**
 * Extract meta tags (description, keywords, og:*, twitter:*).
 */
function scraper_get_meta(DOMDocument $doc): array
{
    $meta = [];
    $tags = $doc->getElementsByTagName('meta');

    foreach ($tags as $tag) {
        $name    = strtolower($tag->getAttribute('name') ?: $tag->getAttribute('property'));
        $content = $tag->getAttribute('content');

        if ($name && $content) {
            $meta[$name] = $content;
        }
    }

    return $meta;
}

/**
 * Extract all links from a page.
 */
function scraper_get_links(DOMDocument $doc, string $base_url): array
{
    $links = [];
    $anchors = $doc->getElementsByTagName('a');

    foreach ($anchors as $a) {
        $href = trim($a->getAttribute('href'));
        if (empty($href) || $href === '#' || strpos($href, 'javascript:') === 0 || strpos($href, 'mailto:') === 0 || strpos($href, 'data:') === 0 || strpos($href, 'tel:') === 0) {
            continue;
        }

        $resolved = scraper_resolve_url($href, $base_url);
        if ($resolved) {
            $links[] = [
                'url'      => $resolved,
                'text'     => trim($a->textContent),
                'rel'      => $a->getAttribute('rel'),
                'internal' => scraper_is_same_domain($resolved, $base_url),
            ];
        }
    }

    return $links;
}

/**
 * Extract all images with src and alt.
 */
function scraper_get_images(DOMDocument $doc, string $base_url): array
{
    $images = [];
    $imgs = $doc->getElementsByTagName('img');

    foreach ($imgs as $img) {
        $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
        if (empty($src) || strpos($src, 'data:') === 0) continue;

        $images[] = [
            'src'     => scraper_resolve_url($src, $base_url),
            'alt'     => $img->getAttribute('alt'),
            'has_alt' => $img->hasAttribute('alt') && trim($img->getAttribute('alt')) !== '',
        ];
    }

    return $images;
}

/**
 * Extract heading structure (H1-H6).
 */
function scraper_get_headings(DOMDocument $doc): array
{
    $headings = [];
    for ($i = 1; $i <= 6; $i++) {
        $tags = $doc->getElementsByTagName("h$i");
        foreach ($tags as $tag) {
            $headings[] = [
                'level' => $i,
                'text'  => trim($tag->textContent),
            ];
        }
    }
    return $headings;
}

/**
 * Extract all text content from body.
 */
function scraper_get_text(DOMDocument $doc): string
{
    $body = $doc->getElementsByTagName('body');
    if ($body->length === 0) return '';

    $text = $body->item(0)->textContent;
    // Clean up whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Detect platform from HTML content.
 */
function scraper_detect_platform(string $html, string $headers): string
{
    $html_lower    = strtolower($html);
    $headers_lower = strtolower($headers);

    if (strpos($html_lower, 'wp-content') !== false || strpos($html_lower, 'wordpress') !== false) {
        return 'wordpress';
    }
    if (strpos($html_lower, 'shopify') !== false || strpos($headers_lower, 'x-shopify') !== false) {
        return 'shopify';
    }
    if (strpos($html_lower, 'opencart') !== false || strpos($html_lower, 'route=common') !== false) {
        return 'opencart';
    }
    if (strpos($html_lower, 'wix.com') !== false) {
        return 'wix';
    }
    if (strpos($html_lower, 'squarespace') !== false) {
        return 'squarespace';
    }
    if (strpos($html_lower, 'webflow') !== false) {
        return 'webflow';
    }
    if (strpos($headers_lower, 'x-drupal') !== false || strpos($html_lower, 'drupal') !== false) {
        return 'drupal';
    }
    if (strpos($html_lower, 'joomla') !== false) {
        return 'joomla';
    }

    return 'custom';
}

/**
 * Extract brand colors from CSS and inline styles.
 */
function scraper_extract_colors(string $html): array
{
    $colors = [];

    // Match hex colors
    preg_match_all('/#([0-9a-fA-F]{3,8})\b/', $html, $hex_matches);
    foreach ($hex_matches[0] as $color) {
        $normalized = strtolower($color);
        if (!in_array($normalized, ['#fff', '#ffffff', '#000', '#000000', '#333', '#333333', '#666', '#999', '#ccc', '#eee', '#f5f5f5', '#fafafa'])) {
            $colors[$normalized] = ($colors[$normalized] ?? 0) + 1;
        }
    }

    // Match rgb/rgba
    preg_match_all('/rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+/', $html, $rgb_matches);
    foreach ($rgb_matches[0] as $color) {
        $colors[$color] = ($colors[$color] ?? 0) + 1;
    }

    // Sort by frequency, return top 5
    arsort($colors);
    return array_slice(array_keys($colors), 0, 5);
}

/**
 * Extract font families from CSS.
 */
function scraper_extract_fonts(string $html): array
{
    $fonts = [];
    preg_match_all('/font-family\s*:\s*([^;}]+)/i', $html, $matches);

    foreach ($matches[1] as $font_str) {
        $font_list = explode(',', $font_str);
        $primary = trim($font_list[0], " \t\n\r\0\x0B'\"");
        if ($primary && !in_array(strtolower($primary), ['inherit', 'initial', 'sans-serif', 'serif', 'monospace', 'cursive', 'fantasy', 'system-ui'])) {
            $fonts[$primary] = ($fonts[$primary] ?? 0) + 1;
        }
    }

    arsort($fonts);
    return array_slice(array_keys($fonts), 0, 3);
}

/**
 * Detect social media links.
 */
function scraper_get_social_links(array $links): array
{
    $social = [];
    $platforms = [
        'facebook.com'  => 'facebook',
        'fb.com'        => 'facebook',
        'twitter.com'   => 'twitter',
        'x.com'         => 'twitter',
        'instagram.com' => 'instagram',
        'linkedin.com'  => 'linkedin',
        'youtube.com'   => 'youtube',
        'tiktok.com'    => 'tiktok',
        'pinterest.com' => 'pinterest',
    ];

    foreach ($links as $link) {
        foreach ($platforms as $domain => $platform) {
            if (strpos($link['url'], $domain) !== false && !isset($social[$platform])) {
                $social[$platform] = $link['url'];
            }
        }
    }

    return $social;
}

/**
 * Check if a URL has a valid sitemap.xml.
 */
function scraper_check_sitemap(string $domain): array
{
    $sitemap_urls = [
        $domain . '/sitemap.xml',
        $domain . '/sitemap_index.xml',
        $domain . '/sitemap/',
    ];

    foreach ($sitemap_urls as $url) {
        $result = scraper_fetch($url, 10);
        if ($result['status'] === 200 && strpos($result['body'], '<urlset') !== false || strpos($result['body'], '<sitemapindex') !== false) {
            return ['exists' => true, 'url' => $url, 'body' => $result['body']];
        }
    }

    return ['exists' => false, 'url' => null, 'body' => null];
}

/**
 * Check if robots.txt exists and parse it.
 */
function scraper_check_robots(string $domain): array
{
    $url = $domain . '/robots.txt';
    $result = scraper_fetch($url, 10);

    if ($result['status'] !== 200) {
        return ['exists' => false, 'content' => null, 'issues' => ['robots.txt not found']];
    }

    $content = $result['body'];
    $issues = [];

    // Check for overly restrictive rules
    if (preg_match('/Disallow:\s*\/\s*$/m', $content)) {
        $issues[] = 'robots.txt blocks all crawlers with "Disallow: /"';
    }

    // Check if sitemap is referenced
    if (stripos($content, 'sitemap:') === false) {
        $issues[] = 'robots.txt does not reference a sitemap';
    }

    return ['exists' => true, 'content' => $content, 'issues' => $issues];
}

/**
 * Check SSL certificate validity.
 */
function scraper_check_ssl(string $domain): array
{
    $host = parse_url($domain, PHP_URL_HOST) ?: $domain;
    $host = preg_replace('/^https?:\/\//', '', $host);

    $context = stream_context_create([
        'ssl' => ['capture_peer_cert' => true, 'verify_peer' => false],
    ]);

    $stream = @stream_socket_client(
        "ssl://{$host}:443",
        $errno, $errstr, 10,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$stream) {
        return ['valid' => false, 'error' => $errstr ?: 'Could not connect', 'expires' => null];
    }

    $params = stream_context_get_params($stream);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    fclose($stream);

    if (!$cert) {
        return ['valid' => false, 'error' => 'Could not parse certificate', 'expires' => null];
    }

    $expires = $cert['validTo_time_t'];
    $days_left = (int)(($expires - time()) / 86400);

    return [
        'valid'     => $days_left > 0,
        'expires'   => date('Y-m-d', $expires),
        'days_left' => $days_left,
        'issuer'    => $cert['issuer']['O'] ?? 'Unknown',
        'error'     => $days_left <= 0 ? 'Certificate expired' : null,
    ];
}

/**
 * Extract canonical tag from page.
 */
function scraper_get_canonical(DOMDocument $doc): ?string
{
    $links = $doc->getElementsByTagName('link');
    foreach ($links as $link) {
        if (strtolower($link->getAttribute('rel')) === 'canonical') {
            return $link->getAttribute('href');
        }
    }
    return null;
}

/**
 * Extract JSON-LD structured data from page.
 */
function scraper_get_schema(DOMDocument $doc): array
{
    $schemas = [];
    $scripts = $doc->getElementsByTagName('script');

    foreach ($scripts as $script) {
        if (strtolower($script->getAttribute('type')) === 'application/ld+json') {
            $json = json_decode(trim($script->textContent), true);
            if ($json) {
                $schemas[] = $json;
            }
        }
    }

    return $schemas;
}

/**
 * Check if page has viewport meta tag (mobile-friendly hint).
 */
function scraper_check_viewport(DOMDocument $doc): bool
{
    $metas = $doc->getElementsByTagName('meta');
    foreach ($metas as $meta) {
        if (strtolower($meta->getAttribute('name')) === 'viewport') {
            return true;
        }
    }
    return false;
}

/**
 * Resolve a relative URL against a base URL.
 */
function scraper_resolve_url(string $relative, string $base): string
{
    if (preg_match('/^https?:\/\//', $relative)) {
        return $relative;
    }

    $parts = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $base_path = $parts['path'] ?? '/';

    if (strpos($relative, '//') === 0) {
        return $scheme . ':' . $relative;
    }

    if (strpos($relative, '/') === 0) {
        return $scheme . '://' . $host . $relative;
    }

    // Relative path
    $dir = rtrim(dirname($base_path), '/');
    return $scheme . '://' . $host . $dir . '/' . $relative;
}

/**
 * Check if two URLs are on the same domain.
 */
function scraper_is_same_domain(string $url1, string $url2): bool
{
    $host1 = parse_url($url1, PHP_URL_HOST);
    $host2 = parse_url($url2, PHP_URL_HOST);
    if (!$host1 || !$host2) return false;

    // Strip www.
    $host1 = preg_replace('/^www\./', '', $host1);
    $host2 = preg_replace('/^www\./', '', $host2);

    return $host1 === $host2;
}

/**
 * Parse sitemap XML and return list of URLs.
 */
function scraper_parse_sitemap(string $xml): array
{
    $urls = [];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);

    if (!$doc->loadXML($xml)) {
        libxml_clear_errors();
        return $urls;
    }

    libxml_clear_errors();

    // Check for sitemap index
    $sitemaps = $doc->getElementsByTagName('sitemap');
    if ($sitemaps->length > 0) {
        foreach ($sitemaps as $sitemap) {
            $loc = $sitemap->getElementsByTagName('loc');
            if ($loc->length > 0) {
                $sub_url = $loc->item(0)->textContent;
                $sub_result = scraper_fetch($sub_url, 15);
                if ($sub_result['status'] === 200) {
                    $urls = array_merge($urls, scraper_parse_sitemap($sub_result['body']));
                }
            }
        }
        return $urls;
    }

    // Regular sitemap
    $url_tags = $doc->getElementsByTagName('url');
    foreach ($url_tags as $url_tag) {
        $loc = $url_tag->getElementsByTagName('loc');
        if ($loc->length > 0) {
            $urls[] = $loc->item(0)->textContent;
        }
    }

    return $urls;
}
