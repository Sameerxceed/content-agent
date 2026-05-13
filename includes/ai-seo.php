<?php
/**
 * AI-era SEO: llms.txt, AI crawler management, and modern discoverability.
 *
 * Covers:
 * - /llms.txt generation (AI model discoverability)
 * - AI bot access management (GPTBot, ClaudeBot, etc.)
 * - robots.txt AI-specific rules
 * - Structured data validation for AI consumption
 * - Content accessibility scoring for LLMs
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/scraper.php';

// Known AI crawlers
define('AI_CRAWLERS', [
    'GPTBot'           => ['owner' => 'OpenAI', 'purpose' => 'Training & ChatGPT web browsing'],
    'ChatGPT-User'     => ['owner' => 'OpenAI', 'purpose' => 'ChatGPT real-time browsing'],
    'Google-Extended'   => ['owner' => 'Google', 'purpose' => 'Gemini AI training'],
    'Googlebot'        => ['owner' => 'Google', 'purpose' => 'Search indexing + AI'],
    'ClaudeBot'        => ['owner' => 'Anthropic', 'purpose' => 'Claude training data'],
    'anthropic-ai'     => ['owner' => 'Anthropic', 'purpose' => 'Claude training data'],
    'CCBot'            => ['owner' => 'Common Crawl', 'purpose' => 'Open dataset (used by many AI)'],
    'PerplexityBot'    => ['owner' => 'Perplexity', 'purpose' => 'AI search engine'],
    'Bytespider'       => ['owner' => 'ByteDance', 'purpose' => 'TikTok/Douyin AI'],
    'cohere-ai'        => ['owner' => 'Cohere', 'purpose' => 'AI model training'],
    'Meta-ExternalAgent' => ['owner' => 'Meta', 'purpose' => 'Llama AI training'],
    'Applebot-Extended' => ['owner' => 'Apple', 'purpose' => 'Apple Intelligence training'],
]);

/**
 * Generate /llms.txt content for a site.
 * llms.txt is a proposed standard for helping AI models understand a website.
 * See: https://llmstxt.org/
 */
function generate_llms_txt(array $site, PDO $db): string
{
    $domain = $site['domain'];
    $name = $site['name'];
    $tone = $site['brand_tone'] ?? '';
    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];

    // Get published posts
    $stmt = $db->prepare('SELECT title, slug, excerpt, type FROM posts WHERE site_id = ? AND status = "published" ORDER BY published_at DESC LIMIT 20');
    $stmt->execute([$site['id']]);
    $posts = $stmt->fetchAll();

    $blog_path = $site['blog_path'] ?: '/blog';

    $txt = "# {$name}\n\n";
    $txt .= "> {$tone}\n\n" ;

    if (!empty($topics)) {
        $txt .= "## Topics\n\n";
        foreach ($topics as $t) {
            $txt .= "- {$t}\n";
        }
        $txt .= "\n";
    }

    $txt .= "## Links\n\n";
    $txt .= "- [Homepage](https://{$domain})\n";
    $txt .= "- [Blog](https://{$domain}{$blog_path})\n";
    $txt .= "- [Contact](https://{$domain}/contact)\n\n";

    if (!empty($posts)) {
        $txt .= "## Recent Articles\n\n";
        foreach ($posts as $p) {
            $txt .= "- [{$p['title']}](https://{$domain}{$blog_path}/{$p['slug']}): {$p['excerpt']}\n";
        }
        $txt .= "\n";
    }

    return $txt;
}

/**
 * Generate /llms-full.txt — extended version with more context.
 */
