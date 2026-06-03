<?php
/**
 * LinkedIn Integration.
 * OAuth2 flow + posting to LinkedIn pages/profiles.
 *
 * Setup:
 * 1. Go to linkedin.com/developers → Create app
 * 2. Products → Request "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect"
 * 3. Auth tab → Add redirect URL: YOUR_APP_URL/api/oauth/linkedin-callback.php
 * 4. Add client_id and client_secret to config.php
 */

require_once __DIR__ . '/../helpers.php';

function linkedin_get_auth_url(int $site_id): string
{
    // Personal-only scopes. Org scopes (w_organization_social, r_organization_admin)
    // require Community Management API, which can't coexist with other products on
    // the same LinkedIn app. Two-app support lands when CMA vetting approves.
    $params = [
        'response_type' => 'code',
        'client_id'     => config('linkedin_client_id'),
        'redirect_uri'  => config('app_url') . '/api/oauth/linkedin-callback.php',
        'scope'         => 'openid profile w_member_social',
        'state'         => $site_id,
    ];
    return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($params);
}

function linkedin_exchange_code(string $code): ?array
{
    $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => config('app_url') . '/api/oauth/linkedin-callback.php',
            'client_id'     => config('linkedin_client_id'),
            'client_secret' => config('linkedin_client_secret'),
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    return !empty($data['access_token']) ? $data : null;
}

function linkedin_get_profile(string $access_token): ?array
{
    $ch = curl_init('https://api.linkedin.com/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true);
}

/**
 * List company pages the connected user is an ADMINISTRATOR of and can post to.
 * Returns array of { urn, id, name } — empty array if none / API failure.
 *
 * Requires r_organization_admin scope (which we now request).
 */
function linkedin_list_admin_pages(string $access_token): array
{
    // Step 1 — fetch the org IDs the user admins
    $url = 'https://api.linkedin.com/v2/organizationalEntityAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED&count=20';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access_token,
            'X-Restli-Protocol-Version: 2.0.0',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) return [];

    $data = json_decode($body, true);
    $org_urns = [];
    foreach ($data['elements'] ?? [] as $el) {
        $urn = $el['organizationalTarget'] ?? null; // urn:li:organization:12345
        if ($urn) $org_urns[] = $urn;
    }
    if (empty($org_urns)) return [];

    // Step 2 — fetch the names of those orgs
    $pages = [];
    foreach ($org_urns as $urn) {
        $org_id = (string)preg_replace('#^urn:li:organization:#', '', $urn);
        if ($org_id === '') continue;

        $ch = curl_init('https://api.linkedin.com/v2/organizations/' . urlencode($org_id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access_token,
                'X-Restli-Protocol-Version: 2.0.0',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $b = curl_exec($ch);
        $s = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $name = 'Company page';
        if ($s === 200) {
            $org = json_decode($b, true);
            $name = $org['localizedName'] ?? ($org['name']['localized']['en_US'] ?? $name);
        }
        $pages[] = ['urn' => $urn, 'id' => $org_id, 'name' => $name];
    }
    return $pages;
}

function linkedin_post(string $access_token, string $author_urn, string $text, string $url = ''): array
{
    $payload = [
        'author'          => $author_urn,
        'lifecycleState'  => 'PUBLISHED',
        'specificContent' => [
            'com.linkedin.ugc.ShareContent' => [
                'shareCommentary'  => ['text' => $text],
                'shareMediaCategory' => $url ? 'ARTICLE' : 'NONE',
            ],
        ],
        'visibility' => [
            'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
        ],
    ];

    if ($url) {
        $payload['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [[
            'status'       => 'READY',
            'originalUrl'  => $url,
        ]];
    }

    $ch = curl_init('https://api.linkedin.com/v2/ugcPosts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'X-Restli-Protocol-Version: 2.0.0',
        ],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true);
    return [
        'success' => $status === 201,
        'id'      => $data['id'] ?? null,
        'error'   => $data['message'] ?? ($status !== 201 ? "HTTP {$status}" : null),
    ];
}

function linkedin_save_tokens(PDO $db, int $site_id, array $tokens, array $profile): void
{
    $expires_at = date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 5184000));
    $author_urn = 'urn:li:person:' . ($profile['sub'] ?? '');

    // Store the personal URN as a default. The author chooser may overwrite
    // account_id with an organization URN if the user picks a company page.
    // We also store the personal URN in extra_data so we can offer "switch back to personal".
    $extra = json_encode([
        'personal_urn'  => $author_urn,
        'personal_name' => $profile['name'] ?? '',
    ]);

    $stmt = $db->prepare('INSERT INTO integrations (site_id, platform, access_token, token_expires_at, account_id, account_name, extra_data)
        VALUES (?, "linkedin", ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), token_expires_at = VALUES(token_expires_at), account_id = VALUES(account_id), account_name = VALUES(account_name), extra_data = VALUES(extra_data), is_active = 1, updated_at = NOW()');
    $stmt->execute([$site_id, $tokens['access_token'], $expires_at, $author_urn, $profile['name'] ?? '', $extra]);
}

/**
 * Switch the author the connected LinkedIn account posts as — personal profile
 * or one of the company pages the user admins.
 */
function linkedin_set_author(PDO $db, int $site_id, string $author_urn, string $author_name): bool
{
    if (!preg_match('#^urn:li:(person|organization):[a-zA-Z0-9_-]+$#', $author_urn)) return false;
    $stmt = $db->prepare('UPDATE integrations SET account_id = ?, account_name = ?, updated_at = NOW() WHERE site_id = ? AND platform = "linkedin" AND is_active = 1');
    $stmt->execute([$author_urn, $author_name, $site_id]);
    return $stmt->rowCount() > 0;
}
