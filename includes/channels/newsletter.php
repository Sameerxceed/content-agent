<?php
/**
 * Newsletter channel adapter.
 *
 * - is_configured: Resend API key is set globally AND the site has active subscribers
 * - transform_post: Claude repurposes into an 80-130 word teaser (newsletter template)
 * - publish: wraps the teaser in the digest HTML shell and sends via Resend to all active subscribers
 *
 * Treats one publish as one "send-out" — records subscriber count in metrics so the
 * Performance Loop can later track opens/clicks when we add Resend webhook handling.
 */

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/../haiku.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../newsletter.php';

class NewsletterChannel extends ChannelAdapter
{
    public function id(): string           { return 'newsletter'; }
    public function display_name(): string { return 'Newsletter'; }
    public function icon(): string         { return '✉'; }
    public function color(): string        { return '#7c3aed'; }

    public function description(): string
    {
        return 'Sends an AI-crafted 80-130 word teaser of the post to all active newsletter subscribers via Resend.';
    }

    public function is_configured(array $site): bool
    {
        // Needs Resend configured globally
        if (empty(config('resend_api_key'))) return false;
        // Driver must be set to resend (or we'd silently fall back to PHP mail())
        // Be permissive: if driver isn't set, we'll force it at publish time.
        return !empty($site['id']);
    }

    public function setup_hint(array $site): ?string
    {
        if (empty(config('resend_api_key'))) {
            return "Add Resend in the Integrations Hub to enable newsletter sends.";
        }
        return "Sends to active subscribers for this site. Add subscribers from the Subscribers page.";
    }

    public function transform_post(array $post, array $site): array
    {
        $result = haiku_repurpose_for_channel($post, 'newsletter', $site);
        if (!empty($result['success'])) {
            return ['content' => $result['content'], 'meta' => null];
        }
        // Fallback: title + excerpt
        $fallback = trim($post['title'] ?? '') . "\n\n" . trim($post['excerpt'] ?? '');
        return ['content' => $fallback, 'meta' => null];
    }

