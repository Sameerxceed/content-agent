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
require_once __DIR__ . '/../connectors/shopify.php';

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

        // Inject schema.org JSON-LD if a sibling 'schema' channel row has it.
        // This is how schema reaches the live site — wrapped in <script> tags
        // appended to the body before push. The schema adapter itself is a
        // no-op marker (see channels/schema.php); CMS is where it actually
        // travels to the website.
        global $db; // cron-publish puts $db in global scope
        if (isset($db) && $db instanceof PDO) {
            $stmt = $db->prepare("SELECT variant_content FROM post_channels
                WHERE post_id = ? AND channel = 'schema' LIMIT 1");
            $stmt->execute([(int)$post['id']]);
            $schema_json = (string)$stmt->fetchColumn();
            $bundle = $schema_json !== '' ? json_decode($schema_json, true) : null;
            if (is_array($bundle) && !empty($bundle)) {
                $script_tags = '';
                foreach ($bundle as $blob) {
                    if (!is_array($blob)) continue;
                    $script_tags .= "\n<script type=\"application/ld+json\">\n"
                                 .  json_encode($blob, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                                 .  "\n</script>";
                }
                // Avoid double-injection if body already has JSON-LD
                if ($script_tags !== '' && !str_contains((string)($post['body'] ?? ''), 'application/ld+json')) {
                    $post['body'] = (string)($post['body'] ?? '') . $script_tags;
                }
            }
        }

        // Platform routing: Shopify gets the Shopify connector (REST or GraphQL
        // auto-routed by token prefix), everyone else uses the generic Xceed-
        // style REST CMS API. We also detect Shopify via cms_url since some
        // sites pre-date the `platform` column being filled in.
        $platform = strtolower((string)($site['platform'] ?? ''));
        $is_shopify = $platform === 'shopify' || str_contains((string)($site['cms_url'] ?? ''), 'myshopify.com');

        if ($is_shopify) {
            $result = shopify_push_post($post, (string)$site['cms_url'], (string)$site['cms_api_key']);
            if (!empty($result['success'])) {
                return [
                    'success'      => true,
                    'external_id'  => isset($result['remote_id']) ? (string)$result['remote_id'] : null,
                    // Shopify's connector returns a fully-qualified blog URL; use it
                    // directly so customers land on their own store, not on the
                    // synthetic /blog/<slug> we'd construct for hosted-CMS sites.
                    'external_url' => $result['url'] ?? null,
                ];
            }
            return ['success' => false, 'error' => $result['error'] ?? 'Unknown Shopify error'];
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
