<?php
/**
 * Image generation + sourcing for blog hero images.
 *
 * Providers tried in this order during autopilot drafting:
 *   1. Gemini Imagen 4    — Google AI Studio (free tier covers normal use)
 *   2. OpenAI DALL-E 3    — premium quality, paid
 *   3. Unsplash stock     — free fallback when no AI provider is configured
 *   4. Manual upload      — user replaces via Plan Item Studio
 *
 * Generated/sourced images get downloaded and stored locally under
 *   public/uploads/posts/{site_id}/{post_id}/hero-{ts}.{ext}
 * so we don't depend on remote URLs that expire (DALL-E URLs die in 60min;
 * Gemini returns base64 inline so we never have a remote URL anyway).
 *
 * Public API:
 *   image_gen_for_post(PDO $db, int $post_id, string $prompt, string $alt): array
 *     - Tries Gemini → DALL-E → Unsplash in order, skipping providers without keys.
 *     - On success: { url, provider, prompt, alt }
 *
 *   image_save_upload(PDO $db, int $post_id, array $upload_file): array
 *     - For manual uploads via $_FILES.
 *     - Validates MIME + size, stores under the same uploads path.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_cost.php';
require_once __DIR__ . '/quotas.php';

const IMAGE_GEN_MAX_BYTES = 6 * 1024 * 1024;  // 6MB upload cap
const IMAGE_GEN_TARGET_W  = 1792;
const IMAGE_GEN_TARGET_H  = 1024;

/**
 * Generate or source a hero image for a post.
 * Updates the posts row with hero_image_url + hero_image_prompt + provider + alt.
 *
 * @return array{provider:string, url:string, alt:string}|null
 */
function image_gen_for_post(PDO $db, int $post_id, string $prompt, string $alt = ''): ?array
{
    $prompt = trim($prompt);
    if ($prompt === '') return null;

    // Load post + site for storage path
    $stmt = $db->prepare('SELECT p.id, p.site_id, p.title FROM posts p WHERE p.id = ?');
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();
    if (!$post) return null;
    $site_id = (int)$post['site_id'];

    if ($alt === '') $alt = mb_substr($post['title'] ?? '', 0, 250);

    // Try Gemini Imagen first (cheaper + Google account often already exists)
    $gemini_key = config('gemini_api_key');
    if (!empty($gemini_key)) {
        $local = _image_gen_gemini($prompt, $gemini_key, $site_id, $post_id);
        if ($local) {
            _image_gen_save_to_post($db, $post_id, $local, $prompt, 'gemini', $alt);
            return ['provider' => 'gemini', 'url' => $local, 'alt' => $alt];
        }
    }

    // Fall back to OpenAI gpt-image-1 (dall-e-3 deprecated end of 2025)
    $oai_key = config('openai_api_key');
    if (!empty($oai_key)) {
        $local = _image_gen_dalle3($prompt, $oai_key, $site_id, $post_id);
        if ($local) {
            _image_gen_save_to_post($db, $post_id, $local, $prompt, 'dalle3', $alt);
            return ['provider' => 'dalle3', 'url' => $local, 'alt' => $alt];
        }
    }

    // Last resort: Unsplash stock photo
    $unsplash_key = config('unsplash_access_key');
    if (!empty($unsplash_key)) {
        $url = _image_gen_unsplash_search($prompt, $unsplash_key);
        if ($url) {
            $local = _image_gen_download_to_local($url, $site_id, $post_id, 'unsplash');
            if ($local) {
                _image_gen_save_to_post($db, $post_id, $local, $prompt, 'unsplash', $alt);
                return ['provider' => 'unsplash', 'url' => $local, 'alt' => $alt];
            }
        }
    }

    return null;
}

/**
 * Manual upload handler (called from /api/post-image-upload.php).
 * Validates the uploaded file, stores under the post's uploads dir,
 * updates the posts row. Returns the stored relative URL.
 *
 * @param array $upload_file $_FILES['hero_image'] entry
 * @return array{url:string, alt:string}|array{error:string}
 */
