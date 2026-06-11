<?php
/**
 * CMS Connector — Push posts to external CMS.
 * Currently supports the Xceed PHP CMS (cms.xceedtech.in).
 */

require_once __DIR__ . '/helpers.php';

/**
 * Hero images live at /uploads/posts/{site_id}/{post_id}/... on ContentAgent's
 * own filesystem. Customer CMSes render the URL against THEIR own domain, so a
 * raw relative path produces a 404 (the file isn't on their server).
 *
 * Prefix with ContentAgent's absolute URL so the customer's frontend fetches
 * the image from where it lives. Leaves already-absolute URLs untouched (e.g.
 * if someone pastes an external image URL or we later move to S3).
 */
function cms_absolutise_image_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url; // already absolute
    if (!str_starts_with($url, '/')) return $url;       // not a path we recognise
    $app_url = rtrim((string)config('app_url'), '/');
    return $app_url . $url;
}

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

    // Pass type through. Xceed CMS recognises blog | news | page.
    // 'page' = static legal/info page rendered at root /:slug (privacy, terms, cookies, etc.)
    $allowed_types = ['blog', 'news', 'page'];
    $type = in_array($post['type'] ?? 'blog', $allowed_types, true) ? ($post['type'] ?? 'blog') : 'blog';

    $payload = [
        'type'            => $type, // blog | news | page — Xceed CMS routes accordingly
        'title'           => $post['title'],
        'slug'            => $post['slug'],
        'excerpt'         => $post['excerpt'] ?? '',
        'body'            => $post['body'],
        'cover_icon'      => get_cover_icon($post['tags'] ?? '[]'),
        // hero_image_url is stored as a relative path like /uploads/posts/N/M/...
        // Xceed's frontend renders that path against its OWN domain → 404. Prefix
        // with ContentAgent's absolute URL so the customer's frontend fetches the
        // image from where it actually lives.
        'hero_image_url'  => cms_absolutise_image_url($post['hero_image_url'] ?? ''),
        'hero_image_alt'  => $post['hero_image_alt'] ?? ($post['title'] ?? ''),
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

    // 409 = duplicate slug. The post is already on the CMS — try to update it
    // with the latest body, but if the update fails, that's NOT a hard error:
    // the content is still live on the site. We verify and report accordingly.
    if ($response['status'] === 409) {
        $upd = cms_update_post($post, $cms_url, $cms_api_key);
        if ($upd['success']) {
            $upd['http_status'] = 200;
            $upd['note']        = 'Already on CMS — updated in place';
            return $upd;
        }

        // Update failed. Verify the post actually exists. If it does, treat as
        // success (already published) and surface the update failure as a note,
        // not an error — the CMS has the content, just couldn't refresh it.
        $verify = cms_verify_post($post['slug'], $cms_url, $cms_api_key);
        if ($verify['found']) {
            return [
                'success'    => true,
                'error'      => null,
                'remote_id'  => null,
                'slug'       => $post['slug'],
                'http_status'=> 409,
                'note'       => 'Already on CMS (update skipped: ' . ($upd['error'] ?? 'unknown') . ')',
                'raw'        => $upd['raw'] ?? '',
            ];
        }

        // 409 said it existed, but verify says it doesn't — odd state. Bubble up.
        return [
            'success'    => false,
            'error'      => '409 on create, but post not found on verify: ' . ($upd['error'] ?? 'update failed'),
            'remote_id'  => null,
            'http_status'=> 409,
            'raw'        => $upd['raw'] ?? substr($response['body'] ?? '', 0, 500),
        ];
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

    $allowed_types = ['blog', 'news', 'page'];
    $type = in_array($post['type'] ?? 'blog', $allowed_types, true) ? ($post['type'] ?? 'blog') : 'blog';

    $payload = [
        'type'            => $type, // MUST be in update too — Xceed CMS routes by (type, slug)
        'slug'            => $post['slug'],
        'title'           => $post['title'],
        'excerpt'         => $post['excerpt'] ?? '',
        'body'            => $post['body'],
        'hero_image_url'  => cms_absolutise_image_url($post['hero_image_url'] ?? ''),
        'hero_image_alt'  => $post['hero_image_alt'] ?? ($post['title'] ?? ''),
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
