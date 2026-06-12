<?php
/**
 * ContentAgent's public support page. Required for the Shopify App Store
 * listing form ("Support URL" field).
 *
 * URL: /legal/support  (via .htaccess rewrite) or /legal/support.php
 */
require_once __DIR__ . '/../../includes/helpers.php';

$company = 'Xceed Imagination Studios';
$product = 'ContentAgent';
$support = 'support@xceedtech.in';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=3600');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Support — <?= e($product) ?></title>
    <link rel="canonical" href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/support">
    <style>
        :root { --fg:#0f172a; --muted:#64748b; --accent:#0891b2; --border:#e2e8f0; --bg:#fff; --bg-soft:#f8fafb; }
        * { box-sizing:border-box; }
        html,body { margin:0; padding:0; background:var(--bg-soft); color:var(--fg);
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            line-height:1.7; font-size:16px; }
        .legal-topbar { background:var(--bg); border-bottom:1px solid var(--border); padding:14px 0; margin-bottom:32px; }
        .legal-topbar .inner { max-width:820px; margin:0 auto; padding:0 20px; display:flex; justify-content:space-between; align-items:center; gap:14px; }
        .legal-topbar .brand { font-weight:600; color:var(--fg); text-decoration:none; font-size:15px; }
        .legal-topbar .back { font-size:13px; color:var(--accent); text-decoration:none; }
        .legal-shell { max-width:820px; margin:0 auto; padding:0 20px; }
        .legal-paper { background:var(--bg); border:1px solid var(--border); border-radius:10px;
            padding:40px 48px; margin-bottom:48px; box-shadow:0 1px 2px rgba(15,23,42,0.04); }
        .legal-paper h1 { font-size:28px; font-weight:700; margin:0 0 18px; }
        .legal-paper h2 { font-size:18px; font-weight:600; margin:28px 0 8px; }
        .legal-paper p, .legal-paper li { font-size:15px; }
        .legal-paper .card { background:var(--bg-soft); border:1px solid var(--border); border-radius:8px; padding:18px 20px; margin:14px 0; }
        .legal-paper .email { font-size:18px; font-weight:600; color:var(--accent); text-decoration:none; }
        .legal-foot { text-align:center; color:var(--muted); font-size:12px; padding:0 0 36px; }
        @media (max-width:600px) { .legal-paper { padding:24px 22px; } .legal-paper h1 { font-size:24px; } }
    </style>
</head>
<body>
<div class="legal-topbar">
    <div class="inner">
        <a class="brand" href="<?= e(rtrim((string)config('app_url',''),'/')) ?>"><?= e($product) ?></a>
        <a class="back" href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/privacy">Privacy</a>
    </div>
</div>

<main class="legal-shell">
<article class="legal-paper">

<h1>Support</h1>
<p>We're a small, hands-on team and we read every message. Most queries get a response within 24 hours on business days (Monday–Friday, India Standard Time).</p>

<div class="card">
    <h2 style="margin-top:0;">Email us</h2>
    <p><a class="email" href="mailto:<?= e($support) ?>"><?= e($support) ?></a></p>
    <p style="color:var(--muted); font-size:13px;">Tell us your store URL, what you were trying to do, and any error message you saw. Screenshots help us help you faster.</p>
</div>

<h2>What we can help with</h2>
<ul>
    <li>Installing and connecting <?= e($product) ?> to your Shopify store</li>
    <li>Generating, reviewing, and publishing blog content</li>
    <li>Building and applying URL redirects (including bulk imports from old sites)</li>
    <li>Setting up Google Search Console, Google Merchant Center, and other integrations</li>
    <li>Understanding what each metric in the dashboard means</li>
    <li>Billing, plan changes, cancellations, and data export</li>
    <li>Reporting bugs and requesting features</li>
</ul>

<h2>Response times</h2>
<ul>
    <li><strong>Critical issues</strong> (cannot log in, data loss, app non-functional): under 4 hours, 7 days a week</li>
    <li><strong>General questions</strong>: under 24 hours, business days</li>
    <li><strong>Feature requests</strong>: acknowledged within 48 hours, prioritised on the public roadmap</li>
</ul>

<h2>Data and account requests</h2>
<p>For privacy-related requests (data access, deletion, correction), see our <a href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/privacy">Privacy Policy</a> for the full process, or email <a href="mailto:privacy@xceedtech.in">privacy@xceedtech.in</a>.</p>

</article>
<div class="legal-foot">© <?= date('Y') ?> <?= e($company) ?>. <a href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/privacy">Privacy</a> &middot; <a href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/terms">Terms</a></div>
</main>
</body>
</html>
