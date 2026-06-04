<?php
/**
 * Sitemap generator + IndexNow pinger.
 *
 * Two related jobs collapsed into one module because they share a trigger:
 * "we just published or updated content — search engines should know NOW."
 *
 *  - Sitemap generator: regenerates the site's sitemap.xml + sitemap_index.xml
 *    on every publish. Hosts as ContentAgent-hosted sitemaps when the
 *    customer's CMS can't generate one (think: pure static, certain Shopify
 *    themes, Webflow).
 *
 *  - IndexNow ping: pushes the new/updated URL list to the IndexNow protocol
 *    endpoint. Major engines accept it (Bing, Yandex, Cloudflare, Naver).
 *    The customer needs a key file at /<key>.txt on their root (we generate
 *    one per site and tell them where to upload).
 *
 * IndexNow protocol spec: https://www.indexnow.org/
 */

require_once __DIR__ . '/helpers.php';

const INDEXNOW_HOST     = 'https://api.indexnow.org';   // central submission — relays to participating engines
const INDEXNOW_TIMEOUT  = 15;
const SITEMAP_PER_FILE  = 1000;                          // safe under search engines' 50k cap

/** Per-site IndexNow key. Generated on first use; same key returned thereafter. */
function indexnow_key_for_site(PDO $db, array $site): string
{
    // Reuse a column if available; otherwise compute deterministically and cache in notes.
    // For v1 we cache in sites.notes JSON under indexnow_key.
    $cur = json_decode($site['notes'] ?? '{}', true) ?: [];
    if (!empty($cur['indexnow_key'])) return $cur['indexnow_key'];
    $key = bin2hex(random_bytes(16)); // 32 chars
    $cur['indexnow_key'] = $key;
    try {
        $db->prepare("UPDATE sites SET notes = ? WHERE id = ?")
           ->execute([json_encode($cur), (int)$site['id']]);
    } catch (Throwable $e) {
        // notes column may not exist — fall back to deterministic per-site key
        $key = substr(sha1('contentagent:indexnow:' . $site['id'] . ':' . $site['domain']), 0, 32);
    }
    return $key;
}

/**
 * Build a sitemap XML body from a list of URLs. Caller pre-shards if >SITEMAP_PER_FILE.
 */
function sitemap_render(array $entries): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($entries as $e) {
        $loc     = htmlspecialchars((string)$e['url'], ENT_XML1);
        $lastmod = !empty($e['lastmod']) ? '    <lastmod>' . htmlspecialchars($e['lastmod'], ENT_XML1) . "</lastmod>\n" : '';
        $cf      = !empty($e['changefreq']) ? '    <changefreq>' . htmlspecialchars($e['changefreq'], ENT_XML1) . "</changefreq>\n" : '';
        $pr      = isset($e['priority']) ? '    <priority>' . htmlspecialchars((string)$e['priority'], ENT_XML1) . "</priority>\n" : '';
        $xml .= "  <url>\n    <loc>{$loc}</loc>\n{$lastmod}{$cf}{$pr}  </url>\n";
    }
    $xml .= '</urlset>' . "\n";
    return $xml;
}

/** Sitemap index when sharded. */
function sitemap_index_render(array $shard_urls): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($shard_urls as $u) {
        $xml .= "  <sitemap>\n    <loc>" . htmlspecialchars($u, ENT_XML1) . "</loc>\n  </sitemap>\n";
    }
    $xml .= '</sitemapindex>' . "\n";
    return $xml;
}

/**
 * Pull URLs for a site's sitemap. Sources (in order):
 *   1. current_site_urls (live URL inventory from the crawler)
 *   2. posts (published) — fallback if crawl hasn't run
 */
