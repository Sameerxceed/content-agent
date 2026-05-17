<?php
/**
 * DataForSEO API wrapper.
 *
 * Single point of control for every DataForSEO call. Uses HTTP Basic Auth
 * with login + API password from config. Cost is tracked per call by
 * DataForSEO's own dashboard — we don't double-count.
 *
 * Endpoints we use:
 *   - /v3/dataforseo_labs/google/keyword_overview/live    : volume + difficulty + CPC
 *   - /v3/dataforseo_labs/google/keywords_for_site/live   : every keyword a domain ranks for
 *   - /v3/serp/google/organic/live/advanced               : current SERP for a keyword
 *
 * Default location = 2840 (US), language = en. Per-site override later if needed.
 */

require_once __DIR__ . '/helpers.php';

const DFSO_DEFAULT_LOCATION = 2840;
const DFSO_DEFAULT_LANGUAGE = 'en';

/**
 * Low-level POST to DataForSEO. Returns ['success', 'data', 'http_status', 'error'].
 */
function dataforseo_call(string $endpoint, array $tasks): array
{
    $login = config('dataforseo_login');
    $pass  = config('dataforseo_password');
    if (empty($login) || empty($pass)) {
        return ['success' => false, 'error' => 'DataForSEO not configured. Set up in Integrations Hub.', 'http_status' => 0];
    }

    $url = 'https://api.dataforseo.com' . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($tasks),
        CURLOPT_USERPWD        => $login . ':' . $pass,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'error' => $err, 'http_status' => 0];
    }
    if ($http !== 200) {
        return ['success' => false, 'error' => "HTTP {$http}: " . substr((string)$body, 0, 250), 'http_status' => $http];
    }

    $data = json_decode($body, true);
    $api_status = $data['status_code'] ?? 0;
    if ($api_status !== 20000) {
        return ['success' => false, 'error' => 'DFSO status_code ' . $api_status . ': ' . ($data['status_message'] ?? 'unknown'), 'http_status' => $http, 'data' => $data];
    }

    return ['success' => true, 'data' => $data, 'http_status' => $http];
}

/**
 * Fetch search volume + difficulty + CPC for a list of keywords.
 * Batches in chunks of 700 (DFSO limit). Returns map keyword => metrics.
 *
 * @return array<string, array{search_volume:int|null, keyword_difficulty:int|null, cpc:float|null, competition:string|null}>
 */
function dataforseo_keyword_overview(array $keywords, int $location_code = DFSO_DEFAULT_LOCATION, string $language_code = DFSO_DEFAULT_LANGUAGE): array
{
    $keywords = array_values(array_filter(array_map('trim', $keywords)));
    if (empty($keywords)) return [];

    $out = [];
    foreach (array_chunk($keywords, 700) as $chunk) {
        $resp = dataforseo_call('/v3/dataforseo_labs/google/keyword_overview/live', [[
            'keywords'      => $chunk,
            'location_code' => $location_code,
            'language_code' => $language_code,
        ]]);
        if (empty($resp['success'])) {
            error_log('[dataforseo_keyword_overview] ' . ($resp['error'] ?? 'unknown'));
            continue;
        }
        foreach (($resp['data']['tasks'][0]['result'] ?? []) as $row) {
            $kw = strtolower(trim($row['keyword'] ?? ''));
            if ($kw === '') continue;
            $info = $row['keyword_info'] ?? [];
            $props = $row['keyword_properties'] ?? [];
            $out[$kw] = [
                'search_volume'      => isset($info['search_volume']) ? (int)$info['search_volume'] : null,
                'keyword_difficulty' => isset($props['keyword_difficulty']) ? (int)$props['keyword_difficulty'] : null,
                'cpc'                => isset($info['cpc']) ? (float)$info['cpc'] : null,
                'competition'        => $info['competition_level'] ?? null,
            ];
        }
        usleep(120000); // ~120ms between batches to be polite
    }
    return $out;
}

/**
 * Pull every keyword a domain ranks for in Google's top 100 (organic).
 * Used for competitor keyword imports.
 *
 * @return array<int, array{keyword:string, position:int, search_volume:int|null, url:string|null}>
 */
function dataforseo_keywords_for_site(string $domain, int $limit = 500, int $location_code = DFSO_DEFAULT_LOCATION, string $language_code = DFSO_DEFAULT_LANGUAGE): array
{
    $domain = preg_replace('#^https?://#i', '', trim($domain));
    $domain = preg_replace('#^www\.#i',     '', $domain);
    $domain = rtrim($domain, '/');
    if ($domain === '') return [];

    $resp = dataforseo_call('/v3/dataforseo_labs/google/ranked_keywords/live', [[
        'target'        => $domain,
        'location_code' => $location_code,
        'language_code' => $language_code,
        'limit'         => min(1000, max(1, $limit)),
        'order_by'      => ['ranked_serp_element.serp_item.rank_group,asc'],
    ]]);
    if (empty($resp['success'])) {
        error_log('[dataforseo_keywords_for_site] ' . ($resp['error'] ?? 'unknown'));
        return [];
    }

    $out = [];
    foreach (($resp['data']['tasks'][0]['result'][0]['items'] ?? []) as $item) {
        $kw_data = $item['keyword_data'] ?? [];
        $serp    = $item['ranked_serp_element']['serp_item'] ?? [];
        $kw      = trim($kw_data['keyword'] ?? '');
        if ($kw === '') continue;
        $out[] = [
            'keyword'       => $kw,
            'position'      => isset($serp['rank_group']) ? (int)$serp['rank_group'] : null,
            'search_volume' => isset($kw_data['keyword_info']['search_volume']) ? (int)$kw_data['keyword_info']['search_volume'] : null,
            'url'           => $serp['url'] ?? null,
        ];
    }
    return $out;
}

/**
 * Get the current Google SERP for a keyword and find a target domain's position.
 * Returns null if domain not in top 100, otherwise the rank (1-100).
 */
function dataforseo_serp_position(string $keyword, string $target_domain, int $location_code = DFSO_DEFAULT_LOCATION, string $language_code = DFSO_DEFAULT_LANGUAGE): ?int
{
    $kw = trim($keyword);
    if ($kw === '') return null;
    $target_domain = preg_replace('#^https?://#i', '', trim($target_domain));
    $target_domain = preg_replace('#^www\.#i',     '', $target_domain);
    $target_domain = rtrim($target_domain, '/');

    $resp = dataforseo_call('/v3/serp/google/organic/live/advanced', [[
        'keyword'       => $kw,
        'location_code' => $location_code,
        'language_code' => $language_code,
        'depth'         => 100,
    ]]);
    if (empty($resp['success'])) return null;

    $items = $resp['data']['tasks'][0]['result'][0]['items'] ?? [];
    foreach ($items as $item) {
        if (($item['type'] ?? '') !== 'organic') continue;
        $url_host = preg_replace('#^https?://#i', '', trim($item['url'] ?? ''));
        $url_host = preg_replace('#^www\.#i',     '', $url_host);
        $url_host = strtolower(rtrim($url_host, '/'));
        if (str_starts_with($url_host, strtolower($target_domain) . '/') || $url_host === strtolower($target_domain)) {
            return isset($item['rank_group']) ? (int)$item['rank_group'] : null;
        }
    }
    return null;
}
