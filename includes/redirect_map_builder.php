<?php
/**
 * 301 Redirect Map Builder — for each dead historical URL, find the best
 * living target on the same site.
 *
 * Strategy: cheap heuristic match FIRST (exact slug, exact path, same-type
 * slug similarity), then Claude fuzzy match for everything that didn't get
 * a high-confidence heuristic hit. Saves tokens on the obvious cases.
 *
 * Match methods + confidence ranges:
 *   slug_exact      — same slug, same type. Confidence 95.
 *   path_exact      — exact path on the live site. Confidence 95.
 *   type_match      — same type, similar slug (levenshtein < 4). Confidence 75.
 *   claude_fuzzy    — Claude picked the target from a curated candidate list. 50-90.
 *   claude_branch   — Claude says "no good target, suggest 410 Gone". Confidence 100 (on the negative judgement).
 *   manual          — user-edited target. Confidence 100.
 *
 * Risk-tiered:
 *   confidence >= 85 → auto_approved = 1, status = 'approved' on insert.
 *   confidence 60-84 → status = 'pending' (review queue).
 *   confidence <  60 → status = 'pending' AND flagged in UI as "needs decision".
 *
 * Builder is idempotent — re-running updates existing rows without dupes.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/haiku.php';

const RMB_AUTO_APPROVE_THRESHOLD = 85;
const RMB_BATCH                  = 30;

/** Extract the slug = last path segment. Lowercased, dashes/underscores normalised. */
function rmb_slug_of(string $path): string
{
    $path = rtrim($path, '/');
    $seg  = substr($path, strrpos($path, '/') + 1);
    $seg  = strtolower($seg);
    $seg  = preg_replace('/\.(php|html?|aspx?)$/', '', $seg);
    $seg  = preg_replace('/[_\s]+/', '-', $seg);
    return $seg ?: '';
}

function rmb_classify_path(string $path): string
{
    if ($path === '/' || $path === '') return 'home';
    if (str_starts_with($path, '/products/'))    return 'product';
    if (str_starts_with($path, '/collections/')) return 'collection';
    if (str_starts_with($path, '/blogs/'))       return 'blog';
    if (str_starts_with($path, '/blog/'))        return 'blog';
    if (str_starts_with($path, '/pages/'))       return 'page';
    return 'other';
}

/**
 * Try cheap heuristic matches first. Returns array on hit, null on miss.
 * { to_path, confidence, match_method }.
 */
function rmb_heuristic_match(string $dead_path, array $live_index): ?array
{
    $dead_slug = rmb_slug_of($dead_path);
    $dead_type = rmb_classify_path($dead_path);

    // index.*, home.*, default.* → homepage (/). Common after CMS migrations where
    // the old site used directory indexes (index.php, home.html) and the new site
    // serves the homepage from /.
    $basename = basename($dead_path);
    $stem     = strtolower(preg_replace('/\.\w+$/', '', $basename));
    if (in_array($stem, ['index', 'home', 'default'], true)) {
        foreach ($live_index as $live) {
            if ($live['path'] === '/' || $live['url_type'] === 'home') {
                return ['to_path' => '/', 'confidence' => 95, 'match_method' => 'slug_exact'];
            }
        }
    }

    // Exact path? Live site happens to have the same path (e.g. /about == /about)
    foreach ($live_index as $live) {
        if ($live['path'] === $dead_path) {
            return ['to_path' => $live['path'], 'confidence' => 95, 'match_method' => 'path_exact'];
        }
    }

    // Same slug + same type (e.g. /products/foo-old → /products/foo-old on a different domain shape)
    if ($dead_slug !== '') {
        foreach ($live_index as $live) {
            if (rmb_slug_of($live['path']) === $dead_slug && $live['url_type'] === $dead_type) {
                return ['to_path' => $live['path'], 'confidence' => 90, 'match_method' => 'slug_exact'];
            }
        }
    }

    // Old .php / .html → /clean-slug fallback (very common after CMS migration)
    if (preg_match('#\.(php|html?|aspx?)$#i', $dead_path)) {
        $clean = preg_replace('#\.(php|html?|aspx?)$#i', '', $dead_path);
        foreach ($live_index as $live) {
            if ($live['path'] === $clean) {
                return ['to_path' => $live['path'], 'confidence' => 92, 'match_method' => 'slug_exact'];
            }
            // also try slug-similarity match (levenshtein-style) — but only on
            // longer slugs. Short slugs (team vs terms, four vs five) collide
            // semantically too often: a 1-2 letter levenshtein distance on a
            // 4-char slug is just noise.
            $live_slug = rmb_slug_of($live['path']);
            if ($live_slug !== '' && $dead_slug !== ''
                && strlen($dead_slug) >= 6 && strlen($live_slug) >= 6
                && levenshtein($dead_slug, $live_slug) <= 2) {
                return ['to_path' => $live['path'], 'confidence' => 78, 'match_method' => 'type_match'];
            }
        }
    }

    return null;
}

