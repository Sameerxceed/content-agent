<?php
/**
 * Google Search Console Integration.
 * Handles OAuth2 flow, token refresh, and data fetching.
 *
 * Setup:
 * 1. Go to console.cloud.google.com
 * 2. Create project → Enable "Search Console API"
 * 3. Create OAuth2 credentials (Web application)
 * 4. Set redirect URI to: YOUR_APP_URL/api/oauth/google-callback.php
 * 5. Add client_id and client_secret to config.php
 */

require_once __DIR__ . '/../helpers.php';

/**
 * Get Google OAuth2 authorization URL.
 */
function google_get_auth_url(int $site_id): string
{
    $client_id = config('google_client_id');
    $redirect_uri = config('app_url') . '/api/oauth/google-callback.php';

    $params = [
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect_uri,
        'response_type' => 'code',
        // Three scopes:
        //   webmasters.readonly — Search Console performance + index status (Item 2)
        //   content              — Google Merchant Center diagnostics (Module 4)
        // Both ask for read-only access in their respective products.
        'scope'         => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/content',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $site_id,
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Exchange authorization code for tokens.
 */
function google_exchange_code(string $code): ?array
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => config('google_client_id'),
            'client_secret' => config('google_client_secret'),
            'redirect_uri'  => config('app_url') . '/api/oauth/google-callback.php',
            'grant_type'    => 'authorization_code',
        ]),
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    if (!empty($data['access_token'])) {
        return $data;
    }
    return null;
}

/**
 * Refresh an expired access token.
 */
function google_refresh_token(string $refresh_token): ?array
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'refresh_token' => $refresh_token,
            'client_id'     => config('google_client_id'),
            'client_secret' => config('google_client_secret'),
            'grant_type'    => 'refresh_token',
        ]),
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    if (!empty($data['access_token'])) {
        return $data;
    }
    return null;
}

/**
 * Get valid access token for a site (auto-refreshes if expired).
 */
function google_get_token(PDO $db, int $site_id): ?string
{
    $stmt = $db->prepare('SELECT * FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
    $stmt->execute([$site_id]);
    $integration = $stmt->fetch();

    if (!$integration) return null;

    // Check if token is expired
    if ($integration['token_expires_at'] && strtotime($integration['token_expires_at']) < time()) {
        // Refresh
        $new_tokens = google_refresh_token($integration['refresh_token']);
        if (!$new_tokens) return null;

        $expires_at = date('Y-m-d H:i:s', time() + ($new_tokens['expires_in'] ?? 3600));
        $db->prepare('UPDATE integrations SET access_token = ?, token_expires_at = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$new_tokens['access_token'], $expires_at, $integration['id']]);

        return $new_tokens['access_token'];
    }

    return $integration['access_token'];
}

/**
 * Save Google OAuth tokens to database.
 */
function google_save_tokens(PDO $db, int $site_id, array $tokens): void
{
    $expires_at = date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600));

    $stmt = $db->prepare('INSERT INTO integrations (site_id, platform, access_token, refresh_token, token_expires_at)
        VALUES (?, "google_search_console", ?, ?, ?)
        ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = COALESCE(VALUES(refresh_token), refresh_token), token_expires_at = VALUES(token_expires_at), is_active = 1, updated_at = NOW()');
    $stmt->execute([$site_id, $tokens['access_token'], $tokens['refresh_token'] ?? null, $expires_at]);
}

/**
 * Get list of sites from Search Console.
 */
function google_get_sites(string $access_token): array
{
    $ch = curl_init('https://www.googleapis.com/webmasters/v3/sites');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return $data['siteEntry'] ?? [];
}

/**
 * Fetch search analytics (keywords, pages, etc.).
 */
function google_search_analytics(string $access_token, string $site_url, string $start_date, string $end_date, string $dimension = 'query', int $row_limit = 100): ?array
{
    $encoded = urlencode($site_url);
    $api = "https://www.googleapis.com/webmasters/v3/sites/{$encoded}/searchAnalytics/query";

    $payload = json_encode([
        'startDate'  => $start_date,
        'endDate'    => $end_date,
        'dimensions' => [$dimension],
        'rowLimit'   => $row_limit,
    ]);

    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) return null;
    return json_decode($body, true);
}

