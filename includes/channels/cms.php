<?php
/**
 * CMS channel adapter — pushes the post to the site's connected CMS.
 *
 * For now this wraps the existing cms_push_post() (Xceed CMS API). When we add
 * OpenCart/WordPress/Shopify-specific support, this adapter dispatches to the
 * right backend based on $site['platform'].
 */

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/../cms-connector.php';

class CmsChannel extends ChannelAdapter
{
    public function id(): string           { return 'cms'; }
    public function display_name(): string { return 'CMS / Blog'; }
    public function icon(): string         { return '📝'; }
    public function color(): string        { return '#1e3a5f'; }

    public function description(): string
    {
        return "Publishes the full post to your website's blog via its CMS API.";
    }

    public function is_configured(array $site): bool
    {
        return !empty($site['cms_url']) && !empty($site['cms_api_key']);
    }

    public function setup_hint(array $site): ?string
    {
        if ($this->is_configured($site)) return null;
        return "Add CMS URL + API key on the site's Edit page. Once set, posts will publish here automatically.";
    }

    public function transform_post(array $post, array $site): array
    {
        // CMS gets the full post body unchanged — no transformation needed
        return ['content' => $post['body'] ?? '', 'meta' => null];
    }

    public function publish(array $post_channel, array $post, array $site): array
    {
        if (!$this->is_configured($site)) {
            return ['success' => false, 'error' => 'CMS not configured for this site (missing cms_url or cms_api_key)'];
        }

        $result = cms_push_post($post, $site['cms_url'], $site['cms_api_key']);

        if (!empty($result['success'])) {
            $public_path = ($post['type'] ?? 'blog') === 'news' ? '/news/' : '/blog/';
            $external_url = rtrim($site['cms_url'], '/') . $public_path . ($result['slug'] ?? $post['slug']);
            if (!empty($site['domain'])) {
                $external_url = 'https://' . ltrim($site['domain'], 'https://') . $public_path . ($result['slug'] ?? $post['slug']);
            }

            return [
                'success'      => true,
                'external_id'  => isset($result['remote_id']) ? (string)$result['remote_id'] : null,
                'external_url' => $external_url,
            ];
        }

        return [
            'success' => false,
            'error'   => $result['error'] ?? 'Unknown CMS error',
        ];
    }
}