/**
 * Ask Claude to pick the best target from a curated candidate list.
 * @return array { to_path: string|null, confidence: int, reasoning: string }
 */
function rmb_claude_match(string $dead_path, string $dead_type, array $candidates): array
{
    if (empty($candidates)) {
        return ['to_path' => null, 'confidence' => 0, 'reasoning' => 'no candidates supplied'];
    }
    $candidate_lines = [];
    foreach ($candidates as $c) {
        $candidate_lines[] = "- {$c['path']} (type={$c['url_type']}" . ($c['title'] ? ", title=\"{$c['title']}\"" : '') . ')';
    }
    $candidate_block = implode("\n", $candidate_lines);

    $system = "You are a website-migration specialist. For each old (dead) URL, pick the SINGLE BEST currently-live URL to 301-redirect to from a candidate list. Match by topic + URL type (a dead product redirects to a live product, not a blog). If no candidate is a reasonable fit, return to_path=null with confidence=0 — that's better than a wrong redirect.\n\nOUTPUT — strict JSON:\n{\"to_path\":\"/path\" or null, \"confidence\":0-100, \"reasoning\":\"1 sentence on why this is the best match (or why none fits)\"}\n\nConfidence calibration:\n- 90+: same content idea, same URL type, name is a clear evolution\n- 70-89: same type, related topic but not identical\n- 50-69: related topic but different type (e.g. product → collection)\n- <50: weak match — prefer null at this band\n- null: no candidate is a good fit; the URL should 410 Gone or get a manual decision";

    $user = "Dead URL path: {$dead_path}\nDead URL type: {$dead_type}\n\nCandidate live URLs:\n{$candidate_block}";

    $resp = haiku_chat($system, $user, 500);
    if (empty($resp['success'])) {
        return ['to_path' => null, 'confidence' => 0, 'reasoning' => 'claude error: ' . ($resp['error'] ?? 'unknown')];
    }
    $txt = trim($resp['content']);
    $txt = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $txt);
    $j = json_decode($txt, true);
    if (!is_array($j) && preg_match('/\{[\s\S]*\}/', $txt, $m)) $j = json_decode($m[0], true);
    if (!is_array($j)) return ['to_path' => null, 'confidence' => 0, 'reasoning' => 'unparseable claude output'];

    $to = $j['to_path'] ?? null;
    if ($to !== null) {
        $to = (string)$to;
        if ($to === '' || $to === 'null') $to = null;
    }
    return [
        'to_path'    => $to,
        'confidence' => max(0, min(100, (int)($j['confidence'] ?? 0))),
        'reasoning'  => mb_substr((string)($j['reasoning'] ?? ''), 0, 1000),
    ];
}

/**
 * Shortlist the most promising live candidates for one dead URL. Caps the
 * Claude prompt size + helps it focus. Strategy: same-type first, then path
 * neighbours, then a generic homepage / catalog fallback.
 */
function rmb_shortlist_candidates(string $dead_path, string $dead_type, array $live_index, int $cap = 15): array
{
    $same_type = []; $other = [];
    foreach ($live_index as $live) {
        if ($live['url_type'] === $dead_type) $same_type[] = $live;
        else                                   $other[]    = $live;
    }
    // Sort same-type by levenshtein to dead slug
    $dead_slug = rmb_slug_of($dead_path);
    usort($same_type, function ($a, $b) use ($dead_slug) {
        return levenshtein($dead_slug, rmb_slug_of($a['path']))
             - levenshtein($dead_slug, rmb_slug_of($b['path']));
    });
    $pick = array_slice($same_type, 0, $cap);
    if (count($pick) < $cap) {
        // top up with other types (home + a couple)
        foreach ($other as $o) {
            if ($o['url_type'] === 'home' || $o['url_type'] === 'collection') {
                $pick[] = $o;
                if (count($pick) >= $cap) break;
            }
        }
    }
    return $pick;
}

