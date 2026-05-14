<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/../integrations/reddit.php';
require_once __DIR__ . '/../haiku.php';

class RedditChannel extends ChannelAdapter
{
    public function id(): string           { return 'reddit'; }
    public function display_name(): string { return 'Reddit'; }
    public function icon(): string         { return 'R'; }
    public function color(): string        { return '#FF4500'; }

    public function description(): string
    {
        return "Posts a discussion-style version to your profile (u/yourname) or a chosen subreddit. Reddit-appropriate tone, not salesy.";
    }

    public function is_configured(array $site): bool
    {
        if (empty(config('reddit_client_id')) || empty(config('reddit_client_secret'))) {
            return false;
        }
        return !empty($site['id']);
    }

    public function setup_hint(array $site): ?string
    {
        if (empty(config('reddit_client_id'))) {
            return "Add Reddit client_id/secret in Settings → API Keys, then connect this site's Reddit account.";
        }
        return "Click 'Connect Reddit' on the site page to authorise. Posts will go to u/{your_username} by default.";
    }

    public function transform_post(array $post, array $site): array
    {
        $result = haiku_repurpose_for_channel($post, 'reddit', $site);
        if (!empty($result['success'])) {
            return ['content' => $result['content'], 'meta' => null];
        }
        return ['content' => ($post['title'] ?? '') . "\n\n" . trim($post['excerpt'] ?? ''), 'meta' => null];
    }

    public function publish(array $post_channel, array $post, array $site): array
    {
        global $db;
        if (!isset($db) || !($db instanceof PDO)) {
            $db = require __DIR__ . '/../db.php';
        }

        $token = reddit_get_active_token($db, (int)$site['id']);
        if (!$token) {
            return ['success' => false, 'error' => 'Reddit not connected for this site, or refresh failed. Reconnect on the site page.'];
        }

        // Username for default subreddit (u_username posts go to user profile)
        $stmt = $db->prepare('SELECT account_name FROM integrations WHERE site_id = ? AND platform = "reddit" AND is_active = 1 LIMIT 1');
        $stmt->execute([(int)$site['id']]);
        $username = $stmt->fetchColumn();

        // variant_meta can override target subreddit
        $meta = !empty($post_channel['variant_meta']) ? json_decode($post_channel['variant_meta'], true) : [];
        $subreddit = $meta['subreddit'] ?? ('u_' . $username);

        $text = $post_channel['variant_content'] ?? '';

        // Split first line as title (matches our Claude template — "title\n\nbody")
        $parts = preg_split("/\r?\n/", trim($text), 2);
        $title = trim($parts[0] ?? ($post['title'] ?? ''));
        $body  = trim($parts[1] ?? '');

        $result = reddit_submit($token, $subreddit, $title, $body);
        if (!empty($result['success'])) {
            return [
                'success'      => true,
                'external_id'  => $result['id'] ?? null,
                'external_url' => $result['url'] ?? null,
            ];
        }
        return ['success' => false, 'error' => $result['error'] ?? 'Reddit API error'];
    }
}
