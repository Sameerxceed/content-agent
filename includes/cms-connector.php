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

    // Preserve the original publication date — re-pushing yesterday's post
    // shouldn't make it look like a fresh post on the website.
    $pub_date = !empty($post['published_at'])
        ? date('Y-m-d', strtotime($post['published_at']))
        : date('Y-m-d');

    $type = ($post['type'] ?? 'blog') === 'news' ? 'news' : 'blog';

    $payload = [
        'type'            => $type, // blog | news — Xceed CMS routes accordingly
        'title'           => $post['title'],
        'slug'            => $post['slug'],
        'excerpt'         => $post['excerpt'] ?? '',
        'body'            => $post['body'],
        'cover_icon'      => get_cover_icon($post['tags'] ?? '[]'),
        'tags'            => $tags,
        'read_time'       => $read_time,
        'author'          => 'Xceed Engineering',
        'is_published'    => 1,
        'published_date'  => $pub_date,
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
            'success'    => true,
            'error'      => null,
            'remote_id'  => $data['id'],
            'slug'       => $data['slug'] ?? $post['slug'],
            'http_status'=> 201,
            'raw'        => substr($response['body'], 0, 500),
        ];
    }

    // 409 = duplicate slug, try updating instead
    if ($response['status'] === 409) {
        $upd = cms_update_post($post, $cms_url, $cms_api_key);
        $upd['http_status'] = 409;
        $upd['note']        = '409 on create → tried update';
        return $upd;
    }

    return [
        'success'    => false,
        'error'      => $data['error'] ?? "HTTP {$response['status']}",
        'remote_id'  => null,
        'http_status'=> $response['status'],
        'raw'        => substr($response['body'] ?? '', 0, 500),
    ];
}

/**
 * Update an existing post on the CMS.
 */
function cms_update_post(array $post, string $cms_url, string $cms_api_key): array
{
    $tags = json_decode($post['tags'] ?? '[]', true) ?: [];

    $pub_date = !empty($post['published_at'])
        ? date('Y-m-d', strtotime($post['published_at']))
        : null;

    $type = ($post['type'] ?? 'blog') === 'news' ? 'news' : 'blog';

    $payload = [
        'type'            => $type, // MUST be in update too — Xceed CMS routes by (type, slug)
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
    if ($pub_date) $payload['published_date'] = $pub_date;

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
        return ['success' => true, 'error' => null, 'remote_id' => null, 'slug' => $post['slug'], 'http_status' => 200];
    }

    return [
        'success'    => false,
        'error'      => $data['error'] ?? "HTTP {$status}",
        'remote_id'  => null,
        'http_status'=> $status,
        'raw'        => substr($body ?? '', 0, 500),
    ];
}

/**
 * Verify a post exists on the CMS by fetching it by slug.
 * Returns ['found' => bool, 'http_status' => int, 'raw' => string].
 */
function cms_verify_post(string $slug, string $cms_url, string $cms_api_key): array
{
    $url = rtrim($cms_url, '/') . '/api/blog.php?slug=' . urlencode($slug);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . $cms_api_key],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'found'       => $status === 200,
        'http_status' => $status,
        'raw'         => substr($body ?? '', 0, 500),
    ];
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