function generate_llms_full_txt(array $site, PDO $db): string
{
    $txt = generate_llms_txt($site, $db);

    // Add keywords context
    $stmt = $db->prepare("SELECT keyword, cluster FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY priority DESC LIMIT 30");
    $stmt->execute([$site['id']]);
    $keywords = $stmt->fetchAll();

    if (!empty($keywords)) {
        $txt .= "## Expertise Areas (Keywords)\n\n";
        $clusters = [];
        foreach ($keywords as $kw) {
            $c = $kw['cluster'] ?: 'General';
            $clusters[$c][] = $kw['keyword'];
        }
        foreach ($clusters as $cluster => $kws) {
            $txt .= "### {$cluster}\n";
            foreach ($kws as $kw) {
                $txt .= "- {$kw}\n";
            }
            $txt .= "\n";
        }
    }

    return $txt;
}

/**
 * Regenerate and deploy llms.txt for a site after content changes.
 * Attempts CMS API deploy first, falls back to logging for manual deploy.
 */
function regenerate_llms_txt(array $site, PDO $db): array
{
    $llms = generate_llms_txt($site, $db);
    $llms_full = generate_llms_full_txt($site, $db);
    $deployed = [];
    $errors = [];

    $cms_url = $site['cms_url'] ?? '';
    $cms_api_key = $site['cms_api_key'] ?? '';

    if (!empty($cms_url) && !empty($cms_api_key)) {
        $deploy_url = rtrim($cms_url, '/') . '/api/deploy-file.php';

        foreach (['llms.txt' => $llms, 'llms-full.txt' => $llms_full] as $filename => $content) {
            $ch = curl_init($deploy_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['filename' => $filename, 'content' => $content]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $cms_api_key],
                CURLOPT_TIMEOUT => 10,
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status === 200) {
                $deployed[] = $filename;
            } else {
                $errors[] = $filename . ': HTTP ' . $status;
            }
        }
    }

    // Log the regeneration
    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site['id'],
        'regenerate_llms',
        json_encode(['deployed' => $deployed, 'errors' => $errors, 'llms_size' => strlen($llms)]),
        !empty($deployed) ? 'success' : (empty($cms_url) ? 'skipped' : 'fail'),
    ]);

    return ['deployed' => $deployed, 'errors' => $errors, 'content' => $llms];
}

/**
 * Audit a site's AI discoverability.
 * Returns a list of checks with pass/fail and recommendations.
 */
