<?php
/**
 * Competitor discovery CLI (background-job version).
 *
 * Replaces the all-in-one synchronous public/api/competitors-discover.php that
 * kept hitting nginx/PHP-FPM 60s proxy timeouts. The thin web endpoint creates
 * an agent_runs row, fires this script via nohup, returns the job_id. UI polls
 * /api/competitors-status.php for current_step + final result.
 *
 * Steps written into agent_runs.current_step so the UI can show real progress
 * instead of an indefinite spinner:
 *   1. Generating discovery queries from business profile
 *   2. Searching Google [N/M queries]
 *   3. Filtering candidates with AI relevance pass
 *   4. Saving competitors
 *
 * Usage: php agent/competitors-discover.php --site=N --run=M
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/business_profile.php';
require_once __DIR__ . '/../includes/haiku.php';
require_once __DIR__ . '/../includes/serp.php';

$db = require __DIR__ . '/../includes/db.php';

$opts    = getopt('', ['site:', 'run:']);
$site_id = (int)($opts['site'] ?? 0);
$run_id  = (int)($opts['run'] ?? 0);

if (!$site_id || !$run_id) {
    fwrite(STDERR, "Usage: php competitors-discover.php --site=N --run=M\n");
    exit(1);
}

// Tiny helper to keep agent_runs current — UI reads this every 3s.
$progress = function(string $step, int $pct, ?array $result = null) use ($db, $run_id) {
    $sql = 'UPDATE agent_runs SET current_step = ?, progress = ?';
    $params = [$step, $pct];
    if ($result !== null) {
        $sql .= ', result_summary = ?';
        $params[] = json_encode($result);
    }
    $sql .= ' WHERE id = ?';
    $params[] = $run_id;
    $db->prepare($sql)->execute($params);
};

$mark_done = function(array $summary) use ($db, $run_id) {
    $db->prepare('UPDATE agent_runs SET status = "done", progress = 100, current_step = "Done", result_summary = ?, finished_at = NOW() WHERE id = ?')
       ->execute([json_encode($summary), $run_id]);
};
$mark_failed = function(string $error, ?array $summary = null) use ($db, $run_id) {
    $db->prepare('UPDATE agent_runs SET status = "failed", current_step = "Failed", error = ?, result_summary = ?, finished_at = NOW() WHERE id = ?')
       ->execute([$error, $summary ? json_encode($summary) : null, $run_id]);
    exit(1);
};

try {
    // ── Load site + profile ───────────────────────────────
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    if (!$site)     $mark_failed('Site not found');
    $profile = profile_get($db, $site_id);

    if (!serp_active_provider()) {
        $mark_failed('No SERP provider configured. Set up Brave Search (free) or DataForSEO in Integrations Hub.');
    }
    if (!$profile || (empty($profile['industry_category']) && empty($profile['industry_sub']))) {
        $mark_failed('Business profile incomplete. Go to Site Identity, fill in at least Industry, then retry.');
    }

    // ── Step 1: Generate discovery queries via Claude ─────
    $progress('Generating discovery queries from your business profile...', 5);

    $query_gen_system = "You are a competitive intelligence analyst. Given a business profile, output 6 Google search queries that a real buyer evaluating ALTERNATIVES to this business would type. The goal is to surface this business's actual competitors — peer-scale firms in the same industry and geography. NOT the business's own customers, NOT their suppliers, NOT mega-corp incumbents the buyer wouldn't realistically consider.\n\nOutput ONLY a JSON array of strings, no commentary.\n\nMix the 6 queries across these intents:\n  - Geography + industry: 'AI consulting firms India', 'document AI companies Pune'\n  - Size-appropriate descriptor: 'boutique AI consulting India', 'mid-market software development partner'\n  - Use case specific: 'computer vision services for manufacturing', 'document automation for healthcare India'\n  - Alternatives framing: 'alternatives to mid-tier AI consultancies', 'top AI consulting agencies for SMBs'\n\nDO NOT include the business's own brand name in the queries. DO NOT make them sound like the business is searching about itself. Frame them as a buyer looking for options.";

    $resp = haiku_chat($query_gen_system, profile_prompt_block($profile), 600);
    if (empty($resp['success'])) {
        $mark_failed('Could not generate discovery queries via AI: ' . ($resp['error'] ?? 'unknown'));
    }
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($resp['content']));
    $query_strings = json_decode($content, true);
    if (!is_array($query_strings) || empty($query_strings)) {
        $mark_failed('AI returned unparseable query list. Raw: ' . substr($content, 0, 300));
    }
    $query_strings = array_values(array_slice($query_strings, 0, 12)); // background job — we can afford more
    $total_q = count($query_strings);

    // ── Domain helpers + filters (verbatim from the old endpoint) ──
    $own_domain = preg_replace('#^(https?://)?(www\.)?#i', '', strtolower(trim($site['domain'])));
    $own_domain = rtrim($own_domain, '/');
    $brand_root = strtolower(explode('.', $own_domain)[0]);
    $brand_root = preg_replace('/(tech|techno|technologies|labs|software|digital|studio|agency|group|inc|co|ltd)$/i', '', $brand_root);
    $brand_root = strlen($brand_root) >= 4 ? $brand_root : strtolower(explode('.', $own_domain)[0]);

    $exclusion_list = [
        'wikipedia.org','reddit.com','quora.com','youtube.com','medium.com',
        'linkedin.com','facebook.com','twitter.com','x.com','instagram.com',
        'pinterest.com','tumblr.com','tiktok.com',
        'amazon.com','amazon.in','amazon.co.uk','ebay.com','etsy.com','alibaba.com',
        'google.com','bing.com','duckduckgo.com','yahoo.com',
        'apple.com','play.google.com','microsoft.com',
        'github.com','stackoverflow.com','news.ycombinator.com',
    ];
    $extract_apex = function(string $url): ?string {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;
        return preg_replace('#^www\.#', '', strtolower($host)) ?: null;
    };
    $is_excluded = function(string $domain) use ($own_domain, $exclusion_list, $brand_root): bool {
        if ($domain === $own_domain || str_ends_with($domain, '.' . $own_domain)) return true;
        foreach ($exclusion_list as $bad) {
            if ($domain === $bad || str_ends_with($domain, '.' . $bad)) return true;
        }
        if ($brand_root !== '' && strlen($brand_root) >= 4) {
            $apex = explode('.', $domain)[0];
            if (str_starts_with($apex, $brand_root)) return true;
            if (similar_text($apex, $brand_root) / max(strlen($apex), strlen($brand_root)) >= 0.75) return true;
        }
        return false;
    };

    // ── Step 2: Run SERP for each generated query ─────────
    $domain_data = [];
    $serp_calls = 0;
    $failed_lookups = 0;
    $provider_tally = [];
    $first_error = null;

    foreach ($query_strings as $i => $q) {
        $progress("Searching Google [" . ($i + 1) . "/{$total_q}]: \"{$q}\"", 10 + (int)(($i / $total_q) * 70));
        $serp_calls++;

        $serp = serp_search($q, 30);
        if (!empty($serp['provider'])) {
            $provider_tally[$serp['provider']] = ($provider_tally[$serp['provider']] ?? 0) + 1;
        }
        if (empty($serp['results'])) {
            $failed_lookups++;
            if ($first_error === null && !empty($serp['errors'])) {
                $first_error = implode(' / ', array_map(
                    fn($p, $msg) => "{$p}: {$msg}",
                    array_keys($serp['errors']),
                    array_values($serp['errors'])
                ));
            }
            continue;
        }

        foreach ($serp['results'] as $item) {
            $url = $item['url'] ?? '';
            if (!$url) continue;
            $domain = $extract_apex($url);
            if (!$domain || $is_excluded($domain)) continue;
            $position = (int)($item['position'] ?? 0) ?: 99;
            if (!isset($domain_data[$domain])) $domain_data[$domain] = ['rankings' => []];
            $domain_data[$domain]['rankings'][] = [
                'keyword'  => $q,
                'position' => $position,
                'url'      => $url,
                'title'    => $item['title'] ?? '',
            ];
        }
        usleep(100000);
    }

    // ── Score candidates ──────────────────────────────────
    $candidates = [];
    foreach ($domain_data as $domain => $info) {
        $shared = count($info['rankings']);
        $top5 = 0;
        foreach ($info['rankings'] as $r) if (($r['position'] ?? 99) <= 5) $top5++;
        if ($shared < 2 && $top5 === 0) continue;
        $candidates[$domain] = [
            'shared'        => $shared,
            'overlap_score' => (int)round(($shared / max(1, $total_q)) * 100),
            'rankings'      => $info['rankings'],
        ];
    }
    uasort($candidates, fn($a, $b) => $b['shared'] <=> $a['shared']);
    $candidates = array_slice($candidates, 0, 30, true);

    // ── Step 3: Claude relevance filter ───────────────────
    if (!empty($candidates) && count($candidates) >= 5) {
        $progress('Filtering candidates with AI relevance pass...', 85);
        $profile_block = profile_prompt_block($profile);
        $domain_list = array_keys($candidates);
        $sys = "You filter SERP-discovered candidate competitor domains for relevance. "
            . "Output ONLY valid JSON: {\"drop\":[\"domain1\",\"domain2\",...],\"reasons\":{\"domain1\":\"why\",...}}. "
            . "Drop a domain when ANY of these are true:\n"
            . "  - Its company is clearly >5x larger than the business below (e.g. Infosys/TCS/Wipro for a 15-person firm)\n"
            . "  - It serves a totally different geographic market than the business\n"
            . "  - It is a job board, directory, marketplace, news site, government site, or aggregator (not a direct competitor)\n"
            . "  - It is the customer's own subsidiary / parent / sibling brand\n"
            . "  - It is a TYPO or VARIANT of the customer's brand name (xceed.com / xceedtek.com / xceed.me class)\n"
            . "Keep when unsure — don't drop more than half the list.";
        $prompt = $profile_block . "\n\n## Candidate competitor domains:\n" . implode("\n", array_map(fn($d) => "  - {$d}", $domain_list));
        $resp = haiku_chat($sys, $prompt, 600);
        if (!empty($resp['success'])) {
            $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($resp['content']));
            $data = json_decode($clean, true);
            if (is_array($data['drop'] ?? null)) {
                $drops = array_map('strtolower', $data['drop']);
                $max_drop = (int)floor(count($candidates) / 2);
                if (count($drops) > $max_drop) $drops = array_slice($drops, 0, $max_drop);
                foreach ($drops as $bad) unset($candidates[$bad]);
            }
        }
    }
    $candidates = array_slice($candidates, 0, 15, true);

    // ── Step 4: Save to DB ────────────────────────────────
    $progress('Saving ' . count($candidates) . ' competitors...', 95);

    $insert_comp = $db->prepare('INSERT INTO competitors (site_id, domain, source, status, overlap_score, shared_keywords, last_analysed_at)
        VALUES (?, ?, "auto", "active", ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            overlap_score = VALUES(overlap_score),
            shared_keywords = VALUES(shared_keywords),
            last_analysed_at = NOW(),
            status = IF(status = "ignored", "ignored", "active")');
    $insert_rank = $db->prepare('INSERT INTO competitor_keyword_rankings (competitor_id, keyword_id, position, url, title, last_seen_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE position = VALUES(position), url = VALUES(url), title = VALUES(title), last_seen_at = NOW()');

    $inserted = 0;
    foreach ($candidates as $domain => $info) {
        $insert_comp->execute([$site_id, $domain, $info['overlap_score'], $info['shared']]);
        $cid_stmt = $db->prepare('SELECT id FROM competitors WHERE site_id = ? AND domain = ?');
        $cid_stmt->execute([$site_id, $domain]);
        $cid = (int)$cid_stmt->fetchColumn();
        if (!$cid) continue;
        $inserted++;
        foreach ($info['rankings'] as $r) {
            $insert_rank->execute([$cid, 0, $r['position'], mb_substr($r['url'], 0, 2048), mb_substr($r['title'], 0, 500)]);
        }
    }

    // ── Build headline + finalize ─────────────────────────
    $headline = null;
    $fix_hint = null;
    if ($serp_calls > 0 && $failed_lookups === $serp_calls) {
        $msg = $first_error ?: 'unknown error';
        $headline = "All {$serp_calls} SERP calls failed: {$msg}";
        if (stripos($msg, 'balance') !== false || stripos($msg, 'insufficient') !== false) {
            $fix_hint = 'DataForSEO balance is empty. Top up at https://app.dataforseo.com/billing';
        } elseif (stripos($msg, 'auth') !== false || stripos($msg, '401') !== false) {
            $fix_hint = 'SERP provider rejected the credentials. Re-enter in Integrations Hub.';
        }
    }

    $summary = [
        'queries_used'       => $total_q,
        'queries_searched'   => $serp_calls,
        'failed_lookups'     => $failed_lookups,
        'candidates_found'   => count($candidates),
        'competitors_saved'  => $inserted,
        'provider_breakdown' => $provider_tally,
        'queries'            => $query_strings, // useful for the UI to show "we asked Google: …"
        'error_headline'     => $headline,
        'fix_hint'           => $fix_hint,
    ];
    $mark_done($summary);

    echo "Done. Saved {$inserted} competitors from {$serp_calls} SERP calls.\n";
} catch (Throwable $e) {
    error_log('[competitors-discover CLI] ' . $e->getMessage());
    $mark_failed($e->getMessage());
}
