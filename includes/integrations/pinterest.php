<?php
/**
 * Pinterest integration — OAuth 2.0 + Pinterest API v5.
 *
 * Why Pinterest as a channel:
 * Pinterest is the highest-buyer-intent platform for visual commerce —
 * jewelry, home, fashion, craft. For B2C/ecommerce customers it routinely
 * out-converts LinkedIn/Twitter by a large margin. We added it 2026-06-13
 * primarily for Anna Lou (jewelry) but it's universal-value for any
 * visual-product customer.
 *
 * Setup (one-time, Sameer's job):
 * 1. Go to https://developers.pinterest.com → "My apps" → Create app
 * 2. Set the redirect URI to:
 *    config('app_url') . '/api/oauth/pinterest-callback.php'
 * 3. Request scopes: boards:read, pins:write, user_accounts:read
 *    (no review needed for standard scopes; "trial" mode is fine for our
 *    use case as long as customers personally connect via OAuth)
 * 4. Copy Client ID + Client Secret into config.php as pinterest_client_id
 *    and pinterest_client_secret.
 *
 * Auth flow:
 *   /api/oauth/pinterest-install.php  → builds authorize URL, redirects user
 *   user approves on pinterest.com
 *   /api/oauth/pinterest-callback.php → exchanges code for tokens, saves,
 *     then redirects to the board picker if user has multiple boards.
 *
 * Token lifetime:
 *   - access_token: 30 days (Pinterest documents 60 in some places; the
 *     conservative answer is 30. We refresh proactively before expiry.)
 *   - refresh_token: 1 year. Refresh issues a new access_token + the same
 *     refresh_token (or a rotated one — we always re-save whatever comes back).
 *
 * Pin creation:
 *   POST https://api.pinterest.com/v5/pins
 *     { board_id, title, description, alt_text, media_source: { source_type: 'image_url', url }, link }
 *
 * Rate limits: 1000 requests/hour/app — generous, no concern at our scale.
 *
 * Reference: https://developers.pinterest.com/docs/api/v5/
 */

require_once __DIR__ . '/../helpers.php';

const PINTEREST_SCOPES = 'boards:read,pins:write,user_accounts:read';
const PINTEREST_API    = 'https://api.pinterest.com/v5';

/**
 * Build the OAuth authorize URL. State encodes the site_id so the callback
 * knows which site to attach the connection to.
 */
function pinterest_get_auth_url(int $site_id): string
{
    $params = [
        'response_type' => 'code',
        'client_id'     => (string)config('pinterest_client_id'),
        'redirect_uri'  => config('app_url') . '/api/oauth/pinterest-callback.php',
        'scope'         => PINTEREST_SCOPES,
        'state'         => (string)$site_id,
    ];
    return 'https://www.pinterest.com/oauth/?' . http_build_query($params);
}

/**
 * Exchange the OAuth code for access + refresh tokens.
 * Returns the raw token response (access_token, refresh_token, expires_in,
 * scope, token_type, etc.) or null on failure.
 *
 * Pinterest requires HTTP Basic auth with client_id:client_secret for the
 * token endpoint — different from the urlencoded body other providers use.
 */
function pinterest_exchange_code(string $code): ?array
{
    $client_id     = (string)config('pinterest_client_id');
    $client_secret = (string)config('pinterest_client_secret');
    if (!$client_id || !$client_secret) return null;

    $ch = curl_init(PINTEREST_API . '/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => config('app_url') . '/api/oauth/pinterest-callback.php',
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($client_id . ':' . $client_secret),
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) return null;
    $data = json_decode((string)$body, true);
    return (is_array($data) && !empty($data['access_token'])) ? $data : null;
}

/**
 * Exchange a refresh_token for a new access_token. Returns the same shape as
 * pinterest_exchange_code. Pinterest sometimes rotates the refresh_token, so
 * callers should re-save whatever comes back.
 */
function pinterest_refresh_token(string $refresh_token): ?array
{
    $client_id     = (string)config('pinterest_client_id');
    $client_secret = (string)config('pinterest_client_secret');
    if (!$client_id || !$client_secret) return null;

    $ch = curl_init(PINTEREST_API . '/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($client_id . ':' . $client_secret),
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) return null;
    $data = json_decode((string)$body, true);
    return (is_array($data) && !empty($data['access_token'])) ? $data : null;
}

