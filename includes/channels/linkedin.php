<?php
/**
 * LinkedIn channel adapter.
 *
 * - is_configured: site has an active LinkedIn integration row with an access_token + author URN
 * - transform_post: Claude repurposes the post body into a 150-220 word LinkedIn post
 * - publish: posts via linkedin_post() using the stored OAuth token
 *
 * Setup happens via /api/oauth/linkedin-callback.php → linkedin_save_tokens() on the
 * integrations row, with account_id = "urn:li:person:..." used as the author.
 */

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/../integrations/linkedin.php';
require_once __DIR__ . '/../haiku.php';

class LinkedInChannel extends ChannelAdapter
{
    public function id(): string           { return 'linkedin'; }
    public function display_name(): string { return 'LinkedIn'; }
    public function icon(): string         { return 'in'; }
    public function color(): string        { return '#0A66C2'; }

    public function description(): string
    {
        return "Posts an AI-tailored LinkedIn version (150-220 words, hook + discussion question) under the connected profile.";
    }

    public function is_configured(array $site): bool
    {
        // We need a global LinkedIn app (client_id) AND a per-site OAuth connection.
        if (empty(config('linkedin_client_id')) || empty(config('linkedin_client_secret'))) {
            return false;
        }
        // Per-site integration row check happens at publish-time (needs DB).
        // We optimistically return true here so the channel appears in the UI; publish() will fail clearly if no token.
        return !empty($site['id']);
    }

    public function setup_hint(array $site): ?string
    {
        if (empty(config('linkedin_client_id'))) {
            return "Add LinkedIn client_id/secret in Settings → API Keys, then connect this site's LinkedIn account.";
        }
        return "Click 'Connect LinkedIn' on this site to authorise. Posts will use the connected profile.";
    }

    public function transform_post(array $post, array $site): array
    {
        $result = haiku_repurpose_for_channel($post, 'linkedin', $site);
        if (!empty($result['success'])) {
            return ['content' => $result['content'], 'meta' => null];
        }
        // Fallback: title + excerpt + link
        $domain = $site['domain'] ?? '';
        $url = ($domain && !empty($post['slug'])) ? 'https://' . ltrim($domain, 'https://') . '/blog/' . $post['slug'] : '';
        $fallback = trim($post['title'] ?? '') . "\n\n" . trim($post['excerpt'] ?? '') . ($url ? "\n\nRead more: {$url}" : '');
        return ['content' => $fallback, 'meta' => null];
    }

    public function publish(array $post_channel, array $post, array $site): array
    {
        global $db;
        // get a fresh DB handle if not in scope
        if (!isset($db) || !($db instanceof PDO)) {
            $db = require __DIR__ . '/../db.php';
        }

        // Fetch the active LinkedIn integration for this site
        $stmt = $db->prepare('SELECT access_token, account_id, account_name, token_expires_at FROM integrations WHERE site_id = ? AND platform = "linkedin" AND is_active = 1 LIMIT 1');
        $stmt->execute([(int)$site['id']]);
        $integration = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$integration || empty($integration['access_token'])) {
            return [
                'success' => false,
                'error'   => 'LinkedIn not connected for this site. Open the site page and click "Connect LinkedIn".',
            ];
        }

        if (empty($integration['account_id'])) {
            return [
                'success' => false,
                'error'   => 'LinkedIn connection is missing the author URN. Reconnect the account.',
            ];
        }

        // Check token expiry (LinkedIn tokens last 60 days, no refresh built in here)
        if (!empty($integration['token_expires_at']) && strtotime($integration['token_expires_at']) < time()) {
            return [
                'success' => false,
                'error'   => 'LinkedIn token has expired. Reconnect the account.',
            ];
        }

        $text = $post_channel['variant_content'] ?? '';
        if (empty($text)) {
            // Should have been set by transform_post; safety net
            $variant = $this->transform_post($post, $site);
            $text = $variant['content'] ?? '';
        }

        $blog_url = '';
        if (!empty($site['domain']) && !empty($post['slug'])) {
            $blog_url = 'https://' . ltrim($site['domain'], 'https://') . '/blog/' . $post['slug'];
        }

        $result = linkedin_post(
            $integration['access_token'],
            $integration['account_id'],
            $text,
            $blog_url
        );

        if (!empty($result['success'])) {
            // LinkedIn returns urn:li:share:XXX or similar
            $share_id = $result['id'] ?? '';
            // Public URL pattern: https://www.linkedin.com/feed/update/{urn}
            $public_url = $share_id ? 'https://www.linkedin.com/feed/update/' . urlencode($share_id) : '';
            return [
                'success'      => true,
                'external_id'  => $share_id,
                'external_url' => $public_url ?: null,
            ];
        }

        return [
            'success' => false,
            'error'   => $result['error'] ?? 'LinkedIn API returned an error',
        ];
    }
}