function audit_ai_discoverability(string $domain): array
{
    $base = 'https://' . ltrim($domain, 'https://');
    $base = rtrim($base, '/');
    $results = [];

    // 1. Check /llms.txt
    $llms = scraper_fetch($base . '/llms.txt', 10);
    $results[] = [
        'check'  => 'llms.txt',
        'status' => $llms['status'] === 200 ? 'pass' : 'missing',
        'detail' => $llms['status'] === 200
            ? 'llms.txt found (' . strlen($llms['body']) . ' bytes)'
            : 'No /llms.txt file. AI models cannot discover your site content efficiently.',
        'fix'    => $llms['status'] !== 200
            ? 'Create a /llms.txt file describing your site, topics, and key pages. ContentAgent can generate this for you.'
            : null,
    ];

    // 2. Check robots.txt for AI bot rules
    $robots = scraper_fetch($base . '/robots.txt', 10);
    $robots_body = $robots['status'] === 200 ? $robots['body'] : '';

    $ai_bot_status = [];
    foreach (AI_CRAWLERS as $bot => $info) {
        $blocked = false;
        if (preg_match('/User-agent:\s*' . preg_quote($bot, '/') . '\s*\n\s*Disallow:\s*\/\s*$/mi', $robots_body)) {
            $blocked = true;
        }
        $ai_bot_status[$bot] = [
            'blocked' => $blocked,
            'owner'   => $info['owner'],
            'purpose' => $info['purpose'],
        ];
    }

    $blocked_bots = array_filter($ai_bot_status, fn($b) => $b['blocked']);
    $allowed_bots = array_filter($ai_bot_status, fn($b) => !$b['blocked']);

    $results[] = [
        'check'  => 'AI Crawler Access',
        'status' => empty($blocked_bots) ? 'pass' : 'warning',
        'detail' => count($allowed_bots) . '/' . count(AI_CRAWLERS) . ' AI crawlers allowed. '
            . (empty($blocked_bots) ? '' : 'Blocked: ' . implode(', ', array_keys($blocked_bots))),
        'fix'    => !empty($blocked_bots)
            ? 'Consider allowing AI crawlers for better discoverability. Blocked bots: ' . implode(', ', array_keys($blocked_bots))
            : null,
        'bots'   => $ai_bot_status,
    ];

    // 3. Check for structured data
    $homepage = scraper_fetch($base, 15);
    $has_schema = false;
    $schema_types = [];

    if ($homepage['status'] === 200) {
        $doc = scraper_parse_html($homepage['body']);
        $schemas = scraper_get_schema($doc);
        $has_schema = !empty($schemas);
        foreach ($schemas as $s) {
            $schema_types[] = $s['@type'] ?? 'Unknown';
        }
    }

    $results[] = [
        'check'  => 'Structured Data (Schema.org)',
        'status' => $has_schema ? 'pass' : 'missing',
        'detail' => $has_schema
            ? 'Found: ' . implode(', ', $schema_types)
            : 'No JSON-LD structured data on homepage. AI models and search engines cannot understand your page structure.',
        'fix'    => !$has_schema
            ? 'Add Organization, WebSite, and BreadcrumbList schema. ContentAgent can generate these for you.'
            : null,
    ];

    // 4. Check for sitemap (AI crawlers use this)
    $sitemap = scraper_check_sitemap($base);
    $results[] = [
        'check'  => 'XML Sitemap',
        'status' => $sitemap['exists'] ? 'pass' : 'missing',
        'detail' => $sitemap['exists']
            ? 'Sitemap found at ' . $sitemap['url']
            : 'No sitemap.xml found. Both search engines and AI crawlers rely on this.',
        'fix'    => !$sitemap['exists']
            ? 'Create a sitemap.xml listing all important pages.'
            : null,
    ];

    // 5. Check meta descriptions (AI uses these for context)
    if ($homepage['status'] === 200) {
        $doc = scraper_parse_html($homepage['body']);
        $meta = scraper_get_meta($doc);
        $has_desc = !empty($meta['description']);

        $results[] = [
            'check'  => 'Meta Description',
            'status' => $has_desc ? 'pass' : 'missing',
            'detail' => $has_desc
                ? 'Meta description found (' . mb_strlen($meta['description']) . ' chars)'
                : 'No meta description. AI models use this to understand page context.',
            'fix'    => !$has_desc ? 'Add a concise meta description (120-160 chars).' : null,
        ];
    }

    // 6. Check heading structure (H1, H2s — AI parses these)
    if ($homepage['status'] === 200) {
        $headings = scraper_get_headings($doc);
        $h1_count = count(array_filter($headings, fn($h) => $h['level'] === 1));
        $h2_count = count(array_filter($headings, fn($h) => $h['level'] === 2));

        $heading_ok = $h1_count === 1 && $h2_count >= 2;
        $results[] = [
            'check'  => 'Heading Structure',
            'status' => $heading_ok ? 'pass' : 'warning',
            'detail' => "{$h1_count} H1, {$h2_count} H2 tags. " . ($h1_count !== 1 ? 'Should have exactly 1 H1. ' : '') . ($h2_count < 2 ? 'Add more H2 sections for better content structure.' : ''),
            'fix'    => !$heading_ok ? 'Use exactly 1 H1 tag and multiple H2/H3 tags to structure content hierarchically.' : null,
        ];
    }

    // 7. Check Open Graph (AI-powered social platforms use this)
    if ($homepage['status'] === 200) {
        $has_og = !empty($meta['og:title']) && !empty($meta['og:description']);
        $results[] = [
            'check'  => 'Open Graph Tags',
            'status' => $has_og ? 'pass' : 'missing',
            'detail' => $has_og
                ? 'OG tags present (title + description)'
                : 'Missing Open Graph tags. AI-powered social platforms and link previews need these.',
            'fix'    => !$has_og ? 'Add og:title, og:description, og:image, og:url tags.' : null,
        ];
    }

    // 8. Check for clean, readable content (not JS-rendered)
    if ($homepage['status'] === 200) {
        $text = scraper_get_text($doc);
        $text_length = mb_strlen($text);
        $readable = $text_length > 500;

        $results[] = [
            'check'  => 'Content Accessibility',
            'status' => $readable ? 'pass' : 'warning',
            'detail' => $readable
                ? "Page has {$text_length} chars of readable text (good for crawlers)"
                : "Only {$text_length} chars of text found. If content is JS-rendered, AI crawlers may not see it.",
            'fix'    => !$readable
                ? 'Ensure key content is in the initial HTML (server-side rendered), not loaded via JavaScript.'
                : null,
        ];
    }

    // Calculate score
    $total = count($results);
    $passed = count(array_filter($results, fn($r) => $r['status'] === 'pass'));
    $score = $total > 0 ? round(($passed / $total) * 100) : 0;

    return [
        'score'   => $score,
        'total'   => $total,
        'passed'  => $passed,
        'results' => $results,
    ];
}