/**
 * Fetch the connected user's account info — used to show a friendly name
 * after connect (e.g. "Connected as @annalouoflondon").
 */
function pinterest_get_account(string $access_token): ?array
{
    $ch = curl_init(PINTEREST_API . '/user_account');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) return null;
    return json_decode((string)$body, true) ?: null;
}

/**
 * List all boards the connected user owns. Returns [{id, name, pin_count}, ...]
 * sorted by name. Empty on failure — caller decides how to handle.
 *
 * Pinterest paginates with `bookmark`; we follow up to 5 pages (~500 boards)
 * which is way past any real account size.
 */
function pinterest_list_boards(string $access_token): array
{
    $boards   = [];
    $bookmark = '';
    for ($page = 0; $page < 5; $page++) {
        $qs = ['page_size' => 100];
        if ($bookmark !== '') $qs['bookmark'] = $bookmark;
        $ch = curl_init(PINTEREST_API . '/boards?' . http_build_query($qs));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) break;
        $data = json_decode((string)$body, true) ?: [];
        foreach (($data['items'] ?? []) as $b) {
            $boards[] = [
                'id'        => (string)($b['id'] ?? ''),
                'name'      => (string)($b['name'] ?? ''),
                'pin_count' => (int)($b['pin_count'] ?? 0),
            ];
        }
        $bookmark = (string)($data['bookmark'] ?? '');
        if ($bookmark === '') break;
    }
    usort($boards, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $boards;
}

/**
 * Create a pin. Returns ['success' => bool, 'id' => string|null, 'url' => string|null, 'error' => string|null].
 *
 * Pinterest pin payload (v5):
 *   - board_id (required)
 *   - title (max 100)
 *   - description (max 500) — what shows under the pin
 *   - alt_text (max 500) — accessibility + Pinterest visual search
 *   - link — destination URL when the pin is clicked (this is where the
 *     real traffic value comes from — the link drives them to your blog)
 *   - media_source.source_type = 'image_url' (we use existing hero image URL)
 *   - media_source.url = absolute image URL
 *
 * Pinterest favours 2:3 vertical (1000x1500) — our heros are typically 16:9.
 * For v1 we use the existing hero as-is (memory: project_pinterest_channel.md
 * Option A). If engagement is weak we'll add a vertical variant generator.
 */
function pinterest_create_pin(string $access_token, string $board_id, array $opts): array
{
    $payload = array_filter([
        'board_id'    => $board_id,
        'title'       => mb_substr((string)($opts['title'] ?? ''), 0, 100),
        'description' => mb_substr((string)($opts['description'] ?? ''), 0, 500),
        'alt_text'    => mb_substr((string)($opts['alt_text'] ?? $opts['title'] ?? ''), 0, 500),
        'link'        => (string)($opts['link'] ?? ''),
        'media_source'=> [
            'source_type' => 'image_url',
            'url'         => (string)($opts['image_url'] ?? ''),
        ],
    ], fn($v) => $v !== '' && $v !== null);

    if (empty($payload['board_id']) || empty($payload['media_source']['url'])) {
        return ['success' => false, 'id' => null, 'url' => null, 'error' => 'board_id and image_url are required'];
    }

    $ch = curl_init(PINTEREST_API . '/pins');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$body, true) ?: [];
    if ($status === 201 && !empty($data['id'])) {
        $pin_id = (string)$data['id'];
        return [
            'success' => true,
            'id'      => $pin_id,
            // Pinterest pin public URL pattern: https://www.pinterest.com/pin/{id}/
            'url'     => 'https://www.pinterest.com/pin/' . $pin_id . '/',
            'error'   => null,
        ];
    }
    return [
        'success' => false,
        'id'      => null,
        'url'     => null,
        'error'   => $data['message'] ?? ('HTTP ' . $status),
    ];
}

