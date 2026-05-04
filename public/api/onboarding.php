<?php
/**
 * Onboarding API — runs agents synchronously and returns results for the wizard.
 * Each action is a step in the onboarding flow.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/scraper.php';

auth_start();

if (!auth_check()) {
    json_response(['error' => 'Unauthorized'], 401);
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// ── Create Site ─────────────────────────────────────────
if ($action === 'create_site') {
    $url = trim($input['url'] ?? '');
    $name = trim($input['name'] ?? '');

    if (empty($url)) json_response(['error' => 'URL is required'], 400);

    $domain = preg_replace('/^https?:\/\//', '', $url);
    $domain = preg_replace('/^www\./', '', $domain);
    $domain = rtrim($domain, '/');
    $name = $name ?: $domain;

    // Check if site already exists
    $stmt = $db->prepare('SELECT id FROM sites WHERE domain = ? AND user_id = ?');
    $stmt->execute([$domain, $user_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        json_response(['site_id' => $existing['id'], 'domain' => $domain, 'existing' => true]);
    }

    $stmt = $db->prepare('INSERT INTO sites (user_id, name, domain) VALUES (?, ?, ?)');
    $stmt->execute([$user_id, $name, $domain]);

    json_response(['site_id' => $db->lastInsertId(), 'domain' => $domain]);
}

// ── Scan ────────────────────────────────────────────────
if ($action === 'scan') {
    $site_id = (int)($input['site_id'] ?? 0);

    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    $site = $stmt->fetch();
    if (!$site) json_response(['error' => 'Site not found'], 404);

    $domain = 'https://' . $site['domain'];

    // Fetch homepage
    $result = scraper_fetch($domain, 20);
    if ($result['error'] || $result['status'] >= 400) {
        // Try with www
        $domain = 'https://www.' . $site['domain'];
        $result = scraper_fetch($domain, 20);
    }

    if ($result['error'] || $result['status'] >= 400) {
        json_response(['success' => false, 'error' => 'Cannot reach site: ' . ($result['error'] ?: 'HTTP ' . $result['status'])]);
    }

    $html = $result['body'];
    $doc = scraper_parse_html($html);

    $title = scraper_get_title($doc);
    $platform = scraper_detect_platform($html, $result['headers']);
    $links = scraper_get_links($doc, $domain);
    $images = scraper_get_images($doc, $domain);
    $colors = scraper_extract_colors($html);
    $social = scraper_get_social_links($links);
    $internal = array_filter($links, fn($l) => $l['internal']);

    // Check SSL & sitemap
    $ssl = scraper_check_ssl($site['domain']);
    $sitemap = scraper_check_sitemap($domain);

    // Check for blog
    $blog_path = null;
    foreach (['/blog', '/news', '/articles'] as $bp) {
        $check = scraper_fetch($domain . $bp, 8);
        if ($check['status'] >= 200 && $check['status'] < 400) {
            $blog_path = $bp;
            break;
        }
    }

    // Detect theme name
    $theme_name = 'default';
    if ($platform === 'opencart' && preg_match('/catalog\/view\/theme\/([a-zA-Z0-9_-]+)\//', $html, $tm)) {
        $theme_name = $tm[1];
    } elseif ($platform === 'wordpress' && preg_match('/wp-content\/themes\/([a-zA-Z0-9_-]+)\//', $html, $tm)) {
        $theme_name = $tm[1];
    } elseif ($platform === 'shopify') {
        $theme_name = 'theme';
    }

    // Save to DB
    $stmt = $db->prepare('UPDATE sites SET platform = ?, theme_name = ?, brand_colors = ?, blog_path = ?, scanned_at = NOW() WHERE id = ?');
    $stmt->execute([$platform, $theme_name, json_encode(array_slice($colors, 0, 5)), $blog_path ?: '/blog', $site_id]);

    // Log
    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site_id, 'scan', json_encode(['platform' => $platform, 'links' => count($internal)]), 'success'
    ]);

    json_response([
        'success'        => true,
        'title'          => $title,
        'platform'       => $platform,
        'internal_links' => count($internal),
        'images'         => count($images),
        'colors'         => array_slice($colors, 0, 5),
        'social'         => $social,
        'blog_path'      => $blog_path,
        'ssl_valid'      => $ssl['valid'] ?? false,
        'sitemap'        => $sitemap['exists'],
        'status'         => $result['status'],
    ]);
}

// ── SEO Audit ───────────────────────────────────────────
if ($action === 'audit') {
    $site_id = (int)($input['site_id'] ?? 0);

    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    $site = $stmt->fetch();
    if (!$site) json_response(['error' => 'Site not found'], 404);

    // Run auditor via CLI (captures output)
    $php = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
    $script = realpath(__DIR__ . '/../../agent/seo-auditor.php');
    $cmd = "\"{$php}\" \"{$script}\" --site={$site_id} --max-pages=30 2>&1";

    $start = microtime(true);
    $output = shell_exec($cmd);
    $duration = round(microtime(true) - $start, 1);

    // Get latest audit results
    $stmt = $db->prepare('SELECT * FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
    $stmt->execute([$site_id]);
    $audit = $stmt->fetch();

    json_response([
        'success'  => true,
        'score'    => $audit ? (int)$audit['score'] : 0,
        'issues'   => $audit ? (int)$audit['total_issues'] : 0,
        'critical' => $audit ? (int)$audit['critical'] : 0,
        'warnings' => $audit ? (int)$audit['warnings'] : 0,
        'pages'    => $audit ? (int)$audit['pages_crawled'] : 0,
        'duration' => $duration,
        'audit_id' => $audit ? $audit['id'] : null,
    ]);
}

// ── Keywords ────────────────────────────────────────────
if ($action === 'keywords') {
    $site_id = (int)($input['site_id'] ?? 0);

    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    $site = $stmt->fetch();
    if (!$site) json_response(['error' => 'Site not found'], 404);

    // Run keyword research via CLI
    $php = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
    $script = realpath(__DIR__ . '/../../agent/keyword-research.php');
    $seed = $site['domain'];
    $cmd = "\"{$php}\" \"{$script}\" --site={$site_id} 2>&1";

    shell_exec($cmd);

    // Get results
    $stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ?');
    $stmt->execute([$site_id]);
    $total = $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(DISTINCT cluster) FROM keywords WHERE site_id = ? AND cluster IS NOT NULL');
    $stmt->execute([$site_id]);
    $clusters = $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT keyword FROM keywords WHERE site_id = ? ORDER BY priority DESC LIMIT 8');
    $stmt->execute([$site_id]);
    $samples = $stmt->fetchAll(PDO::FETCH_COLUMN);

    json_response([
        'success'  => true,
        'total'    => (int)$total,
        'clusters' => (int)$clusters,
        'samples'  => $samples,
    ]);
}

// ── Content Plan ────────────────────────────────────────
if ($action === 'content_plan') {
    $site_id = (int)($input['site_id'] ?? 0);

    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    $site = $stmt->fetch();
    if (!$site) json_response(['error' => 'Site not found'], 404);

    require_once __DIR__ . '/../../includes/haiku.php';

    // Get keywords
    $stmt = $db->prepare('SELECT keyword FROM keywords WHERE site_id = ? ORDER BY priority DESC LIMIT 15');
    $stmt->execute([$site_id]);
    $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];

    if (empty(config('haiku_api_key'))) {
        // Fallback: keyword-based topics
        $fallback = [];
        foreach (array_slice($keywords, 0, 4) as $kw) {
            $fallback[] = ['title' => ucwords($kw), 'description' => "Write about {$kw}", 'keywords' => [$kw]];
        }
        json_response(['success' => true, 'topics' => $fallback]);
    }

    $result = haiku_chat(
        "You are a content strategist. Propose 4 blog topics for {$site['name']} ({$site['domain']}). Year: " . date('Y') . ". Output ONLY valid JSON array: [{\"title\": \"...\", \"description\": \"...\", \"keywords\": [\"...\"]}]",
        "Business topics: " . implode(', ', $topics) . "\nKeywords: " . implode(', ', array_slice($keywords, 0, 10)),
        1024
    );

    if ($result['success']) {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $result['content']);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $parsed = json_decode(trim($content), true);
        if (!$parsed && preg_match('/\[[\s\S]*\]/m', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
        if ($parsed) {
            json_response(['success' => true, 'topics' => $parsed]);
        }
    }

    // Fallback
    $fallback = [];
    foreach (array_slice($keywords, 0, 4) as $kw) {
        $fallback[] = ['title' => ucwords($kw), 'description' => "Write about {$kw}", 'keywords' => [$kw]];
    }
    json_response(['success' => true, 'topics' => $fallback]);
}

json_response(['error' => 'Invalid action'], 400);
