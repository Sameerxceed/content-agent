<?php
/**
 * Public privacy policy page. Required by LinkedIn / Reddit / Twitter
 * developer apps as a publicly accessible URL. Also used as the canonical
 * privacy policy for ContentAgent customers.
 *
 * Lives at /privacy (rewrite) or /privacy.php directly.
 */
$last_updated = '2 June 2026';
$company      = 'Xceed Imagination Pvt. Ltd.';
$contact      = 'privacy@xceedtech.in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy — ContentAgent</title>
    <meta name="description" content="ContentAgent privacy policy: what data we collect, how we use it, and how to delete it.">
    <style>
        :root { --ink: #0f172a; --muted: #475569; --light: #94a3b8; --border: #e2e8f0; --accent: #1B3A6B; --bg: #f8fafb; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--ink); margin: 0; line-height: 1.65; font-size: 15px; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 48px 24px 72px; }
        header { border-bottom: 1px solid var(--border); padding-bottom: 18px; margin-bottom: 28px; }
        header .brand { font-size: 13px; color: var(--accent); font-weight: 600; letter-spacing: 0.4px; text-transform: uppercase; }
        h1 { font-size: 28px; margin: 6px 0 2px; line-height: 1.25; }
        .meta { font-size: 12px; color: var(--light); }
        h2 { font-size: 18px; margin: 32px 0 10px; color: var(--accent); }
        h3 { font-size: 15px; margin: 18px 0 6px; color: var(--ink); }
        p, ul { color: var(--muted); }
        ul { padding-left: 1.4em; }
        li { margin: 4px 0; }
        a { color: var(--accent); }
        .lead { font-size: 16px; color: var(--ink); }
        .footer-note { margin-top: 48px; padding-top: 18px; border-top: 1px solid var(--border); font-size: 12px; color: var(--light); }
        code { background: #eef2f7; padding: 1px 6px; border-radius: 3px; font-size: 13px; }
    </style>
</head>
<body>
<div class="wrap">

<header>
    <div class="brand">ContentAgent</div>
    <h1>Privacy Policy</h1>
    <div class="meta">Last updated: <?= htmlspecialchars($last_updated) ?></div>
</header>

<p class="lead">
    ContentAgent is an AI content automation platform operated by <?= htmlspecialchars($company) ?>
    (&ldquo;we&rdquo;, &ldquo;us&rdquo;, &ldquo;our&rdquo;). This policy explains what data we collect,
    why we collect it, how we use it, and the choices you have. Plain English. Short.
</p>

<h2>1. Who this policy covers</h2>
<p>
    This policy applies to anyone who creates a ContentAgent account, connects a website to our service,
    or grants ContentAgent permission to publish on their behalf via OAuth (LinkedIn, Twitter, Reddit,
    Google Search Console, etc.).
</p>

<h2>2. What we collect</h2>

<h3>Account information you provide</h3>
<ul>
    <li>Name and email address (for sign-in and account-level notifications).</li>
    <li>Company name and the websites you connect to ContentAgent.</li>
    <li>Optional payment information if you upgrade to a paid plan (processed by our payment processor — we do not store full card numbers).</li>
</ul>

<h3>OAuth credentials from connected platforms</h3>
<p>
    When you click &ldquo;Connect&rdquo; on a social or analytics platform (e.g. LinkedIn, Twitter, Google Search Console),
    that platform redirects you to a consent screen where you choose what permissions to grant. After consent,
    that platform sends ContentAgent an access token. We store this token encrypted and use it only to perform
    the actions you authorised &mdash; for example, posting an article to your LinkedIn company page on a schedule
    you set up, or reading your Search Console performance data to feed your content plan.
</p>
<p>
    We do not read, store, or share content from these accounts beyond what is necessary to perform the connected feature.
    We never sell access tokens, and we never use them for any purpose other than the connected feature.
</p>

<h3>Content you upload or generate</h3>
<ul>
    <li>Blog posts, social variants, and metadata you create or that our AI agent generates for you.</li>
    <li>Keyword research, SEO audits, and competitor data sourced on your behalf from public web searches.</li>
    <li>Performance data (impressions, clicks, rankings) returned by connected platforms.</li>
</ul>

<h3>Technical information we collect automatically</h3>
<ul>
    <li>IP address, browser type, and basic device information &mdash; for security, abuse prevention, and debugging.</li>
    <li>Pages visited inside the dashboard &mdash; to improve the product. We do not use third-party advertising trackers.</li>
</ul>

<h2>3. How we use your data</h2>
<ul>
    <li>To deliver the service you signed up for &mdash; generate content, publish to connected channels, track performance.</li>
    <li>To send you account-critical notifications (failed publishes, billing issues, security alerts).</li>
    <li>To improve ContentAgent &mdash; in aggregate, anonymised form. We do not train external AI models on your content.</li>
    <li>To comply with legal obligations.</li>
</ul>

<h2>4. Third parties we share data with</h2>
<p>
    ContentAgent uses a small number of trusted infrastructure providers. Each receives only the minimum data needed
    to perform its function:
</p>
<ul>
    <li><strong>Anthropic (Claude API)</strong> &mdash; processes content-generation prompts. Anthropic does not retain or train on your data.</li>
    <li><strong>Google AI Studio (Gemini API)</strong> &mdash; generates blog hero images when configured.</li>
    <li><strong>OpenAI (DALL-E API)</strong> &mdash; generates blog hero images when configured.</li>
    <li><strong>Unsplash</strong> &mdash; sources stock photos when configured.</li>
    <li><strong>DataForSEO</strong> &mdash; provides keyword search volume and difficulty data.</li>
    <li><strong>Brave Search / Google Custom Search</strong> &mdash; sources public web results for competitor and AI presence tracking.</li>
    <li><strong>Resend</strong> &mdash; sends transactional and digest emails.</li>
    <li><strong>The OAuth platforms you connect</strong> &mdash; receive only the publishing or read actions you authorised.</li>
    <li><strong>Our hosting provider (Linode/Akamai)</strong> &mdash; stores the database and application files.</li>
</ul>
<p>
    We do not sell your data. We do not share it with advertisers. We do not use it to build profiles for resale.
</p>

<h2>5. Data retention and deletion</h2>
<p>
    We retain your data for as long as your account is active. You can:
</p>
<ul>
    <li>Disconnect any OAuth platform at any time from your site&rsquo;s integrations page &mdash; we will revoke and delete the stored token.</li>
    <li>Delete individual content items, posts, or keywords from the dashboard at any time.</li>
    <li>Request deletion of your entire account by emailing <a href="mailto:<?= htmlspecialchars($contact) ?>"><?= htmlspecialchars($contact) ?></a>. We will delete your account and associated data within 30 days, except where retention is required by law (e.g. invoicing records).</li>
</ul>

<h2>6. Security</h2>
<p>
    OAuth tokens and API keys are encrypted at rest. Access to the production database is restricted to a small
    number of named engineers. All data is transmitted over TLS. We log access for audit purposes.
    No system is perfect; if you discover a security issue, please report it to
    <a href="mailto:<?= htmlspecialchars($contact) ?>"><?= htmlspecialchars($contact) ?></a> &mdash; we will respond within 72 hours.
</p>

<h2>7. Children</h2>
<p>
    ContentAgent is a business product. We do not knowingly accept users under 18 years of age.
    If you believe a minor has created an account, contact us and we will delete it.
</p>

<h2>8. International transfers</h2>
<p>
    <?= htmlspecialchars($company) ?> is incorporated in India. Our infrastructure is located in North America and Europe.
    By using ContentAgent you consent to your data being processed in those regions.
</p>

<h2>9. Your rights under GDPR / DPDP / CCPA</h2>
<p>
    You have the right to access, correct, port, or delete your personal data. To exercise any of these rights,
    email <a href="mailto:<?= htmlspecialchars($contact) ?>"><?= htmlspecialchars($contact) ?></a>. We will respond
    within 30 days. We will not discriminate against you for exercising any of these rights.
</p>

<h2>10. Changes to this policy</h2>
<p>
    If we make material changes we will email account owners and update the &ldquo;Last updated&rdquo; date at the top of this page.
    Continued use of ContentAgent after a change constitutes acceptance of the revised policy.
</p>

<h2>11. Contact</h2>
<p>
    Questions, requests, complaints: <a href="mailto:<?= htmlspecialchars($contact) ?>"><?= htmlspecialchars($contact) ?></a><br>
    Mailing address: <?= htmlspecialchars($company) ?>, India.
</p>

<div class="footer-note">
    <?= htmlspecialchars($company) ?> &middot; <a href="/">ContentAgent</a>
</div>

</div>
</body>
</html>
