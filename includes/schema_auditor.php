<?php
/**
 * Live schema.org auditor — fetches each tracked URL, parses every
 * <script type="application/ld+json"> block, and diffs the @type set
 * against what ContentAgent expected to emit for that URL's content type.
 *
 * Why this matters: ContentAgent already generates Article + FAQPage +
 * BreadcrumbList bundles at publish time. But a theme update, plugin
 * conflict, or sloppy CMS edit can silently strip those tags. Without
 * verification, the customer believes their schema is in place when it
 * actually isn't — they lose rich snippets + AI-engine citation eligibility
 * without anyone noticing.
 *
 * Strategy:
 *   1. Per published post (or known URL with structured data), record the
 *      expected @types in schema_audits.expected_types.
 *   2. Auditor fetches the URL, extracts all <script type="application/ld+json">
 *      blocks, parses each, extracts @type at any level (including @graph).
 *   3. Status:
 *        ok        — every expected type present
 *        degraded  — some present, some missing
 *        broken    — no JSON-LD at all (the worst case)
 *        fetch_failed — couldn't get the page
 *   4. Surfaces "broken/degraded" count on the SEO health card and as alerts.
 */

require_once __DIR__ . '/helpers.php';

const SCH_TIMEOUT = 20;
const SCH_DELAY_US = 600000;

function sch_normalise_url(string $url): string
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
    return rtrim($url, '/');
}

/**
 * Pull every JSON-LD block from raw HTML. Returns array of parsed dicts/lists.
 */
function sch_extract_json_ld(string $html): array
{
    $blocks = [];
    $errors = [];
    if (!preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>([\s\S]*?)</script>#i', $html, $m)) {
        return ['blocks' => [], 'errors' => []];
    }
    foreach ($m[1] as $raw) {
        $clean = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($clean === '') continue;
        $j = json_decode($clean, true);
        if (!is_array($j)) {
            // Some themes wrap JSON-LD in HTML comments or have trailing junk — try a softer parse
            if (preg_match('/\{[\s\S]*\}/', $clean, $mm)) $j = json_decode($mm[0], true);
        }
        if (is_array($j)) $blocks[] = $j;
        else $errors[] = mb_substr($clean, 0, 200);
    }
    return ['blocks' => $blocks, 'errors' => $errors];
}

/**
 * Recursively walk a JSON-LD block (handles @graph, nested mainEntity, etc.)
 * and collect every @type encountered. Strings + arrays both supported.
 */
function sch_collect_types(array $node, array &$out): void
{
    if (!empty($node['@type'])) {
        $t = $node['@type'];
        if (is_array($t)) foreach ($t as $tt) $out[$tt] = true;
        else $out[(string)$t] = true;
    }
    // recurse common containers
    foreach (['@graph', 'mainEntity', 'itemListElement', 'about', 'isPartOf'] as $k) {
        if (!empty($node[$k]) && is_array($node[$k])) {
            // could be a single object or a list
            if (isset($node[$k]['@type']) || isset($node[$k]['@graph'])) {
                sch_collect_types($node[$k], $out);
            } else {
                foreach ($node[$k] as $child) {
                    if (is_array($child)) sch_collect_types($child, $out);
                }
            }
        }
    }
}

/**
 * Fetch + audit one URL against an expected-types list. Returns the row to upsert.
 */
function sch_audit_url(string $url, array $expected_types): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => SCH_TIMEOUT,
        CURLOPT_USERAGENT      => 'ContentAgent-SchemaAuditor/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code < 200 || $code >= 300) {
        return [
            'has_json_ld'   => 0,
            'block_count'   => 0,
            'found_types'   => [],
            'missing_types' => $expected_types,
            'extra_types'   => [],
            'parse_errors'  => $err ? [$err] : ["HTTP {$code}"],
            'last_status'   => 'fetch_failed',
        ];
    }

    $extract = sch_extract_json_ld((string)$body);
    $blocks  = $extract['blocks'];
    $errors  = $extract['errors'];

    $found_set = [];
    foreach ($blocks as $b) sch_collect_types($b, $found_set);
    $found = array_keys($found_set);

    $expected_set = array_flip($expected_types);
    $missing = array_values(array_diff($expected_types, $found));
    $extra   = array_values(array_diff($found, $expected_types));

    $has_jsonld = !empty($blocks);
    if (!$has_jsonld)                   $status = 'broken';
    elseif (empty($missing))            $status = 'ok';
    else                                $status = 'degraded';

    return [
        'has_json_ld'   => $has_jsonld ? 1 : 0,
        'block_count'   => count($blocks),
        'found_types'   => $found,
        'missing_types' => $missing,
        'extra_types'   => $extra,
        'parse_errors'  => $errors,
        'last_status'   => $status,
    ];
}

