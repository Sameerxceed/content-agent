<?php
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/../integrations/twitter.php';
require_once __DIR__ . '/../haiku.php';

/**
 * Twitter / X channel adapter.
 *
 * The Claude prompt produces a numbered tweet thread separated by blank lines.
 * Tweet 1 is the hook (no '1/' prefix). Subsequent tweets are '2/' ... 'N/N'.
 *
 * Publishing strategy:
 *   - Split variant_content into individual tweets (one per blank-line-separated chunk)
 *   - Post tweet 1 → capture id
 *   - Post tweet 2 as a reply to tweet 1 → capture id
 *   - Continue threading until all posted
 *
 * Twitter free tier limits the rate so we keep ≤8 tweets per thread.
 */
class TwitterChannel extends ChannelAdapter
{
    public function id(): string           { return 'twitter'; }
    public function display_name(): string { return 'Twitter / X'; }
    public function icon(): string         { return 'X'; }
    public function color(): string        { return '#000000'; }

    public function description(): string
    {
        return "Posts a 5-8 tweet thread under the connected account. Each tweet ≤270 chars, last tweet links to the blog.";
    }

    public function is_configured(array $site): bool
    {
        if (empty(config('twitter_client_id')) || empty(config('twitter_client_secret'))) {
            return false;
        }
        return !empty($site['id']);
    }

    public function setup_hint(array $site): ?string
    {
        if (empty(config('twitter_client_id'))) {
            return "Add Twitter client_id/secret in Settings → API Keys, then connect this site's Twitter account.";
        }
        return "Click 'Connect Twitter' on the site page to authorise.";
    }

    public function transform_post(array $post, array $site): array
    {
        $result = haiku_repurpose_for_channel($post, 'twitter', $site);
        if (!empty($result['success'])) {
            return ['content' => $result['content'], 'meta' => null];
        }
        return ['content' => trim($post['title'] ?? ''), 'meta' => null];
    }

    public function publish(array $post_channel, array $post, array $site): array
    {
        global $db;
        if (!isset($db) || !($db instanceof PDO)) {
            $db = require __DIR__ . '/../db.php';
        }

        $stmt = $db->prepare('SELECT access_token, refresh_token, token_expires_at FROM integrations WHERE site_id = ? AND platform = "twitter" AND is_active = 1 LIMIT 1');
        $stmt->execute([(int)$site['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['access_token'])) {
            return ['success' => false, 'error' => 'Twitter not connected for this site. Click "Connect Twitter" on the site page.'];
        }

        // Refresh if expired
        $token = $row['access_token'];
        if (!empty($row['token_expires_at']) && strtotime($row['token_expires_at']) < time() + 60) {
            if (empty($row['refresh_token'])) {
                return ['success' => false, 'error' => 'Twitter token expired and no refresh token available. Reconnect.'];
            }
            $new = twitter_refresh_token($row['refresh_token']);
            if (!$new) return ['success' => false, 'error' => 'Twitter token refresh failed. Reconnect.'];
            $token = $new['access_token'];
            $expires_at = date('Y-m-d H:i:s', time() + ($new['expires_in'] ?? 7200));
            $db->prepare('UPDATE integrations SET access_token = ?, refresh_token = COALESCE(?, refresh_token), token_expires_at = ?, updated_at = NOW() WHERE site_id = ? AND platform = "twitter"')
                ->execute([$token, $new['refresh_token'] ?? null, $expires_at, (int)$site['id']]);
        }

        $tweets = $this->_split_thread($post_channel['variant_content'] ?? '');
        if (empty($tweets)) {
            return ['success' => false, 'error' => 'No tweets to post — variant is empty.'];
        }

        $reply_to = null;
        $first_id = null;
        $first_url = null;
        foreach ($tweets as $idx => $text) {
            $payload = ['text' => mb_substr($text, 0, 280)];
            if ($reply_to) {
                $payload['reply'] = ['in_reply_to_tweet_id' => $reply_to];
            }
            $ch = curl_init('https://api.twitter.com/2/tweets');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
            ]);
            $resp = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = json_decode($resp, true);
            if ($status !== 201 || empty($data['data']['id'])) {
                $err = $data['detail'] ?? ($data['title'] ?? "HTTP {$status}");
                return [
                    'success' => false,
                    'error'   => 'Posted ' . $idx . '/' . count($tweets) . ' tweets. Failed on tweet ' . ($idx + 1) . ': ' . $err,
                ];
            }
            $tweet_id = $data['data']['id'];
            if ($idx === 0) {
                $first_id  = $tweet_id;
                // We don't know the username here; construct generic URL — Twitter resolves it
                $first_url = 'https://twitter.com/i/status/' . $tweet_id;
            }
            $reply_to = $tweet_id;
            // Be polite — small delay between tweets to avoid rate-limit
            usleep(500000); // 0.5s
        }

        return [
            'success'      => true,
            'external_id'  => $first_id,
            'external_url' => $first_url,
        ];
    }

    /** Split the variant text into separate tweets (blank-line separated). Caps at 8. */
    private function _split_thread(string $text): array
    {
        $chunks = preg_split('/\r?\n\s*\r?\n/', trim($text));
        $chunks = array_values(array_filter(array_map('trim', $chunks), fn($c) => $c !== ''));
        return array_slice($chunks, 0, 8);
    }
}
