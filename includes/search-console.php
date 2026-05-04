<?php
/**
 * Google Search Console API integration.
 * Fetches keyword rankings, impressions, clicks, and CTR for a site.
 *
 * Setup:
 * 1. Create a Google Cloud project
 * 2. Enable Search Console API
 * 3. Create OAuth2 credentials (or service account)
 * 4. Store refresh_token in config
 *
 * Config keys needed:
 * - google_client_id
 * - google_client_secret
 * - google_refresh_token
 */

require_once __DIR__ . '/helpers.php';

/**
 * Get an access token using refresh token.
 */
function gsc_get_access_token(): ?string
{
    $client_id = config('google_client_id');
    $client_secret = config('google_client_secret');
    $refresh_token = config('google_refresh_token');

    if (empty($client_id) || empty($refresh_token)) return null;

    $response = http_post('https://oauth2.googleapis.com/token', [], [
        'Content-Type: application/x-www-form-urlencoded',
    ]);

    // Use cURL directly for form data
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
        ]),
    ]);

    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return $data['access_token'] ?? null;
}

/**
 * Fetch search analytics data from Google Search Console.
 *
 * @param string $site_url     Property URL (e.g., "sc-domain:xceedtech.in" or "https://www.xceedtech.in/")
 * @param string $start_date   YYYY-MM-DD
 * @param string $end_date     YYYY-MM-DD
 * @param string $dimension    "query", "page", "country", "device"
 * @param int    $row_limit    Max rows (default 100)
 * @return array|null
 */
function gsc_fetch_analytics(string $site_url, string $start_date, string $end_date, string $dimension = 'query', int $row_limit = 100): ?array
{
    $access_token = gsc_get_access_token();
    if (!$access_token) return null;

    $encoded_url = urlencode($site_url);
    $api = "https://www.googleapis.com/webmasters/v3/sites/{$encoded_url}/searchAnalytics/query";

    $payload = [
        'startDate'  => $start_date,
        'endDate'    => $end_date,
        'dimensions' => [$dimension],
        'rowLimit'   => $row_limit,
    ];

    $response = http_post($api, $payload, [
        'Authorization: Bearer ' . $access_token,
    ]);

    if ($response['status'] !== 200) return null;

    return json_decode($response['body'], true);
}

/**
 * Fetch keyword rankings and update the keywords table.
 */
function gsc_update_rankings(PDO $db, int $site_id, string $site_url): array
{
    $end_date = date('Y-m-d', strtotime('-2 days')); // GSC data has 2-day delay
    $start_date = date('Y-m-d', strtotime('-30 days'));

    $data = gsc_fetch_analytics($site_url, $start_date, $end_date, 'query', 500);

    if (!$data || empty($data['rows'])) {
        return ['success' => false, 'error' => 'No data returned', 'updated' => 0];
    }

    $updated = 0;

    foreach ($data['rows'] as $row) {
        $keyword = $row['keys'][0] ?? '';
        $position = round($row['position'] ?? 0);
        $clicks = $row['clicks'] ?? 0;
        $impressions = $row['impressions'] ?? 0;
        $ctr = round(($row['ctr'] ?? 0) * 100, 1);

        if (empty($keyword)) continue;

        // Update existing keyword or insert new one
        $stmt = $db->prepare('
            INSERT INTO keywords (site_id, keyword, current_rank, search_volume, last_checked)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE current_rank = VALUES(current_rank), search_volume = ?, last_checked = NOW()
        ');
        $stmt->execute([$site_id, $keyword, $position, $impressions, $impressions]);
        $updated++;
    }

    return ['success' => true, 'updated' => $updated, 'total_rows' => count($data['rows'])];
}

/**
 * Fetch page performance data.
 */
function gsc_fetch_pages(string $site_url, int $days = 30): ?array
{
    $end_date = date('Y-m-d', strtotime('-2 days'));
    $start_date = date('Y-m-d', strtotime("-{$days} days"));

    return gsc_fetch_analytics($site_url, $start_date, $end_date, 'page', 200);
}

/**
 * Get performance summary (total clicks, impressions, avg position).
 */
function gsc_fetch_summary(string $site_url, int $days = 30): ?array
{
    $access_token = gsc_get_access_token();
    if (!$access_token) return null;

    $end_date = date('Y-m-d', strtotime('-2 days'));
    $start_date = date('Y-m-d', strtotime("-{$days} days"));

    $encoded_url = urlencode($site_url);
    $api = "https://www.googleapis.com/webmasters/v3/sites/{$encoded_url}/searchAnalytics/query";

    $payload = [
        'startDate' => $start_date,
        'endDate'   => $end_date,
    ];

    $response = http_post($api, $payload, [
        'Authorization: Bearer ' . $access_token,
    ]);

    if ($response['status'] !== 200) return null;

    $data = json_decode($response['body'], true);
    return $data['rows'][0] ?? null;
}
