<?php
/**
 * Per-type fix generators for seo_issues rows (especially the pasted ones).
 *
 * Each generator returns:
 *   {
 *     success: bool,
 *     fix_type: 'snippet' | 'redirect' | 'meta_remove' | 'ai_rewrite' | 'sitemap_remove' | 'manual',
 *     title:    string  // short label
 *     summary:  string  // 1-2 sentence what this does
 *     preview:  string  // the actual code / patch / explanation
 *     language: string  // for the preview's syntax (html/php/twig/etc.)
 *     followup: ?string // CTA href if there's a downstream action (e.g. open AI Refresh)
 *   }
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/fix-generator.php';
require_once __DIR__ . '/haiku.php';

/**
 * Route an issue to the right generator based on its type.
 */
function seo_issue_generate_fix(PDO $db, array $issue, array $site): array
{
    $type = $issue['type'] ?? '';
    return match ($type) {
        'not_found_404'           => seo_fix_404($issue, $site),
        'noindex_blocked'         => seo_fix_noindex($issue, $site),
        'duplicate_no_canonical'  => seo_fix_canonical($issue, $site),
        'blocked_by_robots'       => seo_fix_robots($issue, $site, $db),
        'soft_404',
        'crawled_not_indexed',
        'discovered_not_indexed'  => seo_fix_thin_content($issue, $site),
        'redirect_error'          => seo_fix_redirect_error($issue, $site),
        'server_error_5xx'        => seo_fix_server_error($issue, $site),
        'mobile_usability'        => seo_fix_mobile($issue, $site),
        default                   => seo_fix_manual($issue, $site),
    };
}

// ── 404 — 301 redirect or sitemap removal ─────────────────────

function seo_fix_404(array $issue, array $site): array
{
    $url = $issue['url'];
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $info = fix_get_platform_info($site);
    $lang = $info['language'];
    $domain = 'https://' . preg_replace('#^https?://#i', '', $site['domain']);

    // Suggest two options: 301 to the homepage (safe default), or sitemap removal.
    $htaccess = "# Redirect dead URL to the homepage\nRedirect 301 {$path} /\n";

    $php_redirect = "<?php\n// Place at the top of the file that handles this URL\nif (\$_SERVER['REQUEST_URI'] === " . var_export($path, true) . ") {\n    header('Location: /', true, 301);\n    exit;\n}\n";

    $preview = "OPTION A — .htaccess (Apache):\n\n{$htaccess}\nOPTION B — PHP at the top of the routing file:\n\n{$php_redirect}\nOPTION C — If this URL was retired on purpose, remove it from sitemap.xml so Google stops checking.";

    return [
        'success'  => true,
        'fix_type' => 'redirect',
        'title'    => '301 redirect for 404',
        'summary'  => 'Send the dead URL to a live destination so Google can drop the 404 and pass any link equity onward.',
        'preview'  => $preview,
        'language' => $lang === 'php' ? 'php' : 'apacheconf',
        'followup' => null,
    ];
}

// ── noindex — remove the meta tag or remove from sitemap ──────

function seo_fix_noindex(array $issue, array $site): array
{
    $url = $issue['url'];
    $path = parse_url($url, PHP_URL_PATH) ?: '/';

    $preview = "WHY THIS HAPPENED\nThis URL has <meta name=\"robots\" content=\"noindex\"> set, OR is blocked via X-Robots-Tag header.\n\nFIX — pick ONE:\n\n1. If this page SHOULD be indexed: open the template that renders {$path} and remove the noindex tag. Common locations:\n   - <head> tag in the template\n   - SEO plugin settings (Yoast, RankMath, OpenCart SEO module)\n   - Server headers (check .htaccess / nginx config for X-Robots-Tag)\n\n2. If this page SHOULD STAY noindexed (admin/draft/test): just remove this URL from sitemap.xml so Google stops listing it as a problem.\n\n3. After fixing, request re-indexing in Search Console > URL Inspection.";

    return [
        'success'  => true,
        'fix_type' => 'meta_remove',
        'title'    => 'Remove noindex tag',
        'summary'  => 'Find and remove the noindex meta tag (or remove from sitemap if intentional).',
        'preview'  => $preview,
        'language' => 'text',
        'followup' => null,
    ];
}

