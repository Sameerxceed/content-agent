<?php
/**
 * Public viewer for hosted legal documents.
 *
 * URL: /legal/<site_id>/<slug>
 *   e.g. /legal/1/privacy → renders the Privacy Policy for site #1
 *
 * This is the Tier-1 universal hosting path. Every customer gets their legal
 * docs hosted here from day one, regardless of whether their site has a CMS
 * API we can push to. The customer adds a single footer link
 * (<a href="https://contentagent.../legal/1/cookies">Cookie Policy</a>) to
 * their site and they're done.
 */
require_once __DIR__ . '/../../includes/helpers.php';

$site_id = (int)($_GET['site'] ?? 0);
$slug    = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_GET['slug'] ?? '')));

if (!$site_id || !$slug) {
    http_response_code(404);
    echo "Not found";
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';

// Pull the doc + the site name. Only serve drafted-or-later rows; never
// expose missing/failed/generating rows publicly.
$stmt = $db->prepare("
    SELECT d.*, s.name AS site_name, s.domain AS site_domain
      FROM legal_docs d
      JOIN sites s ON s.id = d.site_id
     WHERE d.site_id = ? AND d.slug = ?
       AND d.status IN ('drafted', 'approved', 'published')
       AND d.body_html IS NOT NULL
     LIMIT 1
");
$stmt->execute([$site_id, $slug]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    ?><!doctype html><html><head><meta charset="utf-8"><title>Not found</title>
<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:600px;margin:80px auto;padding:0 20px;text-align:center;color:#475569;}h1{color:#0f172a;}</style>
</head><body><h1>404</h1><p>This document is not available.</p></body></html><?php
    exit;
}

$title          = (string)($doc['title'] ?? ucfirst($slug));
$site_name      = (string)$doc['site_name'];
$site_domain    = (string)$doc['site_domain'];
$body_html      = (string)$doc['body_html'];
$last_updated   = $doc['generated_at'] ?? $doc['detected_at'] ?? null;
$customer_url   = $site_domain ? ('https://' . ltrim($site_domain, 'https://')) : '';
$canonical_self = rtrim((string)config('app_url', ''), '/') . '/legal/' . $site_id . '/' . $slug;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=600');  // 10 min — re-publishes propagate quickly
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — <?= e($site_name) ?></title>
    <meta name="description" content="<?= e($title) ?> for <?= e($site_name) ?>.">
    <link rel="canonical" href="<?= e($canonical_self) ?>">
    <style>
        :root {
            --fg: #0f172a;
            --muted: #64748b;
            --accent: #0891b2;
            --border: #e2e8f0;
            --bg: #ffffff;
            --bg-soft: #f8fafb;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg-soft);
            color: var(--fg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.7;
            font-size: 16px;
        }
        .legal-shell { max-width: 820px; margin: 0 auto; padding: 0 20px; }
        .legal-topbar {
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            padding: 14px 0;
            margin-bottom: 32px;
        }
        .legal-topbar .inner { max-width: 820px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; gap: 14px; }
        .legal-topbar .brand { font-weight: 600; color: var(--fg); text-decoration: none; font-size: 15px; }
        .legal-topbar .back { font-size: 13px; color: var(--accent); text-decoration: none; }
        .legal-topbar .back:hover { text-decoration: underline; }
        .legal-paper {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 40px 48px;
            margin-bottom: 48px;
            box-shadow: 0 1px 2px rgba(15,23,42,0.04);
        }
        .legal-paper h1 { font-size: 28px; font-weight: 700; margin: 0 0 6px; line-height: 1.25; color: var(--fg); }
        .legal-paper .meta { color: var(--muted); font-size: 13px; margin-bottom: 28px; }
        .legal-paper h2 { font-size: 20px; font-weight: 600; margin: 32px 0 10px; color: var(--fg); }
        .legal-paper h3 { font-size: 16px; font-weight: 600; margin: 22px 0 8px; color: var(--fg); }
        .legal-paper p, .legal-paper li { font-size: 15px; }
        .legal-paper ul, .legal-paper ol { padding-left: 24px; }
        .legal-paper li { margin: 6px 0; }
        .legal-paper a { color: var(--accent); }
        .legal-paper strong { color: var(--fg); }
        .legal-paper table { border-collapse: collapse; width: 100%; margin: 18px 0; font-size: 14px; }
        .legal-paper th, .legal-paper td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
        .legal-paper th { background: #f1f5f9; font-weight: 600; }
        .legal-foot { text-align: center; color: var(--muted); font-size: 12px; padding: 0 0 36px; }
        .legal-foot a { color: var(--muted); }
        @media (max-width: 600px) {
            .legal-paper { padding: 24px 22px; border-radius: 8px; }
            .legal-paper h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
    <div class="legal-topbar">
        <div class="inner">
            <span class="brand"><?= e($site_name) ?></span>
            <?php if ($customer_url): ?>
                <a class="back" href="<?= e($customer_url) ?>">← Back to <?= e(preg_replace('#^https?://#', '', $customer_url)) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <main class="legal-shell">
        <article class="legal-paper">
            <h1><?= e($title) ?></h1>
            <?php if ($last_updated): ?>
                <div class="meta">Last updated: <?= e(format_date($last_updated)) ?></div>
            <?php endif; ?>
            <?= $body_html /* trusted — sanitised by Claude during generation */ ?>
        </article>

        <div class="legal-foot">
            <?php if ($customer_url): ?>
                <a href="<?= e($customer_url) ?>"><?= e($site_name) ?></a> &middot;
            <?php endif; ?>
            Document hosted by ContentAgent
        </div>
    </main>
</body>
</html>
