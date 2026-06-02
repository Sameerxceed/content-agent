<?php
/**
 * llms.txt channel adapter — regenerates and (when possible) deploys
 * /llms.txt + /llms-full.txt for the site after a new post lands.
 *
 * llms.txt is the convention AI engines (ChatGPT, Perplexity, Gemini,
 * Claude search) read to discover what content a domain has. Refreshing
 * it on every publish keeps citations current. Deploy path:
 *   1. If the site has a Strapi-compatible CMS endpoint, push via API
 *   2. Otherwise fall back to logging — operator deploys manually
 */

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/../ai-seo.php';

class LlmsChannel extends ChannelAdapter
{
    public function id(): string           { return 'llms'; }
    public function display_name(): string { return 'llms.txt'; }
    public function icon(): string         { return '🤖'; }
    public function color(): string        { return '#0891b2'; }

    public function description(): string
    {
        return 'Regenerates /llms.txt so AI engines (ChatGPT, Perplexity, Gemini) discover and cite the new post.';
    }

    public function is_configured(array $site): bool
    {
        // llms.txt regeneration runs against any site with a domain.
        // Deployment may still fall back to manual if CMS API isn't wired,
        // but the regeneration step itself is always available.
        return !empty($site['domain']);
    }

    public function transform_post(array $post, array $site): array
    {
        // The 'content' value here is a sentinel — we don't render anything
        // per-post for llms.txt; the regeneration is sitewide.
        return ['content' => 'regenerate', 'meta' => null];
    }

    public function publish(array $post_channel, array $post, array $site): array
    {
        global $db; // cron-publish has $db in scope; otherwise fall back to a fresh connection
        if (!isset($db) || !($db instanceof PDO)) {
            $db = require __DIR__ . '/../../includes/db.php';
        }
        try {
            $result = regenerate_llms_txt($site, $db);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'llms.txt regen threw: ' . $e->getMessage()];
        }
        // regenerate_llms_txt returns ['deployed' => [...], 'errors' => [...]]
        // — deployment failures are non-fatal (regen still happened locally).
        return [
            'success'      => true,
            'external_id'  => null,
            'external_url' => 'https://' . ltrim((string)($site['domain'] ?? ''), 'https://') . '/llms.txt',
        ];
    }
}
