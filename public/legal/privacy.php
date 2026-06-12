<?php
/**
 * ContentAgent's own Privacy Policy — covers the SaaS platform itself, not
 * a customer's site. Required URL for the Shopify App Store listing form
 * ("Privacy policy URL" field).
 *
 * URL: /legal/privacy  (via .htaccess rewrite) or /legal/privacy.php
 *
 * Customer-site legal docs are at /legal/<site_id>/<slug> via view.php —
 * unrelated, do not confuse.
 */
require_once __DIR__ . '/../../includes/helpers.php';

$updated = '2026-06-12';
$company = 'Xceed Imagination Studios';
$product = 'ContentAgent';
$support = 'support@xceedtech.in';
$privacy_email = 'privacy@xceedtech.in';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=3600');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy — <?= e($product) ?></title>
    <meta name="description" content="<?= e($product) ?> privacy policy: what we collect, how we use it, and your rights.">
    <link rel="canonical" href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/privacy">
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
        <a class="back" href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/terms">Terms of Service →</a>
    </div>
</div>

<main class="legal-shell">
<article class="legal-paper">

<h1>Privacy Policy</h1>
<div class="meta">Last updated: <?= e($updated) ?></div>

<p><?= e($product) ?> ("we", "our", or "us") is operated by <?= e($company) ?>. This privacy policy explains what information we collect from merchants who install <?= e($product) ?> on their Shopify store (or any other supported platform), how we use it, and the rights you have over it.</p>

<h2>1. What we collect</h2>

<p>When you connect your store to <?= e($product) ?>, we collect:</p>
<ul>
    <li><strong>Store metadata:</strong> shop name, primary domain, currency, and store ID. Used to identify your store and address you correctly in the dashboard.</li>
    <li><strong>Product catalog:</strong> product titles, descriptions, images, SKUs, and prices — read-only. Used to audit on-page SEO and Google Merchant Center feed quality.</li>
    <li><strong>Theme files:</strong> read-only access to your active theme. Used to deploy a branded 404 page when you opt in.</li>
    <li><strong>URL redirects:</strong> existing and new 301 redirects. Used to install the redirects you approve in the <?= e($product) ?> dashboard.</li>
    <li><strong>Blog content:</strong> blog articles and pages — read + write. Used to publish AI-generated content you have approved.</li>
    <li><strong>OAuth access token:</strong> a long-lived API token Shopify issues during install. Encrypted at rest. Used to perform the read/write operations above.</li>
</ul>

<p><strong>We do NOT collect:</strong> customer personal data (names, emails, addresses, order details), payment information, or any data subject to PCI-DSS. <?= e($product) ?> operates on the merchant side only — your customers' data never leaves Shopify.</p>

<p>We also collect:</p>
<ul>
    <li><strong>Your <?= e($product) ?> account data:</strong> email, name, password hash, login timestamps. Used to authenticate you to the dashboard.</li>
    <li><strong>Usage logs:</strong> which features you used and when. Used to operate the service and improve product quality. Retained for 90 days.</li>
</ul>

<h2>2. How we use it</h2>

<p>We use the information we collect to:</p>
<ul>
    <li>Operate the <?= e($product) ?> service as described in our product documentation.</li>
    <li>Generate AI-assisted content (blog posts, redirect maps, image alt text, schema markup) on your behalf, only when you trigger the action.</li>
    <li>Send transactional emails about your account (account changes, security alerts, support replies).</li>
    <li>Comply with legal obligations.</li>
</ul>

<p>We do <strong>not</strong> sell or rent your data. We do <strong>not</strong> use your store's content to train any AI models.</p>

<h2>3. Sub-processors</h2>

