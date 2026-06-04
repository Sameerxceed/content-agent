<?php
/**
 * Light site crawler — discover URLs that exist on the LIVE customer site
 * today. Powers the 301 redirect map builder by giving Claude a roster of
 * possible target URLs to fuzzy-match dead historical URLs against.
 *
 * Strategy (cheapest first):
 *   1. sitemap.xml — typically lists every canonical URL the customer wants
 *      indexed. Both /sitemap.xml and Shopify's sitemap_index pattern handled.
 *   2. In-page link extraction (limited) — only if sitemap is missing / sparse.
 *
 * We capture (url, title, type). Type classification helps the builder route
 * matches: a dead /products/X redirects to a living /products/Y not to a
 * /blogs/Z, even when slug similarity is high.
 *
 * Polite: 0.8s between requests. Per-host caps so a malformed sitemap can't
 * make us crawl 100k URLs accidentally.
 */

require_once __DIR__ . '/helpers.php';

const SC_TIMEOUT      = 20;
const SC_DELAY_US     = 800000;
const SC_MAX_URLS     = 5000;  // hard cap per crawl — protects against runaway sitemaps
const SC_USER_AGENT   = 'ContentAgent-SiteCrawler/1.0';

function sc_normalise_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return '';
    $scheme = strtolower($p['scheme'] ?? 'https');
    $host   = strtolower($p['host']);
    $path   = $p['path'] ?? '/';
    if ($path !== '' && $path !== '/') $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    $query  = isset($p['query']) ? '?' . $p['query'] : '';
    return $scheme . '://' . $host . $path . $query;
}

function sc_path_only(string $url): string
{
    return mb_substr(parse_url($url, PHP_URL_PATH) ?: '/', 0, 1024);
}

function sc_classify_url(string $path): string
{
    if ($path === '/' || $path === '') return 'home';
    if (str_starts_with($path, '/products/'))    return 'product';
    if (str_starts_with($path, '/collections/')) return 'collection';
    if (str_starts_with($path, '/blogs/'))       return 'blog';
    if (str_starts_with($path, '/blog/'))        return 'blog';
    if (str_starts_with($path, '/pages/'))       return 'page';
    if (str_starts_with($path, '/news/'))        return 'blog';
    if (str_starts_with($path, '/services/'))    return 'page';
    return 'other';
}

/** GET a URL with curl. Returns [body, status, content_type]. */
function sc_http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => SC_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => SC_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['body' => (string)$body, 'status' => $code, 'content_type' => $ctype];
}

/** Parse a sitemap XML body. Returns a flat list of URLs (handles sitemap-index
 *  by recursing one level deep). Capped at SC_MAX_URLS to be safe. */
function sc_parse_sitemap(string $xml_body, string $base_url, int &$remaining): array
{
    if ($remaining <= 0) return [];
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($xml_body);
    libxml_clear_errors();
    if (!$xml) return [];

    $urls = [];
    // Sitemap-index: <sitemapindex><sitemap><loc>...</loc></sitemap>...
    if (isset($xml->sitemap)) {
        foreach ($xml->sitemap as $sm) {
            $loc = trim((string)($sm->loc ?? ''));
            if ($loc === '') continue;
            usleep(SC_DELAY_US);
            $r = sc_http_get($loc);
            if ($r['status'] >= 200 && $r['status'] < 300) {
                $urls = array_merge($urls, sc_parse_sitemap($r['body'], $base_url, $remaining));
            }
            if ($remaining <= 0) break;
        }
        return $urls;
    }
    // Regular urlset: <urlset><url><loc>...</loc></url>...
    if (isset($xml->url)) {
        foreach ($xml->url as $u) {
            $loc = trim((string)($u->loc ?? ''));
            if ($loc === '') continue;
            $urls[] = $loc;
            $remaining--;
            if ($remaining <= 0) break;
        }
    }
    return $urls;
}

/**
 * Fetch + cache a page's <title>. Returns null on miss (we still keep the URL
 * — Claude can match on slug alone when needed).
 */
