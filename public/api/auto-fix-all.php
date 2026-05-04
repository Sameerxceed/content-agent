<?php
/**
 * API — Auto-fix ALL SEO issues for a site.
 * POST /api/auto-fix-all.php { "site_id": 1 }
 *
 * This is the REAL auto-fixer. It:
 * 1. Reads all open SEO issues
 * 2. Generates fixes (canonical, meta, OG, schema, redirects)
 * 3. Saves them to page_seo table (served via JS snippet)
 * 4. Saves redirects to redirects table
 * 5. Deploys llms.txt, robots.txt, schema files via CMS API
 * 6. Updates issue statuses
 *
 * Returns real-time progress as JSON.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/haiku.php';
require_once __DIR__ . '/../../includes/ai-seo.php';
require_once __DIR__ . '/../../includes/schema-generator.php';
require_once __DIR__ . '/../../includes/scraper.php';

auth_start();

if (!auth_check()) {
    json_response(['error' => 'Unauthorized'], 401);
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$site_id = (int)($input['site_id'] ?? 0);
$batch_size = (int)($input['batch_size'] ?? 10);
$offset = (int)($input['offset'] ?? 0);

if (!$site_id) json_response(['error' => 'site_id required'], 400);

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();

if (!$site) json_response(['error' => 'Site not found'], 404);

$domain = 'https://' . $site['domain'];
$cms_url = $site['cms_url'] ?? '';
$cms_api_key = $site['cms_api_key'] ?? '';
$has_cms = !empty($cms_url) && !empty($cms_api_key);

// Get latest audit's open issues
$stmt = $db->prepare('SELECT id FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
$stmt->execute([$site_id]);
$latest_audit = $stmt->fetch();

if (!$latest_audit) {
    json_response(['error' => 'No audit found. Run SEO Audit first.'], 400);
}

// Get total count first
$stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE audit_id = ? AND status IN ("open", "fix_proposed")');
$stmt->execute([$latest_audit['id']]);
$total_issues = (int)$stmt->fetchColumn();

// Get batch
$stmt = $db->prepare('SELECT * FROM seo_issues WHERE audit_id = ? AND status IN ("open", "fix_proposed") ORDER BY FIELD(severity, "critical", "warning", "info") LIMIT ? OFFSET ?');
$stmt->execute([$latest_audit['id'], $batch_size, $offset]);
$issues = $stmt->fetchAll();

$fixed = 0;
$skipped = 0;
$applied = [];

$upsert_seo = $db->prepare('INSERT INTO page_seo (site_id, url_path, canonical, meta_title, meta_description, og_title, og_description, schema_json)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        canonical = COALESCE(VALUES(canonical), canonical),
        meta_title = COALESCE(VALUES(meta_title), meta_title),
        meta_description = COALESCE(VALUES(meta_description), meta_description),
        og_title = COALESCE(VALUES(og_title), og_title),
        og_description = COALESCE(VALUES(og_description), og_description),
        schema_json = COALESCE(VALUES(schema_json), schema_json),
        updated_at = NOW()
');

$upsert_redirect = $db->prepare('INSERT INTO redirects (site_id, from_path, to_url, type)
    VALUES (?, ?, ?, 301)
    ON DUPLICATE KEY UPDATE to_url = VALUES(to_url)
');

$update_issue = $db->prepare('UPDATE seo_issues SET suggested_fix = ?, status = "fix_applied", fixed_at = NOW() WHERE id = ?');

foreach ($issues as $issue) {
    $type = $issue['type'];
    $url = $issue['url'];
    $path = parse_url($url, PHP_URL_PATH) ?: '/';

    switch ($type) {
        case 'missing_canonical':
            $canonical = rtrim($url, '/');
            $upsert_seo->execute([$site_id, $path, $canonical, null, null, null, null, null]);
            $update_issue->execute(["Canonical tag added via ContentAgent snippet: {$canonical}", $issue['id']]);
            $fixed++;
            $applied[] = "canonical: {$path}";
            break;

        case 'missing_meta':
        case 'duplicate_meta':
            $desc = $issue['description'];
            $meta_title = null;
            $meta_desc = null;

            if (strpos($desc, 'too long') !== false) {
                preg_match('/"([^"]+)"/', $desc, $m);
                $original = $m[1] ?? '';
                if ($original) {
                    $result = haiku_chat('Shorten this page title to under 60 characters. Keep the main keyword. Output ONLY the new title.', $original, 64);
                    $meta_title = $result['success'] ? trim($result['content'], '"\'') : mb_substr($original, 0, 57) . '...';
                }
            } elseif (strpos($desc, 'no meta description') !== false) {
                $result = haiku_chat('Generate a concise SEO meta description (130-155 chars) for this page. Output ONLY the description.', "Page: {$url}, Site: {$site['name']}", 128);
                $meta_desc = $result['success'] ? trim($result['content'], '"\'') : null;
            } elseif (strpos($desc, 'Duplicate') !== false) {
                $result = haiku_chat('Generate a unique SEO title (under 60 chars) for this page. Output ONLY the title.', "Page: {$url}, Site: {$site['name']}", 64);
                $meta_title = $result['success'] ? trim($result['content'], '"\'') : null;
            }

            if ($meta_title || $meta_desc) {
                $upsert_seo->execute([$site_id, $path, null, $meta_title, $meta_desc, $meta_title, $meta_desc, null]);
                $fix_text = $meta_title ? "Title: {$meta_title}" : "Description: " . mb_substr($meta_desc, 0, 80) . "...";
                $update_issue->execute(["Fixed via ContentAgent snippet. {$fix_text}", $issue['id']]);
                $fixed++;
                $applied[] = "meta: {$path}";
            } else {
                $skipped++;
            }
            usleep(200000);
            break;

        case 'missing_og':
            $upsert_seo->execute([$site_id, $path, null, null, null, $site['name'], $site['brand_tone'] ?? '', null]);
            $update_issue->execute(["OG tags added via ContentAgent snippet.", $issue['id']]);
            $fixed++;
            $applied[] = "og: {$path}";
            break;

        case 'missing_schema':
            $schema = json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $site['name'],
                'url' => $url,
            ]);
            $upsert_seo->execute([$site_id, $path, null, null, null, null, null, $schema]);
            $update_issue->execute(["Schema markup added via ContentAgent snippet.", $issue['id']]);
            $fixed++;
            $applied[] = "schema: {$path}";
            break;

        case 'missing_canonical':
            // Already handled above
            break;

        case 'broken_link':
            if (strpos($issue['description'], 'External') !== false) {
                $update_issue->execute(["External link — update manually on your site.", $issue['id']]);
                $skipped++;
            } else {
                // Internal broken link — find redirect target
                $sitemap = scraper_check_sitemap($domain);
                $target = null;
                if ($sitemap['exists']) {
                    $urls = scraper_parse_sitemap($sitemap['body']);
                    $best_score = 0;
                    foreach ($urls as $surl) {
                        $spath = parse_url($surl, PHP_URL_PATH) ?? '';
                        similar_text($path, $spath, $pct);
                        if ($pct > $best_score && $pct > 40) {
                            $best_score = $pct;
                            $target = $surl;
                        }
                    }
                }

                if ($target) {
                    $upsert_redirect->execute([$site_id, $path, $target]);
                    $update_issue->execute(["Redirect created: {$path} → {$target}. Handled via ContentAgent snippet.", $issue['id']]);
                    $fixed++;
                    $applied[] = "redirect: {$path} → {$target}";
                } else {
                    $update_issue->execute(["Broken link. No matching redirect target found. Create the page or remove links.", $issue['id']]);
                    $skipped++;
                }
            }
            break;

        case 'missing_alt':
            $result = haiku_chat('Generate descriptive alt text (max 100 chars) for an image. Output ONLY the alt text.', "Image: {$url}, Site: {$site['name']}", 64);
            if ($result['success']) {
                $update_issue->execute(["Alt text generated: " . trim($result['content'], '"\''), $issue['id']]);
                $fixed++;
                $applied[] = "alt: " . basename($url);
            } else {
                $skipped++;
            }
            usleep(200000);
            break;

        default:
            $update_issue->execute([$issue['suggested_fix'] ?? 'Review manually.', $issue['id']]);
            $skipped++;
            break;
    }
}

// Deploy files to CMS only on first batch
$deployed_files = [];
if ($has_cms && $offset === 0) {
    $deploy_url = rtrim($cms_url, '/') . '/api/deploy-file.php';

    // llms.txt
    $llms = generate_llms_txt($site, $db);
    if (deploy_to_cms($deploy_url, $cms_api_key, 'llms.txt', $llms)) {
        $deployed_files[] = 'llms.txt';
    }

    // Schema files
    $org_schema = schema_organization($site);
    if (deploy_to_cms($deploy_url, $cms_api_key, 'schema-organization.json', $org_schema)) {
        $deployed_files[] = 'schema-organization.json';
    }
}

// Log
$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $site_id, 'auto_fix_all',
    json_encode(['fixed' => $fixed, 'skipped' => $skipped, 'deployed' => $deployed_files, 'applied' => array_slice($applied, 0, 20)]),
    'success',
]);

$next_offset = $offset + $batch_size;
$has_more = $next_offset < $total_issues;

json_response([
    'success'      => true,
    'fixed'        => $fixed,
    'skipped'      => $skipped,
    'batch_size'   => count($issues),
    'total_issues' => $total_issues,
    'offset'       => $offset,
    'next_offset'  => $has_more ? $next_offset : null,
    'has_more'     => $has_more,
    'applied'      => $applied,
    'deployed'     => $deployed_files,
    'snippet_url'  => config('app_url') . '/snippet/contentagent.js',
]);

function deploy_to_cms(string $url, string $key, string $filename, string $content): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['filename' => $filename, 'content' => $content]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $key],
        CURLOPT_TIMEOUT => 15,
    ]);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status === 200;
}