/**
 * Register or refresh expected schema for a URL. Idempotent.
 */
function sch_register_url(PDO $db, int $site_id, string $url, array $expected_types): void
{
    $url  = sch_normalise_url($url);
    $hash = sha1($url);
    $stmt = $db->prepare("INSERT INTO schema_audits (site_id, url, url_hash, expected_types, created_at)
                          VALUES (?, ?, ?, ?, NOW())
                          ON DUPLICATE KEY UPDATE expected_types = VALUES(expected_types), updated_at = NOW()");
    $stmt->execute([$site_id, mb_substr($url, 0, 2048), $hash, json_encode(array_values(array_unique($expected_types)))]);
}

/**
 * Audit one URL by id — pulls expected list from DB, fetches, diffs, stores.
 */
function sch_audit_row(PDO $db, int $audit_id): array
{
    $stmt = $db->prepare("SELECT id, url, expected_types FROM schema_audits WHERE id = ?");
    $stmt->execute([$audit_id]);
    $row = $stmt->fetch();
    if (!$row) return ['error' => 'not found'];

    $expected = json_decode($row['expected_types'] ?? '[]', true) ?: [];
    $res = sch_audit_url($row['url'], $expected);

    $upd = $db->prepare("UPDATE schema_audits SET
        has_json_ld = ?, block_count = ?, found_types = ?, missing_types = ?, extra_types = ?,
        parse_errors = ?, last_status = ?, last_checked_at = NOW(), updated_at = NOW()
        WHERE id = ?");
    $upd->execute([
        $res['has_json_ld'], $res['block_count'],
        json_encode($res['found_types']), json_encode($res['missing_types']),
        json_encode($res['extra_types']), json_encode($res['parse_errors']),
        $res['last_status'], $audit_id,
    ]);
    return $res + ['url' => $row['url']];
}

/** Audit all tracked URLs for a site. Polite spacing. */
function sch_audit_site(PDO $db, int $site_id, int $batch = 200): array
{
    $stmt = $db->prepare("SELECT id FROM schema_audits WHERE site_id = ? ORDER BY last_checked_at IS NULL DESC, last_checked_at ASC LIMIT ?");
    $stmt->bindValue(1, $site_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $batch, PDO::PARAM_INT);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $counters = ['checked' => 0, 'ok' => 0, 'degraded' => 0, 'broken' => 0, 'fetch_failed' => 0];
    foreach ($ids as $id) {
        $r = sch_audit_row($db, (int)$id);
        $counters['checked']++;
        $st = $r['last_status'] ?? 'fetch_failed';
        if (isset($counters[$st])) $counters[$st]++;
        usleep(SCH_DELAY_US);
    }
    return $counters;
}

/**
 * Auto-register every published post for a site that ContentAgent has
 * emitted schema for. Uses the content_type to derive expected_types.
 */
function sch_register_posts(PDO $db, int $site_id): int
{
    $stmt = $db->prepare("SELECT id, slug, type FROM posts
                          WHERE site_id = ? AND status IN ('published','approved') AND slug IS NOT NULL AND slug <> ''");
    $stmt->execute([$site_id]);
    $rows = $stmt->fetchAll();

    $site = $db->query("SELECT domain, blog_path FROM sites WHERE id = {$site_id}")->fetch();
    if (!$site) return 0;
    $base = 'https://' . preg_replace('#^https?://#i', '', (string)$site['domain']);
    $blog = $site['blog_path'] ?: '/blog';

    $registered = 0;
    foreach ($rows as $r) {
        $url = $base . rtrim($blog, '/') . '/' . $r['slug'];
        $expected = ['BlogPosting', 'BreadcrumbList']; // Article variants
        // Most blog posts also include FAQPage when ContentAgent's multi-artifact
        // pipeline ran — register that too; auditor surfaces it as missing only
        // when ALL expected are missing (degraded) vs broken.
        $expected[] = 'FAQPage';
        sch_register_url($db, $site_id, $url, $expected);
        $registered++;
    }
    return $registered;
}

/** Summary counts for the dashboard. */
function sch_site_summary(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT last_status, COUNT(*) AS cnt FROM schema_audits WHERE site_id = ? GROUP BY last_status");
    $stmt->execute([$site_id]);
    $by_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = $db->prepare("SELECT MAX(last_checked_at) FROM schema_audits WHERE site_id = ?");
    $stmt->execute([$site_id]);
    return [
        'by_status' => $by_status,
        'total'     => (int)array_sum($by_status),
        'last_run'  => $stmt->fetchColumn(),
    ];
}