function image_save_upload(PDO $db, int $post_id, array $upload_file, string $alt = ''): array
{
    if (empty($upload_file) || ($upload_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload error'];
    }
    if (($upload_file['size'] ?? 0) > IMAGE_GEN_MAX_BYTES) {
        return ['error' => 'File too large (max ' . (int)(IMAGE_GEN_MAX_BYTES / 1024 / 1024) . 'MB)'];
    }
    $mime = mime_content_type($upload_file['tmp_name']) ?: '';
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) return ['error' => 'Only JPG/PNG/WebP/GIF allowed (got ' . $mime . ')'];

    $stmt = $db->prepare('SELECT site_id, title FROM posts WHERE id = ?');
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();
    if (!$post) return ['error' => 'Post not found'];
    $site_id = (int)$post['site_id'];
    if ($alt === '') $alt = mb_substr($post['title'] ?? '', 0, 250);

    $rel = _image_gen_local_path_for($site_id, $post_id, 'manual', $allowed[$mime]);
    $abs = _image_gen_abs($rel);
    if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0755, true);
    if (!@move_uploaded_file($upload_file['tmp_name'], $abs)) {
        return ['error' => 'Could not store uploaded file'];
    }

    _image_gen_save_to_post($db, $post_id, $rel, null, 'manual', $alt);
    return ['url' => $rel, 'alt' => $alt];
}

// ─────────────────────────────────────────────────────────────────
// Providers
// ─────────────────────────────────────────────────────────────────

/**
 * Gemini Imagen 4 Fast — returns base64 image bytes inline (no remote URL).
 * We decode + save directly, then return the local path.
 */
function _image_gen_gemini(string $prompt, string $api_key, int $site_id, int $post_id): ?string
{
    // Guardrails — both budget and per-month image cap
    try {
        $guard_db = _ai_db();
        if ($guard_db) {
            $q1 = quota_check_budget($guard_db, $site_id);
            if (!$q1['allowed']) { error_log('[image_gen gemini] quota: ' . $q1['message']); return null; }
            $q2 = quota_check_images_per_month($guard_db, $site_id);
            if (!$q2['allowed']) { error_log('[image_gen gemini] quota: ' . $q2['message']); return null; }
        }
    } catch (Throwable $e) { error_log('[image_gen gemini guard] ' . $e->getMessage()); }

    $payload = json_encode([
        'instances'  => [['prompt' => mb_substr($prompt, 0, 4000)]],
        'parameters' => [
            'sampleCount'      => 1,
            'aspectRatio'      => '16:9',
            'personGeneration' => 'allow_adult',
        ],
    ]);
    // Imagen 4 Fast — cheapest tier, fine for hero images
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-fast-generate-001:predict';
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $api_key,
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$body) {
        error_log('[image_gen gemini] HTTP ' . $http . ' body=' . substr((string)$body, 0, 250));
        return null;
    }
    // Image models bill per-image, not per-token. Pass count=1 as input_tokens.
    ai_log_call('gemini', 'imagen-4.0-fast', 'image_hero_gemini', $site_id,
        ['input_tokens' => 1, 'output_tokens' => 0],
        (int)round((microtime(true) - $t0) * 1000), $post_id);
    $data = json_decode($body, true);
    $b64  = $data['predictions'][0]['bytesBase64Encoded'] ?? null;
    $mime = $data['predictions'][0]['mimeType'] ?? 'image/png';
    if (!$b64) {
        error_log('[image_gen gemini] no image in response: ' . substr((string)$body, 0, 250));
        return null;
    }
    $bin = base64_decode($b64);
    if ($bin === false || strlen($bin) < 100) return null;

    $ext = str_contains($mime, 'jpeg') ? 'jpg'
         : (str_contains($mime, 'webp') ? 'webp' : 'png');
    $rel = _image_gen_local_path_for($site_id, $post_id, 'gemini', $ext);
    $abs = _image_gen_abs($rel);
    if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0755, true);
    if (@file_put_contents($abs, $bin) === false) return null;
    return $rel;
}

/**
 * OpenAI image generation. Uses gpt-image-1 — dall-e-3 was deprecated late
 * 2025 and returns "model does not exist". gpt-image-1 returns base64 in
 * b64_json (no URL option), so we decode + save like Gemini does. Function
 * name kept as _image_gen_dalle3 for backwards compatibility with the
 * "dalle3" provider label used everywhere else.
 */
