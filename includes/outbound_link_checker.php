<?php
/**
 * Outbound link checker.
 *
 * Walks every published post, extracts external <a href> links, HEAD-checks
 * each, and writes one row to outbound_links per (post, URL). Same-domain
 * links are skipped (those are internal links / a separate concern).
 *
 * Default heuristic: anything where the host doesn't end in the site's
 * domain is "outbound". Subdomains of the site's domain are kept internal.
 */

require_once __DIR__ . '/helpers.php';

const OUTBOUND_HEAD_TIMEOUT = 8;

/**
 * Extract all (href, anchor_text) tuples from a body of HTML.
 */
function outbound_extract_links(string $html): array
{
    if ($html === '') return [];
    $out = [];
    if (!preg_match_all('/<a\b([^>]*?)href\s*=\s*["\']([^"\']+)["\']([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) return [];
    foreach ($matches as $m) {
        $url = trim($m[2]);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) continue;
        $anchor = trim(html_entity_decode(strip_tags($m[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $out[] = ['url' => $url, 'anchor' => $anchor];
    }
    return $out;
}

function outbound_is_external(string $url, string $site_domain): bool
{
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    if ($host === '') return false; // relative — internal
    $site = strtolower(preg_replace('#^www\.#', '', $site_domain));
    $host = preg_replace('#^www\.#', '', $host);
    return !($host === $site || str_ends_with($host, '.' . $site));
}

function outbound_head(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => OUTBOUND_HEAD_TIMEOUT,
        CURLOPT_USERAGENT      => 'ContentAgent-LinkChecker/1.0',
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $redirs = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $err = curl_error($ch);
    curl_close($ch);

    // Some sites block HEAD with 405 / 403. Retry with a small GET range.
    if (in_array($code, [403, 405, 0], true)) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => OUTBOUND_HEAD_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Range: bytes=0-1024'],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ContentAgent/1.0)',
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $redirs = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        $err = curl_error($ch);
        curl_close($ch);
    }

    return ['code' => $code, 'final' => $final, 'redirects' => $redirs, 'error' => $err];
}

function outbound_classify(int $code, int $redirects, string $error): string
{
    if ($code === 0)          return 'timeout';
    if ($code >= 400)         return 'broken';
    if ($redirects >= 3)      return 'redirect_chain';
    return 'ok';
}

/**
 * Check every outbound link on every published post on the site.
 * Idempotent — upserts on (post_id, url_hash).
 */
function outbound_check_site(PDO $db, int $site_id, ?callable $progress = null): array
{
    $stmt = $db->prepare("SELECT id, title, body FROM posts WHERE site_id = ? AND status = 'published' ORDER BY id DESC");
    $stmt->execute([$site_id]);
    $posts = $stmt->fetchAll();

    $sstmt = $db->prepare("SELECT domain FROM sites WHERE id = ?");
    $sstmt->execute([$site_id]);
    $site_domain = (string)($sstmt->fetchColumn() ?: '');

    $upsert = $db->prepare("INSERT INTO outbound_links
        (site_id, post_id, url, url_hash, anchor_text, status, http_code, final_url, redirect_count, last_checked_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            anchor_text = VALUES(anchor_text),
            status = VALUES(status),
            http_code = VALUES(http_code),
            final_url = VALUES(final_url),
            redirect_count = VALUES(redirect_count),
            last_checked_at = NOW()");

    $by_status = ['ok' => 0, 'broken' => 0, 'timeout' => 0, 'redirect_chain' => 0];
    $links_found = 0; $i = 0;

    // Skip already-checked URLs in this run to avoid re-HEADing the same
    // URL hundreds of times if the same external link is on many posts.
    $seen = [];

    foreach ($posts as $post) {
        $i++;
        $links = outbound_extract_links((string)$post['body']);
        foreach ($links as $link) {
            if (!outbound_is_external($link['url'], $site_domain)) continue;
            $hash = sha1($link['url']);
            $head = $seen[$hash] ?? null;
            if ($head === null) {
                $head = outbound_head($link['url']);
                $seen[$hash] = $head;
            }
            $status = outbound_classify($head['code'], $head['redirects'], $head['error']);
            $upsert->execute([
                $site_id, (int)$post['id'], $link['url'], $hash, $link['anchor'],
                $status, $head['code'] ?: null, $head['final'] ?: null, $head['redirects'],
            ]);
            $by_status[$status]++;
            $links_found++;
        }
        if ($progress) $progress(['posts' => $i, 'total_posts' => count($posts), 'links' => $links_found]);
    }

    return [
        'posts_scanned' => count($posts),
        'links_found'   => $links_found,
        'by_status'     => $by_status,
    ];
}

function outbound_site_summary(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT status, COUNT(*) AS cnt
        FROM outbound_links WHERE site_id = ? AND dismissed_at IS NULL
        GROUP BY status");
    $stmt->execute([$site_id]);
    $by_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'by_status' => $by_status,
        'total'     => (int)array_sum($by_status),
    ];
}