<p>To operate <?= e($product) ?>, we share limited data with the following sub-processors, each bound by their own data-processing terms:</p>
<ul>
    <li><strong>Anthropic (Claude API)</strong> — content generation, redirect matching, structured data inference. Prompts and outputs are processed but, per Anthropic's policy, not used to train models. <a href="https://www.anthropic.com/legal/privacy">Anthropic Privacy Policy</a>.</li>
    <li><strong>OpenAI</strong> — backup AI provider for image generation and embeddings. <a href="https://openai.com/policies/privacy-policy">OpenAI Privacy Policy</a>.</li>
    <li><strong>Google (Gemini API, Search Console API, Merchant Center API)</strong> — only when you explicitly connect your Google account. <a href="https://policies.google.com/privacy">Google Privacy Policy</a>.</li>
    <li><strong>Akamai Linode</strong> — our cloud infrastructure provider. All data is hosted in their datacenters. <a href="https://www.linode.com/legal-privacy/">Linode Privacy Policy</a>.</li>
    <li><strong>Shopify</strong> — when you install the app, you authorise the data flows described above. <a href="https://www.shopify.com/legal/privacy">Shopify Privacy Policy</a>.</li>
</ul>

<h2>4. Data location and retention</h2>

<p>Your data is stored on encrypted-at-rest disks in <?= e($company) ?>'s cloud infrastructure. OAuth tokens are encrypted before being written to the database.</p>

<p>Retention:</p>
<ul>
    <li><strong>Store data, content, redirects:</strong> retained while your <?= e($product) ?> account is active. Deleted within 30 days of you uninstalling the app or closing your account.</li>
    <li><strong>OAuth tokens:</strong> deleted immediately on app uninstall via Shopify's <code>app/uninstalled</code> webhook.</li>
    <li><strong>Usage logs:</strong> 90 days.</li>
    <li><strong>Billing records:</strong> 7 years, for tax/accounting purposes.</li>
</ul>

<h2>5. Your rights (GDPR / UK GDPR / CCPA)</h2>

<p>You have the right to:</p>
<ul>
    <li><strong>Access</strong> — request a copy of the data we hold about you and your store.</li>
    <li><strong>Correct</strong> — fix any inaccurate data.</li>
    <li><strong>Delete</strong> — request deletion of your data ("right to be forgotten"). Uninstalling the Shopify app triggers automatic deletion within 30 days.</li>
    <li><strong>Export</strong> — receive a machine-readable copy of your data.</li>
    <li><strong>Object</strong> — to particular kinds of processing.</li>
    <li><strong>Lodge a complaint</strong> with your local data protection authority.</li>
</ul>

<p>To exercise any of these rights, email <a href="mailto:<?= e($privacy_email) ?>"><?= e($privacy_email) ?></a>. We respond within 30 days.</p>

<h2>6. Cookies</h2>

<p>The <?= e($product) ?> dashboard uses a single session cookie (HTTP-only, secure, SameSite=Lax) to keep you logged in. No tracking, advertising, or analytics cookies. The dashboard does not embed third-party scripts.</p>

<h2>7. Children</h2>

<p><?= e($product) ?> is a B2B tool for merchants. It is not directed at children under 16 and we do not knowingly collect data from anyone under 16.</p>

<h2>8. Changes to this policy</h2>

<p>We may update this policy from time to time. Material changes will be notified by email to all active accounts at least 14 days before they take effect. The "Last updated" date at the top reflects the most recent change.</p>

<h2>9. Contact</h2>

<p>Questions or requests:</p>
<ul>
    <li>General: <a href="mailto:<?= e($support) ?>"><?= e($support) ?></a></li>
    <li>Privacy / data rights: <a href="mailto:<?= e($privacy_email) ?>"><?= e($privacy_email) ?></a></li>
    <li>Postal: <?= e($company) ?>, India</li>
</ul>

</article>
<div class="legal-foot">© <?= date('Y') ?> <?= e($company) ?>. <a href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/terms">Terms</a> &middot; <a href="<?= e(rtrim((string)config('app_url',''),'/')) ?>/legal/support">Support</a></div>
</main>
</body>
</html>