/**
 * Generate recommended robots.txt with AI crawler rules.
 */
function generate_ai_robots_txt(string $domain, bool $allow_all_ai = true): string
{
    $sitemap_url = "https://{$domain}/sitemap.xml";

    $txt = "# robots.txt for {$domain}\n";
    $txt .= "# Generated by ContentAgent\n\n";

    // Default: allow all
    $txt .= "User-agent: *\n";
    $txt .= "Allow: /\n";
    $txt .= "Disallow: /admin/\n";
    $txt .= "Disallow: /api/\n";
    $txt .= "Disallow: /dashboard/\n\n";

    if ($allow_all_ai) {
        $txt .= "# AI Crawlers — Allowed for discoverability\n";
        foreach (AI_CRAWLERS as $bot => $info) {
            $txt .= "# {$bot} ({$info['owner']} — {$info['purpose']})\n";
            $txt .= "User-agent: {$bot}\n";
            $txt .= "Allow: /\n\n";
        }
    } else {
        $txt .= "# AI Crawlers — Blocked (opt-out of AI training)\n";
        foreach (AI_CRAWLERS as $bot => $info) {
            $txt .= "User-agent: {$bot}\n";
            $txt .= "Disallow: /\n\n";
        }
    }

    $txt .= "Sitemap: {$sitemap_url}\n";

    return $txt;
}

/**
 * Check which AI crawlers are currently blocked/allowed.
 */
function check_ai_crawler_access(string $domain): array
{
    $base = 'https://' . ltrim($domain, 'https://');
    $robots = scraper_fetch(rtrim($base, '/') . '/robots.txt', 10);

    if ($robots['status'] !== 200) {
        return ['has_robots' => false, 'bots' => []];
    }

    $body = $robots['body'];
    $bots = [];

    foreach (AI_CRAWLERS as $bot => $info) {
        $blocked = false;

        // Check for specific block
        if (preg_match('/User-agent:\s*' . preg_quote($bot, '/') . '\s*\n\s*Disallow:\s*\/\s*$/mi', $body)) {
            $blocked = true;
        }

        // Check for wildcard block
        if (preg_match('/User-agent:\s*\*\s*\n\s*Disallow:\s*\/\s*$/mi', $body)) {
            // Wildcard blocks everyone unless specifically allowed
            if (!preg_match('/User-agent:\s*' . preg_quote($bot, '/') . '\s*\n\s*Allow:/mi', $body)) {
                $blocked = true;
            }
        }

        $bots[$bot] = [
            'blocked' => $blocked,
            'owner'   => $info['owner'],
            'purpose' => $info['purpose'],
        ];
    }

    return ['has_robots' => true, 'bots' => $bots];
}
