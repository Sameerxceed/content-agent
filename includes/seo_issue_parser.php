<?php
/**
 * SEO issue parser — turns pasted alert text (GSC emails, Bing alerts, manual
 * notifications) into structured rows in the seo_issues table.
 *
 * Flow:
 *   1. User pastes raw text from a Google Search Console email (or similar).
 *   2. Claude extracts a list of { url, issue_code, severity, recommended_fix }.
 *   3. We normalise issue_code to our seo_issues.type ENUM.
 *   4. Caller decides which to persist via seo_issue_save_parsed().
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/haiku.php';

/**
 * Map common external issue labels onto our internal ENUM values.
 */
function seo_issue_normalise_code(string $raw): string
{
    $key = strtolower(trim($raw));
    $map = [
        'not found (404)'                              => 'not_found_404',
        'not found'                                    => 'not_found_404',
        '404'                                          => 'not_found_404',
        '404 not found'                                => 'not_found_404',
        "excluded by 'noindex' tag"                    => 'noindex_blocked',
        'excluded by noindex tag'                      => 'noindex_blocked',
        'noindex'                                      => 'noindex_blocked',
        'noindex tag'                                  => 'noindex_blocked',
        'duplicate without user-selected canonical'    => 'duplicate_no_canonical',
        'duplicate, google chose different canonical'  => 'duplicate_no_canonical',
        'duplicate without canonical'                  => 'duplicate_no_canonical',
        'duplicate'                                    => 'duplicate_no_canonical',
        'soft 404'                                     => 'soft_404',
        'blocked by robots.txt'                        => 'blocked_by_robots',
        'crawled - currently not indexed'              => 'crawled_not_indexed',
        'crawled - not indexed'                        => 'crawled_not_indexed',
        'discovered - currently not indexed'           => 'discovered_not_indexed',
        'discovered - not indexed'                     => 'discovered_not_indexed',
        'server error (5xx)'                           => 'server_error_5xx',
        'server error'                                 => 'server_error_5xx',
        'redirect error'                               => 'redirect_error',
        'page with redirect'                           => 'redirect_error',
        'mobile usability issues'                      => 'mobile_usability',
        'core web vitals'                              => 'core_web_vitals',
    ];
    return $map[$key] ?? 'other_external';
}

/**
 * Suggested fix template per issue code — used when AI didn't provide one.
 */
function seo_issue_default_fix(string $code): string
{
    return match ($code) {
        'not_found_404'           => 'Restore the page, 301 redirect to the closest equivalent, or remove the URL from sitemap.xml so Google stops crawling it.',
        'noindex_blocked'         => 'If the page should be indexed, remove the <meta name="robots" content="noindex"> tag. If it should NOT be indexed, also remove it from sitemap.xml to silence the warning.',
        'duplicate_no_canonical'  => 'Add a <link rel="canonical"> tag on this URL pointing to the version you want Google to treat as primary, or 301 redirect duplicates to it.',
        'soft_404'                => 'Either return a real 404 status, or add unique content so the page is genuinely useful.',
        'blocked_by_robots'       => 'Remove the matching Disallow rule from robots.txt if you want this URL indexed.',
        'crawled_not_indexed'     => 'Quality signal — Google saw it but didn\'t find it valuable. Improve content depth, add internal links, and request re-indexing.',
        'discovered_not_indexed'  => 'Google knows the URL exists but hasn\'t crawled it. Improve internal linking from your indexed pages.',
        'server_error_5xx'        => 'Investigate server logs for the timestamp Google reported. Common causes: timeout, memory limit, plugin error, DNS flap.',
        'redirect_error'          => 'Check for redirect chains, loops, or destinations that 404. Use a fresh single-hop 301.',
        'mobile_usability'        => 'Open the URL in mobile view. Fix tap-target spacing, viewport meta, font size, or horizontal scroll.',
        'core_web_vitals'         => 'Audit LCP/CLS/INP on the URL. Most often caused by large images, layout shift from late-loading widgets, or main-thread JS.',
        default                   => 'Review the URL and check Search Console for further details.',
    };
}

/**
 * Parse a blob of alert / email text into structured issues.
 *
 * @param string $raw_text   Pasted email or alert body
 * @param array  $site       site row (for domain hints in the prompt)
 * @return array { success: bool, issues?: array<{url, issue_code, severity, recommended_fix}>, error?: string }
 */
