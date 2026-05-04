<?php
/**
 * WordPress REST API Connector.
 * Pushes posts to WordPress sites via REST API.
 *
 * Requirements:
 * - WordPress site with REST API enabled (default in WP 4.7+)
 * - Application Password (Users → Profile → Application Passwords in WP admin)
 * - cms_url = https://yoursite.com (WordPress root URL)
 * - cms_api_key = username:application_password (base64 encoded by this connector)
 */

require_once __DIR__ . '/../helpers.php';

/**
 * Push a post to WordPress.
 */
function wp_push_post(array $post, string $wp_url, string $credentials): array
{
    $wp_url = rtrim($wp_url, '/');
    $api = $wp_url . '/wp-json/wp/v2/posts';

    // credentials format: username:app_password
    $auth = base64_encode($credentials);

    $tags = json_decode($post['tags'] ?? '[]', true) ?: [];

    $payload = [
        'title'   => $post['title'],
        'slug'    => $post['slug'],
        'content' => $post['body'],
        'excerpt' => $post['excerpt'] ?? '',
        'status'  => 'publish',
        'meta'    => [
            '_yoast_wpseo_title'   => $post['seo_title'] ?? '',
            '_yoast_wpseo_metadesc' => $post['seo_description'] ?? '',
        ],
    ];

    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . $auth,
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

    if ($status === 201 && !empty($data['id'])) {
        // Add tags if available
        if (!empty($tags)) {
            wp_set_tags($wp_url, $auth, $data['id'], $tags);
        }

        return [
            'success'   => true,
            'error'     => null,
            'remote_id' => $data['id'],
            'slug'      => $data['slug'] ?? $post['slug'],
            'url'       => $data['link'] ?? null,
        ];
    }

    return [
        'success'   => false,
        'error'     => $data['message'] ?? "HTTP {$status}",
        'remote_id' => null,
    ];
}

/**
 * Update an existing WordPress post.
 */
function wp_update_post(int $wp_post_id, array $post, string $wp_url, string $credentials): array
{
    $api = rtrim($wp_url, '/') . '/wp-json/wp/v2/posts/' . $wp_post_id;
    $auth = base64_encode($credentials);

    $payload = [
        'title'   => $post['title'],
        'content' => $post['body'],
        'excerpt' => $post['excerpt'] ?? '',
    ];

    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . $auth,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['success' => false, 'error' => $error];

    $data = json_decode($body, true);
    return [
        'success' => $status === 200,
        'error'   => $data['message'] ?? null,
    ];
}

/**
 * Set tags on a WordPress post (creates tags if they don't exist).
 */
function wp_set_tags(string $wp_url, string $auth, int $post_id, array $tags): void
{
    $api = rtrim($wp_url, '/') . '/wp-json/wp/v2';
    $tag_ids = [];

    foreach ($tags as $tag_name) {
        // Search for existing tag
        $ch = curl_init($api . '/tags?search=' . urlencode($tag_name));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
        ]);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!empty($result[0]['id'])) {
            $tag_ids[] = $result[0]['id'];
        } else {
            // Create tag
            $ch = curl_init($api . '/tags');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['name' => $tag_name]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . $auth,
                ],
            ]);
            $result = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (!empty($result['id'])) {
                $tag_ids[] = $result['id'];
            }
        }
    }

    if (!empty($tag_ids)) {
        $ch = curl_init($api . '/posts/' . $post_id);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode(['tags' => $tag_ids]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . $auth,
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

/**
 * Test WordPress connection.
 */
function wp_test_connection(string $wp_url, string $credentials): array
{
    $api = rtrim($wp_url, '/') . '/wp-json/wp/v2/users/me';
    $auth = base64_encode($credentials);

    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth],
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true);

    if ($status === 200 && !empty($data['name'])) {
        return ['success' => true, 'user' => $data['name'], 'roles' => $data['roles'] ?? []];
    }

    return ['success' => false, 'error' => $data['message'] ?? "HTTP {$status}"];
}
