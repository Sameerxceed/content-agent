<?php
/**
 * Channel registry — discovers adapters, dispatches calls, queues publications.
 *
 * Usage:
 *   $registry = channels_registry();
 *   $adapter = $registry->get('cms');
 *   $registry->queue_publish($db, $post_id, ['cms', 'linkedin']);
 */

require_once __DIR__ . '/base.php';

class ChannelRegistry
{
    /** @var array<string, ChannelAdapter> */
    private array $adapters = [];

    public function register(ChannelAdapter $adapter): void
    {
        $this->adapters[$adapter->id()] = $adapter;
    }

    /** @return ChannelAdapter|null */
    public function get(string $id): ?ChannelAdapter
    {
        return $this->adapters[$id] ?? null;
    }

    /** @return ChannelAdapter[] */
    public function all(): array
    {
        return $this->adapters;
    }

    /** Channels configured for this site (creds present). */
    public function configured_for(array $site): array
    {
        $out = [];
        foreach ($this->adapters as $a) {
            if ($a->is_configured($site)) $out[$a->id()] = $a;
        }
        return $out;
    }

    /**
     * Queue a post for publication on the given channels.
     * Creates post_channels rows (status=queued) and runs transform_post.
     * Does NOT actually publish — that happens in cron-publish or via API call.
     *
     * @param array $channel_ids ['cms', 'linkedin', ...]
     * @param ?DateTime $scheduled_for null = ASAP
     * @return array of created/updated post_channels row IDs
     */
    public function queue_publish(PDO $db, int $post_id, array $channel_ids, ?string $scheduled_for = null): array
    {
        // Load the post + site
        $stmt = $db->prepare('SELECT p.*, s.* FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ?');
        $stmt->execute([$post_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Post not found');
        }
        // Split — post fields vs site fields. They share some names (id, name, created_at).
        // Re-fetch separately for clarity.
        $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
        $stmt->execute([$post_id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([$post['site_id']]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);

        $created = [];

        $upsert = $db->prepare('INSERT INTO post_channels (post_id, channel, variant_content, variant_meta, status, scheduled_for, attempts, created_at)
            VALUES (?, ?, ?, ?, "queued", ?, 0, NOW())
            ON DUPLICATE KEY UPDATE
                variant_content = VALUES(variant_content),
                variant_meta    = VALUES(variant_meta),
                status          = IF(status IN ("published"), status, "queued"),
                scheduled_for   = VALUES(scheduled_for),
                error           = NULL,
                attempts        = IF(status IN ("published"), attempts, 0)');

        foreach ($channel_ids as $cid) {
            $adapter = $this->get($cid);
            if (!$adapter) continue;
            if (!$adapter->is_configured($site)) continue;

            $variant = $adapter->transform_post($post, $site);
            $upsert->execute([
                $post_id,
                $cid,
                $variant['content'] ?? null,
                isset($variant['meta']) ? json_encode($variant['meta']) : null,
                $scheduled_for,
            ]);

            // Find the row id
            $find = $db->prepare('SELECT id FROM post_channels WHERE post_id = ? AND channel = ?');
            $find->execute([$post_id, $cid]);
            $created[$cid] = (int)$find->fetchColumn();
        }

        return $created;
    }

    /**
     * Publish a single post_channels row right now (synchronous).
     * Used by both the cron worker and the manual "publish now" API.
     */
    public function publish_row(PDO $db, int $post_channel_id): array
    {
        $stmt = $db->prepare('SELECT pc.*, p.site_id FROM post_channels pc JOIN posts p ON pc.post_id = p.id WHERE pc.id = ?');
        $stmt->execute([$post_channel_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['success' => false, 'error' => 'Row not found'];

        $adapter = $this->get($row['channel']);
        if (!$adapter) return ['success' => false, 'error' => 'No adapter for channel "' . $row['channel'] . '"'];

        $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
        $stmt->execute([$row['post_id']]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([$row['site_id']]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);

        // Mark as publishing + increment attempts
        $db->prepare('UPDATE post_channels SET status = "publishing", attempts = attempts + 1, updated_at = NOW() WHERE id = ?')
            ->execute([$post_channel_id]);

        $result = $adapter->publish($row, $post, $site);

        if (!empty($result['success'])) {
            $stmt = $db->prepare('UPDATE post_channels SET status = "published", published_at = NOW(), external_id = ?, external_url = ?, error = NULL, updated_at = NOW() WHERE id = ?');
            $stmt->execute([
                $result['external_id'] ?? null,
                $result['external_url'] ?? null,
                $post_channel_id,
            ]);
            return ['success' => true, 'external_id' => $result['external_id'] ?? null, 'external_url' => $result['external_url'] ?? null];
        }

        // Fail — leave for retry up to 3 attempts, then mark failed permanently
        $attempts = (int)$row['attempts'] + 1;
        $new_status = $attempts >= 3 ? 'failed' : 'queued';
        $stmt = $db->prepare('UPDATE post_channels SET status = ?, error = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$new_status, $result['error'] ?? 'Unknown error', $post_channel_id]);
        return ['success' => false, 'error' => $result['error'] ?? 'Unknown error', 'attempts' => $attempts, 'next_status' => $new_status];
    }
}

/**
 * Global registry accessor — lazily registers all built-in adapters on first call.
 */
function channels_registry(): ChannelRegistry
{
    static $registry = null;
    if ($registry !== null) return $registry;

    $registry = new ChannelRegistry();

    require_once __DIR__ . '/cms.php';
    $registry->register(new CmsChannel());

    // Future adapters will register here:
    // require_once __DIR__ . '/reddit.php';     $registry->register(new RedditChannel());
    // require_once __DIR__ . '/linkedin.php';   $registry->register(new LinkedInChannel());
    // require_once __DIR__ . '/twitter.php';    $registry->register(new TwitterChannel());

    return $registry;
}