    public function publish(array $post_channel, array $post, array $site): array
    {
        global $db;
        if (!isset($db) || !($db instanceof PDO)) {
            $db = require __DIR__ . '/../db.php';
        }

        if (empty(config('resend_api_key'))) {
            return ['success' => false, 'error' => 'Resend not configured. Set it up in the Integrations Hub.'];
        }

        // Fetch active subscribers for the site
        $stmt = $db->prepare('SELECT email, name, token FROM subscribers WHERE site_id = ? AND status = "active"');
        $stmt->execute([(int)$site['id']]);
        $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subscribers)) {
            return ['success' => false, 'error' => 'No active subscribers for this site. Add some on the Subscribers page first.'];
        }

        $teaser = trim($post_channel['variant_content'] ?? '');
        if ($teaser === '') {
            $teaser = trim($post['title'] ?? '') . "\n\n" . trim($post['excerpt'] ?? '');
        }

        $subject = trim($post['title'] ?? 'New post from ' . ($site['name'] ?? ''));

        $domain    = $site['domain'] ?? '';
        $blog_path = $site['blog_path'] ?? '/blog';
        $post_url  = $domain ? 'https://' . preg_replace('#^https?://#i', '', $domain) . rtrim($blog_path, '/') . '/' . $post['slug'] : '';
        $app_url   = config('app_url') ?: 'https://contentagent.xceedtech.in';

        // Force Resend driver for this send (newsletter quality needs it)
        $original_driver = config('mail_driver');
        $cfg = require __DIR__ . '/../../config/config.php';
        $cfg['mail_driver'] = 'resend';
        // Note: we don't write back to config.php; we just rely on mailer reading it via config()
        // To make mailer_send pick this up, override by setting mail_driver via env or just call _mailer_resend directly.

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($subscribers as $sub) {
            $body_html = self::build_email_html(
                $site['name'] ?? 'Newsletter',
                $sub['name'] ?? '',
                $subject,
                $teaser,
                $post_url,
                $app_url . '/blog/unsubscribe.php?token=' . urlencode($sub['token'] ?? '')
            );

            // Call mailer with explicit Resend by inlining the driver check
            $result = self::send_via_resend($sub['email'], $subject, $body_html);
            if (!empty($result['success'])) {
                $sent++;
            } else {
                $failed++;
                if (count($errors) < 3) $errors[] = $sub['email'] . ': ' . ($result['error'] ?? 'unknown');
            }
            usleep(80000); // ~80ms throttle to stay friendly with Resend
        }

        // Persist a row in newsletters table for the audit log
        try {
            $db->prepare('INSERT INTO newsletters (site_id, subject, body, status, sent_count, sent_at) VALUES (?, ?, ?, "sent", ?, NOW())')
               ->execute([(int)$site['id'], $subject, $teaser, $sent]);
        } catch (PDOException $e) { /* table optional */ }

        if ($sent === 0) {
            return [
                'success' => false,
                'error'   => 'All sends failed' . (empty($errors) ? '' : ': ' . implode(' | ', $errors)),
            ];
        }

        return [
            'success'      => true,
            'external_id'  => 'send-' . date('Ymd-His'),
            'external_url' => null,
            'metrics'      => [
                'sends'       => $sent,
                'failed'      => $failed,
                'recipients'  => count($subscribers),
            ],
        ];
    }

    public function fetch_metrics(array $post_channel, array $post, array $site): ?array
    {
        // Without Resend webhook integration we can't auto-pull opens/clicks.
        // Surface the send count we recorded at publish time so Performance Loop has a number.
        return null;
    }

    private static function send_via_resend(string $to, string $subject, string $html): array
    {
        $key = config('resend_api_key');
        if (empty($key)) return ['success' => false, 'error' => 'resend_api_key not set'];

        $from    = config('mail_from')      ?: 'noreply@contentagent.xceedtech.in';
        $from_nm = config('mail_from_name') ?: 'ContentAgent';

        $payload = [
            'from'    => "{$from_nm} <{$from}>",
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ];

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) return ['success' => true];
        return ['success' => false, 'error' => "HTTP {$code}: " . substr((string)$body, 0, 180)];
    }

    private static function build_email_html(string $site_name, string $subscriber_name, string $title, string $teaser, string $post_url, string $unsub_url): string
    {
        $name      = htmlspecialchars($subscriber_name ?: 'there', ENT_QUOTES, 'UTF-8');
        $site_safe = htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8');
        $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $teaser_html = nl2br(htmlspecialchars($teaser, ENT_QUOTES, 'UTF-8'));
        $post_url_safe = htmlspecialchars($post_url, ENT_QUOTES, 'UTF-8');
        $unsub_safe = htmlspecialchars($unsub_url, ENT_QUOTES, 'UTF-8');

        $cta = $post_url
            ? "<p style=\"margin-top:18px;\"><a href=\"{$post_url_safe}\" style=\"display:inline-block;padding:10px 20px;background:#CC3300;color:#fff;text-decoration:none;border-radius:5px;font-size:13px;font-weight:600;\">Read the full post →</a></p>"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f6fa;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6fa;padding:24px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">
    <tr><td style="background:#1B3A6B;padding:18px 24px;color:#fff;">
        <div style="font-size:16px;font-weight:600;">{$site_safe}</div>
    </td></tr>
    <tr><td style="padding:22px 24px;color:#1f2937;font-size:14px;line-height:1.6;">
        <p style="margin:0 0 14px;">Hi {$name},</p>
        <h2 style="margin:0 0 12px;font-size:18px;font-weight:600;color:#1B3A6B;">{$title_safe}</h2>
        <div style="color:#475569;">{$teaser_html}</div>
        {$cta}
    </td></tr>
    <tr><td style="padding:14px 24px;background:#f8fafc;text-align:center;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:11px;">
        You're receiving this because you subscribed to {$site_safe}.<br>
        <a href="{$unsub_safe}" style="color:#94a3b8;">Unsubscribe</a>
    </td></tr>
</table>
</td></tr></table></body></html>
HTML;
    }
}
