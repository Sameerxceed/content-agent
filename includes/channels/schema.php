<?php
/**
 * Schema channel adapter — owns the schema.org JSON-LD bundle for a post.
 *
 * Schema is metadata, not a remote destination. We don't push it anywhere on
 * its own; instead, the CMS adapter looks up this row's variant_content at
 * publish time and embeds the JSON-LD as <script type="application/ld+json">
 * tags inside the body it pushes to the website.
 *
 * publish() here is a no-op marker: when this row hits 'published' it means
 * "the JSON-LD bundle exists and is ready for CMS to consume." Order of
 * row processing inside cron-publish doesn't matter — CMS reads schema
 * directly from post_channels by post_id, not from the run sequence.
 */

require_once __DIR__ . '/base.php';

class SchemaChannel extends ChannelAdapter
{
    public function id(): string           { return 'schema'; }
    public function display_name(): string { return 'Schema.org JSON-LD'; }
    public function icon(): string         { return '🏷️'; }
    public function color(): string        { return '#7c3aed'; }

    public function description(): string
    {
        return 'Structured metadata for Google rich snippets and AI engine citations. Embedded into the post body when the CMS publishes.';
    }

    public function is_configured(array $site): bool
    {
        // Schema needs nothing per-site — it just needs the bundle to exist.
        return true;
    }

    public function transform_post(array $post, array $site): array
    {
        // Schema's variant_content is populated by the autopilot
        // (content_artifacts_compose_schema). Nothing to do here.
        return ['content' => '[]', 'meta' => null];
    }

    public function publish(array $post_channel, array $post, array $site): array
    {
        // No-op publish. The CMS adapter embeds this row's JSON-LD into the
        // post body when it pushes. Marking 'published' here just records
        // that the bundle was successfully generated.
        $content = (string)($post_channel['variant_content'] ?? '[]');
        $decoded = json_decode($content, true);
        $count   = is_array($decoded) ? count($decoded) : 0;
        if ($count === 0) {
            return ['success' => false, 'error' => 'Schema bundle is empty — autopilot did not generate any JSON-LD blocks.'];
        }
        return [
            'success'      => true,
            'external_id'  => null,
            'external_url' => null,
        ];
    }
}
