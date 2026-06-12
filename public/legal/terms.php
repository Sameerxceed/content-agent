<?php
/**
 * ContentAgent's own Terms of Service. Required for the Shopify App Store
 * listing form ("Terms of service URL" field).
 *
 * URL: /legal/terms  (via .htaccess rewrite) or /legal/terms.php
 */
require_once __DIR__ . '/../../includes/helpers.php';

$updated = '2026-06-12';
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
    <title>Terms of Service — <?= e($product) ?></title>
    <link rel="canonical" href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/terms">
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
        .legal-paper h1 { font-size:28px; font-weight:700; margin:0 0 6px; }
        .legal-paper .meta { color:var(--muted); font-size:13px; margin-bottom:28px; }
        .legal-paper h2 { font-size:20px; font-weight:600; margin:32px 0 10px; }
        .legal-paper p, .legal-paper li { font-size:15px; }
        .legal-paper ul { padding-left:24px; }
        .legal-paper li { margin:6px 0; }
        .legal-paper a { color:var(--accent); }
        .legal-foot { text-align:center; color:var(--muted); font-size:12px; padding:0 0 36px; }
        @media (max-width:600px) { .legal-paper { padding:24px 22px; } .legal-paper h1 { font-size:24px; } }
    </style>
</head>
<body>
<div class="legal-topbar">
    <div class="inner">
        <a class="brand" href="<?= e(rtrim((string)config('app_url',''),'/')) ?>"><?= e($product) ?></a>
        <a class="back" href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/privacy">Privacy Policy →</a>
    </div>
</div>

<main class="legal-shell">
<article class="legal-paper">

<h1>Terms of Service</h1>
<div class="meta">Last updated: <?= e($updated) ?></div>

<p>These Terms of Service ("Terms") govern your use of the <?= e($product) ?> service ("Service") operated by <?= e($company) ?> ("we", "our", or "us"). By installing the <?= e($product) ?> Shopify app or signing up for an account, you agree to these Terms.</p>

<h2>1. The Service</h2>
<p><?= e($product) ?> is a content-and-SEO automation platform for online stores. The Service helps you generate blog content, manage URL redirects, audit on-page SEO, deploy structured data, and track answer-engine visibility, among other features that may be added over time.</p>

<h2>2. Account &amp; eligibility</h2>
<p>You must be at least 18 years old and authorised to bind the business that owns the connected store. You are responsible for keeping your account credentials safe. You are responsible for all activity under your account.</p>

<h2>3. Acceptable use</h2>
<p>You agree not to:</p>
<ul>
    <li>Use the Service for any unlawful purpose, including generating content that infringes third-party intellectual property, defames any person, or promotes hate or violence.</li>
    <li>Reverse engineer, decompile, or attempt to extract the source code of the Service.</li>
    <li>Use the Service to send spam or to scrape data from sites you do not own or have permission to access.</li>
    <li>Resell, sublicense, or white-label the Service without a written agreement with us.</li>
</ul>

<h2>4. AI-generated content</h2>
<p>The Service uses third-party large language models (Anthropic Claude, OpenAI, Google Gemini) to generate text, redirect suggestions, and other outputs. AI-generated content can contain factual errors, omissions, or inappropriate phrasing. <strong>You are responsible for reviewing AI-generated content before publishing it to your store or website.</strong> We are not liable for losses arising from unreviewed AI output.</p>

<h2>5. Fees and billing</h2>
<p>Pricing tiers are listed at <a href="<?= e(rtrim((string)config('app_url',''),'/')) ?>">our pricing page</a> and in the Shopify App Store listing. Fees are billed monthly in advance via Shopify Billing (for Shopify merchants) or via Stripe / Razorpay (for direct customers). You may cancel any time; you remain responsible for charges incurred up to the cancellation date. Refunds are at our discretion.</p>

<h2>6. Your content</h2>
<p>You retain ownership of all content you create, publish, or push via the Service. By using the Service, you grant us a limited licence to host, process, and transmit your content solely to provide the Service to you.</p>

<h2>7. Our intellectual property</h2>
<p>The Service, including its software, designs, and documentation, is owned by <?= e($company) ?>. You receive a non-exclusive, non-transferable licence to use the Service for the duration of your subscription.</p>

<h2>8. Availability and changes</h2>
<p>We strive for high availability but do not guarantee uninterrupted service. We may add, modify, or remove features at our discretion. We will give reasonable notice of material changes that adversely affect you.</p>

<h2>9. Disclaimer of warranties</h2>
<p>The Service is provided "as is" and "as available". To the maximum extent permitted by law, we disclaim all warranties, express or implied, including merchantability, fitness for a particular purpose, and non-infringement.</p>

<h2>10. Limitation of liability</h2>
<p>To the maximum extent permitted by law, our aggregate liability arising out of or related to these Terms or the Service will not exceed the greater of (a) the fees you paid us in the 12 months preceding the claim, or (b) USD 100. We are not liable for indirect, incidental, consequential, or punitive damages, including lost profits or lost data.</p>

<h2>11. Indemnification</h2>
<p>You agree to indemnify and hold <?= e($company) ?> harmless from any claim arising out of your use of the Service in violation of these Terms or applicable law, including claims relating to content you publish via the Service.</p>

<h2>12. Termination</h2>
<p>You may terminate by uninstalling the app or deleting your account. We may suspend or terminate for material breach of these Terms with reasonable notice. On termination, we delete your data per the schedule in our <a href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/privacy">Privacy Policy</a>.</p>

<h2>13. Governing law</h2>
<p>These Terms are governed by the laws of India. Disputes will be resolved in the courts of Bengaluru, India, subject to your mandatory consumer rights in your country of residence.</p>

<h2>14. Changes to these Terms</h2>
<p>We may update these Terms from time to time. Material changes will be notified by email at least 14 days before they take effect. Continued use after that date constitutes acceptance.</p>

<h2>15. Contact</h2>
<p>Questions: <a href="mailto:<?= e($support) ?>"><?= e($support) ?></a></p>

</article>
<div class="legal-foot">© <?= date('Y') ?> <?= e($company) ?>. <a href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/privacy">Privacy</a> &middot; <a href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/support">Support</a></div>
</main>
</body>
</html>
