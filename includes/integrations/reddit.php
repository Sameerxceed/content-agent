<?php
/**
 * Reddit Integration.
 * OAuth2 flow + submitting posts to a subreddit or self profile.
 *
 * Setup:
 *  1. https://www.reddit.com/prefs/apps → Create app type "web"
 *  2. Redirect URI: YOUR_APP_URL/api/oauth/reddit-callback.php
 *  3. Copy client_id (shown under the app name) + secret → config.php as
 *     reddit_client_id / reddit_client_secret.
 */

require_once __DIR__ . '/../helpers.php';

const REDDIT_USER_AGENT = 'web:contentagent:v1.0 (by /u/contentagent-app)';

function reddit_get_auth_url(int $site_id): string
{
    $state = $site_id . ':' . bin2hex(random_bytes(8));
    $_SESSION['reddit_oauth_state'] = $state;

    $params = [
        'client_id'     => config('reddit_client_id'),
        'response_type' => 'code',
        'state'         => $state,
        'redirect_uri'  => config('app_url') . '/api/oauth/reddit-callback.php',
        'duration'      => 'permanent',
        'scope'         => 'identity submit',
    ];
    return 'https://www.reddit.com/api/v1/authorize?' . http_build_query($params);
}

function reddit_exchange_code(string $code): ?array
{
    $ch = curl_init('https://www.reddit.com/api/v1/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => config('app_url') . '/api/oauth/reddit-callback.php',
        ]),
        CURLOPT_USERPWD        => config('reddit_client_id') . ':' . config('reddit_client_secret'),
        CURLOPT_USERAGENT      => REDDIT_USER_AGENT,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    return !empty($data['access_token']) ? $data : null;
}

function reddit_refresh_token(string $refresh_token): ?array
{
    $ch = curl_init('https://www.reddit.com/api/v1/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ]),
        CURLOPT_USERPWD        => config('reddit_client_id') . ':' . config('reddit_client_secret'),
        CURLOPT_USERAGENT      => REDDIT_USER_AGENT,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    return !empty($data['access_token']) ? $data : null;
}

function reddit_get_username(string $access_token): ?string
{
    $ch = curl_init('https://oauth.reddit.com/api/v1/me');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => REDDIT_USER_AGENT,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    return $data['name'] ?? null;
}

/**
 * Submit a self post (text) to a subreddit. To post to the user's own profile
 * page, pass $subreddit = 'u_' . username.
 *
 * @return array { success, id, url, error }
 */
function reddit_submit(string $access_token, string $subreddit, string $title, string $body, ?string $external_url = null): array
{
    $payload = [
        'sr'    => ltrim($subreddit, 'r/'),
        'kind'  => $external_url ? 'link' : 'self',
        'title' => mb_substr($title, 0, 300),
        'api_type' => 'json',
    ];
    if ($external_url) {
        $payload['url'] = $external_url;
    } else {
        $payload['text'] = $body;
    }

    $ch = curl_init('https://oauth.reddit.com/api/submit');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_USERAGENT      => REDDIT_USER_AGENT,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    $errors = $data['json']['errors'] ?? [];
    if ($status === 200 && empty($errors) && !empty($data['json']['data']['url'])) {
        return [
            'success' => true,
            'id'      => $data['json']['data']['id'] ?? $data['json']['data']['name'] ?? null,
            'url'     => $data['json']['data']['url'],
        ];
    }

    $error_text = !empty($errors) ? implode('; ', array_map(fn($e) => is_array($e) ? implode(' ', $e) : (string)$e, $errors))
        : ($data['message'] ?? ($status !== 200 ? "HTTP {$status}" : 'Unknown error'));
    return ['success' => false, 'id' => null, 'url' => null, 'error' => $error_text];
}

function reddit_save_tokens(PDO $db, int $site_id, array $tokens, ?string $username): void
{
    $expires_at = date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600));
    $stmt = $db->prepare('INSERT INTO integrations (site_id, platform, access_token, refresh_token, token_expires_at, account_id, account_name)
        VALUES (?, "reddit", ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = COALESCE(VALUES(refresh_token), refresh_token), token_expires_at = VALUES(token_expires_at), account_id = VALUES(account_id), account_name = VALUES(account_name), is_active = 1, updated_at = NOW()');
    $stmt->execute([$site_id, $tokens['access_token'], $tokens['refresh_token'] ?? null, $expires_at, $username, $username]);
}

/** Get a valid access token for this site, refreshing if expired. Returns null if not connected. */
function reddit_get_active_token(PDO $db, int $site_id): ?string
{
    $stmt = $db->prepare('SELECT access_token, refresh_token, token_expires_at FROM integrations WHERE site_id = ? AND platform = "reddit" AND is_active = 1 LIMIT 1');
    $stmt->execute([$site_id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    if (!empty($row['token_expires_at']) && strtotime($row['token_expires_at']) < time() + 60) {
        if (empty($row['refresh_token'])) return null;
        $new = reddit_refresh_token($row['refresh_token']);
        if (!$new) return null;
        $expires_at = date('Y-m-d H:i:s', time() + ($new['expires_in'] ?? 3600));
        $db->prepare('UPDATE integrations SET access_token = ?, token_expires_at = ?, updated_at = NOW() WHERE site_id = ? AND platform = "reddit"')
            ->execute([$new['access_token'], $expires_at, $site_id]);
        return $new['access_token'];
    }
    return $row['access_token'];
}
