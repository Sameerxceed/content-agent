<?php
/**
 * Mailer — sends email via PHP mail() or a configured API (Resend/SES).
 *
 * Config keys (optional):
 *   - mail_from        (default: noreply@contentagent.xceedtech.in)
 *   - mail_from_name   (default: ContentAgent)
 *   - mail_driver      ('mail' default, or 'resend')
 *   - resend_api_key   (if mail_driver = resend)
 */

require_once __DIR__ . '/helpers.php';

/**
 * Send an email. Returns ['success' => bool, 'error' => ?string].
 * Body is HTML; a plain-text fallback is auto-generated from the HTML.
 */
function mailer_send(string $to, string $subject, string $html_body, ?string $reply_to = null): array
{
    $driver  = config('mail_driver') ?: 'mail';
    $from    = config('mail_from') ?: 'noreply@contentagent.xceedtech.in';
    $from_nm = config('mail_from_name') ?: 'ContentAgent';

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid recipient email'];
    }

    if ($driver === 'resend') {
        return _mailer_resend($to, $subject, $html_body, $from, $from_nm, $reply_to);
    }

    return _mailer_php($to, $subject, $html_body, $from, $from_nm, $reply_to);
}

function _mailer_php(string $to, string $subject, string $html, string $from, string $from_nm, ?string $reply_to): array
{
    $boundary = '=_' . md5(uniqid('', true));
    $plain    = trim(strip_tags(preg_replace('/<\s*br\s*\/?>/i', "\n", $html)));

    $headers  = "From: {$from_nm} <{$from}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    if ($reply_to) $headers .= "Reply-To: {$reply_to}\r\n";
    $headers .= "X-Mailer: ContentAgent\r\n";

    $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$plain}\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n";
    $body .= "--{$boundary}--";

    $ok = @mail($to, $subject, $body, $headers);
    return $ok
        ? ['success' => true]
        : ['success' => false, 'error' => 'PHP mail() returned false (MTA may not be configured)'];
}

function _mailer_resend(string $to, string $subject, string $html, string $from, string $from_nm, ?string $reply_to): array
{
    $key = config('resend_api_key');
    if (empty($key)) return ['success' => false, 'error' => 'resend_api_key not configured'];

    $payload = [
        'from'    => "{$from_nm} <{$from}>",
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($reply_to) $payload['reply_to'] = $reply_to;

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) return ['success' => true];

    return ['success' => false, 'error' => "Resend returned HTTP {$status}: " . substr($body, 0, 200)];
}

/**
 * Minimal HTML email wrapper — gives every digest the same look.
 */
function mailer_wrap(string $title, string $inner_html): string
{
    return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#f8fafc;margin:0;padding:20px;">'
        . '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">'
        . '<tr><td style="padding:18px 24px;background:#1e3a5f;color:#fff;">'
        . '<div style="font-size:18px;font-weight:600;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div style="font-size:12px;color:#cbd5e1;margin-top:2px;">ContentAgent · ' . date('d M Y') . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 24px;color:#1f2937;font-size:14px;line-height:1.6;">' . $inner_html . '</td></tr>'
        . '<tr><td style="padding:14px 24px;background:#f8fafc;color:#94a3b8;font-size:11px;text-align:center;border-top:1px solid #e2e8f0;">'
        . 'Sent by ContentAgent. <a href="' . htmlspecialchars(config('app_url') ?: 'https://contentagent.xceedtech.in', ENT_QUOTES, 'UTF-8') . '" style="color:#3b82f6;">Open dashboard</a>'
        . '</td></tr></table></body></html>';
}
