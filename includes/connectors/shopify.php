<?php
/**
 * Shopify Blog API Connector.
 * Pushes posts to Shopify stores via Admin API.
 *
 * Requirements:
 * - Shopify store with Blog feature
 * - Admin API access token (Settings → Apps → Develop apps)
 * - cms_url = https://yourstore.myshopify.com
 * - cms_api_key = shpat_xxxxx (Admin API access token)
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../integrations/shopify_graphql.php';

/**
 * Push a post to Shopify blog.
 *
 * Routes to GraphQL articleCreate for atkn_ tokens (Dev Dashboard automation
 * tokens, which can't use REST). For legacy shpat_ tokens the existing REST
 * path runs unchanged.
 */
function shopify_push_post(array $post, string $shop_url, string $access_token, int $blog_id = 0): array
{
    $shop_url = rtrim($shop_url, '/');

    if (shopify_uses_graphql($access_token)) {
        // GraphQL path — blog_id (REST int) is ignored; GraphQL needs GID.
        return shopify_graphql_create_article($post, $shop_url, $access_token, null);
    }

    // Get or find the blog ID
    if (!$blog_id) {
        $blog_id = shopify_get_default_blog($shop_url, $access_token);
        if (!$blog_id) {
            return ['success' => false, 'error' => 'No blog found on Shopify store', 'remote_id' => null];
        }
    }

    $api = "{$shop_url}/admin/api/2024-01/blogs/{$blog_id}/articles.json";

    $tags = json_decode($post['tags'] ?? '[]', true) ?: [];

    $payload = [
        'article' => [
            'title'      => $post['title'],
            'body_html'  => $post['body'],
            'summary_html' => $post['excerpt'] ?? '',
            'tags'       => implode(', ', $tags),
            'published'  => true,
            'metafields' => [
                [
                    'key'       => 'title_tag',
                    'value'     => $post['seo_title'] ?? $post['title'],
                    'type'      => 'single_line_text_field',
                    'namespace' => 'global',
                ],
                [
                    'key'       => 'description_tag',
                    'value'     => $post['seo_description'] ?? '',
                    'type'      => 'single_line_text_field',
                    'namespace' => 'global',
                ],
            ],
        ],
    ];

    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $access_token,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => $error, 'remote_id' => null];
    }

    $data = json_decode($body, true);

    if ($status === 201 && !empty($data['article']['id'])) {
        return [
            'success'   => true,
            'error'     => null,
            'remote_id' => $data['article']['id'],
            'slug'      => $data['article']['handle'] ?? $post['slug'],
            'url'       => $shop_url . '/blogs/' . $blog_id . '/' . ($data['article']['handle'] ?? ''),
        ];
    }

    $errors = $data['errors'] ?? $data['error'] ?? "HTTP {$status}";
    if (is_array($errors)) $errors = json_encode($errors);

    return ['success' => false, 'error' => $errors, 'remote_id' => null];
}

/**
 * Get the default blog ID from a Shopify store.
 */
function shopify_get_default_blog(string $shop_url, string $access_token): ?int
{
    $api = rtrim($shop_url, '/') . '/admin/api/2024-01/blogs.json';

    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $access_token],
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return $data['blogs'][0]['id'] ?? null;
}

/**
 * Test Shopify connection. Routes to GraphQL for atkn_ tokens.
 */
function shopify_test_connection(string $shop_url, string $access_token): array
{
    if (shopify_uses_graphql($access_token)) {
        $v = shopify_graphql_verify($shop_url, $access_token);
        if ($v['ok']) {
            return ['success' => true, 'shop' => $v['shop']['name'] ?? '', 'domain' => $v['shop']['domain'] ?? ''];
        }
        return ['success' => false, 'error' => $v['error'] ?? 'verify_failed'];
    }

    $api = rtrim($shop_url, '/') . '/admin/api/2024-01/shop.json';

    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $access_token],
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true);

    if ($status === 200 && !empty($data['shop']['name'])) {
        return ['success' => true, 'shop' => $data['shop']['name'], 'domain' => $data['shop']['domain']];
    }

    return ['success' => false, 'error' => $data['errors'] ?? "HTTP {$status}"];
}