function seo_issue_parse_alert(string $raw_text, array $site): array
{
    $raw_text = trim($raw_text);
    if ($raw_text === '') return ['success' => false, 'error' => 'No text provided'];

    $domain = preg_replace('#^https?://#i', '', trim($site['domain'] ?? ''));
    $domain = preg_replace('#^www\.#i', '', $domain);

    $system = "You parse search-console / SEO alert emails into structured data.\n"
        . "Extract every distinct issue and the URL(s) it applies to. Output ONLY valid JSON of this shape:\n"
        . "[{\"url\": \"https://...\", \"issue_label\": \"the raw issue label as it appeared\", \"severity\": \"critical|warning|info\", \"recommended_fix\": \"one short action\"}]\n\n"
        . "Rules:\n"
        . "- If a URL has multiple issues, emit one entry per (url, issue) pair.\n"
        . "- If the alert mentions an issue but not specific URLs, set url to the domain root and explain in recommended_fix.\n"
        . "- Severity guidance: 'critical' for 404/5xx/blocked-by-robots, 'warning' for noindex/duplicate/redirect-error, 'info' for soft cases.\n"
        . "- recommended_fix should be one short imperative sentence, not paragraphs.\n"
        . "- Skip noise: signoffs, footer links, unsubscribe text.";

    $user = "Site: {$domain}\n\nAlert text:\n{$raw_text}";

    $resp = haiku_chat($system, $user, 2500);
    if (empty($resp['success'])) {
        return ['success' => false, 'error' => $resp['error'] ?? 'AI call failed'];
    }

    $content = trim($resp['content']);
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
    $arr = json_decode($content, true);
    if (!is_array($arr) && preg_match('/\[[\s\S]*\]/', $content, $m)) {
        $arr = json_decode($m[0], true);
    }
    if (!is_array($arr)) {
        return ['success' => false, 'error' => 'AI returned unparseable output', 'raw' => substr($content, 0, 300)];
    }

    $issues = [];
    foreach ($arr as $row) {
        if (!is_array($row)) continue;
        $url   = trim($row['url'] ?? '');
        $label = trim($row['issue_label'] ?? '');
        if ($url === '' || $label === '') continue;

        $code = seo_issue_normalise_code($label);
        $sev  = strtolower(trim($row['severity'] ?? ''));
        if (!in_array($sev, ['critical', 'warning', 'info'], true)) {
            $sev = match ($code) {
                'not_found_404','server_error_5xx','blocked_by_robots' => 'critical',
                'noindex_blocked','duplicate_no_canonical','redirect_error','mobile_usability' => 'warning',
                default => 'info',
            };
        }

        $fix = trim($row['recommended_fix'] ?? '');
        if ($fix === '') $fix = seo_issue_default_fix($code);

        $issues[] = [
            'url'             => $url,
            'issue_code'      => $code,
            'issue_label'     => $label,
            'severity'        => $sev,
            'recommended_fix' => $fix,
        ];
    }

    return ['success' => true, 'issues' => $issues];
}

/**
 * Persist parsed issues to seo_issues with source='pasted_alert'.
 * Returns the number of rows actually written (skipping duplicates of open issues
 * for the same URL + type, to keep the issues list clean).
 */
function seo_issue_save_parsed(PDO $db, int $site_id, array $issues): int
{
    if (empty($issues)) return 0;

    $check = $db->prepare('SELECT id FROM seo_issues WHERE site_id = ? AND url = ? AND type = ? AND status = "open" LIMIT 1');
    $insert = $db->prepare(
        'INSERT INTO seo_issues (audit_id, site_id, source, type, severity, url, description, suggested_fix, status, created_at)
         VALUES (NULL, ?, "pasted_alert", ?, ?, ?, ?, ?, "open", NOW())'
    );

    $count = 0;
    foreach ($issues as $i) {
        $check->execute([$site_id, $i['url'], $i['issue_code']]);
        if ($check->fetch()) continue; // already open
        $insert->execute([
            $site_id,
            $i['issue_code'],
            $i['severity'],
            $i['url'],
            'External alert: ' . ($i['issue_label'] ?? $i['issue_code']),
            $i['recommended_fix'],
        ]);
        $count++;
    }
    return $count;
}
