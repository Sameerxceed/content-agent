<?php
/**
 * CMS Connector — Push posts to external CMS.
 * Currently supports the Xceed PHP CMS (cms.xceedtech.in).
 */

require_once __DIR__ . '/helpers.php';

/**
 * Push a post to the Xceed CMS.
 *
 * @param array $post  Post data from ContentAgent database
 * @param array $site  Site data (must have cms_url and cms_api_key in config or DB)
 * @return array ['success' => bool, 'error' => string|null, 'remote_id' => int|null]
 */
function cms_push_post(array $post, string $cms_url, string $cms_api_key): array
{
    $tags = json_decode($post['tags'] ?? '[]', true) ?: [];
    $word_count = str_word_count(strip_tags($post['body']));
    $read_time = max(1, round($word_count / 200)) . ' min';

    $payload = [
        'title'           => $post['title'],
        'slug'            => $post['slug'],
        'excerpt'         => $post['excerpt'] ?? '',
        'body'            => $post['body'],
        'cover_icon'      => get_cover_icon($post['tags'] ?? '[]'),
        'tags'            => $tags,
        'read_time'       => $read_time,
        'author'          => 'Xceed Engineering',
        'is_published'    => 1,
        'published_date'  => date('Y-m-d'),
        'seo_title'       => $post['seo_title'] ?? $post['title'],
        'seo_description' => $post['seo_description'] ?? '',
        'seo_keywords'    => $post['seo_keywords'] ?? '',
    ];

    $response = http_post(
        rtrim($cms_url, '/') . '/api/blog.php',
        $payload,
        ['X-API-Key: ' . $cms_api_key],
        30
    );

    if ($response['error']) {
        return ['success' => false, 'error' => $response['error'], 'remote_id' => null];
    }

    $data = json_decode($response['body'], true);

    if ($response['status'] === 201 && !empty($data['id'])) {
        return [
            'success'   => true,
            'error'     => null,
            'remote_id' => $data['id'],
            'slug'      => $data['slug'] ?? $post['slug'],
        ];
    }

    // 409 = duplicate slug, try updating instead
    if ($response['status'] === 409) {
        return cms_update_post($post, $cms_url, $cms_api_key);
    }

    return [
        'success'   => false,
        'error'     => $data['error'] ?? "HTTP {$response['status']}",
        'remote_id' => null,
    ];
}

/**
 * Update an existing post on the CMS.
 */
function cms_update_post(array $post, string $cms_url, string $cms_api_key): array
{
    $tags = json_decode($post['tags'] ?? '[]', true) ?: [];

    $payload = [
        'slug'            => $post['slug'],
        'title'           => $post['title'],
        'excerpt'         => $post['excerpt'] ?? '',
        'body'            => $post['body'],
        'tags'            => $tags,
        'seo_title'       => $post['seo_title'] ?? $post['title'],
        'seo_description' => $post['seo_description'] ?? '',
        'seo_keywords'    => $post['seo_keywords'] ?? '',
        'is_published'    => 1,
    ];

    $ch = curl_init(rtrim($cms_url, '/') . '/api/blog.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $cms_api_key,
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
    if ($status === 200 && !empty($data['updated'])) {
        return ['success' => true, 'error' => null, 'remote_id' => null, 'slug' => $post['slug']];
    }

    return ['success' => false, 'error' => $data['error'] ?? "HTTP {$status}", 'remote_id' => null];
}

/**
 * Pick a cover icon based on tags/topic.
 */
function get_cover_icon(string $tags_json): string
{
    $tags = strtolower(implode(' ', json_decode($tags_json, true) ?: []));

    if (strpos($tags, 'ai') !== false || strpos($tags, 'machine learning') !== false) return '🤖';
    if (strpos($tags, 'cost') !== false || strpos($tags, 'pricing') !== false) return '💰';
    if (strpos($tags, 'software') !== false || strpos($tags, 'development') !== false) return '💻';
    if (strpos($tags, 'seo') !== false) return '🔍';
    if (strpos($tags, 'design') !== false) return '🎨';
    if (strpos($tags, 'security') !== false) return '🔒';
    if (strpos($tags, 'cloud') !== false) return '☁️';
    if (strpos($tags, 'mobile') !== false) return '📱';
    if (strpos($tags, 'data') !== false) return '📊';

    return '📝';
}
