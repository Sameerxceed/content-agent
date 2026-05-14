<?php
/**
 * Channel adapter base — every publishing destination (CMS, Reddit, LinkedIn,
 * Twitter, newsletter, etc.) extends this class.
 *
 * The pipeline:
 *   1. User publishes a post → write.php creates post_channels rows (status=queued)
 *   2. cron-publish.php picks up queued rows and calls $adapter->publish($row)
 *   3. Adapter transforms the post for its channel + makes the API call
 *   4. Row marked published/failed with external URL/error
 *
 * Each adapter declares:
 *   - id, display_name, icon, color
 *   - is_configured(site) — do we have credentials for this site/channel?
 *   - transform_post(post, site) — build channel-specific content (variant_content)
 *   - publish(post_channel_row, post, site) — make the API call, return result array
 *   - fetch_metrics(post_channel_row) — optional, pull engagement back
 */

abstract class ChannelAdapter
{
    /** @return string short stable identifier, e.g. "cms", "reddit", "linkedin" */
    abstract public function id(): string;

    /** @return string human label for UI */
    abstract public function display_name(): string;

    /** Single-character or emoji icon */
    public function icon(): string { return '🔌'; }

    /** Brand color (hex) for UI badges */
    public function color(): string { return '#64748b'; }

    /** One-sentence description for the channels widget */
    public function description(): string { return ''; }

    /**
     * Does this site have credentials to use this channel?
     * Cheap check — don't hit any external API here.
     */
    abstract public function is_configured(array $site): bool;

    /**
     * Build the channel-specific content for this post.
     * Default = return the post body unchanged. Override for Twitter threads,
     * LinkedIn summaries, etc.
     *
     * @return array { 'content' => string, 'meta' => array }
     *   content goes into post_channels.variant_content
     *   meta   goes into post_channels.variant_meta
     */
    public function transform_post(array $post, array $site): array
    {
        return [
            'content' => $post['body'] ?? '',
            'meta'    => null,
        ];
    }

    /**
     * Publish the post to this channel.
     * Receives the post_channels row (with variant_content already populated),
     * the parent post, and the site.
     *
     * @return array {
     *   success: bool,
     *   external_id?: string,
     *   external_url?: string,
     *   error?: string,
     * }
     */
    abstract public function publish(array $post_channel, array $post, array $site): array;

    /**
     * Optional — fetch engagement metrics back from the channel.
     * Most adapters won't implement this until needed.
     *
     * @return array|null channel-specific metrics blob to store in post_channels.metrics
     */
    public function fetch_metrics(array $post_channel, array $post, array $site): ?array
    {
        return null;
    }

    /**
     * Optional — adapter-specific user-facing guidance for what to set up.
     * Used by the integrations hub. Returns null if not applicable.
     */
    public function setup_hint(array $site): ?string
    {
        return null;
    }
}
