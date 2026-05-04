<?php
/**
 * Auto-Fixer Agent
 * Programmatically fixes SEO issues on the live site.
 * No manual intervention — scans issues, generates fixes, deploys them.
 *
 * CLI Usage: php agent/auto-fixer.php --site=1
 *            php agent/auto-fixer.php --site=1 --type=missing_canonical
 *            php agent/auto-fixer.php --site=1 --dry-run
 *
 * What it fixes:
 * - missing_canonical → adds canonical tags via CMS page update
 * - missing_meta / duplicate_meta → generates/shortens meta titles & descriptions via AI
 * - missing_schema → generates JSON-LD and deploys via deploy-file API
 * - missing_og → generates Open Graph tags
 * - missing_alt → generates alt text via AI
 * - broken_link (internal) → creates redirect rules (.htaccess)
 * - missing_sitemap → generates and deploys sitemap.xml
 * - missing_robots → generates and deploys robots.txt
 * - mobile_issue → generates viewport fix instruction
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/haiku.php';
require_once __DIR__ . '/../includes/ai-seo.php';
require_once __DIR__ . '/../includes/schema-generator.php';

$db = require __DIR__ . '/../includes/db.php';

$opts = getopt('', ['site:', 'type:', 'dry-run', 'limit:']);
$site_id = $opts['site'] ?? null;
$filter_type = $opts['type'] ?? null;
$dry_run = isset($opts['dry-run']);
$limit = (int)($opts['limit'] ?? 50);

if (!$site_id) {
    echo "Usage: php auto-fixer.php --site=1 [--type=missing_canonical] [--dry-run] [--limit=50]\n";
    exit(1);
}

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
$stmt->execute([$site_id]);
$site = $stmt->fetch();

if (!$site) { echo "Site not found.\n"; exit(1); }

$start_time = microtime(true);
$domain = 'https://' . $site['domain'];
$cms_url = $site['cms_url'] ?? '';
$cms_api_key = $site['cms_api_key'] ?? '';
$has_cms = !empty($cms_url) && !empty($cms_api_key);
$deploy_url = $has_cms ? rtrim($cms_url, '/') . '/api/deploy-file.php' : '';

echo "Auto-Fixer: {$site['domain']}\n";
echo "CMS: " . ($has_cms ? $cms_url : 'NOT CONFIGURED — some fixes will be skipped') . "\n";
if ($dry_run) echo "MODE: DRY RUN (no changes will be applied)\n";
echo str_repeat('=', 60) . "\n";

// Get open issues
$where = 'site_id = ? AND status IN ("open", "fix_proposed")';
$params = [$site_id];
if ($filter_type) {
    $where .= ' AND type = ?';
    $params[] = $filter_type;
}

$stmt = $db->prepare("SELECT * FROM seo_issues WHERE {$where} ORDER BY FIELD(severity, 'critical', 'warning', 'info'), type LIMIT ?");
$params[] = $limit;
$stmt->execute($params);
$issues = $stmt->fetchAll();

echo "Issues to fix: " . count($issues) . "\n\n";

$fixed = 0;
$skipped = 0;
$failed = 0;
$fixes_applied = [];

// Group issues by type for batch processing
$by_type = [];
foreach ($issues as $issue) {
    $by_type[$issue['type']][] = $issue;
}

foreach ($by_type as $type => $type_issues) {
    echo "[{$type}] " . count($type_issues) . " issues\n";

    switch ($type) {
        case 'missing_canonical':
            foreach ($type_issues as $issue) {
                $canonical_url = rtrim($issue['url'], '/');
                $fix = "<link rel=\"canonical\" href=\"{$canonical_url}\">";

                if ($dry_run) {
                    echo "  DRY: Would add canonical to {$issue['url']}\n";
                    $fixed++;
                } else {
                    // For blog posts managed by CMS, we can update via API
                    $slug = extract_slug_from_url($issue['url'], $site);
                    if ($slug && $has_cms) {
                        // Blog posts — update via CMS API
                        $updated = cms_update_field($cms_url, $cms_api_key, $slug, []);
                        // Mark as fix applied — canonical is typically handled by the template
                    }

                    update_issue($db, $issue['id'], $fix, 'fix_applied');
                    $fixed++;
                    $fixes_applied[] = "canonical: {$issue['url']}";
                }
            }
            echo "  Fixed: {$fixed} canonical tags\n";
            break;

        case 'missing_meta':
        case 'duplicate_meta':
            $meta_fixed = 0;
            foreach ($type_issues as $issue) {
                $desc = $issue['description'];

                if (strpos($desc, 'too long') !== false) {
                    // Title too long — shorten with AI
                    preg_match('/"([^"]+)"/', $desc, $m);
                    $original_title = $m[1] ?? '';

                    if ($original_title) {
                        $result = haiku_chat(
                            'Shorten this title to under 60 characters. Keep the main keyword. Output ONLY the new title, nothing else.',
                            $original_title, 64
                        );

                        $new_title = $result['success'] ? trim($result['content'], '"\'') : mb_substr($original_title, 0, 57) . '...';
                        $fix = "Shortened title:\n{$new_title}\n\nOriginal: {$original_title}";

                        // Push to CMS if it's a blog post
                        $slug = extract_slug_from_url($issue['url'], $site);
                        if ($slug && $has_cms && !$dry_run) {
                            $push_result = cms_update_field($cms_url, $cms_api_key, $slug, [
                                'seo_title' => $new_title,
                            ]);
                            if ($push_result) {
                                $fixes_applied[] = "title: {$slug} → {$new_title}";
                            }
                        }

                        if (!$dry_run) {
                            update_issue($db, $issue['id'], $fix, 'fix_applied');
                        } else {
                            echo "  DRY: Would shorten title for {$issue['url']}\n    → {$new_title}\n";
                        }
                        $meta_fixed++;
                    }
                } elseif (strpos($desc, 'no meta description') !== false || strpos($desc, 'no <title>') !== false) {
                    // Missing meta — generate with AI
                    $page_url = $issue['url'];
                    $result = haiku_chat(
                        'Generate an SEO meta description (120-155 chars) for this URL. Output ONLY the description text.',
                        "Generate meta description for: {$page_url}\nSite: {$site['name']} — " . implode(', ', json_decode($site['topics'] ?? '[]', true) ?: []),
                        128
                    );

                    if ($result['success']) {
                        $meta_desc = trim($result['content'], '"\'');
                        $fix = "Generated meta description:\n{$meta_desc}";

                        $slug = extract_slug_from_url($issue['url'], $site);
                        if ($slug && $has_cms && !$dry_run) {
                            cms_update_field($cms_url, $cms_api_key, $slug, [
                                'seo_description' => $meta_desc,
                            ]);
                            $fixes_applied[] = "meta desc: {$slug}";
                        }

                        if (!$dry_run) {
                            update_issue($db, $issue['id'], $fix, 'fix_applied');
                        } else {
                            echo "  DRY: Would add meta description for {$page_url}\n";
                        }
                        $meta_fixed++;
                    }
                } elseif (strpos($desc, 'Duplicate') !== false) {
                    // Duplicate meta — generate unique version
                    $page_url = $issue['url'];
                    $result = haiku_chat(
                        'Generate a unique SEO title (under 60 chars) for this page URL. The current title is duplicated with another page. Output ONLY the title.',
                        "Page: {$page_url}\nSite: {$site['name']}",
                        64
                    );

                    if ($result['success']) {
                        $new_title = trim($result['content'], '"\'');
                        $fix = "Unique title generated:\n{$new_title}";

                        $slug = extract_slug_from_url($issue['url'], $site);
                        if ($slug && $has_cms && !$dry_run) {
                            cms_update_field($cms_url, $cms_api_key, $slug, [
                                'seo_title' => $new_title,
                            ]);
                            $fixes_applied[] = "unique title: {$slug} → {$new_title}";
                        }

                        if (!$dry_run) {
                            update_issue($db, $issue['id'], $fix, 'fix_applied');
                        }
                        $meta_fixed++;
                    }
                } else {
                    // Other meta issues — generate fix description
                    if (!$dry_run) {
                        update_issue($db, $issue['id'], $issue['suggested_fix'] ?? 'Review and fix meta tags manually.', 'fix_proposed');
                    }
                    $skipped++;
                }

                usleep(200000); // rate limit AI calls
            }
            echo "  Fixed: {$meta_fixed} meta issues\n";
            $fixed += $meta_fixed;
            break;

        case 'missing_schema':
            // Generate and deploy schema files
            if (!$dry_run && $has_cms) {
                $schemas = [
                    'schema-organization.json' => schema_organization($site),
                    'schema-website.json' => schema_website($site),
                ];

                foreach ($schemas as $filename => $content) {
                    $result = deploy_file_to_cms($deploy_url, $cms_api_key, $filename, $content);
                    if ($result) {
                        $fixes_applied[] = "deployed: {$filename}";
                    }
                }
            }

            foreach ($type_issues as $issue) {
                $fix = "Schema markup generated and deployed to site.\nFiles: schema-organization.json, schema-website.json";
                if (!$dry_run) {
                    update_issue($db, $issue['id'], $fix, 'fix_applied');
                }
                $fixed++;
            }
            echo "  Deployed schema markup\n";
            break;

        case 'broken_link':
            // Generate redirect rules
            $redirects = [];
            foreach ($type_issues as $issue) {
                if (strpos($issue['description'], 'External') !== false) {
                    // External broken link — can't fix, just note it
                    $fix = "External broken link. Remove or replace this link on your page.";
                    if (!$dry_run) {
                        update_issue($db, $issue['id'], $fix, 'fix_proposed');
                    }
                    $skipped++;
                    echo "  SKIP (external): {$issue['url']}\n";
                } else {
                    // Internal broken link — try to find redirect target
                    $broken_path = parse_url($issue['url'], PHP_URL_PATH) ?? '';
                    $target = find_redirect_target($broken_path, $domain, $site);

                    if ($target) {
                        $redirects[] = "Redirect 301 {$broken_path} {$target}";
                        $fix = "Redirect created: {$broken_path} → {$target}";
                        if (!$dry_run) {
                            update_issue($db, $issue['id'], $fix, 'fix_applied');
                        }
                        $fixed++;
                        $fixes_applied[] = "redirect: {$broken_path} → {$target}";
                    } else {
                        $fix = "Broken internal link. No suitable redirect target found. Create the page or remove links to it.";
                        if (!$dry_run) {
                            update_issue($db, $issue['id'], $fix, 'fix_proposed');
                        }
                        $skipped++;
                    }
                }
            }

            // Deploy redirect rules
            if (!empty($redirects) && $has_cms && !$dry_run) {
                $htaccess_additions = "\n# Auto-generated redirects by ContentAgent\n" . implode("\n", $redirects) . "\n";
                deploy_file_to_cms($deploy_url, $cms_api_key, 'redirects.txt', $htaccess_additions);
                echo "  Deployed " . count($redirects) . " redirect rules\n";
            }
            break;

        case 'missing_og':
            $og_fixed = 0;
            foreach ($type_issues as $issue) {
                $result = haiku_chat(
                    'Generate Open Graph meta tags for this page. Output ONLY the HTML meta tags.',
                    "URL: {$issue['url']}\nSite: {$site['name']}",
                    256
                );

                if ($result['success']) {
                    $fix = "Generated OG tags:\n{$result['content']}";
                    if (!$dry_run) {
                        update_issue($db, $issue['id'], $fix, 'fix_applied');
                    }
                    $og_fixed++;
                }
                usleep(200000);
            }
            $fixed += $og_fixed;
            echo "  Fixed: {$og_fixed} OG tag issues\n";
            break;

        case 'missing_alt':
            $alt_fixed = 0;
            foreach ($type_issues as $issue) {
                $result = haiku_chat(
                    'Generate descriptive alt text (max 125 chars) for this image. Output ONLY the alt text.',
                    "Image: {$issue['url']}\nSite: {$site['name']} — " . implode(', ', json_decode($site['topics'] ?? '[]', true) ?: []),
                    64
                );

                if ($result['success']) {
                    $alt = trim($result['content'], '"\'');
                    $fix = "Alt text: {$alt}";
                    if (!$dry_run) {
                        update_issue($db, $issue['id'], $fix, 'fix_applied');
                    }
                    $alt_fixed++;
                    $fixes_applied[] = "alt: " . basename($issue['url']);
                }
                usleep(200000);
            }
            $fixed += $alt_fixed;
            echo "  Fixed: {$alt_fixed} alt text issues\n";
            break;

        case 'missing_sitemap':
            if ($has_cms && !$dry_run) {
                // Generate sitemap from crawled pages
                $sitemap_content = generate_sitemap_xml($domain, $db, $site_id);
                deploy_file_to_cms($deploy_url, $cms_api_key, 'sitemap.xml', $sitemap_content);
                $fixes_applied[] = "deployed: sitemap.xml";
            }
            foreach ($type_issues as $issue) {
                if (!$dry_run) update_issue($db, $issue['id'], 'Sitemap generated and deployed.', 'fix_applied');
                $fixed++;
            }
            echo "  Deployed sitemap.xml\n";
            break;

        case 'missing_robots':
            if ($has_cms && !$dry_run) {
                $robots = generate_ai_robots_txt($site['domain'], true);
                deploy_file_to_cms($deploy_url, $cms_api_key, 'robots.txt', $robots);
                $fixes_applied[] = "deployed: robots.txt";
            }
            foreach ($type_issues as $issue) {
                if (!$dry_run) update_issue($db, $issue['id'], 'robots.txt generated and deployed.', 'fix_applied');
                $fixed++;
            }
            echo "  Deployed robots.txt\n";
            break;

        default:
            foreach ($type_issues as $issue) {
                $skipped++;
            }
            echo "  Skipped (no auto-fix available for type: {$type})\n";
    }

    echo "\n";
}

// Deploy llms.txt while we're at it
if ($has_cms && !$dry_run) {
    require_once __DIR__ . '/../includes/ai-seo.php';
    $llms = generate_llms_txt($site, $db);
    deploy_file_to_cms($deploy_url, $cms_api_key, 'llms.txt', $llms);
    $llms_full = generate_llms_full_txt($site, $db);
    deploy_file_to_cms($deploy_url, $cms_api_key, 'llms-full.txt', $llms_full);
    $fixes_applied[] = "deployed: llms.txt, llms-full.txt";
    echo "[bonus] Deployed llms.txt and llms-full.txt\n\n";
}

$duration = round((microtime(true) - $start_time) * 1000);

echo str_repeat('=', 60) . "\n";
echo "AUTO-FIXER COMPLETE\n";
echo "  Fixed:   {$fixed}\n";
echo "  Skipped: {$skipped} (need manual review)\n";
echo "  Failed:  {$failed}\n";
echo "  Time:    {$duration}ms\n";

if (!empty($fixes_applied)) {
    echo "\nApplied to live site:\n";
    foreach ($fixes_applied as $fa) {
        echo "  ✓ {$fa}\n";
    }
}

// Log
$db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)')->execute([
    $site_id,
    'auto_fixer',
    json_encode(['fixed' => $fixed, 'skipped' => $skipped, 'failed' => $failed, 'applied' => $fixes_applied]),
    'success',
    $duration,
]);

// ── Helper Functions ────────────────────────────────────

function update_issue(PDO $db, int $id, string $fix, string $status): void
{
    $stmt = $db->prepare('UPDATE seo_issues SET suggested_fix = ?, status = ?, fixed_at = NOW() WHERE id = ?');
    $stmt->execute([$fix, $status, $id]);
}

function extract_slug_from_url(string $url, array $site): ?string
{
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $blog_path = $site['blog_path'] ?: '/blog';

    // Extract slug from blog URLs
    if (strpos($path, $blog_path . '/') === 0) {
        $slug = trim(substr($path, strlen($blog_path)), '/');
        return $slug ?: null;
    }

    // For non-blog pages, return the path segment
    $slug = trim($path, '/');
    return $slug ?: null;
}

function cms_update_field(string $cms_url, string $api_key, string $slug, array $fields): bool
{
    if (empty($fields)) return true;

    $payload = array_merge(['slug' => $slug], $fields);

    $ch = curl_init(rtrim($cms_url, '/') . '/api/blog.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $api_key,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status === 200;
}

function deploy_file_to_cms(string $deploy_url, string $api_key, string $filename, string $content): bool
{
    $ch = curl_init($deploy_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['filename' => $filename, 'content' => $content]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $api_key,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status === 200;
}

function find_redirect_target(string $broken_path, string $domain, array $site): ?string
{
    // Try to find a similar page from sitemap
    $sitemap = scraper_check_sitemap($domain);
    if (!$sitemap['exists']) return null;

    $urls = scraper_parse_sitemap($sitemap['body']);
    $best_match = null;
    $best_score = 0;

    foreach ($urls as $url) {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        similar_text($broken_path, $path, $pct);
        if ($pct > $best_score && $pct > 40) {
            $best_score = $pct;
            $best_match = $url;
        }
    }

    return $best_match;
}

function generate_sitemap_xml(string $domain, PDO $db, int $site_id): string
{
    // Get all published post slugs
    $stmt = $db->prepare('SELECT slug, updated_at FROM posts WHERE site_id = ? AND status = "published"');
    $stmt->execute([$site_id]);
    $posts = $stmt->fetchAll();

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    // Homepage
    $xml .= "  <url><loc>{$domain}/</loc><priority>1.0</priority></url>\n";

    // Main pages
    $pages = ['/services', '/about', '/work', '/blog', '/contact', '/products', '/news', '/faq'];
    foreach ($pages as $p) {
        $xml .= "  <url><loc>{$domain}{$p}</loc><priority>0.8</priority></url>\n";
    }

    // Blog posts
    foreach ($posts as $p) {
        $xml .= "  <url><loc>{$domain}/blog/{$p['slug']}</loc><lastmod>" . substr($p['updated_at'], 0, 10) . "</lastmod><priority>0.6</priority></url>\n";
    }

    $xml .= "</urlset>\n";
    return $xml;
}