function sitemap_collect_urls(PDO $db, array $site): array
{
    $sid = (int)$site['id'];
    $base = 'https://' . preg_replace('#^https?://#i', '', (string)$site['domain']);
    $entries = [];

    $stmt = $db->prepare("SELECT url, last_crawled_at FROM current_site_urls WHERE site_id = ? ORDER BY url");
    $stmt->execute([$sid]);
    foreach ($stmt->fetchAll() as $r) {
        $entries[$r['url']] = [
            'url'     => $r['url'],
            'lastmod' => $r['last_crawled_at'] ? date('Y-m-d', strtotime($r['last_crawled_at'])) : null,
        ];
    }

    // Top up with recently-published posts (catches things crawl hasn't seen yet)
    $stmt = $db->prepare("SELECT slug, published_at, updated_at FROM posts
                          WHERE site_id = ? AND status IN ('published','approved') AND slug IS NOT NULL AND slug <> ''
                          ORDER BY published_at DESC LIMIT 5000");
    $stmt->execute([$sid]);
    $blog_path = $site['blog_path'] ?: '/blog';
    foreach ($stmt->fetchAll() as $r) {
        $url = $base . rtrim($blog_path, '/') . '/' . $r['slug'];
        if (!isset($entries[$url])) {
            $entries[$url] = [
                'url'     => $url,
                'lastmod' => $r['updated_at'] ? date('Y-m-d', strtotime($r['updated_at'])) : null,
                'changefreq' => 'weekly',
                'priority'   => '0.8',
            ];
        }
    }
    return array_values($entries);
}

/**
 * Generate sitemap.xml (sharded if needed) and return the bodies.
 * Storage is the caller's job — for now we just return them.
 * Returns ['/sitemap.xml' => body, '/sitemap-1.xml' => body, ...].
 */
function sitemap_generate(PDO $db, array $site): array
{
    $entries = sitemap_collect_urls($db, $site);
    $count = count($entries);
    if ($count === 0) return [];

    if ($count <= SITEMAP_PER_FILE) {
        return ['/sitemap.xml' => sitemap_render($entries)];
    }

    $shards = array_chunk($entries, SITEMAP_PER_FILE);
    $base   = 'https://' . preg_replace('#^https?://#i', '', (string)$site['domain']);
    $files  = [];
    $index_locs = [];
    foreach ($shards as $i => $shard) {
        $name = '/sitemap-' . ($i + 1) . '.xml';
        $files[$name] = sitemap_render($shard);
        $index_locs[] = $base . $name;
    }
    $files['/sitemap.xml'] = sitemap_index_render($index_locs);
    return $files;
}

/**
 * Push URLs to IndexNow. URLs must all share the host. Caps at 10k per call
 * per spec; we keep it under to be polite.
 *
 * Returns ['success' => bool, 'pushed' => int, 'http' => int, 'error' => ?str]
 */
function indexnow_push(string $host, string $key, array $urls): array
{
    if (empty($urls)) return ['success' => true, 'pushed' => 0, 'http' => 0, 'error' => null];
    $urls = array_values(array_slice(array_unique($urls), 0, 9000));
    $payload = [
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => 'https://' . $host . '/' . $key . '.txt',
        'urlList'     => $urls,
    ];
    $ch = curl_init(INDEXNOW_HOST . '/indexnow');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_TIMEOUT        => INDEXNOW_TIMEOUT,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // 200 = accepted, 202 = accepted (delayed), 204 = no content (accepted).
    // 400 = bad request, 403 = invalid key, 422 = key file mismatch, 429 = too many requests.
    if ($err) return ['success' => false, 'pushed' => 0, 'http' => 0, 'error' => $err];
    $ok = $code >= 200 && $code < 300;
    return [
        'success' => $ok,
        'pushed'  => $ok ? count($urls) : 0,
        'http'    => $code,
        'error'   => $ok ? null : (string)substr($body ?? '', 0, 200),
    ];
}

/**
 * Verify the customer uploaded the key file by hitting https://host/<key>.txt
 * and confirming it contains the key. Used to gate first ping.
 */
function indexnow_verify_key(string $host, string $key): array
{
    $url = 'https://' . $host . '/' . $key . '.txt';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = trim((string)curl_exec($ch));
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'verified' => $code >= 200 && $code < 300 && $body === $key,
        'http'     => $code,
        'url'      => $url,
    ];
}