/**
 * Persist tokens + connected account on the integrations row.
 *   - account_id      = pinterest user id (the "username" field)
 *   - account_name    = display username
 *   - extra_data JSON = { refresh_token, board_id (set later by picker), board_name }
 *
 * We store refresh_token in extra_data rather than its own column because
 * the integrations table doesn't have a refresh_token column — and adding
 * one for one provider isn't worth a migration.
 */
function pinterest_save_tokens(PDO $db, int $site_id, array $tokens, ?array $account): void
{
    $access_token  = (string)$tokens['access_token'];
    $refresh_token = (string)($tokens['refresh_token'] ?? '');
    // Pinterest's expires_in is in seconds; default to 30d if missing.
    $expires_at    = date('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? 2592000));

    $account_id   = (string)($account['username'] ?? $account['id'] ?? '');
    $account_name = (string)($account['username'] ?? '');

    // Merge with any existing extra_data so a re-connect doesn't drop board_id.
    $existing = $db->prepare('SELECT extra_data FROM integrations WHERE site_id = ? AND platform = "pinterest" LIMIT 1');
    $existing->execute([$site_id]);
    $prev = json_decode((string)($existing->fetchColumn() ?: '{}'), true) ?: [];
    if (!is_array($prev)) $prev = [];

    $extra = array_merge($prev, [
        'refresh_token' => $refresh_token,
        'connected_at'  => date('c'),
    ]);

    $stmt = $db->prepare('INSERT INTO integrations (site_id, platform, access_token, token_expires_at, account_id, account_name, extra_data, is_active)
        VALUES (?, "pinterest", ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            access_token     = VALUES(access_token),
            token_expires_at = VALUES(token_expires_at),
            account_id       = VALUES(account_id),
            account_name     = VALUES(account_name),
            extra_data       = VALUES(extra_data),
            is_active        = 1,
            updated_at       = NOW()');
    $stmt->execute([$site_id, $access_token, $expires_at, $account_id, $account_name, json_encode($extra)]);
}

/**
 * Update which Pinterest board this site posts to. Called by the board
 * picker after OAuth, or any time the customer changes it.
 */
function pinterest_set_board(PDO $db, int $site_id, string $board_id, string $board_name): bool
{
    $row = $db->prepare('SELECT extra_data FROM integrations WHERE site_id = ? AND platform = "pinterest" AND is_active = 1 LIMIT 1');
    $row->execute([$site_id]);
    $extra = json_decode((string)($row->fetchColumn() ?: '{}'), true) ?: [];
    if (!is_array($extra)) $extra = [];
    $extra['board_id']   = $board_id;
    $extra['board_name'] = $board_name;

    $stmt = $db->prepare('UPDATE integrations SET extra_data = ?, updated_at = NOW() WHERE site_id = ? AND platform = "pinterest" AND is_active = 1');
    $stmt->execute([json_encode($extra), $site_id]);
    return $stmt->rowCount() > 0;
}

/**
 * Get a usable access_token for this site — refreshing if expired or about
 * to expire in the next 24h. Returns null if no connection / refresh failed.
 *
 * Always go through this rather than reading integrations.access_token
 * directly, so refresh happens transparently and stale tokens don't bite us
 * during a publish.
 */
function pinterest_get_active_token(PDO $db, int $site_id): ?string
{
    $row = $db->prepare('SELECT access_token, token_expires_at, extra_data FROM integrations WHERE site_id = ? AND platform = "pinterest" AND is_active = 1 LIMIT 1');
    $row->execute([$site_id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r || empty($r['access_token'])) return null;

    $expires_ts = $r['token_expires_at'] ? strtotime((string)$r['token_expires_at']) : 0;
    // If more than 24h of life left, just return it.
    if ($expires_ts && $expires_ts > time() + 86400) {
        return (string)$r['access_token'];
    }

    // Otherwise refresh.
    $extra = json_decode((string)($r['extra_data'] ?: '{}'), true) ?: [];
    $refresh = (string)($extra['refresh_token'] ?? '');
    if (!$refresh) return null;

    $new = pinterest_refresh_token($refresh);
    if (!$new) return null;

    // Re-save. pinterest_save_tokens preserves board_id via the merge.
    pinterest_save_tokens($db, $site_id, $new, ['username' => '']);
    return (string)$new['access_token'];
}