/**
 * Fetch keyword rankings and update the keywords table.
 */
function google_update_rankings(PDO $db, int $site_id): array
{
    $access_token = google_get_token($db, $site_id);
    if (!$access_token) {
        return ['success' => false, 'error' => 'Not connected to Google Search Console'];
    }

    // Get site URL — normalise so we don't get www.www.xxx
    $stmt = $db->prepare('SELECT domain FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $raw = trim((string)$stmt->fetchColumn());
    $bare = preg_replace('#^https?://#i', '', $raw);
    $bare = preg_replace('#^www\.#i', '', $bare);
    $bare = rtrim($bare, '/');

    $end_date = date('Y-m-d', strtotime('-2 days'));
    $start_date = date('Y-m-d', strtotime('-30 days'));

    // Try multiple property formats — GSC verifies any of these independently
    $candidates = [
        'sc-domain:' . $bare,
        'https://www.' . $bare . '/',
        'https://' . $bare . '/',
        'http://www.' . $bare . '/',
        'http://' . $bare . '/',
    ];

    $data = null;
    $tried = [];
    $matched_url = null;
    foreach ($candidates as $candidate) {
        $tried[] = $candidate;
        $resp = google_search_analytics($access_token, $candidate, $start_date, $end_date, 'query', 500);
        if ($resp && !empty($resp['rows'])) {
            $data = $resp;
            $matched_url = $candidate;
            break;
        }
    }

    if (!$data) {
        return [
            'success' => false,
            'error'   => 'No data returned. Make sure the connected Google account has access to one of these properties in Search Console: ' . implode(', ', $tried),
            'updated' => 0,
            'tried'   => $tried,
        ];
    }

    $updated = 0;
    $inserted = 0;
    $new_keywords = []; // freshly-inserted rows fed to the relevance filter below
    foreach ($data['rows'] as $row) {
        $keyword = $row['keys'][0] ?? '';
        if (empty($keyword)) continue;

        $position_precise = round($row['position'] ?? 0, 1);
        $position = (int)round($position_precise);
        $clicks = (int)($row['clicks'] ?? 0);
        $impressions = (int)($row['impressions'] ?? 0);
        $ctr = round(($row['ctr'] ?? 0) * 100, 2);

        // Real priority based on real signals — clicks×3 + log(impressions)×5, capped 0-100
        $priority = (int)min(100, ($clicks * 3) + (log(max(1, $impressions)) * 8));

        $stmt = $db->prepare('INSERT INTO keywords (site_id, keyword, current_rank, gsc_position, impressions, clicks, ctr, search_volume, priority, source, gsc_synced_at, last_checked)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "gsc", NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                current_rank = VALUES(current_rank),
                gsc_position = VALUES(gsc_position),
                impressions = VALUES(impressions),
                clicks = VALUES(clicks),
                ctr = VALUES(ctr),
                search_volume = VALUES(search_volume),
                priority = VALUES(priority),
                source = IF(source = "manual", "manual", "gsc"),
                gsc_synced_at = NOW(),
                last_checked = NOW()');
        $stmt->execute([
            $site_id,
            mb_substr($keyword, 0, 255),
            $position,
            $position_precise,
            $impressions,
            $clicks,
            $ctr,
            $impressions,
            $priority,
        ]);
        // PDO rowCount() returns 1 for a fresh INSERT and 2 for an
        // ON-DUPLICATE-KEY UPDATE — so 1 means "this row is new this sync".
        if ($stmt->rowCount() === 1) {
            $inserted++;
            $new_keywords[] = mb_substr($keyword, 0, 255);
        } else {
            $updated++;
        }
    }

    // ── Auto-filter off-topic queries from the freshly-imported batch ──
    // GSC pulls every search Google decided to show this site for, and
    // long-tail blog/news drift tends to dominate ("amazon stuck centers
    // with months", "claude vs gpt4", etc. when a tech-news blog ranks
    // for them). Claude scans the new arrivals against the business
    // profile and ignores anything a real customer of THIS business
    // would never type. User can always restore from the Ignored tab.
    $auto_ignored = 0;
    if (!empty($new_keywords)) {
        try {
            require_once __DIR__ . '/../keyword_intelligence.php';
            $auto_ignored = keywords_auto_ignore_offtopic($db, $site_id, $new_keywords);
        } catch (Throwable $e) {
            error_log('[google_update_rankings] auto-ignore pass failed: ' . $e->getMessage());
        }
    }

    return [
        'success'      => true,
        'updated'      => $updated,
        'inserted'     => $inserted,
        'auto_ignored' => $auto_ignored,
        'total_rows'   => count($data['rows']),
        'matched_url'  => $matched_url,
    ];
}

/**
 * Get page performance data.
 */
function google_page_performance(PDO $db, int $site_id, int $days = 30): ?array
{
    $access_token = google_get_token($db, $site_id);
    if (!$access_token) return null;

    $stmt = $db->prepare('SELECT domain FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $domain = (string)$stmt->fetchColumn();

    $end_date = date('Y-m-d', strtotime('-2 days'));
    $start_date = date('Y-m-d', strtotime("-{$days} days"));

    // GSC properties can be sc-domain: or URL-prefix (http/https, with/without
    // www) — try them in turn and use whichever returns data. Hard-coding
    // sc-domain: caused empty page tables for accounts that only have the
    // URL-prefix property verified (e.g. xceedtech.in).
    foreach (_google_property_candidates($domain) as $candidate) {
        $resp = google_search_analytics($access_token, $candidate, $start_date, $end_date, 'page', 100);
        if ($resp && !empty($resp['rows'])) return $resp;
    }
    return null;
}

/**
 * GSC verifies properties independently — a single account can have any of:
 *   sc-domain:xceedtech.in        (domain property)
 *   https://www.xceedtech.in/     (URL-prefix, https + www)
 *   https://xceedtech.in/         (URL-prefix, https, no www)
 *   http://www.xceedtech.in/      (URL-prefix, http + www)
 *   http://xceedtech.in/          (URL-prefix, http, no www)
 * Returns all five so callers can try each until one returns data.
 */
function _google_property_candidates(string $domain): array
{
    $bare = preg_replace('#^https?://#i', '', trim($domain));
    $bare = preg_replace('#^www\.#i', '', $bare);
    $bare = rtrim($bare, '/');
    return [
        'sc-domain:' . $bare,
        'https://www.' . $bare . '/',
        'https://' . $bare . '/',
        'http://www.' . $bare . '/',
        'http://' . $bare . '/',
    ];
}

/**
 * Get overall performance summary.
 */
function google_performance_summary(PDO $db, int $site_id, int $days = 30): ?array
{
    $access_token = google_get_token($db, $site_id);
    if (!$access_token) return null;

    $stmt = $db->prepare('SELECT domain FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $domain = (string)$stmt->fetchColumn();

    $end_date = date('Y-m-d', strtotime('-2 days'));
    $start_date = date('Y-m-d', strtotime("-{$days} days"));

    // Same multi-property fallback as page_performance / update_rankings.
    foreach (_google_property_candidates($domain) as $candidate) {
        $encoded = urlencode($candidate);
        $api = "https://www.googleapis.com/webmasters/v3/sites/{$encoded}/searchAnalytics/query";

        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['startDate' => $start_date, 'endDate' => $end_date]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($body, true);
        if (!empty($data['rows'][0])) {
            return [
                'clicks'      => $data['rows'][0]['clicks'] ?? 0,
                'impressions' => $data['rows'][0]['impressions'] ?? 0,
                'ctr'         => round(($data['rows'][0]['ctr'] ?? 0) * 100, 1),
                'position'    => round($data['rows'][0]['position'] ?? 0, 1),
            ];
        }
    }
    return null;
}