// ── Duplicate without canonical — emit the canonical snippet ───

function seo_fix_canonical(array $issue, array $site): array
{
    $url = $issue['url'];
    $clean_url = strtok($url, '?#'); // strip query + hash → recommended canonical
    $info = fix_get_platform_info($site);
    $lang = $info['language'];

    // Per-language snippet
    $html  = "<link rel=\"canonical\" href=\"{$clean_url}\" />";
    $php   = "<link rel=\"canonical\" href=\"<?= htmlspecialchars('{$clean_url}', ENT_QUOTES, 'UTF-8') ?>\" />";
    $twig  = "<link rel=\"canonical\" href=\"{{ '{$clean_url}'|escape }}\" />";
    $liquid = "<link rel=\"canonical\" href=\"{$clean_url}\" />";

    $snippet = match ($lang) {
        'php'    => $php,
        'twig'   => $twig,
        'liquid' => $liquid,
        default  => $html,
    };

    $preview = "Add this inside <head> on the page that renders {$url}\n(or update your SEO plugin to set canonical = {$clean_url}):\n\n{$snippet}\n\nWHY: the query string / tracking params confuse Google. The canonical points it at the clean URL as the indexable version.";

    return [
        'success'  => true,
        'fix_type' => 'snippet',
        'title'    => 'Canonical tag',
        'summary'  => 'Tell Google which version of this URL is the primary, deduplicating tracking-param variants.',
        'preview'  => $preview,
        'language' => $lang,
        'followup' => null,
    ];
}

// ── Blocked by robots — patch robots.txt ───────────────────────

function seo_fix_robots(array $issue, array $site, PDO $db): array
{
    $url = $issue['url'];
    $path = parse_url($url, PHP_URL_PATH) ?: '/';

    // Reuse the existing robots generator for a safe template,
    // but also explain the specific Disallow likely blocking this URL.
    $result = fix_generate_robots($site);
    $generated = $result['content'] ?? '';

    $preview = "This URL is blocked by a Disallow rule in robots.txt.\n\n1. Open your current robots.txt and look for a Disallow line whose pattern matches: {$path}\n2. Delete or narrow that line.\n3. Make sure the file STILL keeps these (good defaults):\n\n--- recommended robots.txt ---\n{$generated}";

    return [
        'success'  => true,
        'fix_type' => 'snippet',
        'title'    => 'Robots.txt patch',
        'summary'  => 'Remove the Disallow rule blocking this URL; keep the rest of robots.txt intact.',
        'preview'  => $preview,
        'language' => 'text',
        'followup' => null,
    ];
}

// ── Thin / not-indexed content → AI Refresh ────────────────────

function seo_fix_thin_content(array $issue, array $site): array
{
    $url = $issue['url'];

    // Try to find the matching post by slug
    $slug = null;
    if (preg_match('#/blog/([^/?#]+)/?$#i', $url, $m)) {
        $slug = $m[1];
    }

    $followup = null;
    if ($slug) {
        $followup = url('/dashboard/posts.php?site=' . (int)$site['id']) . '#slug-' . urlencode($slug);
    }

    $preview = "Google crawled this URL but chose not to index it. That's a quality signal — Google thinks the content is too thin, generic, or duplicates other pages.\n\nFIX — pick the strongest:\n\n1. Run Smart AI Refresh on this post (rewrites the body with stronger angle, internal links, and fixes for low-CTR queries from GSC).\n2. Add genuinely new value: a unique example, an original screenshot, your own data, a quote from someone in your industry.\n3. Improve internal linking — pages that no other page on your site links to look orphaned to Google.\n4. After improvements, request re-indexing in Search Console.";

    return [
        'success'  => true,
        'fix_type' => 'ai_rewrite',
        'title'    => 'AI Refresh + content depth',
        'summary'  => 'Rewrite the post with stronger angle, real examples, and internal links so Google reconsiders.',
        'preview'  => $preview,
        'language' => 'text',
        'followup' => $followup,
    ];
}

