<?php
/**
 * Brave Search API provider.
 *
 * Free tier: 2,000 queries/month, 1 query/second rate limit.
 * No credit card required to sign up.
 * Get a key at https://api.search.brave.com/
 *
 * Returns the same shape as dataforseo_serp_results():
 *   [['position' => int, 'url' => string, 'title' => string, 'snippet' => string], ...]
 */

require_once __DIR__ . '/../helpers.php';

function brave_serp_results(string $keyword, int $depth = 30): array
{
    $kw = trim($keyword);
    if ($kw === '') return [];

    $api_key = config('brave_search_api_key');
    if (empty($api_key)) {
        throw new RuntimeException('Brave Search not configured (brave_search_api_key missing).');
    }

    // Brave caps count at 20 per request — fetch as many pages as needed
    // to satisfy the requested depth, then trim.
    $per_page = 20;
    $needed = min(100, max(10, $depth));
    $results = [];
    $offset = 0;

    while (count($results) < $needed) {
        $remaining = $needed - count($results);
        $count = min($per_page, $remaining);

        $url = 'https://api.search.brave.com/res/v1/web/search?' . http_build_query([
            'q'      => $kw,
            'count'  => $count,
            'offset' => $offset,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Subscription-Token: ' . $api_key,
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200) {
            $err = json_decode((string)$body, true);
            $msg = $err['message'] ?? $err['error'] ?? ('HTTP ' . $http);
            throw new RuntimeException('Brave Search failed: ' . $msg);
        }

        $data = json_decode($body, true);
        $page = $data['web']['results'] ?? [];
        if (empty($page)) break;

        foreach ($page as $item) {
            $u = trim($item['url'] ?? '');
            if ($u === '') continue;
            $results[] = [
                'position' => count($results) + 1,
                'url'      => $u,
                'title'    => (string)($item['title'] ?? ''),
                'snippet'  => (string)($item['description'] ?? ''),
            ];
            if (count($results) >= $needed) break 2;
        }

        // Free tier: 1 req/sec rate limit. Pause before next page.
        if (count($results) < $needed) {
            usleep(1100000); // 1.1s
            $offset += $count;
        }
    }

    return $results;
}
