<?php
/**
 * Twitter/X Integration.
 * OAuth2 flow + posting tweets.
 *
 * Setup:
 * 1. Go to developer.x.com → Create project + app
 * 2. Set up OAuth2 with PKCE
 * 3. Add redirect: YOUR_APP_URL/api/oauth/twitter-callback.php
 * 4. Add client_id and client_secret to config.php
 */

require_once __DIR__ . '/../helpers.php';

function twitter_get_auth_url(int $site_id): string
{
    $code_verifier = bin2hex(random_bytes(32));
    $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');

    // Store verifier in session for callback
    $_SESSION['twitter_code_verifier'] = $code_verifier;

    $params = [
        'response_type'         => 'code',
        'client_id'             => config('twitter_client_id'),
        'redirect_uri'          => config('app_url') . '/api/oauth/twitter-callback.php',
        'scope'                 => 'tweet.read tweet.write users.read offline.access',
        'state'                 => $site_id,
        'code_challenge'        => $code_challenge,
        'code_challenge_method' => 'S256',
    ];
    return 'https://twitter.com/i/oauth2/authorize?' . http_build_query($params);
}

function twitter_exchange_code(string $code, string $code_verifier): ?array
{
    $ch = curl_init('https://api.twitter.com/2/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => config('app_url') . '/api/oauth/twitter-callback.php',
            'code_verifier' => $code_verifier,
            'client_id'     => config('twitter_client_id'),
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode(config('twitter_client_id') . ':' . config('twitter_client_secret')),
        ],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    return !empty($data['access_token']) ? $data : null;
}

function twitter_get_user(string $access_token): ?array
{
    $ch = curl_init('https://api.twitter.com/2/users/me');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    return $data['data'] ?? null;
}

function twitter_post_tweet(string $access_token, string $text): array
{
    $ch = curl_init('https://api.twitter.com/2/tweets');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['text' => mb_substr($text, 0, 280)]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true);
    return [
        'success' => $status === 201,
        'id'      => $data['data']['id'] ?? null,
        'error'   => $data['detail'] ?? ($status !== 201 ? "HTTP {$status}" : null),
    ];
}

function twitter_refresh_token(string $refresh_token): ?array
{
    $ch = curl_init('https://api.twitter.com/2/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
            'client_id'     => config('twitter_client_id'),
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode(config('twitter_client_id') . ':' . config('twitter_client_secret')),
        ],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    return !empty($data['access_token']) ? $data : null;
}

function twitter_save_tokens(PDO $db, int $site_id, array $tokens, ?array $user): void
{
    $expires_at = date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 7200));

    $stmt = $db->prepare('INSERT INTO integrations (site_id, platform, access_token, refresh_token, token_expires_at, account_id, account_name)
        VALUES (?, "twitter", ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = COALESCE(VALUES(refresh_token), refresh_token), token_expires_at = VALUES(token_expires_at), account_id = VALUES(account_id), account_name = VALUES(account_name), is_active = 1, updated_at = NOW()');
    $stmt->execute([$site_id, $tokens['access_token'], $tokens['refresh_token'] ?? null, $expires_at, $user['id'] ?? null, '@' . ($user['username'] ?? '')]);
}