// ── Redirect error ─────────────────────────────────────────────

function seo_fix_redirect_error(array $issue, array $site): array
{
    $url = $issue['url'];
    $path = parse_url($url, PHP_URL_PATH) ?: '/';

    $preview = "Google followed redirects from {$url} and got confused — likely causes:\n\n1. Redirect chain (A → B → C). Collapse to a single hop A → C.\n2. Redirect loop (A → B → A). Pick one canonical.\n3. The destination 404s.\n\nHOW TO FIND IT:\n\ncurl -sI -L -o /dev/null -w \"%{http_code} %{url_effective}\\n\" {$url}\n\nIf you see two or more redirects before a 200, that's the chain. Edit .htaccess / your routing layer so {$path} 301s directly to the final destination.";

    return [
        'success'  => true,
        'fix_type' => 'manual',
        'title'    => 'Untangle redirect',
        'summary'  => 'Trace the redirect chain and collapse to a single 301.',
        'preview'  => $preview,
        'language' => 'bash',
        'followup' => null,
    ];
}

// ── 5xx ─────────────────────────────────────────────────────────

function seo_fix_server_error(array $issue, array $site): array
{
    $url = $issue['url'];
    $preview = "Google saw a 5xx when fetching {$url}. Common causes:\n\n1. PHP error / timeout — check error logs around the timestamp Google reported.\n2. Database connection refused — check uptime of the DB host.\n3. Out-of-memory — increase memory_limit or fix a memory leak in the request handler.\n4. CDN / origin mismatch — check that the CDN passes the right Host header.\n\nQUICK CHECK from your dev machine:\ncurl -sI {$url}\n\nIf it returns 5xx for you too, the bug is reproducible — fix it. If it returns 200 for you, the issue was transient — request re-indexing in Search Console and move on.";

    return [
        'success'  => true,
        'fix_type' => 'manual',
        'title'    => 'Diagnose 5xx',
        'summary'  => 'Check logs for the URL\'s timestamp; if it 5xxs for you now, fix the root cause.',
        'preview'  => $preview,
        'language' => 'bash',
        'followup' => null,
    ];
}

// ── Mobile usability ───────────────────────────────────────────

function seo_fix_mobile(array $issue, array $site): array
{
    $info = fix_get_platform_info($site);
    $lang = $info['language'];

    $viewport = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $preview = "Common mobile usability fixes:\n\n1. Missing viewport meta — add this to <head>:\n   {$viewport}\n\n2. Tap targets too close — make buttons at least 48x48px with 8px gap.\n3. Font size too small — body text 16px minimum.\n4. Horizontal scroll — find the element wider than the viewport (Chrome DevTools > Mobile view > scroll right). Often a table or hardcoded width.\n\nRun the page through PageSpeed Insights to confirm: https://pagespeed.web.dev/?url=" . urlencode($issue['url']);

    return [
        'success'  => true,
        'fix_type' => 'snippet',
        'title'    => 'Mobile usability fixes',
        'summary'  => 'Add viewport, fix tap targets, ensure 16px+ body text.',
        'preview'  => $preview,
        'language' => $lang === 'php' ? 'php' : 'html',
        'followup' => null,
    ];
}

// ── Fallback ───────────────────────────────────────────────────

function seo_fix_manual(array $issue, array $site): array
{
    return [
        'success'  => true,
        'fix_type' => 'manual',
        'title'    => 'Manual fix needed',
        'summary'  => 'No automated fix template — investigate in Search Console.',
        'preview'  => ($issue['suggested_fix'] ?? '') . "\n\nOpen Search Console > URL Inspection on {$issue['url']} for Google's exact diagnostic.",
        'language' => 'text',
        'followup' => null,
    ];
}
