<?php
/**
 * Newsletter & Email helpers.
 * Uses PHP mail() by default, can be swapped to Resend/SendGrid.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Add a subscriber to a site.
 */
function newsletter_subscribe(PDO $db, int $site_id, string $email, string $name = ''): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address'];
    }

    $token = bin2hex(random_bytes(32));

    try {
        $stmt = $db->prepare('INSERT INTO subscribers (site_id, email, name, token) VALUES (?, ?, ?, ?)');
        $stmt->execute([$site_id, $email, $name ?: null, $token]);
        return ['success' => true, 'id' => $db->lastInsertId()];
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            // Re-activate if previously unsubscribed
            $stmt = $db->prepare('UPDATE subscribers SET status = "active", unsubscribed_at = NULL WHERE site_id = ? AND email = ? AND status = "unsubscribed"');
            $stmt->execute([$site_id, $email]);
            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'reactivated' => true];
            }
            return ['success' => false, 'error' => 'Already subscribed'];
        }
        throw $e;
    }
}

/**
 * Unsubscribe by token.
 */
function newsletter_unsubscribe(PDO $db, string $token): bool
{
    $stmt = $db->prepare('UPDATE subscribers SET status = "unsubscribed", unsubscribed_at = NOW() WHERE token = ? AND status = "active"');
    $stmt->execute([$token]);
    return $stmt->rowCount() > 0;
}

/**
 * Get active subscriber count for a site.
 */
function newsletter_subscriber_count(PDO $db, int $site_id): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM subscribers WHERE site_id = ? AND status = "active"');
    $stmt->execute([$site_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Generate a weekly digest email from recent posts.
 */
function newsletter_generate_digest(PDO $db, int $site_id): ?array
{
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    if (!$site) return null;

    // Get posts published in the last 7 days
    $stmt = $db->prepare('SELECT title, slug, excerpt, type, published_at FROM posts WHERE site_id = ? AND status = "published" AND published_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY published_at DESC LIMIT 5');
    $stmt->execute([$site_id]);
    $posts = $stmt->fetchAll();

    if (empty($posts)) return null;

    $domain = $site['domain'];
    $blog_path = $site['blog_path'] ?: '/blog';
    $site_name = $site['name'];

    $subject = "Weekly Update from {$site_name} — " . count($posts) . " new " . (count($posts) === 1 ? 'article' : 'articles');

    // Build HTML email
    $body = newsletter_digest_template($site_name, $domain, $blog_path, $posts);

    return [
        'subject' => $subject,
        'body'    => $body,
        'posts'   => count($posts),
    ];
}

/**
 * Send newsletter to all active subscribers.
 */
function newsletter_send(PDO $db, int $site_id, string $subject, string $body): array
{
    $stmt = $db->prepare('SELECT * FROM subscribers WHERE site_id = ? AND status = "active"');
    $stmt->execute([$site_id]);
    $subscribers = $stmt->fetchAll();

    $sent = 0;
    $failed = 0;

    foreach ($subscribers as $sub) {
        $unsub_link = config('app_url') . "/blog/unsubscribe.php?token={$sub['token']}";
        $personalized_body = str_replace('{{UNSUBSCRIBE_URL}}', $unsub_link, $body);
        $personalized_body = str_replace('{{SUBSCRIBER_NAME}}', e($sub['name'] ?: 'there'), $personalized_body);

        $success = newsletter_send_email($sub['email'], $subject, $personalized_body);
        if ($success) {
            $sent++;
        } else {
            $failed++;
        }

        usleep(100000); // 100ms delay between sends
    }

    // Save newsletter record
    $stmt = $db->prepare('INSERT INTO newsletters (site_id, subject, body, status, sent_count, sent_at) VALUES (?, ?, ?, "sent", ?, NOW())');
    $stmt->execute([$site_id, $subject, $body, $sent]);

    return ['sent' => $sent, 'failed' => $failed, 'total' => count($subscribers)];
}

/**
 * Send a single email. Override this for Resend/SendGrid.
 */
function newsletter_send_email(string $to, string $subject, string $html_body): bool
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ContentAgent <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'contentagent.app') . '>',
    ];

    return @mail($to, $subject, $html_body, implode("\r\n", $headers));
}

/**
 * Generate digest email HTML template.
 */
function newsletter_digest_template(string $site_name, string $domain, string $blog_path, array $posts): string
{
    $base = "https://{$domain}";

    $posts_html = '';
    foreach ($posts as $p) {
        $url = "{$base}{$blog_path}/{$p['slug']}";
        $date = format_date($p['published_at'], 'd M Y');
        $badge = $p['type'] === 'news' ? '📰' : '📝';
        $posts_html .= "
        <tr>
            <td style='padding: 14px 0; border-bottom: 1px solid #f0f0f0;'>
                <div style='font-size: 10px; color: #999; text-transform: uppercase;'>{$badge} {$p['type']} · {$date}</div>
                <a href='{$url}' style='color: #1B3A6B; text-decoration: none; font-size: 16px; font-weight: 600; line-height: 1.3;'>{$p['title']}</a>
                <p style='color: #666; font-size: 13px; margin: 4px 0 0; line-height: 1.4;'>" . truncate($p['excerpt'] ?: strip_tags($p['body'] ?? ''), 120) . "</p>
            </td>
        </tr>";
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.1);">
    <tr><td style="background:#1B3A6B;padding:20px 24px;text-align:center;">
        <div style="font-size:18px;font-weight:700;color:#fff;">{$site_name}</div>
        <div style="font-size:12px;color:rgba(255,255,255,0.7);margin-top:2px;">Weekly Digest</div>
    </td></tr>
    <tr><td style="padding:20px 24px;">
        <p style="color:#333;font-size:14px;">Hi {{SUBSCRIBER_NAME}},</p>
        <p style="color:#666;font-size:14px;">Here's what we published this week:</p>
        <table width="100%" cellpadding="0" cellspacing="0">
            {$posts_html}
        </table>
        <p style="margin-top:20px;text-align:center;">
            <a href="{$base}{$blog_path}" style="display:inline-block;padding:10px 20px;background:#CC3300;color:#fff;text-decoration:none;border-radius:5px;font-size:13px;font-weight:600;">Read All Articles →</a>
        </p>
    </td></tr>
    <tr><td style="padding:14px 24px;background:#fafafa;text-align:center;border-top:1px solid #eee;">
        <p style="font-size:11px;color:#aaa;margin:0;">You're receiving this because you subscribed to {$site_name}.</p>
        <p style="font-size:11px;color:#aaa;margin:4px 0 0;"><a href="{{UNSUBSCRIBE_URL}}" style="color:#aaa;">Unsubscribe</a></p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