function _image_gen_dalle3(string $prompt, string $api_key, int $site_id, int $post_id): ?string
{
    try {
        $guard_db = _ai_db();
        if ($guard_db) {
            $q1 = quota_check_budget($guard_db, $site_id);
            if (!$q1['allowed']) { error_log('[image_gen dalle3] quota: ' . $q1['message']); return null; }
            $q2 = quota_check_images_per_month($guard_db, $site_id);
            if (!$q2['allowed']) { error_log('[image_gen dalle3] quota: ' . $q2['message']); return null; }
        }
    } catch (Throwable $e) { error_log('[image_gen dalle3 guard] ' . $e->getMessage()); }

    $payload = json_encode([
        'model'  => 'gpt-image-1',
        'prompt' => mb_substr($prompt, 0, 4000),
        'size'   => '1536x1024',  // landscape — closest gpt-image-1 supports to 16:9
        'n'      => 1,
    ]);
    $t0 = microtime(true);
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_TIMEOUT        => 90,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$body) {
        error_log('[image_gen dalle3] HTTP ' . $http . ' body=' . substr((string)$body, 0, 250));
        return null;
    }
    ai_log_call('openai', 'gpt-image-1', 'image_hero_openai', $site_id,
        ['input_tokens' => 1, 'output_tokens' => 0],
        (int)round((microtime(true) - $t0) * 1000), $post_id);
    $data = json_decode($body, true);
    $b64  = $data['data'][0]['b64_json'] ?? null;
    if (!$b64) {
        error_log('[image_gen dalle3] no image in response: ' . substr((string)$body, 0, 250));
        return null;
    }
    $bin = base64_decode($b64);
    if ($bin === false || strlen($bin) < 100) return null;

    $rel = _image_gen_local_path_for($site_id, $post_id, 'dalle3', 'png');
    $abs = _image_gen_abs($rel);
    if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0755, true);
    if (@file_put_contents($abs, $bin) === false) return null;
    return $rel;
}

function _image_gen_unsplash_search(string $prompt, string $access_key): ?string
{
    // Unsplash search likes short queries, so trim down to ~6 keywords
    $query = trim(preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $prompt));
    $words = preg_split('/\s+/', $query);
    $query = implode(' ', array_slice($words, 0, 6));
    if ($query === '') return null;

    $url = 'https://api.unsplash.com/search/photos?per_page=1&orientation=landscape&query=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Client-ID ' . $access_key],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$body) {
        error_log('[image_gen unsplash] HTTP ' . $http . ' body=' . substr((string)$body, 0, 250));
        return null;
    }
    $data = json_decode($body, true);
    return $data['results'][0]['urls']['regular'] ?? $data['results'][0]['urls']['full'] ?? null;
}

// ─────────────────────────────────────────────────────────────────
// Local storage
// ─────────────────────────────────────────────────────────────────

/** Download a remote image to local storage. Returns the relative URL path or null. */
function _image_gen_download_to_local(string $remote_url, int $site_id, int $post_id, string $provider): ?string
{
    $ch = curl_init($remote_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $bin = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($http !== 200 || !$bin) return null;

    $ext = match (true) {
        str_contains((string)$ctype, 'png')  => 'png',
        str_contains((string)$ctype, 'webp') => 'webp',
        str_contains((string)$ctype, 'gif')  => 'gif',
        default                              => 'jpg',
    };
    $rel = _image_gen_local_path_for($site_id, $post_id, $provider, $ext);
    $abs = _image_gen_abs($rel);
    if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0755, true);
    if (@file_put_contents($abs, $bin) === false) return null;
    return $rel;
}

function _image_gen_local_path_for(int $site_id, int $post_id, string $provider, string $ext): string
{
    $ts = time();
    return "/uploads/posts/{$site_id}/{$post_id}/hero-{$provider}-{$ts}.{$ext}";
}

function _image_gen_abs(string $relative_url): string
{
    return realpath(__DIR__ . '/..') . '/public' . $relative_url;
}

function _image_gen_save_to_post(PDO $db, int $post_id, string $url, ?string $prompt, string $provider, string $alt): void
{
    $stmt = $db->prepare("UPDATE posts SET hero_image_url = ?, hero_image_prompt = ?, hero_image_provider = ?, hero_image_alt = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$url, $prompt, $provider, mb_substr($alt, 0, 500), $post_id]);
}
