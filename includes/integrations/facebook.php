<?php
/**
 * Facebook/Instagram Integration.
 * OAuth2 flow + posting to Facebook pages + Instagram business accounts.
 *
 * Setup:
 * 1. Go to developers.facebook.com → Create app (Business type)
 * 2. Add "Facebook Login" product
 * 3. Settings → Add redirect: YOUR_APP_URL/api/oauth/facebook-callback.php
 * 4. Add app_id and app_secret to config.php
 * 5. For Instagram: user must have Instagram Business account linked to a Facebook Page
 */

require_once __DIR__ . '/../helpers.php';

function facebook_get_auth_url(int $site_id): string
{
    $params = [
        'client_id'     => config('facebook_app_id'),
        'redirect_uri'  => config('app_url') . '/api/oauth/facebook-callback.php',
        'scope'         => 'pages_manage_posts,pages_read_engagement,instagram_basic,instagram_content_publish',
        'response_type' => 'code',
        'state'         => $site_id,
    ];
    return 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query($params);
}

function facebook_exchange_code(string $code): ?array
{
    $url = 'https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
        'client_id'     => config('facebook_app_id'),
        'client_secret' => config('facebook_app_secret'),
        'redirect_uri'  => config('app_url') . '/api/oauth/facebook-callback.php',
        'code'          => $code,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return !empty($data['access_token']) ? $data : null;
}

function facebook_get_long_lived_token(string $short_token): ?string
{
    $url = 'https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
        'grant_type'    => 'fb_exchange_token',
        'client_id'     => config('facebook_app_id'),
        'client_secret' => config('facebook_app_secret'),
        'fb_exchange_token' => $short_token,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return $data['access_token'] ?? null;
}

function facebook_get_pages(string $access_token): array
{
    $ch = curl_init('https://graph.facebook.com/v19.0/me/accounts?access_token=' . $access_token);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return $data['data'] ?? [];
}

function facebook_post_to_page(string $page_access_token, string $page_id, string $message, string $link = ''): array
{
    $payload = ['message' => $message];
    if ($link) $payload['link'] = $link;

    $ch = curl_init("https://graph.facebook.com/v19.0/{$page_id}/feed");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(array_merge($payload, ['access_token' => $page_access_token])),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true);
    return [
        'success' => !empty($data['id']),
        'id'      => $data['id'] ?? null,
        'error'   => $data['error']['message'] ?? null,
    ];
}

function facebook_get_instagram_account(string $page_access_token, string $page_id): ?string
{
    $ch = curl_init("https://graph.facebook.com/v19.0/{$page_id}?fields=instagram_business_account&access_token={$page_access_token}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return $data['instagram_business_account']['id'] ?? null;
}

function instagram_post_image(string $access_token, string $ig_user_id, string $image_url, string $caption): array
{
    // Step 1: Create media container
    $ch = curl_init("https://graph.facebook.com/v19.0/{$ig_user_id}/media");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'image_url'    => $image_url,
            'caption'      => $caption,
            'access_token' => $access_token,
        ]),
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    $creation_id = $data['id'] ?? null;

    if (!$creation_id) {
        return ['success' => false, 'error' => $data['error']['message'] ?? 'Failed to create media'];
    }

    // Step 2: Publish
    $ch = curl_init("https://graph.facebook.com/v19.0/{$ig_user_id}/media_publish");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'creation_id'  => $creation_id,
            'access_token' => $access_token,
        ]),
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);

    return [
        'success' => !empty($data['id']),
        'id'      => $data['id'] ?? null,
        'error'   => $data['error']['message'] ?? null,
    ];
}

function instagram_post_carousel(string $access_token, string $ig_user_id, array $image_urls, string $caption): array
{
    // Step 1: Create children
    $children_ids = [];
    foreach ($image_urls as $url) {
        $ch = curl_init("https://graph.facebook.com/v19.0/{$ig_user_id}/media");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'image_url'    => $url,
                'is_carousel_item' => true,
                'access_token' => $access_token,
            ]),
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($body, true);
        if (!empty($data['id'])) {
            $children_ids[] = $data['id'];
        }
    }

    if (empty($children_ids)) {
        return ['success' => false, 'error' => 'Failed to create carousel items'];
    }

    // Step 2: Create carousel container
    $ch = curl_init("https://graph.facebook.com/v19.0/{$ig_user_id}/media");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $children_ids),
            'caption'      => $caption,
            'access_token' => $access_token,
        ]),
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    $creation_id = $data['id'] ?? null;

    if (!$creation_id) {
        return ['success' => false, 'error' => 'Failed to create carousel'];
    }

    // Step 3: Publish
    $ch = curl_init("https://graph.facebook.com/v19.0/{$ig_user_id}/media_publish");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'creation_id'  => $creation_id,
            'access_token' => $access_token,
        ]),
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);

    return [
        'success' => !empty($data['id']),
        'id'      => $data['id'] ?? null,
        'error'   => $data['error']['message'] ?? null,
    ];
}

function facebook_save_tokens(PDO $db, int $site_id, array $tokens, array $pages): void
{
    $long_token = facebook_get_long_lived_token($tokens['access_token']);
    $token = $long_token ?: $tokens['access_token'];
    $expires_at = date('Y-m-d H:i:s', time() + 5184000); // ~60 days for long-lived

    $page_data = [];
    foreach ($pages as $p) {
        $ig = facebook_get_instagram_account($p['access_token'], $p['id']);
        $page_data[] = [
            'id'           => $p['id'],
            'name'         => $p['name'],
            'access_token' => $p['access_token'],
            'instagram_id' => $ig,
        ];
    }

    // Save Facebook
    $stmt = $db->prepare('INSERT INTO integrations (site_id, platform, access_token, token_expires_at, account_name, extra_data)
        VALUES (?, "facebook", ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), token_expires_at = VALUES(token_expires_at), account_name = VALUES(account_name), extra_data = VALUES(extra_data), is_active = 1, updated_at = NOW()');
    $stmt->execute([$site_id, $token, $expires_at, $pages[0]['name'] ?? '', json_encode($page_data)]);

    // Save Instagram if available
    foreach ($page_data as $p) {
        if (!empty($p['instagram_id'])) {
            $stmt = $db->prepare('INSERT INTO integrations (site_id, platform, access_token, token_expires_at, account_id, account_name, extra_data)
                VALUES (?, "instagram", ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), token_expires_at = VALUES(token_expires_at), account_id = VALUES(account_id), is_active = 1, updated_at = NOW()');
            $stmt->execute([$site_id, $p['access_token'], $expires_at, $p['instagram_id'], $p['name'], json_encode($p)]);
            break; // Only save first Instagram account
        }
    }
}
