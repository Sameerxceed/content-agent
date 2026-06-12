<?php
/**
 * Pinterest channel adapter.
 *
 * Publishes a blog post as a pin on the connected board:
 *   - title       = Claude-generated Pinterest-optimised headline
 *   - description = Claude-generated 150-300 char pin caption with hashtags
 *   - image_url   = post's hero image (existing — no extra cost for v1)
 *   - link        = full blog URL (this is the SEO win — backlink + traffic)
 *
 * Configuration prerequisites:
 *   - pinterest_client_id + pinterest_client_secret in config.php
 *   - Site has an active integrations row (platform='pinterest', is_active=1)
 *   - The row's extra_data JSON contains {board_id, board_name}
 *
 * If the connection exists but the board hasn't been picked yet, publish()
 * returns a clear "pick a board" error so the queue retry logic doesn't
 * spin on it forever.
 */

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/../integrations/pinterest.php';
require_once __DIR__ . '/../haiku.php';

class PinterestChannel extends ChannelAdapter
{
    public function id(): string           { return 'pinterest'; }
    public function display_name(): string { return 'Pinterest'; }
    public function icon(): string         { return 'P'; }
    public function color(): string        { return '#E60023'; }

    public function description(): string
    {
        return 'Posts an AI-tailored pin (title + caption + hashtags) on the chosen board, with a click-through to the blog post.';
    }

    public function is_configured(array $site): bool
    {
        // Need a global Pinterest app registered AND a per-site OAuth
        // connection. Per-site board pick happens after connect; we still
        // show the channel as "configured" so the UI can guide them through
        // the board-pick step rather than hiding the channel entirely.
        if (empty(config('pinterest_client_id')) || empty(config('pinterest_client_secret'))) {
            return false;
        }
        return !empty($site['id']);
    }

    public function setup_hint(array $site): ?string
    {
        if (empty(config('pinterest_client_id'))) {
            return 'Add Pinterest client_id/secret in Settings → API Keys, then connect this site\'s Pinterest account.';
        }
        return 'Click "Connect Pinterest" on this site, then pick which board pins should go to.';
    }

    public function transform_post(array $post, array $site): array
    {
        $result = haiku_repurpose_for_channel($post, 'pinterest', $site);
        if (!empty($result['success'])) {
            // Pinterest's transform returns "Title\n\nDescription" — split.
            $content = (string)$result['content'];
            $parts   = preg_split('/\n\s*\n/', $content, 2);
            $title   = trim($parts[0] ?? '');
            $desc    = trim($parts[1] ?? '');
            return [
                'content' => $content,
                'meta'    => ['pin_title' => $title, 'pin_description' => $desc],
            ];
        }
        // Fallback: post title as pin title, excerpt as description.
        $title = (string)($post['title'] ?? '');
        $desc  = (string)($post['excerpt'] ?? '');
        return [
            'content' => $title . "\n\n" . $desc,
            'meta'    => ['pin_title' => $title, 'pin_description' => $desc],
        ];
    }

    public function publish(array $post_channel, array $post, array $site): array
    {
        $db = require __DIR__ . '/../db.php';

        $token = pinterest_get_active_token($db, (int)$site['id']);
        if (!$token) {
            return [
                'success' => false,
                'error'   => 'Pinterest not connected for this site (or token refresh failed). Open Setup → Channels and reconnect.',
            ];
        }

        // Resolve board_id from the integrations row.
        $row = $db->prepare('SELECT extra_data FROM integrations WHERE site_id = ? AND platform = "pinterest" AND is_active = 1 LIMIT 1');
        $row->execute([(int)$site['id']]);
        $extra = json_decode((string)($row->fetchColumn() ?: '{}'), true) ?: [];
        $board_id = (string)($extra['board_id'] ?? '');
        if (!$board_id) {
            return [
                'success' => false,
                'error'   => 'Pinterest is connected but no board is selected. Open Setup → Channels → Pinterest and pick a board.',
            ];
        }

        // Decode meta back from variant_meta JSON (set by transform_post).
        $meta = [];
        if (!empty($post_channel['variant_meta'])) {
            $meta = json_decode((string)$post_channel['variant_meta'], true) ?: [];
        }
        $pin_title = (string)($meta['pin_title'] ?? $post['title'] ?? '');
        $pin_desc  = (string)($meta['pin_description'] ?? $post['excerpt'] ?? '');

        // Image — Pinterest wants an absolute URL. If hero_image_url is a
        // relative path we resolve against the ContentAgent app_url since
        // that's where it's hosted (image_gen.php saves under public/).
        // Column name confirmed against database/migrations/: hero_image_url.
        $image_url = (string)($post['hero_image_url'] ?? $post['hero_image'] ?? $post['featured_image'] ?? '');
        if ($image_url === '') {
            return ['success' => false, 'error' => 'Post has no hero image. Pinterest requires an image to create a pin — generate one before publishing.'];
        }
        $alt_text = (string)($post['hero_image_alt'] ?? $pin_title);
        if (!preg_match('#^https?://#i', $image_url)) {
            $base      = rtrim((string)config('app_url', ''), '/');
            $image_url = $base . '/' . ltrim($image_url, '/');
        }

        // Click-through link — the customer's blog post.
        $blog_url = '';
        if (!empty($site['domain']) && !empty($post['slug'])) {
            $blog_url = 'https://' . ltrim((string)$site['domain'], 'https://') . '/blog/' . $post['slug'];
        }

        $result = pinterest_create_pin($token, $board_id, [
            'title'       => $pin_title,
            'description' => $pin_desc,
            'alt_text'    => $alt_text,
            'image_url'   => $image_url,
            'link'        => $blog_url,
        ]);

        if (!empty($result['success'])) {
            return [
                'success'      => true,
                'external_id'  => $result['id'],
                'external_url' => $result['url'],
            ];
        }
        return [
            'success' => false,
            'error'   => $result['error'] ?? 'Pinterest API returned an error',
        ];
    }
}