function sc_fetch_title(string $url): ?string
{
    $r = sc_http_get($url);
    if ($r['status'] < 200 || $r['status'] >= 300) return null;
    if (!preg_match('#<title[^>]*>([^<]+)</title>#i', $r['body'], $m)) return null;
    return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Crawl a site's sitemap and populate current_site_urls. Returns
 * { urls_found, urls_stored, source: 'sitemap'|'fallback'|'none', error }.
 *
 * Title fetching is OPTIONAL — by default we just store url/path/type so the
 * crawl finishes in seconds. Titles can be enriched later via a separate pass
 * if Claude's slug-only matching turns out too noisy.
 */
function sc_crawl_site(PDO $db, int $site_id, string $domain, bool $fetch_titles = false): array
{
    $domain = preg_replace('#^https?://#i', '', trim($domain));
    $domain = rtrim($domain, '/');
    if ($domain === '') return ['urls_found' => 0, 'urls_stored' => 0, 'source' => 'none', 'error' => 'empty domain'];

    $db->prepare("INSERT INTO redirect_runs (site_id, kind, status) VALUES (?, 'crawl', 'running')")
       ->execute([$site_id]);
    $run_id = (int)$db->lastInsertId();

    $base = 'https://' . $domain;
    $sitemap_candidates = [
        $base . '/sitemap.xml',
        $base . '/sitemap_index.xml',
        $base . '/sitemap1.xml',
    ];

    $urls = [];
    $source = 'none';
    $error  = null;
    $remaining = SC_MAX_URLS;

    foreach ($sitemap_candidates as $sm_url) {
        $r = sc_http_get($sm_url);
        if ($r['status'] >= 200 && $r['status'] < 300 && str_contains(strtolower($r['content_type']), 'xml')) {
            $urls = sc_parse_sitemap($r['body'], $base, $remaining);
            if (!empty($urls)) { $source = 'sitemap'; break; }
        }
        usleep(SC_DELAY_US);
    }

    if (empty($urls)) {
        // No sitemap → light fallback: scrape homepage links + a couple of well-known paths.
        // (Production: extend to a frontier crawl; v1 keeps it bounded.)
        $source = 'fallback';
        $seed_paths = ['/', '/products', '/collections', '/blogs', '/blog', '/pages/about', '/about', '/contact'];
        foreach ($seed_paths as $p) {
            $r = sc_http_get($base . $p);
            if ($r['status'] >= 200 && $r['status'] < 300) {
                $urls[] = $base . $p;
                // Pull all hrefs from the page that point at the same host.
                if (preg_match_all('#href=["\']([^"\']+)["\']#i', $r['body'], $m)) {
                    foreach ($m[1] as $h) {
                        $abs = sc_normalise_url($h);
                        if ($abs === '') {
                            // relative URL — resolve against base
                            if ($h[0] === '/') $abs = sc_normalise_url($base . $h);
                            else continue;
                        }
                        if (parse_url($abs, PHP_URL_HOST) === $domain) {
                            $urls[] = $abs;
                            if (--$remaining <= 0) break 2;
                        }
                    }
                }
            }
            usleep(SC_DELAY_US);
        }
    }

    // Dedupe + normalise + store
    $seen = [];
    $upsert = $db->prepare("INSERT INTO current_site_urls
        (site_id, url, url_hash, path, url_type, last_crawled_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            path = VALUES(path), url_type = VALUES(url_type),
            last_crawled_at = NOW(), updated_at = NOW()");
    $title_upsert = $db->prepare("UPDATE current_site_urls SET title = ? WHERE site_id = ? AND url_hash = ?");
    $stored = 0;
    foreach ($urls as $raw) {
        $n = sc_normalise_url($raw);
        if ($n === '') continue;
        $hash = sha1($n);
        if (isset($seen[$hash])) continue;
        $seen[$hash] = true;
        $path = sc_path_only($n);
        $type = sc_classify_url($path);
        try {
            $upsert->execute([$site_id, mb_substr($n, 0, 2048), $hash, $path, $type]);
            $stored++;
        } catch (Throwable $e) {
            error_log('[sc] upsert: ' . $e->getMessage());
        }
    }

    // Optional title enrichment — skipped by default for speed.
    if ($fetch_titles) {
        $stmt = $db->prepare("SELECT url, url_hash FROM current_site_urls
                              WHERE site_id = ? AND (title IS NULL OR title = '')
                              ORDER BY id LIMIT 500");
        $stmt->execute([$site_id]);
        foreach ($stmt->fetchAll() as $row) {
            $t = sc_fetch_title((string)$row['url']);
            if ($t) $title_upsert->execute([mb_substr($t, 0, 500), $site_id, $row['url_hash']]);
            usleep(SC_DELAY_US);
        }
    }

    $db->prepare("UPDATE redirect_runs SET status='done', finished_at=NOW(), items_processed=?, items_succeeded=? WHERE id=?")
       ->execute([count($urls), $stored, $run_id]);

    return ['urls_found' => count($urls), 'urls_stored' => $stored, 'source' => $source, 'error' => $error, 'run_id' => $run_id];
}

/** Aggregate counts for the dashboard widget. */
function sc_site_summary(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT COUNT(*) AS total, MAX(last_crawled_at) AS last
                          FROM current_site_urls WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $row = $stmt->fetch() ?: [];
    $stmt = $db->prepare("SELECT url_type, COUNT(*) cnt FROM current_site_urls WHERE site_id = ? GROUP BY url_type ORDER BY cnt DESC");
    $stmt->execute([$site_id]);
    $by_type = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'total'    => (int)($row['total'] ?? 0),
        'last'     => $row['last'] ?? null,
        'by_type'  => $by_type,
    ];
}