/**
 * Build the redirect map for a site. Picks dead URLs from historical_urls
 * (where is_dead=1) and produces a `redirects` row for each. Idempotent.
 *
 * @return array { processed, hits, no_target, errors }
 */
function rmb_build_map(PDO $db, int $site_id, ?int $limit = null, ?callable $progress = null): array
{
    $db->prepare("INSERT INTO redirect_runs (site_id, kind, status) VALUES (?, 'build', 'running')")
       ->execute([$site_id]);
    $run_id = (int)$db->lastInsertId();

    // Live URL inventory (same site)
    $stmt = $db->prepare("SELECT path, url_type, title FROM current_site_urls WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $live_index = $stmt->fetchAll();
    if (empty($live_index)) {
        $db->prepare("UPDATE redirect_runs SET status='failed', finished_at=NOW(), error=? WHERE id=?")
           ->execute(['no live URL inventory — run the crawler first', $run_id]);
        return ['processed' => 0, 'hits' => 0, 'no_target' => 0, 'errors' => 0, 'error' => 'crawl_first'];
    }

    // Dead URLs that don't yet have a redirect row (or have one we should refresh)
    $sql = "SELECT h.id, h.url, h.path
            FROM historical_urls h
            LEFT JOIN redirect_map r ON r.site_id = h.site_id
                                 AND r.from_path_hash = SHA1(h.path)
                                 AND r.status IN ('approved','applied','rejected')
            WHERE h.site_id = ? AND h.is_dead = 1 AND r.id IS NULL
            ORDER BY h.snapshot_count DESC, h.id";
    if ($limit) $sql .= " LIMIT " . max(1, (int)$limit);
    $stmt = $db->prepare($sql);
    $stmt->execute([$site_id]);
    $dead_rows = $stmt->fetchAll();

    $upsert = $db->prepare("INSERT INTO redirect_map
        (site_id, from_path, from_path_hash, to_path, source, source_ref,
         confidence, match_method, reasoning, status, auto_approved)
        VALUES (?, ?, ?, ?, 'wayback', ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            to_path = VALUES(to_path),
            confidence = VALUES(confidence),
            match_method = VALUES(match_method),
            reasoning = VALUES(reasoning),
            status = IF(status IN ('applied','reverted','rejected'), status, VALUES(status)),
            auto_approved = VALUES(auto_approved),
            updated_at = NOW()");

    $processed = 0; $hits = 0; $no_target = 0; $errors = 0;
    foreach ($dead_rows as $row) {
        $dead_path = (string)$row['path'];
        $dead_type = rmb_classify_path($dead_path);
        $processed++;

        // 1. Heuristic match first
        $match = rmb_heuristic_match($dead_path, $live_index);

        // 2. Claude fuzzy if heuristic missed
        if (!$match) {
            $candidates = rmb_shortlist_candidates($dead_path, $dead_type, $live_index, 15);
            $cm = rmb_claude_match($dead_path, $dead_type, $candidates);
            if ($cm['to_path']) {
                $match = [
                    'to_path' => $cm['to_path'],
                    'confidence' => $cm['confidence'],
                    'match_method' => 'claude_fuzzy',
                    'reasoning' => $cm['reasoning'],
                ];
            } else {
                $match = [
                    'to_path' => null,
                    'confidence' => 0,
                    'match_method' => 'claude_branch',
                    'reasoning' => $cm['reasoning'] ?: 'no living target identified',
                ];
            }
        } else {
            // Add a tiny reasoning blurb for heuristic hits so the review UI is informative
            $match['reasoning'] = "Heuristic match: {$match['match_method']}";
        }

        $auto_approved = ($match['to_path'] !== null && $match['confidence'] >= RMB_AUTO_APPROVE_THRESHOLD) ? 1 : 0;
        $status = $auto_approved ? 'approved' : 'pending';
        if ($match['to_path'] === null) {
            $status = 'pending';
            $no_target++;
        } else {
            $hits++;
        }

        try {
            $upsert->execute([
                $site_id, $dead_path, sha1($dead_path), $match['to_path'],
                (string)$row['id'], $match['confidence'], $match['match_method'],
                $match['reasoning'], $status, $auto_approved,
            ]);
        } catch (Throwable $e) {
            $errors++;
            error_log('[rmb] upsert: ' . $e->getMessage());
        }

        if ($progress) $progress(['processed' => $processed, 'hits' => $hits, 'no_target' => $no_target]);
    }

    $db->prepare("UPDATE redirect_runs SET status='done', finished_at=NOW(),
        items_processed=?, items_succeeded=?, items_failed=? WHERE id=?")
       ->execute([$processed, $hits, $errors, $run_id]);

    return ['processed' => $processed, 'hits' => $hits, 'no_target' => $no_target, 'errors' => $errors, 'run_id' => $run_id];
}

/**
 * Dry-run the heuristic pass over every unprocessed dead URL without writing
 * anything or calling Claude. Used by the preflight estimator to figure out
 * how many URLs will fall through to the (paid) AI fuzzy match.
 *
 * Returns counts in the same buckets the live builder would have used —
 * which is the input the cost estimator expects.
 *
 * Runtime: ~30s for 16K URLs. All-PHP, no network.
 *
 * @return array {
 *   dead_total: int,
 *   already_done: int,        // dead URLs with an existing approved/applied/rejected redirect — won't reprocess
 *   to_process: int,
 *   heuristic_hits: int,      // would match via path_exact / slug_exact / type_match
 *   needs_ai: int,            // would need rmb_claude_match
 *   live_inventory_size: int,
 * }
 */
function rmb_dry_run(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT path, url_type, title FROM current_site_urls WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $live_index = $stmt->fetchAll();

    // Mirror the live builder's "what's left to process" query.
    $sql_total = "SELECT COUNT(*) FROM historical_urls WHERE site_id = ? AND is_dead = 1";
    $stmt = $db->prepare($sql_total);
    $stmt->execute([$site_id]);
    $dead_total = (int)$stmt->fetchColumn();

    $sql_pending = "SELECT h.path
        FROM historical_urls h
        LEFT JOIN redirect_map r ON r.site_id = h.site_id
                             AND r.from_path_hash = SHA1(h.path)
                             AND r.status IN ('approved','applied','rejected')
        WHERE h.site_id = ? AND h.is_dead = 1 AND r.id IS NULL";
    $stmt = $db->prepare($sql_pending);
    $stmt->execute([$site_id]);
    $pending = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $heuristic_hits = 0;
    if (!empty($live_index)) {
        foreach ($pending as $dead_path) {
            if (rmb_heuristic_match((string)$dead_path, $live_index) !== null) {
                $heuristic_hits++;
            }
        }
    }
    $to_process = count($pending);

    return [
        'dead_total'          => $dead_total,
        'already_done'        => max(0, $dead_total - $to_process),
        'to_process'          => $to_process,
        'heuristic_hits'      => $heuristic_hits,
        'needs_ai'            => max(0, $to_process - $heuristic_hits),
        'live_inventory_size' => count($live_index),
    ];
}

/** Summary counts for the dashboard. */
function rmb_site_summary(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT status, COUNT(*) AS cnt FROM redirect_map WHERE site_id = ? GROUP BY status");
    $stmt->execute([$site_id]);
    $by_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = $db->prepare("SELECT
        SUM(CASE WHEN confidence >= 85 THEN 1 ELSE 0 END) AS high,
        SUM(CASE WHEN confidence BETWEEN 60 AND 84 THEN 1 ELSE 0 END) AS med,
        SUM(CASE WHEN confidence < 60 OR confidence IS NULL THEN 1 ELSE 0 END) AS low
        FROM redirect_map WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $by_conf = $stmt->fetch() ?: [];
    return [
        'by_status'    => $by_status,
        'high'         => (int)($by_conf['high'] ?? 0),
        'medium'       => (int)($by_conf['med'] ?? 0),
        'low'          => (int)($by_conf['low'] ?? 0),
        'total'        => (int)array_sum($by_status),
    ];
}
